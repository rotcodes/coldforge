<?php

namespace App\Services\Senders;

use App\Models\Sender;

class SenderRegistry
{
    /** Providers with a native API driver. Everything else connects over SMTP. */
    public const API_PROVIDERS = ['brevo'];

    public static function method(Sender $sender): string
    {
        $method = $sender->config['method'] ?? null;

        return $method === 'smtp' || $sender->provider === 'smtp_generic' || ! in_array($sender->provider, self::API_PROVIDERS)
            ? 'smtp'
            : 'api';
    }

    public static function make(Sender $sender): SenderInterface
    {
        return self::method($sender) === 'smtp'
            ? new SmtpSender($sender->config ?? [])
            : new BrevoSender($sender->config ?? []);
    }

    /**
     * Pick the next active sender with quota left.
     * round_robin = least-used today first; priority = lowest priority number first.
     */
    public static function pick(string $strategy): ?Sender
    {
        $query = Sender::where('is_active', true);

        $query = $strategy === 'priority'
            ? $query->orderBy('priority')->orderBy('id')
            : $query->orderBy('sent_today')->orderBy('id');

        return $query->get()->first(fn (Sender $s) => $s->hasQuota());
    }
}
