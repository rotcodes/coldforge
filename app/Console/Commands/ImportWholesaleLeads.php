<?php

namespace App\Console\Commands;

use App\Models\Contact;
use App\Models\Suppression;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class ImportWholesaleLeads extends Command
{
    protected $signature = 'outreach:import-wholesale
        {file : Absolute or application-relative CSV path}
        {--tag=wholesale-csv-20261124 : Audit tag added to imported contacts}
        {--dry-run : Classify and validate without changing the database}';

    protected $description = 'Import only explicitly identified wholesale/distribution companies from the owner-provided lead CSV.';

    private const REQUIRED_HEADERS = ['Company', 'Contact', 'Email', 'Phone', 'Website', 'Amazon URL', 'Category', 'Source', 'Status', 'Assigned Rep', 'Created'];

    private const WHOLESALE_PATTERN = '/\b(wholesale|wholesaler|distribut(?:or|ors|ion|ing)?)\b/i';

    public function handle(): int
    {
        $path = $this->resolvePath((string) $this->argument('file'));
        $rows = $this->readCsv($path);
        $tag = trim((string) $this->option('tag'));
        if ($tag === '') {
            throw new RuntimeException('The import audit tag cannot be blank.');
        }

        $counts = [
            'rows scanned' => 0,
            'mixed rows skipped' => 0,
            'wholesale/distributor matches' => 0,
            'invalid or missing email' => 0,
            'duplicates in file' => 0,
            'non-new records imported but not eligible' => 0,
            'suppressed, contacted, or otherwise ineligible' => 0,
            'created' => 0,
            'updated' => 0,
            'eligible for ramp' => 0,
        ];
        $seen = [];

        foreach ($rows as $row) {
            $counts['rows scanned']++;
            $classificationText = trim(($row['Company'] ?? '').' '.($row['Website'] ?? ''));
            if (! preg_match(self::WHOLESALE_PATTERN, $classificationText)) {
                $counts['mixed rows skipped']++;

                continue;
            }
            $counts['wholesale/distributor matches']++;

            $email = strtolower(trim((string) ($row['Email'] ?? '')));
            if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $counts['invalid or missing email']++;

                continue;
            }
            if (isset($seen[$email])) {
                $counts['duplicates in file']++;

                continue;
            }
            $seen[$email] = true;

            $sourceStatus = strtolower(trim((string) ($row['Status'] ?? '')));
            $existing = Contact::where('email', $email)->first();
            $blocked = Suppression::where('email', $email)->exists()
                || $existing?->status !== null && $existing->status !== 'active'
                || filled($existing?->last_contacted_at)
                || filled($existing?->last_replied_at)
                || DB::table('mail_recipients as recipient')
                    ->join('mail_messages as message', 'message.id', '=', 'recipient.mail_message_id')
                    ->where('recipient.email', $email)
                    ->where('message.direction', 'outgoing')
                    ->exists();
            $eligible = $sourceStatus === 'new' && ! $blocked;
            if ($sourceStatus !== 'new') {
                $counts['non-new records imported but not eligible']++;
            } elseif ($blocked) {
                $counts['suppressed, contacted, or otherwise ineligible']++;
            }

            if ($this->option('dry-run')) {
                $counts[$existing ? 'updated' : 'created']++;
                if ($eligible) {
                    $counts['eligible for ramp']++;
                }

                continue;
            }

            [$firstName, $lastName] = $this->splitName((string) ($row['Contact'] ?? ''));
            $company = trim((string) ($row['Company'] ?? ''));
            $category = trim((string) ($row['Category'] ?? '')) ?: 'products';
            $existingTags = array_values(array_filter((array) ($existing?->tags ?? [])));
            $tags = array_values(array_unique([...$existingTags, $tag, 'wholesale-distributor']));
            $tags = array_values(array_diff($tags, ['wholesale-outreach-eligible']));
            if ($eligible) {
                $tags[] = 'wholesale-outreach-eligible';
            }
            $customFields = array_merge((array) ($existing?->custom_fields ?? []), [
                'amazon_url' => trim((string) ($row['Amazon URL'] ?? '')),
                'assigned_rep' => trim((string) ($row['Assigned Rep'] ?? '')),
                'source_created' => trim((string) ($row['Created'] ?? '')),
                'source_label' => trim((string) ($row['Source'] ?? '')),
                'source_status' => $sourceStatus,
                'classification_evidence' => 'Company or website explicitly contains wholesale/distribution wording.',
            ]);
            $permission = $eligible ? 'legitimate_interest' : ($existing?->permission_status ?? 'unknown');
            $pipeline = $sourceStatus === 'interested' ? 'interested' : ($existing?->pipeline_status ?? 'new');
            $greeting = $firstName ? 'Hi '.$firstName : 'Hi';
            $opener = $greeting.', quick question: how are you managing wholesale orders, inventory availability, and fulfillment across your '.Str::lower($category).' product lines?';

            $contact = Contact::updateOrCreate(['email' => $email], [
                'first_name' => $firstName ?: $existing?->first_name,
                'last_name' => $lastName ?: $existing?->last_name,
                'company' => $company ?: $existing?->company,
                'business' => $company ?: $existing?->business,
                'country' => $existing?->country ?: 'United States',
                'industry' => $category,
                'industry_slug' => 'wholesale_distribution',
                'website' => trim((string) ($row['Website'] ?? '')) ?: $existing?->website,
                'decision_maker' => trim((string) ($row['Contact'] ?? '')) ?: $existing?->decision_maker,
                'main_phone' => trim((string) ($row['Phone'] ?? '')) ?: $existing?->main_phone,
                'source' => 'csv',
                'source_system' => 'amazon_wholesale',
                'external_id' => hash('sha256', $email),
                'remote_status' => $sourceStatus,
                'personalized_opener' => $opener,
                'custom_subject' => 'Orders and inventory at '.$company,
                'custom_fields' => $customFields,
                'status' => $existing?->status ?? 'active',
                'pipeline_status' => $pipeline,
                'permission_status' => $permission,
                'consent_source' => $eligible ? 'Owner-approved targeted wholesale/distributor CSV segment' : $existing?->consent_source,
                'consent_evidence' => $eligible
                    ? 'The owner approved relevant one-to-one B2B outreach on 2026-11-24 only for source rows explicitly identifying wholesale/distribution and marked new. No consent is claimed; recorded under legitimate-interest review.'
                    : $existing?->consent_evidence,
                'tags' => $tags,
                'notes' => $existing?->notes ?: 'Imported from the owner-provided Amazon US lead file; source ownership and status are retained in custom fields.',
            ]);

            $counts[$existing ? 'updated' : 'created']++;
            if ($eligible && $contact->permission_status === 'legitimate_interest') {
                $counts['eligible for ramp']++;
            }
        }

        $this->table(['Result', 'Count'], collect($counts)->map(fn ($count, $label) => [$label, $count])->values());
        $this->info($this->option('dry-run') ? 'Dry run complete; no contacts changed.' : 'Wholesale-only import complete.');

        return self::SUCCESS;
    }

    private function resolvePath(string $file): string
    {
        $path = preg_match('/^(?:[A-Za-z]:[\\\\\/]|\/)/', $file) ? $file : base_path($file);
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException('CSV file is not readable: '.$path);
        }

        return $path;
    }

    /** @return array<int, array<string, string>> */
    private function readCsv(string $path): array
    {
        $handle = fopen($path, 'rb');
        if (! $handle) {
            throw new RuntimeException('Unable to open CSV file.');
        }
        $headers = fgetcsv($handle, null, ',', '"', '');
        if ($headers !== self::REQUIRED_HEADERS) {
            fclose($handle);
            throw new RuntimeException('Unexpected CSV headers. Refusing an ambiguous import.');
        }
        $rows = [];
        while (($values = fgetcsv($handle, null, ',', '"', '')) !== false) {
            if (count($values) !== count($headers)) {
                continue;
            }
            $rows[] = array_combine($headers, $values);
        }
        fclose($handle);

        return $rows;
    }

    /** @return array{0: ?string, 1: ?string} */
    private function splitName(string $name): array
    {
        $parts = preg_split('/\s+/', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($parts === []) {
            return [null, null];
        }

        return [array_shift($parts), $parts ? implode(' ', $parts) : null];
    }
}
