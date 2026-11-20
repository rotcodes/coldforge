<?php

namespace App\Services\AI;

interface StructuredAiClient
{
    public function available(): bool;

    public function complete(array $messages, ?string $model = null, array $options = []): ?string;

    public function json(array $messages, ?string $model = null, array $required = [], array $options = []): ?array;
}
