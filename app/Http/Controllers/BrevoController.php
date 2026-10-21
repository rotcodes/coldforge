<?php

namespace App\Http\Controllers;

use App\Models\Campaign;
use App\Models\CampaignSend;
use App\Models\Contact;
use App\Models\Event;
use App\Models\Sender;
use App\Models\WebhookEvent;
use App\Services\BrevoClient;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;

class BrevoController extends Controller
{
    /** Dashboard page: quota, credits, 7-day chart, live event feed, issues. */
    public function index(Request $request)
    {
        $brevo = new BrevoClient;
        $sender = Sender::where('provider', 'brevo')->where('is_active', true)->first();

        $account = $brevo->available() ? $brevo->account() : null;
        $credits = $brevo->available() ? $brevo->emailCredits() : null;

        return Inertia::render('Brevo/Index', [
            'available' => $brevo->available(),
            'account' => $account ? [
                'company' => $account['companyName'] ?? null,
                'email' => $account['email'] ?? null,
                'plan' => $account['plan'] ?? [],
            ] : null,
            'credits' => $credits,
            'sender' => $sender ? [
                'id' => $sender->id,
                'name' => $sender->name,
                'daily_quota' => $sender->daily_quota,
                'sent_today' => $sender->sent_today,
            ] : null,
            'daily' => $this->dailySeries(),
            'events' => $this->recentEvents($request),
            'issues' => $this->currentIssues(),
            'webhook_url' => url('/webhooks/brevo/'.config('coldforge.brevo_webhook_secret')),
            'webhook_configured' => (bool) config('coldforge.brevo_webhook_secret'),
            'last_event_at' => WebhookEvent::latest('created_at')->value('created_at'),
        ]);
    }

    /**
     * Public webhook endpoint — Brevo posts events here with no auth header,
     * so we embed a long random secret in the URL path and verify it here.
     */
    public function webhook(Request $request, string $secret)
    {
        $expected = config('coldforge.brevo_webhook_secret');
        if (! $expected || ! hash_equals($expected, $secret)) {
            abort(404);
        }

        $payload = $request->all();
        if (empty($payload['event'])) {
            return response('ok', 200); // ignore health checks / mis-posts
        }

        $this->handleEvent($payload);

        return response('ok', 200);
    }

    private function handleEvent(array $p): void
    {
        $event = strtolower($p['event'] ?? '');
        $email = strtolower(trim((string) ($p['email'] ?? '')));
        $messageId = (string) ($p['message-id'] ?? $p['messageId'] ?? '');

        $send = $messageId ? CampaignSend::where('message_id', 'like', '%'.$messageId.'%')->first() : null;
        $contact = $email ? Contact::firstWhere('email', $email) : null;
        if (! $contact && $send) {
            $contact = $send->contact;
        }

        $occurredAt = $p['ts_event'] ?? $p['ts'] ?? null;
        $occurredAt = $occurredAt ? Carbon::createFromTimestamp($occurredAt) : now();

        WebhookEvent::create([
            'provider' => 'brevo',
            'event' => $event,
            'email' => $email ?: null,
            'message_id' => $messageId ?: null,
            'campaign_send_id' => $send?->id,
            'contact_id' => $contact?->id,
            'reason' => $p['reason'] ?? null,
            'link' => $p['link'] ?? null,
            'payload' => $p,
            'occurred_at' => $occurredAt,
            'created_at' => now(),
        ]);

        $this->applyEffect($event, $contact, $send);
    }

    /** Apply the side-effect on the contact / send for each event type. */
    private function applyEffect(string $event, ?Contact $contact, ?CampaignSend $send): void
    {
        switch ($event) {
            case 'hard_bounce':
            case 'invalid_email':
                $contact?->update(['status' => 'bounced']);
                $send?->update(['status' => 'bounced']);
                if ($send) {
                    Campaign::where('id', $send->campaign_id)->increment('bounced_count');
                    Event::create(['campaign_send_id' => $send->id, 'type' => 'bounced', 'payload' => ['via' => 'brevo', 'event' => $event], 'created_at' => now()]);
                }
                break;

            case 'blocked':
            case 'spam':
            case 'unsubscribed':
                $contact?->update(['status' => $event === 'spam' ? 'complained' : 'unsubscribed']);
                $send?->update(['unsubscribed_at' => now()]);
                if ($send) {
                    Campaign::where('id', $send->campaign_id)->increment('unsubscribed_count');
                    Event::create(['campaign_send_id' => $send->id, 'type' => $event === 'spam' ? 'complained' : 'unsubscribed', 'payload' => ['via' => 'brevo'], 'created_at' => now()]);
                }
                break;

            case 'opened':
            case 'unique_opened':
            case 'proxy_open':
            case 'unique_proxy_open':
                if ($send && ! $send->opened_at) {
                    $send->update(['opened_at' => now()]);
                    Campaign::where('id', $send->campaign_id)->increment('opened_count');
                    Event::create(['campaign_send_id' => $send->id, 'type' => 'opened', 'payload' => ['via' => 'brevo'], 'created_at' => now()]);
                }
                break;

            case 'click':
                if ($send) {
                    if (! $send->first_clicked_at) {
                        $send->update(['first_clicked_at' => now()]);
                        Campaign::where('id', $send->campaign_id)->increment('clicked_count');
                    }
                    $send->increment('clicked_count');
                    Event::create(['campaign_send_id' => $send->id, 'type' => 'clicked', 'payload' => ['via' => 'brevo'], 'created_at' => now()]);
                }
                break;

            case 'delivered':
            case 'soft_bounce':
            case 'deferred':
            case 'error':
            case 'request':
                // Just log — no side effect.
                break;
        }
    }

