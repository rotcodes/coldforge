<?php

namespace App\Jobs;

use App\Models\CampaignSend;
use App\Services\EmailRenderer;
use App\Services\Senders\SenderRegistry;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\RateLimited;
use RuntimeException;
use Throwable;

class SendEmailJob implements ShouldQueue
{
    use Queueable;

    /** Real failures (429/5xx/network) get 3 attempts; rate-limit releases don't count toward this. */
    public int $maxExceptions = 3;

    public array $backoff = [10, 60, 300];

    public function __construct(public CampaignSend $send) {}

    public function retryUntil(): \DateTimeInterface
    {
        return now()->addHours(6);
    }

    public function middleware(): array
    {
        return [new RateLimited('sender')];
    }

    public function handle(): void
    {
        $send = $this->send->load('campaign.template', 'contact', 'sender');
        $campaign = $send->campaign;

        // Paused while waiting in the queue: put it back, ProcessCampaignJob picks it up on resume.
        if ($campaign->status !== 'running') {
            $this->releaseQuota();
            $send->update(['status' => 'queued', 'sender_id' => null]);

            return;
        }

        if ($send->status === 'sent') {
            return;
        }

        if ($send->contact->status !== 'active') {
            $this->markFailed('Contact is '.$send->contact->status);

            return;
        }

        if (! $send->sender) {
            $this->markFailed('No sender assigned');

            return;
        }

        $mail = EmailRenderer::render($campaign->template, $send->contact, $send);
        $template = $campaign->template;

        $result = SenderRegistry::make($send->sender)->sendTransactional([
            'from_name' => $template->from_name,
            'from_email' => $template->from_email,
            'reply_to' => $template->reply_to,
            'to_email' => $send->contact->email,
            'to_name' => trim($send->contact->first_name.' '.$send->contact->last_name) ?: null,
            'subject' => $mail['subject'],
            'html' => $mail['html'],
            'text' => $mail['text'],
            'headers' => [
                'List-Unsubscribe' => '<'.$mail['unsubscribe_url'].'>',
                'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
            ],
            'tags' => ['campaign-'.$campaign->id],
        ]);

        if ($result->ok) {
            $send->update(['status' => 'sent', 'sent_at' => now(), 'message_id' => $result->messageId, 'error' => null]);
            $send->log('sent', ['message_id' => $result->messageId, 'sender' => $send->sender->name]);
            $campaign->increment('sent_count');

            return;
        }

        if ($result->retryable) {
            throw new RuntimeException($result->error); // retried with backoff, failed() after 3
        }

        $this->markFailed($result->error);
    }

    public function failed(?Throwable $e): void
    {
        $this->markFailed($e?->getMessage() ?? 'Unknown error');
    }

    private function markFailed(string $error): void
    {
        $this->releaseQuota();
        $this->send->update(['status' => 'failed', 'failed_at' => now(), 'error' => $error]);
        $this->send->log('failed', ['error' => $error]);
    }

    /** ProcessCampaignJob reserves quota up-front; give it back when nothing was sent. */
    private function releaseQuota(): void
    {
        $sender = $this->send->sender;
        if ($sender && $sender->sent_today > 0) {
            $sender->decrement('sent_today');
        }
    }
}
