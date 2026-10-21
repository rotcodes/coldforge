<?php

namespace App\Services\Inbox;

use App\Models\Campaign;
use App\Models\CampaignSend;
use App\Models\Contact;
use App\Models\Event;
use App\Models\InboxMessage;
use Illuminate\Support\Carbon;

/**
 * Stores one normalized mailbox message (see MailboxReader::fetch) and applies the reply
 * side-effects: link to the campaign send it answers, flip the contact to `replied`,
 * bump the campaign's replied_count and log a `replied` event.
 */
class InboxIngestor
{
    /** Returns null when the message was already stored. */
    public function ingest(array $m, string $mailbox, string $sourceId): ?InboxMessage
    {
        $messageId = $this->stripBrackets($m['message_id'] ?? '');

        $exists = InboxMessage::where('event_id', $sourceId)
            ->when($messageId !== '', fn ($q) => $q->orWhere('message_id', $messageId))
            ->exists();
        if ($exists) {
            return null;
        }

        $inReplyTo = $this->stripBrackets($m['in_reply_to'] ?? '');
        $references = $this->referenceIds($m['references'] ?? []);
        $fromEmail = strtolower(trim((string) ($m['from_email'] ?? '')));

        // Match this reply to one of our sent emails and that send's contact.
        $send = $this->findSend($inReplyTo, $references);
        $contact = $fromEmail ? Contact::firstWhere('email', $fromEmail) : null;
        if (! $contact && $send) {
            $contact = $send->contact;
        }

        $plain = trim((string) ($m['text'] ?? '')) ?: $this->htmlToText((string) ($m['html'] ?? ''));

        $message = InboxMessage::create([
            'provider' => 'imap',
            'event_id' => $this->fit($sourceId),
            'mailbox_address' => $this->fit($mailbox) ?? '',
            'message_id' => $this->fit($messageId),
            'in_reply_to' => $this->fit($inReplyTo),
            'references' => $references ?: null,
            'from_email' => $this->fit($fromEmail) ?? '',
            'from_name' => $this->fit(trim((string) ($m['from_name'] ?? ''), " \"'")),
            'to_addresses' => $m['to'] ?? null,
            'cc_addresses' => $m['cc'] ?? null,
            'subject' => ($m['subject'] ?? '') ?: null,
            'plain_preview' => $plain !== '' ? mb_substr(preg_replace('/\s+/', ' ', $plain), 0, 200) : null,
            'plain_body' => $plain !== '' ? $plain : null,
            'html_body' => ($m['html'] ?? '') ?: null,
            'attachments' => ($m['attachments'] ?? []) ?: null,
            'campaign_send_id' => $send?->id,
            'contact_id' => $contact?->id,
            'received_at' => rescue(fn () => Carbon::parse($m['date'] ?? now()), now(), false),
            'created_at' => now(),
        ]);

        if ($contact && $contact->status === 'active') {
            $contact->update(['status' => 'replied']);
        }
        if ($send) {
            Campaign::where('id', $send->campaign_id)->increment('replied_count');
            Event::create([
                'campaign_send_id' => $send->id,
                'type' => 'replied',
                'payload' => ['subject' => $message->subject, 'inbox_message_id' => $message->id],
                'created_at' => now(),
            ]);
        }

        return $message;
    }

    /** In-Reply-To first, then References newest-first (some clients drop or mangle In-Reply-To). */
    private function findSend(string $inReplyTo, array $references): ?CampaignSend
    {
        foreach (array_filter([$inReplyTo, ...array_reverse($references)]) as $id) {
            $send = CampaignSend::whereIn('message_id', [$id, "<{$id}>"])->first();
            if ($send) {
                return $send;
            }
        }

        return null;
    }

    /** References arrive as an array or a space-separated header string; return bare ids. */
    private function referenceIds(mixed $raw): array
    {
        $items = is_array($raw) ? $raw : preg_split('/\s+/', (string) $raw);

        return array_values(array_filter(array_map(fn ($r) => $this->stripBrackets((string) $r), $items)));
    }

    private function htmlToText(string $html): string
    {
        $html = preg_replace(['/<(script|style)\b.*?<\/\1>/is', '/<(br|\/p|\/div|\/li|\/tr)\b[^>]*>/i'], ['', "\n"], $html);

        return trim(preg_replace("/\n{3,}/", "\n\n", html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5)));
    }

    /** Clamp to a VARCHAR(255) column so odd headers can't fail the insert. */
    private function fit(?string $v): ?string
    {
        return $v === null || $v === '' ? null : mb_substr($v, 0, 255);
    }

    private function stripBrackets(string $v): string
    {
        return trim($v, " \t\n\r<>");
    }
}
