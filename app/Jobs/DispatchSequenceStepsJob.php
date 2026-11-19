<?php

namespace App\Jobs;

use App\Models\MailMessage;
use App\Models\SequenceDelivery;
use App\Models\SequenceEnrollment;
use App\Models\Suppression;
use App\Services\EmailRenderer;
use App\Services\Mailbox\OutboundMailService;
use App\Services\Sequences\SequenceScheduler;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class DispatchSequenceStepsJob implements ShouldQueue
{
    use Queueable;

    public function handle(OutboundMailService $outbound, SequenceScheduler $scheduler): void
    {
        SequenceEnrollment::query()
            ->where('status', 'active')
            ->where('next_send_at', '<=', now())
            ->with(['sequence.steps.template', 'campaignSend.campaign', 'contact'])
            ->limit(100)
            ->get()
            ->each(fn (SequenceEnrollment $enrollment) => $this->dispatch($enrollment, $outbound, $scheduler));
    }

    private function dispatch(SequenceEnrollment $enrollment, OutboundMailService $outbound, SequenceScheduler $scheduler): void
    {
        $contact = $enrollment->contact;
        $campaignSend = $enrollment->campaignSend;
        $campaign = $campaignSend->campaign;
        $sequence = $enrollment->sequence;

        if ($sequence->status === 'paused') {
            return;
        }

        $reason = match (true) {
            $sequence->status !== 'active' => 'sequence_not_active',
            in_array($campaign->status, ['paused', 'cancelled', 'failed'], true) => 'campaign_not_active',
            $contact->status === 'replied' || filled($contact->last_replied_at) => 'recipient_replied',
            $contact->status !== 'active' || ! in_array($contact->permission_status, ['consented', 'legitimate_interest'], true) => 'recipient_ineligible',
            Suppression::where('email', strtolower($contact->email))->exists() => 'recipient_suppressed',
            default => null,
        };
        if ($reason) {
            $scheduler->stop($enrollment, $reason);

            return;
        }

        $step = $sequence->steps->firstWhere('position', $enrollment->next_step_position);
        if (! $step) {
            $scheduler->stop($enrollment, 'step_missing');

            return;
        }

        $delivery = SequenceDelivery::firstOrCreate(
            ['sequence_enrollment_id' => $enrollment->id, 'sequence_step_id' => $step->id],
            ['status' => 'queued', 'scheduled_at' => $enrollment->next_send_at],
        );
        if ($delivery->mail_message_id || $delivery->status === 'sent') {
            return;
        }

        $idempotency = 'sequence-delivery-'.$delivery->id;
        $existing = MailMessage::where('idempotency_key', $idempotency)->first();
        $mail = EmailRenderer::render($step->template, $contact, $campaignSend);
        $message = $existing ?: $outbound->create([
            'subject' => $mail['subject'],
            'html_body' => $mail['html'],
            'plain_body' => $mail['text'],
            'contact_id' => $contact->id,
            'campaign_send_id' => $campaignSend->id,
            'source_type' => 'sequence',
            'source_id' => $delivery->id,
            'idempotency_key' => $idempotency,
            'headers' => ['List-Unsubscribe' => '<'.$mail['unsubscribe_url'].'>', 'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click'],
        ], [['type' => 'to', 'email' => $contact->email, 'name' => trim($contact->first_name.' '.$contact->last_name) ?: null]], 'send');

        $delivery->update(['mail_message_id' => $message->id, 'status' => 'sending']);
        SendMailMessageJob::dispatch($message);
    }
}
