<?php

namespace App\Services\Mailbox;

final class MailDeliveryResult
{
    private function __construct(
        public readonly bool $ok,
        public readonly bool $retryable = false,
        public readonly bool $uncertain = false,
        public readonly ?string $error = null,
    ) {}

    public static function accepted(): self
    {
        return new self(true);
    }

    public static function failed(string $error, bool $retryable = false): self
    {
        return new self(false, $retryable, false, $error);
    }

    public static function uncertain(string $error): self
    {
        return new self(false, false, true, $error);
    }
}
