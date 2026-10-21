<?php

namespace App\Http\Controllers;

use App\Models\Campaign;
use App\Models\CampaignSend;
use App\Models\Contact;
use App\Models\Event;
use App\Models\InboxMessage;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;

class InboxController extends Controller
{
    /** List + show in one page: table left, selected message detail right. */
    public function index(Request $request)
    {
        $filter = $request->input('filter', 'all'); // all | unread | replied-to-campaign | orphan

        $query = InboxMessage::query()->with(['contact:id,email,business,company', 'send:id,campaign_id,contact_id', 'send.campaign:id,name']);

        if ($filter === 'unread') {
            $query->where('is_read', false);
        } elseif ($filter === 'replied-to-campaign') {
            $query->whereNotNull('campaign_send_id');
        } elseif ($filter === 'orphan') {
            $query->whereNull('campaign_send_id');
        }

        $messages = $query->latest('received_at')->paginate(25)->withQueryString();
        $selected = $request->filled('id') ? InboxMessage::with(['contact', 'send.campaign'])->find($request->input('id')) : null;

        return Inertia::render('Inbox/Index', [
            'messages' => $messages,
            'filter' => $filter,
            'selected' => $selected,
            'webhook_url' => url('/webhooks/hmail/'.config('coldforge.hostinger_mail_webhook_secret')),
            'webhook_configured' => (bool) config('coldforge.hostinger_mail_webhook_secret'),
            'counts' => [
                'all' => InboxMessage::count(),
                'unread' => InboxMessage::where('is_read', false)->count(),
                'campaign' => InboxMessage::whereNotNull('campaign_send_id')->count(),
                'orphan' => InboxMessage::whereNull('campaign_send_id')->count(),
            ],
        ]);
    }

    public function markRead(Request $request, InboxMessage $message)
    {
        $message->update(['is_read' => true]);

        return back();
    }

    public function markAll(Request $request)
    {
        InboxMessage::where('is_read', false)->update(['is_read' => true]);

        return back()->with('success', 'Marked all as read.');
    }

    /**
     * Hostinger Agentic Mail posts here when a new message arrives.
     * Payload format: {id, event, timestamp, data: {eventId, mailboxAddress, messageId, inReplyTo,
     * references, from, to, cc, subject, plainBody, htmlBody, bodyUrl, attachments[]}}
     */
    public function webhook(Request $request, string $secret)
    {
        $expected = config('coldforge.hostinger_mail_webhook_secret');
        if (! $expected || ! hash_equals($expected, $secret)) {
            abort(404);
        }

        $payload = $request->all();
        $event = $payload['event'] ?? '';
        if ($event !== 'message.received') {
            return response('ignored', 200);
        }

        $data = $payload['data'] ?? [];
        $eventId = (string) ($data['eventId'] ?? '');
        if ($eventId === '') {
            return response('no event id', 200);
        }

        // Idempotent: Hostinger retries use the same eventId.
        if (InboxMessage::where('event_id', $eventId)->exists()) {
            return response('duplicate', 200);
        }

        [$fromName, $fromEmail] = $this->parseAddress($data['from'] ?? '');
        $inReplyTo = $this->stripBrackets($data['inReplyTo'] ?? '');
        $messageId = $this->stripBrackets($data['messageId'] ?? '');

        // Match this reply to one of our sent emails and that send's contact.
        $send = $this->findSend($inReplyTo, $messageId);
        $contact = $fromEmail ? Contact::firstWhere('email', strtolower($fromEmail)) : null;
        if (! $contact && $send) {
            $contact = $send->contact;
        }

        $message = InboxMessage::create([
            'provider' => 'hostinger_mail',
            'event_id' => $eventId,
            'delivery_id' => $payload['id'] ?? null,
            'mailbox_address' => $data['mailboxAddress'] ?? '',
            'message_id' => $messageId ?: null,
            'in_reply_to' => $inReplyTo ?: null,
            'references' => $data['references'] ?? null,
            'from_email' => strtolower($fromEmail),
            'from_name' => $fromName ?: null,
            'to_addresses' => $data['to'] ?? null,
            'cc_addresses' => $data['cc'] ?? null,
            'subject' => $data['subject'] ?? null,
            'plain_preview' => $data['plainBody'] ?? null,
            'html_preview' => $data['htmlBody'] ?? null,
            'body_url' => $data['bodyUrl'] ?? null,
            'attachments' => $data['attachments'] ?? null,
            'campaign_send_id' => $send?->id,
            'contact_id' => $contact?->id,
            'received_at' => Carbon::parse($payload['timestamp'] ?? now()),
            'created_at' => now(),
        ]);

        // Side-effects: this contact engaged — mark them 'replied' and bump the campaign counter.
        if ($contact) {
            if ($contact->status === 'active') {
                $contact->update(['status' => 'replied']);
            }
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

        return response('ok', 200);
    }

    private function findSend(?string $inReplyTo, ?string $messageId): ?CampaignSend
    {
        if ($inReplyTo) {
            $send = CampaignSend::where('message_id', 'like', '%'.$inReplyTo.'%')->first();
            if ($send) {
                return $send;
            }
        }
        // Fallback: client may have mangled the In-Reply-To; try References header entries.
        return null;
    }

    /** "Name <email@x.com>" → ["Name", "email@x.com"]. "email@x.com" alone → ["", "email@x.com"]. */
    private function parseAddress(string $raw): array
    {
        $raw = trim($raw);
        if (preg_match('/^(.*?)\s*<\s*([^>]+)\s*>$/', $raw, $m)) {
            return [trim(trim($m[1]), '"'), trim($m[2])];
        }

        return ['', $raw];
    }

    private function stripBrackets(string $v): string
    {
        return trim($v, " \t\n\r<>");
    }
}
