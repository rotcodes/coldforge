<?php

namespace App\Services;

use App\Models\Contact;

/**
 * Scans unmapped industry text, clusters by shared significant keywords,
 * and returns draft industry presets the operator can one-click accept.
 */
class IndustrySuggester
{
    private const STOPWORDS = [
        'and', 'of', 'the', 'a', 'an', 'in', 'for', 'to', 'with', 'or', 'on',
        'at', 'by', 'from', 'as', 'is', 'be', 'inc', 'llc', 'co', 'corp', 'ltd',
        'company', 'services', 'service', 'business', 'other',
    ];

    /** Default workflow/tracking phrasing used as a sensible starting point for a new preset. */
    private const DEFAULT_WORKFLOWS = 'customer accounts, orders, quotes, and delivery coordination';
    private const DEFAULT_TRACKING = 'accounts, orders, quotes, and delivery status';

    /** @return array<int, array{slug: string, label: string, aliases: string[], contact_count: number, texts: array<string, int>, workflows: string, tracking: string}> */
    public function suggest(): array
    {
        $texts = Contact::whereNotNull('industry')
            ->where('industry', '!=', '')
            ->whereNull('industry_slug')
            ->select('industry')
            ->selectRaw('COUNT(*) as cnt')
            ->groupBy('industry')
            ->orderByDesc('cnt')
            ->limit(200)
            ->get()
            ->mapWithKeys(fn ($r) => [$r->industry => (int) $r->cnt])
            ->all();

        if (empty($texts)) {
            return [];
        }

        // tokenMap: token => [text => count]
        $tokenMap = [];
        foreach ($texts as $text => $count) {
            foreach ($this->tokens($text) as $tok) {
                $tokenMap[$tok][$text] = $count;
            }
        }

        // Rank tokens by (number of distinct texts * total contact count)
        $scored = [];
        foreach ($tokenMap as $token => $texts2) {
            $scored[$token] = ['token' => $token, 'texts' => $texts2, 'contacts' => array_sum($texts2), 'distinct' => count($texts2)];
        }
        uasort($scored, fn ($a, $b) => ($b['contacts'] * $b['distinct']) <=> ($a['contacts'] * $a['distinct']));

        // Greedy cluster: iterate tokens, claim texts not yet assigned.
        $used = [];
        $clusters = [];
        foreach ($scored as $token => $data) {
            $unclaimed = array_diff_key($data['texts'], $used);
            // Only keep a cluster if it covers >=2 distinct texts or >=3 contacts.
            if (count($unclaimed) < 2 && array_sum($unclaimed) < 3) {
                continue;
            }
            $used += array_fill_keys(array_keys($unclaimed), true);

            // Build the suggestion.
            $aliases = array_keys($unclaimed);
            $totalContacts = array_sum($unclaimed);
            $label = $this->labelFor($token, $aliases);
            $clusters[] = [
                'slug' => $this->slug($token),
                'label' => $label,
                'aliases' => array_map('strtolower', $aliases),
                'contact_count' => $totalContacts,
                'workflows' => self::DEFAULT_WORKFLOWS,
                'tracking' => self::DEFAULT_TRACKING,
            ];

            if (count($clusters) >= 10) {
                break;
            }
        }

        return $clusters;
    }

    /** Lowercase significant tokens (>=4 chars, not stopword, not pure digits). */
    private function tokens(string $text): array
    {
        $parts = preg_split('/[\s\/\-&,.()]+/', strtolower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_filter($parts, fn ($t) => strlen($t) >= 4 && ! in_array($t, self::STOPWORDS, true) && ! ctype_digit($t)));
    }

    /** Pick a readable label: the shortest alias is usually the cleanest, otherwise "<token> business". */
    private function labelFor(string $token, array $aliases): string
    {
        usort($aliases, fn ($a, $b) => strlen($a) <=> strlen($b));
        $shortest = $aliases[0] ?? $token;

        // If the shortest alias is already a reasonable business label (≤4 words), use it.
        if (str_word_count($shortest) <= 4) {
            return strtolower($shortest).' business';
        }

        return $token.' business';
    }

    private function slug(string $s): string
    {
        return trim(preg_replace('/[^a-z0-9]+/', '_', strtolower($s)), '_');
    }
}
