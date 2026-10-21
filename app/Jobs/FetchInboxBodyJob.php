<?php

namespace App\Jobs;

use App\Models\InboxMessage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;

/**
 * The Agentic Mail webhook only carries a ~200-char preview. The full body sits behind
 * bodyUrl: a signed storage link returning {plainText, html} that expires after ~24h,
 * so we copy it into the row shortly after the message arrives.
 */
class FetchInboxBodyJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [30, 120];

    public function __construct(public InboxMessage $message) {}

    public function handle(): void
    {
        if ($this->message->body_fetched_at || ! $this->message->body_url) {
            return;
        }

        $res = Http::timeout(20)->get($this->message->body_url)->throw();

        $this->message->update([
            'plain_body' => $res->json('plainText'),
            'html_body' => $res->json('html'),
            'body_fetched_at' => now(),
        ]);
    }
}
