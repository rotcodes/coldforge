<?php

namespace App\Jobs;

use App\Models\CampaignSend;
use App\Models\MailboxSetting;
use App\Models\MailMessage;
use App\Models\Suppression;
use App\Services\EmailRenderer;
use App\Services\Mailbox\OutboundMailService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Prepares a campaign message, then hands delivery to the shared mailbox sending engine. */
class SendEmailJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public CampaignSend $send) {}

    public function handle(OutboundMailService $outbound): void
    {
        $send = $this->send->load('campaign.template', 'contact');
        $campaign = $send->campaign;
        $contact = $send->contact;

        if ($campaign->status !== 'running') {
            $send->update(['status' => 'queued']);

            return;
        }
        if ($send->status === 'sent') {
            return;
        }
        if ($contact->status !== 'active' || ! in_array($contact->permission_status, ['consented', 'legitimate_interest'], true)) {
            $this->failSend($send, 'Recipient is not eligible for campaign email.');

            return;
        }
        if (Suppression::where('email', strtolower($contact->email))->exists()) {
            $this->failSend($send, 'Recipient is on the suppression list.');

            return;
        }
        if (! MailboxSetting::current()->configured()) {
            $this->failSend($send, 'Hostinger mailbox is not configured.');

            return;
        }

        $existing = MailMessage::where('idempotency_key', 'campaign-send-'.$send->id)->first();
        if ($existing) {
            if (! in_array($existing->status, ['sent', 'uncertain'], true)) {
                SendMailMessageJob::dispatch($existing);
            }

            return;
        }

        $mail = EmailRenderer::render($campaign->template, $contact, $send);
        $message = $outbound->create([
            'subject' => $mail['subject'],
            'html_body' => $mail['html'],
            'plain_body' => $mail['text'],
            'contact_id' => $contact->id,
            'campaign_send_id' => $send->id,
            'source_type' => 'campaign',
            'source_id' => $campaign->id,
            'idempotency_key' => 'campaign-send-'.$send->id,
            'headers' => [
                'List-Unsubscribe' => '<'.$mail['unsubscribe_url'].'>',
                'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
                'X-ColdForge-Campaign' => (string) $campaign->id,
            ],
        ], [[
            'type' => 'to',
            'email' => $contact->email,
            'name' => trim($contact->first_name.' '.$contact->last_name) ?: null,
        ]], 'send');

        SendMailMessageJob::dispatch($message);
    }

    private function failSend(CampaignSend $send, string $error): void
    {
        $send->update(['status' => 'failed', 'failed_at' => now(), 'error' => $error]);
        $send->log('failed', ['error' => $error]);
    }
}
