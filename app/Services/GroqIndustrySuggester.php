<?php

namespace App\Services;

use App\Models\Contact;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Calls Groq's free LLM API (OpenAI-compatible) to semantically cluster
 * unmapped industry text into clean industry presets.
 *
 * Returns an empty array on any failure — the controller falls back to the
 * keyword-based IndustrySuggester so the feature always works even without
 * a key, offline, or when the API is down.
 */
class GroqIndustrySuggester
{
    private const ENDPOINT = 'https://api.groq.com/openai/v1/chat/completions';
    private const MODEL = 'llama-3.3-70b-versatile';
    private const MAX_TEXTS = 150;
    private const TIMEOUT_SECONDS = 15;

    public function available(): bool
    {
        return filled(config('services.groq.api_key'));
    }

    /** @return array<int, array{slug: string, label: string, aliases: string[], contact_count: int, workflows: string, tracking: string}> */
    public function suggest(): array
    {
        $texts = Contact::whereNotNull('industry')
            ->where('industry', '!=', '')
            ->whereNull('industry_slug')
            ->select('industry')
            ->selectRaw('COUNT(*) as cnt')
            ->groupBy('industry')
            ->orderByDesc('cnt')
            ->limit(self::MAX_TEXTS)
            ->get()
            ->mapWithKeys(fn ($r) => [$r->industry => (int) $r->cnt])
            ->all();

        if (empty($texts)) {
            return [];
        }

        $payload = $this->buildPayload($texts);

        try {
            $response = Http::timeout(self::TIMEOUT_SECONDS)
                ->withToken(config('services.groq.api_key'))
                ->acceptJson()
                ->asJson()
                ->post(self::ENDPOINT, $payload);
        } catch (\Throwable $e) {
            Log::warning('Groq suggester network error', ['error' => $e->getMessage()]);

            return [];
        }

        if (! $response->successful()) {
            Log::warning('Groq suggester non-2xx', ['status' => $response->status(), 'body' => $response->body()]);

            return [];
        }

        $content = $response->json('choices.0.message.content');
        if (! $content) {
            return [];
        }

        return $this->parseClusters($content, $texts);
    }

    private function buildPayload(array $texts): array
    {
        $list = collect($texts)->map(fn ($cnt, $text) => "- {$text} ({$cnt} contacts)")->implode("\n");

        $system = <<<'PROMPT'
You cluster raw business/industry text strings into coherent cold-outreach segments.

Rules:
- Group semantically similar businesses, even when the words differ (e.g. "swiss machining" and "cnc machine shop" belong together).
- Prefer 4-10 clusters total. Never leave a text unassigned.
- EVERY input string must appear verbatim in exactly ONE aliases array.
- Workflows and tracking must be specific to that cluster's industry, not generic.
- Slugs are lowercase with underscores, max 50 chars, descriptive (e.g. "machining_shop", "wire_harness_mfr").

Return ONLY valid JSON matching this exact shape (no prose, no markdown fences):
{
  "clusters": [
    {
      "slug": "machining_shop",
      "label": "precision machining shop",
      "workflows": "RFQs, drawing revisions, CNC jobs, and quality documentation",
      "tracking": "quotes, drawings, production jobs, and QC records",
      "aliases": ["<exact input string>", "<another exact input string>"]
    }
  ]
}
PROMPT;

        $user = "Cluster these industry strings from imported leads:\n\n{$list}";

        return [
            'model' => self::MODEL,
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $user],
            ],
            'temperature' => 0.2,
            'max_tokens' => 4096,
            'response_format' => ['type' => 'json_object'],
        ];
    }

    private function parseClusters(string $raw, array $textCounts): array
    {
        $json = json_decode($raw, true);
        if (! is_array($json) || ! isset($json['clusters']) || ! is_array($json['clusters'])) {
            Log::warning('Groq suggester bad JSON', ['raw' => $raw]);

            return [];
        }

        $knownTexts = array_change_key_case($textCounts, CASE_LOWER);
        $out = [];
        foreach ($json['clusters'] as $c) {
            $slug = $this->slug($c['slug'] ?? ($c['label'] ?? ''));
            $label = trim((string) ($c['label'] ?? ''));
            $workflows = trim((string) ($c['workflows'] ?? ''));
            $tracking = trim((string) ($c['tracking'] ?? ''));
            $aliases = array_values(array_filter(array_map('strval', $c['aliases'] ?? [])));

            if (! $slug || ! $label || ! $workflows || ! $tracking || empty($aliases)) {
                continue;
            }

            // Count contacts covered — only aliases that actually exist in our input.
            $covered = 0;
            $validAliases = [];
            foreach ($aliases as $a) {
                $lookup = $textCounts[$a] ?? $knownTexts[strtolower($a)] ?? null;
                if ($lookup !== null) {
                    $covered += $lookup;
                    $validAliases[] = $a; // keep the model's casing but the backend matches case-insensitively on import anyway
                }
            }

            if (empty($validAliases)) {
                continue;
            }

            $out[] = [
                'slug' => substr($slug, 0, 50),
                'label' => substr($label, 0, 150),
                'workflows' => substr($workflows, 0, 1000),
                'tracking' => substr($tracking, 0, 1000),
                'aliases' => $validAliases,
                'contact_count' => $covered,
            ];
        }

        // Sort by contact impact so the operator sees the big wins first.
        usort($out, fn ($a, $b) => $b['contact_count'] <=> $a['contact_count']);

        return $out;
    }

    private function slug(string $s): string
    {
        return trim(preg_replace('/[^a-z0-9]+/', '_', strtolower($s)), '_');
    }
}
