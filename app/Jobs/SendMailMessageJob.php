<?php

namespace App\Jobs;

use App\Models\CampaignSend;
use App\Models\MailboxSetting;
use App\Models\MailMessage;
use App\Models\SequenceDelivery;
use App\Services\Mailbox\HostingerMailer;
use App\Services\Sequences\SequenceScheduler;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class SendMailMessageJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $maxExceptions = 3;

    public array $backoff = [60, 300, 900];

    public function __construct(public MailMessage $message) {}

    public function uniqueId(): string
    {
        return (string) $this->message->id;
    }

    public function middleware(): array
    {
        return [new RateLimited('mailbox')];
    }

    public function handle(HostingerMailer $mailer, SequenceScheduler $sequences): void
    {
        $message = DB::transaction(function () {
            $message = MailMessage::lockForUpdate()->with('recipients')->find($this->message->id);
            if (! $message || in_array($message->status, ['sent', 'uncertain', 'cancelled'], true)) {
                return null;
            }
            $message->update(['status' => 'processing', 'send_attempts' => $message->send_attempts + 1, 'send_error' => null]);

            return $message;
        });
        if (! $message) {
            return;
        }

        $mailbox = MailboxSetting::current()->refreshAllowance();
        if (! $mailbox->configured()) {
            $message->update(['status' => 'failed', 'failed_at' => now(), 'send_error' => 'Mailbox is not configured.']);

            return;
        }
        if ($mailbox->automation_paused && $message->source_type !== 'personal') {
            $message->update(['status' => 'queued', 'send_error' => 'Automation is paused.']);

            return;
        }

        $recipientCount = $message->recipients->count();
        if ($mailbox->sent_today + $recipientCount > $mailbox->daily_allowance) {
            $message->update([
                'status' => 'scheduled',
                'scheduled_at' => now($mailbox->timezone)->addDay()->startOfDay()->utc(),
                'send_error' => 'Deferred to the next allowance window.',
            ]);

            return;
        }

        $result = $mailer->send($message, $mailbox);
        if ($result->ok) {
            $message->update(['status' => 'sent', 'sent_at' => now(), 'message_at' => now(), 'send_error' => null]);
            $mailbox->increment('sent_today', $recipientCount);
            if ($message->source_type === 'sequence') {
                $this->completeSequenceDelivery($message, $sequences);
            } else {
                $this->completeCampaignSend($message, $sequences);
            }

            return;
        }
        if ($result->uncertain) {
            $message->update(['status' => 'uncertain', 'send_error' => 'SMTP acceptance uncertain: '.$result->error]);
            $this->failSource($message, 'SMTP acceptance is uncertain; manual review required.', 'uncertain', $sequences);

            return;
        }
        if ($result->retryable) {
            $message->update(['status' => 'queued', 'send_error' => $result->error]);
            throw new RuntimeException($result->error);
        }

        $message->update(['status' => 'failed', 'failed_at' => now(), 'send_error' => $result->error]);
        $this->failSource($message, $result->error ?? 'Sending failed.', 'failed', $sequences);
    }

    public function failed(?Throwable $e): void
    {
        MailMessage::whereKey($this->message->id)->whereNotIn('status', ['sent', 'uncertain'])->update([
            'status' => 'failed', 'failed_at' => now(), 'send_error' => mb_substr($e?->getMessage() ?? 'Unknown sending failure', 0, 1000),
        ]);
        $message = MailMessage::find($this->message->id);
        if ($message) {
            $this->failSource($message, $e?->getMessage() ?? 'Sending failed.', 'failed', app(SequenceScheduler::class));
        }
    }

    private function completeCampaignSend(MailMessage $message, SequenceScheduler $sequences): void
    {
        if (! $message->campaign_send_id) {
            return;
        }
        $send = CampaignSend::with('campaign', 'contact')->find($message->campaign_send_id);
        if (! $send || $send->status === 'sent') {
            return;
        }
        $send->update(['status' => 'sent', 'sent_at' => now(), 'message_id' => $message->message_id, 'error' => null]);
        $send->log('sent', ['message_id' => $message->message_id, 'mailbox' => $message->from_email]);
        $send->campaign->increment('sent_count');
        $send->contact->update([
            'last_contacted_at' => now(),
            'pipeline_status' => $send->contact->pipeline_status === 'new' ? 'contacted' : $send->contact->pipeline_status,
        ]);
        $sequences->enroll($send);
    }

    private function completeSequenceDelivery(MailMessage $message, SequenceScheduler $sequences): void
    {
        $delivery = SequenceDelivery::with('enrollment')->find($message->source_id);
        if (! $delivery || $delivery->status === 'sent') {
            return;
        }

        $delivery->update(['status' => 'sent', 'sent_at' => now(), 'error' => null]);
        $sequences->advance($delivery->enrollment);
        $delivery->enrollment->contact()->update(['last_contacted_at' => now()]);
    }

    private function failSource(MailMessage $message, string $error, string $status, SequenceScheduler $sequences): void
    {
        if ($message->source_type !== 'sequence') {
            $this->failCampaignSend($message, $error, $status);

            return;
        }

        $delivery = SequenceDelivery::with('enrollment')->find($message->source_id);
        if ($delivery) {
            $delivery->update(['status' => $status, 'error' => mb_substr($error, 0, 1000)]);
            $sequences->stop($delivery->enrollment, $status === 'uncertain' ? 'delivery_uncertain' : 'delivery_failed');
        }
    }

    private function failCampaignSend(MailMessage $message, string $error, string $status = 'failed'): void
    {
        if (! $message->campaign_send_id) {
            return;
        }
        $send = CampaignSend::find($message->campaign_send_id);
        if ($send && $send->status !== 'sent') {
            $send->update(['status' => $status, 'failed_at' => now(), 'error' => mb_substr($error, 0, 1000)]);
            $send->log($status, ['error' => mb_substr($error, 0, 1000)]);
        }
    }
}
