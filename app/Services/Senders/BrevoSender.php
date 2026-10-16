<?php

namespace App\Services\Senders;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Throwable;

class BrevoSender implements SenderInterface
{
    private const BASE = 'https://api.brevo.com/v3';

    public function __construct(private readonly array $config) {}

    private function http(): PendingRequest
    {
        $key = ($this->config['api_key'] ?? null) ?: config('coldforge.brevo_api_key');

        return Http::baseUrl(self::BASE)
            ->withHeaders(['api-key' => (string) $key])
            ->acceptJson()
            ->asJson()
            ->timeout(20);
    }

    public function sendTransactional(array $payload): SenderResult
    {
        $body = array_filter([
            'sender' => ['name' => $payload['from_name'] ?: $payload['from_email'], 'email' => $payload['from_email']],
            'to' => [array_filter(['email' => $payload['to_email'], 'name' => $payload['to_name'] ?? null])],
            'replyTo' => ! empty($payload['reply_to']) ? ['email' => $payload['reply_to']] : null,
            'subject' => $payload['subject'],
            'htmlContent' => $payload['html'],
            'textContent' => $payload['text'] ?? null,
            'headers' => $payload['headers'] ?? null,
            'tags' => array_map('strval', $payload['tags'] ?? []),
        ]);

        try {
            $res = $this->http()->post('/smtp/email', $body);
        } catch (Throwable $e) {
            return SenderResult::failed('Brevo connection error: '.$e->getMessage(), true);
        }

        if ($res->successful()) {
            return SenderResult::sent($res->json('messageId'));
        }

        $retryable = $res->status() === 429 || $res->serverError();

        return SenderResult::failed("Brevo {$res->status()}: ".($res->json('message') ?? $res->body()), $retryable);
    }

    public function getQuotaInfo(): array
    {
        $res = $this->http()->get('/account');

        return $res->successful() ? ['email' => $res->json('email'), 'plan' => $res->json('plan')] : [];
    }

    public function testConnection(): bool
    {
        try {
            return $this->http()->get('/account')->successful();
        } catch (Throwable) {
            return false;
        }
    }
}
