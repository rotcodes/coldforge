<?php

namespace App\Jobs;

use App\Models\Contact;
use App\Models\CsvImport;
use App\Services\IndustryMatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Upserts contacts from an uploaded CSV in batches of 500.
 * mapping: { "<csv header>": "<field>" } where field is one of Contact::FIELDS,
 * "tags", "custom:<key>", or "" (skip).
 *
 * Rules enforced here (reported to the operator via CsvImport counters):
 *   - rows missing a valid email are counted as failed
 *   - rows whose remote_status / remote_reply_status indicates SENT/CONTACTED/replied
 *     are imported but auto-marked status=unsubscribed so the pipeline never retries them
 *   - industry text is fuzzy-matched to an Industry slug for template personalization
 *   - (source_system, external_id) pairs upsert in place to allow re-imports
 */
class ImportContactsJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 1800;

    private const UNSUBSCRIBE_STATUSES = ['SENT', 'CONTACTED', 'REPLIED', 'OPTOUT', 'OPT-OUT', 'UNSUBSCRIBED', 'BOUNCED'];

    public function __construct(public CsvImport $import) {}

    public function handle(): void
    {
        $import = $this->import;
        $import->update(['status' => 'processing', 'imported_count' => 0, 'failed_count' => 0]);

        $matcher = new IndustryMatcher;

        $handle = fopen(Storage::path($import->path), 'r');
        if (fread($handle, 3) !== "\xEF\xBB\xBF") {
            rewind($handle);
        }
        $headers = array_map(fn ($h) => trim($h, " \t\n\r\0\x0B\"'"), fgetcsv($handle) ?: []);
        $mapping = $import->mapping ?? [];
        $source = 'csv_import_'.$import->id;

        $batch = [];
        $failed = 0;
        while (($row = fgetcsv($handle)) !== false) {
            if ($row === [null]) {
                continue;
            }
            $contact = $this->mapRow($headers, $row, $mapping, $matcher);
            if (! $contact) {
                $failed++;

                continue;
            }
            $batch[$contact['email']] = $contact;
            if (count($batch) >= 500) {
                $this->flush($batch, $source, $failed);
                $batch = [];
                $failed = 0;
            }
        }
        $this->flush($batch, $source, $failed);
        fclose($handle);

        $import->update(['status' => 'done']);
    }

    public function failed(Throwable $e): void
    {
        $this->import->update(['status' => 'failed']);
    }

    private function mapRow(array $headers, array $row, array $mapping, IndustryMatcher $matcher): ?array
    {
        $contact = ['custom_fields' => [], 'tags' => $this->import->tags ?? []];

        foreach ($headers as $i => $header) {
            $field = $mapping[$header] ?? '';
            $value = trim((string) ($row[$i] ?? ''));
            if ($field === '' || $value === '') {
                continue;
            }
            if (str_starts_with($field, 'custom:')) {
                $contact['custom_fields'][substr($field, 7)] = $value;
            } elseif ($field === 'tags') {
                $contact['tags'] = array_merge($contact['tags'], array_map('trim', preg_split('/[,;|]/', $value)));
            } elseif (in_array($field, Contact::FIELDS, true) || in_array($field, ['source_system', 'external_id', 'remote_status', 'remote_reply_status', 'lead_score', 'confidence'], true)) {
                $contact[$field] = $field === 'lead_score' ? (int) preg_replace('/\D/', '', $value) : $value;
            }
        }

        $email = strtolower($contact['email'] ?? '');
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }
        $contact['email'] = $email;

        // Fuzzy-match industry text to a known slug (if any).
        if (! empty($contact['industry']) && empty($contact['industry_slug'] ?? null)) {
            $slug = $matcher->match($contact['industry']);
            if ($slug) {
                $contact['industry_slug'] = $slug;
            }
        }

        // Honor remote-side status: anything already contacted / replied / unsubbed stays off-limits.
        $remote = strtoupper((string) ($contact['remote_status'] ?? '')).'|'.strtoupper((string) ($contact['remote_reply_status'] ?? ''));
        $contact['status'] = $this->isUnsubscribed($remote) ? 'unsubscribed' : 'active';

        return $contact;
    }

    private function isUnsubscribed(string $combined): bool
    {
        if (preg_match('/\|[^|]+$/', $combined, $m) && trim($m[0], '|') !== '') {
            return true; // any non-empty reply status means they engaged — don't re-pitch
        }
        foreach (self::UNSUBSCRIBE_STATUSES as $s) {
            if (str_contains($combined, $s)) {
                return true;
            }
        }

        return false;
    }

    /** Merge with existing contacts (keeps their tags/custom fields/local status) and upsert. */
    private function flush(array $batch, string $source, int $failed): void
    {
        if ($batch) {
            $existing = Contact::whereIn('email', array_keys($batch))->get()->keyBy('email');
            $now = now();

            $rows = array_map(fn ($c) => $this->toRow($c, $existing[$c['email']] ?? null, $source, $now), array_values($batch));

            $updateCols = [
                'first_name', 'last_name', 'company', 'country', 'business', 'industry', 'industry_slug',
                'city_state', 'website', 'decision_maker', 'dm_role', 'direct_phone', 'main_phone',
                'company_linkedin', 'dm_linkedin', 'source_system', 'external_id', 'remote_status',
                'remote_reply_status', 'lead_score', 'confidence', 'personalized_opener', 'pain_evidence',
                'recommended_offer', 'custom_subject', 'why_now_trigger', 'notion_url', 'other_emails',
                'custom_fields', 'tags', 'status', 'updated_at',
            ];
            Contact::upsert($rows, ['email'], $updateCols);
        }

        $this->import->increment('imported_count', count($batch));
        $this->import->increment('failed_count', $failed);
    }

    private function toRow(array $c, ?Contact $old, string $source, $now): array
    {
        $customFields = array_merge($old?->custom_fields ?? [], $c['custom_fields'] ?? []);
        $tags = array_values(array_unique(array_filter(array_merge($old?->tags ?? [], $c['tags'] ?? []))));

        return [
            'email' => $c['email'],
            'first_name' => $c['first_name'] ?? $old?->first_name,
            'last_name' => $c['last_name'] ?? $old?->last_name,
            'company' => $c['company'] ?? $old?->company,
            'country' => $c['country'] ?? $old?->country,
            'business' => $c['business'] ?? $old?->business ?? ($c['company'] ?? null),
            'industry' => $c['industry'] ?? $old?->industry,
            'industry_slug' => $c['industry_slug'] ?? $old?->industry_slug,
            'city_state' => $c['city_state'] ?? $old?->city_state,
            'website' => $c['website'] ?? $old?->website,
            'decision_maker' => $c['decision_maker'] ?? $old?->decision_maker,
            'dm_role' => $c['dm_role'] ?? $old?->dm_role,
            'direct_phone' => $c['direct_phone'] ?? $old?->direct_phone,
            'main_phone' => $c['main_phone'] ?? $old?->main_phone,
            'company_linkedin' => $c['company_linkedin'] ?? $old?->company_linkedin,
            'dm_linkedin' => $c['dm_linkedin'] ?? $old?->dm_linkedin,
            'source_system' => $c['source_system'] ?? $old?->source_system,
            'external_id' => $c['external_id'] ?? $old?->external_id,
            'remote_status' => $c['remote_status'] ?? $old?->remote_status,
            'remote_reply_status' => $c['remote_reply_status'] ?? $old?->remote_reply_status,
            'lead_score' => $c['lead_score'] ?? $old?->lead_score,
            'confidence' => $c['confidence'] ?? $old?->confidence,
            'personalized_opener' => $c['personalized_opener'] ?? $old?->personalized_opener,
            'pain_evidence' => $c['pain_evidence'] ?? $old?->pain_evidence,
            'recommended_offer' => $c['recommended_offer'] ?? $old?->recommended_offer,
            'custom_subject' => $c['custom_subject'] ?? $old?->custom_subject,
            'why_now_trigger' => $c['why_now_trigger'] ?? $old?->why_now_trigger,
            'notion_url' => $c['notion_url'] ?? $old?->notion_url,
            'other_emails' => $c['other_emails'] ?? $old?->other_emails,
            'custom_fields' => json_encode($customFields ?: new \stdClass),
            'tags' => json_encode($tags),
            'status' => $this->resolveStatus($c, $old),
            'source' => $old?->source ?? $source,
            'created_at' => $old?->created_at ?? $now,
            'updated_at' => $now,
        ];
    }

    private function resolveStatus(array $c, ?Contact $old): string
    {
        // Never un-unsubscribe someone who already said no.
        if ($old && $old->status !== 'active') {
            return $old->status;
        }

        return $c['status'] ?? 'active';
    }
}
