<?php

namespace App\Services\Deliverability;

use App\Models\ActivityLog;
use App\Models\CampaignSend;
use App\Models\MailMessage;
use App\Models\SequenceEnrollment;
use App\Models\Suppression;
use Illuminate\Support\Facades\DB;

class InboundSignalProcessor
{
    public function process(int $limit = 100): int
    {
        $processed = 0;
        MailMessage::where('direction', 'incoming')->whereNull('delivery_processed_at')->oldest('id')->limit($limit)->get()
            ->each(function (MailMessage $message) use (&$processed) {
                $signal = $this->detect($message);
                if ($signal) {
                    DB::transaction(fn () => $this->apply($message, $signal));
                    $processed++;
                } else {
                    $message->update(['delivery_processed_at' => now()]);
                }
            });

        return $processed;
    }

    private function detect(MailMessage $message): ?array
    {
        $body = (string) $message->plain_body;
        $subject = strtolower((string) $message->subject);
        $from = strtolower((string) $message->from_email);
        $isDsn = str_contains($from, 'mailer-daemon') || str_contains($from, 'postmaster') || preg_match('/delivery (status|failure)|undeliver|returned mail|failure notice/i', $subject);
        if ($isDsn) {
            preg_match('/(?:Final-Recipient|Original-Recipient):\s*(?:rfc822;)?\s*([^\s<>;]+)/i', $body, $recipient);
            preg_match('/Status:\s*([245]\.\d+\.\d+)/i', $body, $status);
            preg_match('/Diagnostic-Code:\s*[^;]*;?\s*(.+)/i', $body, $diagnostic);
            $code = $status[1] ?? null;
            $event = str_starts_with((string) $code, '5.') || preg_match('/user unknown|mailbox.+(?:not found|does not exist)|invalid recipient|no such user|permanent failure/i', $body) ? 'hard_bounce' : 'soft_bounce';

            return ['event' => $event, 'recipient' => strtolower(trim($recipient[1] ?? '')), 'status' => $code, 'diagnostic' => mb_substr(trim($diagnostic[1] ?? $subject), 0, 1000)];
        }

        $contact = $message->contact;
        if ($contact && mb_strlen(trim($body)) <= 1000 && preg_match('/\b(unsubscribe|remove me|stop emailing|do not email|don\'t email|opt[ -]?out)\b/i', $body)) {
            return ['event' => 'unsubscribe_reply', 'recipient' => strtolower($contact->email), 'diagnostic' => 'Explicit opt-out request detected in an inbound reply.'];
        }

        return null;
    }

    private function apply(MailMessage $message, array $signal): void
    {
        $recipient = $signal['recipient'] ?: null;
        $outgoing = $recipient ? MailMessage::where('direction', 'outgoing')->where('status', 'sent')
            ->whereHas('recipients', fn ($query) => $query->where('email', $recipient))->latest('sent_at')->first() : null;
        $contact = $message->contact ?: $outgoing?->contact;
        $send = $outgoing?->campaign_send_id ? CampaignSend::with('campaign')->find($outgoing->campaign_send_id) : null;

        if (in_array($signal['event'], ['hard_bounce', 'unsubscribe_reply'], true) && $contact) {
            $reason = $signal['event'] === 'hard_bounce' ? 'hard_bounce' : 'unsubscribe_reply';
            $contact->update([
                'status' => $signal['event'] === 'hard_bounce' ? 'bounced' : 'unsubscribed',
                'permission_status' => 'suppressed',
            ]);
            Suppression::updateOrCreate(['email' => strtolower($contact->email)], [
                'reason' => $reason, 'source' => 'inbound_mail', 'evidence' => $signal['diagnostic'], 'suppressed_at' => now(),
            ]);
            SequenceEnrollment::where('contact_id', $contact->id)->whereIn('status', ['active', 'paused'])->update([
                'status' => 'stopped', 'stop_reason' => $reason, 'stopped_at' => now(), 'next_send_at' => null,
            ]);
        }

        if ($send) {
            if ($signal['event'] === 'hard_bounce' && $send->status !== 'bounced') {
                $send->update(['status' => 'bounced', 'failed_at' => now(), 'error' => $signal['diagnostic']]);
                $send->campaign()->increment('bounced_count');
            }
            $send->log($signal['event'], array_filter(['status' => $signal['status'] ?? null, 'diagnostic' => $signal['diagnostic']]));
        }

        $message->update(['delivery_event' => $signal['event'], 'delivery_details' => $signal, 'delivery_processed_at' => now()]);
        ActivityLog::create(['action' => 'mail:'.$signal['event'], 'subject_type' => 'mail_message', 'subject_id' => $message->id, 'metadata' => ['recipient' => $recipient], 'created_at' => now()]);
    }
}
