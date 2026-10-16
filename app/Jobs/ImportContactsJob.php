<?php

namespace App\Jobs;

use App\Models\Contact;
use App\Models\CsvImport;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Upserts contacts from an uploaded CSV in batches of 500.
 * mapping: { "<csv header>": "email|first_name|last_name|company|country|tags|custom:<key>|" (blank = skip) }
 */
class ImportContactsJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 1800;

    public function __construct(public CsvImport $import) {}

    public function handle(): void
    {
        $import = $this->import;
        $import->update(['status' => 'processing', 'imported_count' => 0, 'failed_count' => 0]);

        $handle = fopen(Storage::path($import->path), 'r');
        $headers = array_map(fn ($h) => trim($h, " \t\n\r\0\x0B\xEF\xBB\xBF"), fgetcsv($handle) ?: []);
        $mapping = $import->mapping ?? [];
        $source = 'csv_import_'.$import->id;

        $batch = [];
        $failed = 0;
        while (($row = fgetcsv($handle)) !== false) {
            if ($row === [null]) {
                continue; // blank line
            }
            $contact = $this->mapRow($headers, $row, $mapping);
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

    private function mapRow(array $headers, array $row, array $mapping): ?array
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
            } elseif (in_array($field, Contact::FIELDS, true)) {
                $contact[$field] = $value;
            }
        }

        $email = strtolower($contact['email'] ?? '');
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }
        $contact['email'] = $email;

        return $contact;
    }

    /** Merge with existing contacts (keeps their tags/custom fields/status) and upsert. */
    private function flush(array $batch, string $source, int $failed): void
    {
        if ($batch) {
            $existing = Contact::whereIn('email', array_keys($batch))->get()->keyBy('email');
            $now = now();

            $rows = array_map(function ($c) use ($existing, $source, $now) {
                $old = $existing[$c['email']] ?? null;

                return [
                    'email' => $c['email'],
                    'first_name' => $c['first_name'] ?? $old?->first_name,
                    'last_name' => $c['last_name'] ?? $old?->last_name,
                    'company' => $c['company'] ?? $old?->company,
                    'country' => $c['country'] ?? $old?->country,
                    'custom_fields' => json_encode(array_merge($old?->custom_fields ?? [], $c['custom_fields']) ?: new \stdClass),
                    'tags' => json_encode(array_values(array_unique(array_filter(array_merge($old?->tags ?? [], $c['tags']))))),
                    'status' => $old?->status ?? 'active',
                    'source' => $old?->source ?? $source,
                    'created_at' => $old?->created_at ?? $now,
                    'updated_at' => $now,
                ];
            }, array_values($batch));

            Contact::upsert($rows, ['email'], ['first_name', 'last_name', 'company', 'country', 'custom_fields', 'tags', 'updated_at']);
        }

        $this->import->increment('imported_count', count($batch));
        $this->import->increment('failed_count', $failed);
    }
}
