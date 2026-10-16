<?php

namespace App\Services\Senders;

interface SenderInterface
{
    /**
     * $payload keys: from_name, from_email, reply_to, to_email, to_name, subject, html, text, headers (array), tags (array)
     */
    public function sendTransactional(array $payload): SenderResult;

    public function getQuotaInfo(): array;

    public function testConnection(): bool;
}
