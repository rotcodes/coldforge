<?php

namespace App\Services\Senders;

final class SenderResult
{
    public function __construct(
        public readonly bool $ok,
        public readonly ?string $messageId = null,
        public readonly ?string $error = null,
        public readonly bool $retryable = false,
    ) {}

    public static function sent(?string $messageId): self
    {
        return new self(true, $messageId);
    }

    public static function failed(string $error, bool $retryable = false): self
    {
        return new self(false, null, $error, $retryable);
    }
}
