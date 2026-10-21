<?php

namespace App\Http\Controllers;

use App\Jobs\FetchInboxBodyJob;
use App\Models\Campaign;
use App\Models\CampaignSend;
use App\Models\Contact;
use App\Models\Event;
use App\Models\InboxMessage;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Throwable;

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
            'token_configured' => (bool) config('coldforge.hostinger_mail_webhook_token'),
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
     *
     * Auth: path secret, plus the webhook's own secret which Hostinger sends as
     * `Authorization: Bearer <secret>` on every delivery (checked when configured).
     * Hostinger gives up after 5 failed attempts and pauses webhooks that keep failing,
     * so once auth passes this never returns 5xx: failures are logged with the payload.
     */
    public function webhook(Request $request, string $secret)
    {
        $expected = config('coldforge.hostinger_mail_webhook_secret');
        if (! $expected || ! hash_equals($expected, $secret)) {
            abort(404);
        }

        $token = config('coldforge.hostinger_mail_webhook_token');
        if ($token && ! hash_equals($token, (string) $request->bearerToken())) {
            abort(401);
        }

        $payload = $request->all();

        try {
            return $this->receive($payload);
        } catch (Throwable $e) {
            Log::error('hmail webhook failed: '.$e->getMessage(), ['payload' => $payload]);

            return response('logged', 200);
        }
    }

    private function receive(array $payload)
    {
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

        [$fromName, $fromEmail] = $this->parseAddress((string) ($data['from'] ?? ''));
        $inReplyTo = $this->stripBrackets((string) ($data['inReplyTo'] ?? ''));
        $messageId = $this->stripBrackets((string) ($data['messageId'] ?? ''));
        $references = $this->referenceIds($data['references'] ?? []);

        // Match this reply to one of our sent emails and that send's contact.
        $send = $this->findSend($inReplyTo, $references);
        $contact = $fromEmail ? Contact::firstWhere('email', strtolower($fromEmail)) : null;
        if (! $contact && $send) {
            $contact = $send->contact;
        }

        $message = InboxMessage::create([
            'provider' => 'hostinger_mail',
            'event_id' => $this->fit($eventId),
            'delivery_id' => $this->fit($payload['id'] ?? null),
            'mailbox_address' => $this->fit($data['mailboxAddress'] ?? '') ?? '',
            'message_id' => $this->fit($messageId),
            'in_reply_to' => $this->fit($inReplyTo),
            'references' => $references ?: null,
            'from_email' => $this->fit(strtolower($fromEmail)) ?? '',
            'from_name' => $this->fit($fromName),
            'to_addresses' => $data['to'] ?? null,
            'cc_addresses' => $data['cc'] ?? null,
            'subject' => $data['subject'] ?? null,
            'plain_preview' => $data['plainBody'] ?? null,
            'html_preview' => $data['htmlBody'] ?? null,
            'body_url' => $data['bodyUrl'] ?? null,
            'attachments' => $data['attachments'] ?? null,
            'campaign_send_id' => $send?->id,
            'contact_id' => $contact?->id,
            'received_at' => rescue(fn () => Carbon::parse($payload['timestamp'] ?? now()), now(), false),
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

        // bodyUrl is a signed link that expires after ~24h, so pull the full body now.
        if ($message->body_url) {
            FetchInboxBodyJob::dispatch($message);
        }

        return response('ok', 200);
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

    /** Clamp to a VARCHAR(255) column so odd payloads can't fail the insert. */
    private function fit(?string $v): ?string
    {
        return $v === null || $v === '' ? null : mb_substr($v, 0, 255);
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
