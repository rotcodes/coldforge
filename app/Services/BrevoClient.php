<?php

namespace App\Services;

use App\Models\Sender;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Thin wrapper around Brevo's REST API for the "Brevo panel" UI.
 * Pulls account/credits/stats so the operator can see Brevo-side numbers
 * without ever leaving ColdForge.
 */
class BrevoClient
{
    private const BASE = 'https://api.brevo.com/v3';
    private const CACHE_TTL = 60; // seconds

    private ?string $apiKey;

    public function __construct()
    {
        $this->apiKey = $this->resolveApiKey();
    }

    public function available(): bool
    {
        return filled($this->apiKey);
    }

    /** @return array|null Full /account payload — plan, credits, email, name. */
    public function account(): ?array
    {
        return $this->cached('brevo:account', fn () => $this->get('/account'));
    }

    /** Email credits remaining this month (sum of all non-SMS plan buckets). */
    public function emailCredits(): ?int
    {
        $acct = $this->account();
        if (! $acct || empty($acct['plan'])) {
            return null;
        }
        $total = 0;
        foreach ($acct['plan'] as $p) {
            if (($p['type'] ?? '') !== 'sms' && isset($p['credits'])) {
                $total += (int) $p['credits'];
            }
        }

        return $total;
    }

    /** @return array|null Daily aggregated stats for the last N days. */
    public function stats(int $days = 7): ?array
    {
        $to = now()->toDateString();
        $from = now()->subDays($days - 1)->toDateString();

        return $this->cached("brevo:stats:{$from}:{$to}", fn () => $this->get('/smtp/statistics/aggregatedReport', [
            'startDate' => $from,
            'endDate' => $to,
        ]));
    }

    /** @return array<int, array<string, mixed>>|null Per-day stats (date → counts). */
    public function dailyStats(int $days = 7): ?array
    {
        $to = now()->toDateString();
        $from = now()->subDays($days - 1)->toDateString();

        $resp = $this->cached("brevo:daily:{$from}:{$to}", fn () => $this->get('/smtp/statistics/reports', [
            'startDate' => $from,
            'endDate' => $to,
            'days' => $days,
        ]));

        return $resp['reports'] ?? null;
    }

    public function clearCache(): void
    {
        Cache::forget('brevo:account');
    }

    private function resolveApiKey(): ?string
    {
        // Prefer an active Brevo sender's own key; fall back to env.
        $sender = Sender::where('provider', 'brevo')->where('is_active', true)->first();
        if ($sender && filled($sender->config['api_key'] ?? null)) {
            return $sender->config['api_key'];
        }

        return config('coldforge.brevo_api_key') ?: null;
    }

    private function get(string $path, array $query = []): ?array
    {
        try {
            $response = Http::timeout(10)
                ->withHeaders(['api-key' => $this->apiKey, 'accept' => 'application/json'])
                ->get(self::BASE.$path, $query);
        } catch (\Throwable $e) {
            Log::warning('BrevoClient error', ['path' => $path, 'error' => $e->getMessage()]);

            return null;
        }

        if (! $response->successful()) {
            Log::warning('BrevoClient non-2xx', ['path' => $path, 'status' => $response->status()]);

            return null;
        }

        return $response->json();
    }

    private function cached(string $key, callable $resolver): ?array
    {
        return Cache::remember($key, self::CACHE_TTL, $resolver);
    }
}
