<?php

namespace App\Services;

use App\Models\Industry;
use Illuminate\Support\Facades\Cache;

/**
 * Resolve free-text industry strings (from CSVs) to a known industry slug.
 * Match order: exact slug > exact alias > alias substring.
 * Cached per request to keep CSV imports fast.
 */
class IndustryMatcher
{
    private array $keys;

    public function __construct()
    {
        $this->keys = Cache::driver('array')->remember('industry_matcher_keys', 60, function () {
            return Industry::where('is_active', true)->get()->mapWithKeys(fn ($i) => [$i->slug => $i->matchKeys()])->all();
        });
    }

    public function match(?string $text): ?string
    {
        $needle = strtolower(trim((string) $text));
        if ($needle === '') {
            return null;
        }

        // Exact match (slug or alias)
        foreach ($this->keys as $slug => $aliases) {
            if (in_array($needle, $aliases, true)) {
                return $slug;
            }
        }

        // Substring: pick the LONGEST matching alias so "steel fabrication" beats "steel".
        $best = null;
        $bestLen = 0;
        foreach ($this->keys as $slug => $aliases) {
            foreach ($aliases as $alias) {
                if ($alias !== '' && str_contains($needle, $alias) && strlen($alias) > $bestLen) {
                    $best = $slug;
                    $bestLen = strlen($alias);
                }
            }
        }

        return $best;
    }

    public static function clearCache(): void
    {
        Cache::driver('array')->forget('industry_matcher_keys');
    }
}
