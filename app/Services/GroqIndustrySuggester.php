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

        $system = 'You cluster business categories for cold outreach. Output JSON only: '
            .'{"clusters":[{"slug":"...","label":"...","workflows":"...","tracking":"...","aliases":["..."]}]}. '
            .'4-8 clusters. Every input belongs to exactly one cluster. '
            .'Aliases are the raw input strings verbatim WITHOUT the "(N contacts)" suffix. '
            .'Slug: lowercase_underscores. Workflows/tracking: one short phrase specific to the cluster.';

        $user = "Cluster:\n{$list}";

        return [
            'model' => self::MODEL,
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $user],
            ],
            'temperature' => 0.2,
            // GPT-OSS 120B burns many reasoning tokens before output; give it room.
            'max_tokens' => 16384,
            // Intentionally no response_format: Groq's json_object mode validates the whole
            // completion as JSON, which fails whenever the model's reasoning or any trailing
            // whitespace/comment slips through. We extract JSON ourselves below.
        ];
    }

    private function parseClusters(string $raw, array $textCounts): array
    {
        // Strip markdown fences and prose around the JSON. Extract the first {...} that parses.
        $trimmed = trim($raw);
        $trimmed = preg_replace('/^```(?:json)?\s*/i', '', $trimmed);
        $trimmed = preg_replace('/\s*```\s*$/', '', $trimmed);

        $json = json_decode($trimmed, true);
        if (! is_array($json) && preg_match('/\{.*\}/s', $trimmed, $m)) {
            $json = json_decode($m[0], true);
        }

        if (! is_array($json) || ! isset($json['clusters']) || ! is_array($json['clusters'])) {
            Log::warning('Groq suggester bad JSON', ['raw' => substr($raw, 0, 500)]);

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
