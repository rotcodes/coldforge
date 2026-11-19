<?php

namespace App\Services\Mailbox;

use App\Models\Contact;
use App\Models\MailboxSetting;
use App\Models\MailMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OutboundMailService
{
    /** @param array<int, array{type: string, email: string, name?: ?string}> $recipients */
    public function create(array $data, array $recipients, string $action = 'draft'): MailMessage
    {
        $mailbox = MailboxSetting::current();
        if (! $mailbox->configured()) {
            throw new \RuntimeException('Mailbox credentials are not configured.');
        }

        $domain = str($mailbox->email)->after('@')->value() ?: parse_url(config('app.url'), PHP_URL_HOST) ?: 'coldforge.local';
        $status = match ($action) {
            'send' => 'queued',
            'schedule' => 'scheduled',
            default => 'draft',
        };
        if (empty($data['contact_id'])) {
            $to = collect($recipients)->where('type', 'to')->pluck('email')->unique()->values();
            if ($to->count() === 1) {
                $data['contact_id'] = Contact::where('email', strtolower($to->first()))->value('id');
            }
        }

        if (($data['source_type'] ?? 'personal') === 'personal') {
            $data['plain_body'] = $this->withTextSignature($data['plain_body'] ?? null, $mailbox->signature_text);
            $data['html_body'] = $this->withHtmlSignature($data['html_body'] ?? null, $mailbox->signature_html);
        }

        $message = DB::transaction(function () use ($data, $recipients, $mailbox, $domain, $status) {
            $message = MailMessage::create([
                'message_id' => Str::uuid().'@'.$domain,
                'in_reply_to' => $data['in_reply_to'] ?? null,
                'references' => $data['references'] ?? null,
                'thread_key' => $data['thread_key'] ?? ($data['in_reply_to'] ?? null),
                'headers' => $data['headers'] ?? null,
                'direction' => 'outgoing',
                'status' => $status,
                'source_type' => $data['source_type'] ?? 'personal',
                'source_id' => $data['source_id'] ?? null,
                'contact_id' => $data['contact_id'] ?? null,
                'campaign_send_id' => $data['campaign_send_id'] ?? null,
                'from_email' => $mailbox->email,
                'from_name' => $mailbox->display_name,
                'subject' => $data['subject'] ?? null,
                'plain_body' => $data['plain_body'] ?? null,
                'html_body' => $data['html_body'] ?? null,
                'scheduled_at' => $data['scheduled_at'] ?? null,
                'idempotency_key' => $data['idempotency_key'] ?? (string) Str::uuid(),
            ]);
            foreach ($recipients as $recipient) {
                $message->recipients()->create([
                    'type' => $recipient['type'],
                    'email' => strtolower($recipient['email']),
                    'name' => $recipient['name'] ?? null,
                ]);
            }

            return $message;
        });

        return $message;
    }

    private function withTextSignature(?string $body, ?string $signature): ?string
    {
        if (blank($signature)) {
            return $body;
        }

        return rtrim((string) $body)."\n\n--\n".trim($signature);
    }

    private function withHtmlSignature(?string $body, ?string $signature): ?string
    {
        if (blank($signature)) {
            return $body;
        }

        return rtrim((string) $body).'<div class="coldforge-signature">'.trim($signature).'</div>';
    }
}