    /** Last 7 days: event counts per day for the chart. */
    private function dailySeries(): array
    {
        $from = now()->subDays(6)->startOfDay();

        $rows = WebhookEvent::selectRaw('DATE(occurred_at) as d, event, COUNT(*) as c')
            ->where('occurred_at', '>=', $from)
            ->groupBy('d', 'event')
            ->get()
            ->groupBy('d');

        return collect(range(0, 6))->map(function ($i) use ($from, $rows) {
            $date = $from->copy()->addDays($i)->toDateString();
            $events = $rows->get($date, collect())->mapWithKeys(fn ($r) => [$r->event => (int) $r->c])->all();

            return [
                'date' => Carbon::parse($date)->format('M j'),
                'delivered' => (int) ($events['delivered'] ?? 0),
                'opened' => (int) ($events['opened'] ?? 0) + (int) ($events['unique_opened'] ?? 0),
                'clicked' => (int) ($events['click'] ?? 0),
                'bounced' => (int) ($events['hard_bounce'] ?? 0) + (int) ($events['soft_bounce'] ?? 0) + (int) ($events['invalid_email'] ?? 0),
                'blocked' => (int) ($events['blocked'] ?? 0) + (int) ($events['spam'] ?? 0),
            ];
        })->all();
    }

    /** Latest 50 events, with contact + campaign context inlined for the feed. */
    private function recentEvents(Request $request): array
    {
        $sinceId = (int) $request->query('since_id', 0);

        return WebhookEvent::query()
            ->with(['contact:id,email,business,company', 'send:id,campaign_id,contact_id', 'send.campaign:id,name'])
            ->when($sinceId > 0, fn ($q) => $q->where('id', '>', $sinceId))
            ->latest('id')
            ->limit(50)
            ->get()
            ->map(fn ($e) => [
                'id' => $e->id,
                'event' => $e->event,
                'email' => $e->email,
                'reason' => $e->reason,
                'link' => $e->link,
                'is_negative' => $e->isNegative(),
                'at' => $e->occurred_at ?? $e->created_at,
                'contact' => $e->contact ? [
                    'id' => $e->contact->id,
                    'business' => $e->contact->business ?: $e->contact->company,
                ] : null,
                'campaign' => $e->send?->campaign ? [
                    'id' => $e->send->campaign->id,
                    'name' => $e->send->campaign->name,
                ] : null,
            ])->all();
    }

    /** Negative events in the last 24h — "needs attention" strip. */
    private function currentIssues(): array
    {
        $since = now()->subDay();
        $counts = WebhookEvent::selectRaw('event, COUNT(*) as c')
            ->where('created_at', '>=', $since)
            ->whereIn('event', ['hard_bounce', 'invalid_email', 'blocked', 'spam', 'error'])
            ->groupBy('event')
            ->pluck('c', 'event')
            ->all();

        $issues = [];
        if (! empty($counts['hard_bounce']) || ! empty($counts['invalid_email'])) {
            $n = ($counts['hard_bounce'] ?? 0) + ($counts['invalid_email'] ?? 0);
            $issues[] = ['severity' => 'high', 'label' => "{$n} hard bounces", 'detail' => 'Those contacts were auto-marked bounced and will not receive further sends.'];
        }
        if (! empty($counts['spam'])) {
            $issues[] = ['severity' => 'critical', 'label' => "{$counts['spam']} spam complaints", 'detail' => 'Review template content and warmup pace — complaints damage domain reputation.'];
        }
        if (! empty($counts['blocked'])) {
            $issues[] = ['severity' => 'high', 'label' => "{$counts['blocked']} blocked by Brevo", 'detail' => 'Brevo rejected these for reputation or policy reasons.'];
        }
        if (! empty($counts['error'])) {
            $issues[] = ['severity' => 'medium', 'label' => "{$counts['error']} send errors", 'detail' => 'Brevo reported transport errors — check sender config.'];
        }

        return $issues;
    }
}
