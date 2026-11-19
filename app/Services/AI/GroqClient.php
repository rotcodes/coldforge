<?php

namespace App\Services\AI;

use App\Models\AiRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class GroqClient
{
    private const ENDPOINT = 'https://api.groq.com/openai/v1/chat/completions';

    public function available(): bool
    {
        return filled(config('services.groq.api_key'));
    }

    public function complete(array $messages, ?string $model = null, array $options = []): ?string
    {
        $model ??= config('services.groq.model');
        $started = microtime(true);
        $request = AiRequest::create([
            'user_id' => auth()->id(),
            'task' => $options['task'] ?? 'completion',
            'model' => $model,
            'prompt_version' => $options['prompt_version'] ?? 'v1',
            'status' => 'started',
            'metadata' => ['message_count' => count($messages)],
        ]);
        if (! $this->available()) {
            $request->update(['status' => 'unavailable', 'error' => 'Groq API key is not configured.']);

            return null;
        }

        try {
            $response = Http::timeout(45)
                ->retry(2, 300, throw: false)
                ->withToken(config('services.groq.api_key'))
                ->acceptJson()
                ->post(self::ENDPOINT, [
                    'model' => $model,
                    'messages' => $messages,
                    'temperature' => $options['temperature'] ?? 0.2,
                    'max_tokens' => $options['max_tokens'] ?? 2048,
                    'reasoning_effort' => $options['reasoning_effort'] ?? 'low',
                ]);
        } catch (Throwable $exception) {
            $request->update(['status' => 'failed', 'duration_ms' => (int) ((microtime(true) - $started) * 1000), 'error' => mb_substr($exception->getMessage(), 0, 500)]);
            Log::warning('Groq request failed', ['error' => mb_substr($exception->getMessage(), 0, 300)]);

            return null;
        }

        if (! $response->successful()) {
            $request->update(['status' => 'failed', 'duration_ms' => (int) ((microtime(true) - $started) * 1000), 'error' => 'HTTP '.$response->status()]);
            Log::warning('Groq returned a non-success response', ['status' => $response->status()]);

            return null;
        }

        $request->update([
            'status' => 'completed',
            'duration_ms' => (int) ((microtime(true) - $started) * 1000),
            'input_tokens' => (int) $response->json('usage.prompt_tokens', 0),
            'output_tokens' => (int) $response->json('usage.completion_tokens', 0),
        ]);

        return $response->json('choices.0.message.content');
    }

    public function json(array $messages, ?string $model = null, array $required = [], array $options = []): ?array
    {
        $raw = $this->complete($messages, $model, $options);
        if (! $raw) {
            return null;
        }

        $clean = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', trim($raw));
        $decoded = json_decode($clean, true);
        if (! is_array($decoded) && preg_match('/\{.*\}/s', $clean, $match)) {
            $decoded = json_decode($match[0], true);
        }
        if (! is_array($decoded) || collect($required)->contains(fn ($key) => ! array_key_exists($key, $decoded))) {
            Log::warning('Groq response failed application schema validation');

            return null;
        }

        return $decoded;
    }
}
