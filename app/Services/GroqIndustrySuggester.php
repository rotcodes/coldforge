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
    // Groq's current large OSS model. Smaller/faster alternative: 'openai/gpt-oss-20b'.
    private const MODEL = 'openai/gpt-oss-120b';
    private const MAX_TEXTS = 150;
    private const TIMEOUT_SECONDS = 20;

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
- EVERY input string must appear in exactly ONE aliases array.
- CRITICAL: Each alias must be the raw string ONLY. Do NOT append anything like "(N contacts)" or any suffix — the parenthesised counts in the input are metadata for you, strip them from your output.
- Workflows and tracking must be short, specific to that cluster's industry, non-empty.
- Slugs are lowercase with underscores, max 50 chars, descriptive (e.g. "machining_shop", "wire_harness_mfr").

Return ONLY valid JSON matching this exact shape (no prose, no markdown fences):
{
  "clusters": [
    {
      "slug": "machining_shop",
      "label": "precision machining shop",
      "workflows": "RFQs, drawing revisions, CNC jobs, and quality documentation",
      "tracking": "quotes, drawings, production jobs, and QC records",
      "aliases": ["Swiss machining", "CNC machine shop"]
    }
  ]
}
PROMPT;

        $user = "Cluster these industry strings. The number in parentheses after each is how many contacts have that text — informational only, strip it from your aliases.\n\n{$list}";

        return [
            'model' => self::MODEL,
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $user],
            ],
            'temperature' => 0.2,
            // GPT-OSS 120B burns a lot of internal reasoning tokens before producing output;
            // 4096 wasn't enough and Groq returned json_validate_failed. 8192 gives headroom.
            'max_tokens' => 8192,
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
            // Defensively strip "(N contacts)" suffix in case the model ignored the instruction.
            $covered = 0;
            $validAliases = [];
            foreach ($aliases as $a) {
                $clean = trim(preg_replace('/\s*\(\s*\d+\s*contacts?\s*\)\s*$/i', '', $a));
                if ($clean === '') {
                    continue;
                }
                $lookup = $textCounts[$clean] ?? $knownTexts[strtolower($clean)] ?? null;
                if ($lookup !== null) {
                    $covered += $lookup;
                    $validAliases[] = $clean;
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
