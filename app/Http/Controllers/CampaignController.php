<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessCampaignJob;
use App\Models\Campaign;
use App\Models\Contact;
use App\Models\Event;
use App\Models\Industry;
use App\Models\MailboxSetting;
use App\Models\Sequence;
use App\Models\SequenceEnrollment;
use App\Models\Template;
use App\Services\EmailRenderer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class CampaignController extends Controller
{
    public function index()
    {
        return Inertia::render('Campaigns/Index', [
            'campaigns' => Campaign::with('template:id,name')->latest()->get(),
        ]);
    }

    public function create()
    {
        return Inertia::render('Campaigns/Create', [
            'templates' => Template::latest('updated_at')->get(['id', 'name', 'subject', 'from_email', 'updated_at', 'variables']),
            'tags' => ContactController::allTags(),
            'activeCount' => Contact::matchingFilter([])->count(),
            'mailboxConfigured' => MailboxSetting::current()->configured(),
            'defaultCap' => config('coldforge.default_daily_cap'),
            'industries' => Industry::where('is_active', true)->orderBy('label')->get(['slug', 'label']),
            'sequences' => Sequence::where('status', 'active')->with('steps:id,sequence_id,position,delay_days')->orderBy('name')->get(['id', 'name']),
        ]);
    }

    /** Live recipient count + tier breakdown for the wizard's audience/readiness steps. */
    public function count(Request $request)
    {
        $filter = $this->filterFromRequest($request);
        $contacts = Contact::matchingFilter($filter);

        $breakdown = ['t1' => 0, 't2' => 0, 't3' => 0, 't4' => 0];
        $contacts->clone()->select('id', 'industry_slug', 'business', 'company', 'personalized_opener', 'pain_evidence', 'recommended_offer', 'email')
            ->chunkById(1000, function ($rows) use (&$breakdown) {
                foreach ($rows as $r) {
                    $tier = $r->readinessTier();
                    if ($tier >= 1 && $tier <= 4) {
                        $breakdown['t'.$tier]++;
                    }
                }
            }, 'id');

        return response()->json(['count' => array_sum($breakdown), 'tiers' => $breakdown]);
    }

    /** Render a sample email for a single contact — used by Campaigns wizard + Templates editor. */
    public function preview(Request $request)
    {
        $data = $request->validate([
            'template_id' => ['required', 'exists:templates,id'],
            'contact_id' => ['nullable', 'exists:contacts,id'],
        ]);

        $template = Template::findOrFail($data['template_id']);
        $contact = ! empty($data['contact_id'])
            ? Contact::find($data['contact_id'])
            : Contact::whereNotNull('email')->inRandomOrder()->first();

        if (! $contact) {
            return response()->json(['error' => 'No contacts yet. Import a CSV first.'], 422);
        }

        $vars = $contact->templateVars();
        $rendered = EmailRenderer::render($template, $contact);

        // Report which template vars actually used a fallback (empty source value replaced by non-empty resolved value).
        $usedFallback = [];
        foreach ($template->variables ?? [] as $v) {
            $raw = $contact->getAttribute($v);
            if ((! $raw || $raw === '') && ! empty($vars[$v])) {
                $usedFallback[] = $v;
            }
        }

        return response()->json([
            'subject' => $rendered['subject'],
            'html' => $rendered['html'],
            'text' => $rendered['text'],
            'contact' => ['id' => $contact->id, 'email' => $contact->email, 'business' => $contact->business ?: $contact->company, 'industry' => $contact->industry, 'tier' => $contact->readinessTier()],
            'used_fallback' => $usedFallback,
        ]);
    }

    private function filterFromRequest(Request $request): array
    {
        return [
            'tags' => (array) $request->input('tags', []),
            'industry_slugs' => array_values(array_filter((array) $request->input('industry_slugs', []))),
            'source_system' => $request->input('source_system') ?: null,
            'lead_score_min' => $request->input('lead_score_min') ?: null,
            'require_industry' => (bool) $request->input('require_industry', false),
        ];
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'template_id' => ['required', 'exists:templates,id'],
            'tags' => ['array'],
            'tags.*' => ['string'],
            'industry_slugs' => ['array'],
            'industry_slugs.*' => ['string', Rule::exists('industries', 'slug')],
            'source_system' => ['nullable', 'string', 'max:20'],
            'lead_score_min' => ['nullable', 'integer', 'min:0', 'max:100'],
            'require_industry' => ['boolean'],
            'send_mode' => ['required', Rule::in(['now', 'later'])],
            'schedule_at' => ['nullable', 'required_if:send_mode,later', 'date', 'after:now'],
            'daily_cap' => ['required', 'integer', 'min:1', 'max:100000'],
            'sequence_id' => ['nullable', 'exists:sequences,id'],
        ]);

        $filter = [
            'tags' => array_values($data['tags'] ?? []),
            'industry_slugs' => array_values($data['industry_slugs'] ?? []),
            'source_system' => $data['source_system'] ?? null,
            'lead_score_min' => $data['lead_score_min'] ?? null,
            'require_industry' => (bool) ($data['require_industry'] ?? false),
        ];
        if (! Contact::matchingFilter($filter)->exists()) {
            return back()->withErrors(['tags' => 'No active contacts match this filter.']);
        }

        $campaign = DB::transaction(function () use ($data, $filter) {
            $campaign = Campaign::create([
                'name' => $data['name'],
                'template_id' => $data['template_id'],
                'sequence_id' => $data['sequence_id'] ?? null,
                'contact_filter' => $filter,
                'schedule_at' => $data['send_mode'] === 'later' ? $data['schedule_at'] : null,
                'daily_cap' => $data['daily_cap'],
                'sender_strategy' => 'single_mailbox',
                'status' => 'draft',
            ]);

            $now = now();
            Contact::matchingFilter($filter)->select('id')->chunkById(1000, function ($contacts) use ($campaign, $now) {
                $campaign->sends()->insert($contacts->map(fn ($c) => [
                    'campaign_id' => $campaign->id, 'contact_id' => $c->id, 'status' => 'queued',
                    'queued_at' => $now, 'created_at' => $now, 'updated_at' => $now,
                ])->all());
            });

            $campaign->update(['total_recipients' => $campaign->sends()->count()]);

            return $campaign;
        });

        return redirect("/campaigns/{$campaign->id}")->with('success', 'Campaign created as draft. Launch when ready.');
    }

    public function show(Campaign $campaign)
    {
        $campaign->load('template:id,name,subject');

        $breakdown = $campaign->sends()->selectRaw('status, COUNT(*) as c')->groupBy('status')->pluck('c', 'status');

        $events = Event::query()
            ->join('campaign_sends', 'campaign_sends.id', '=', 'events.campaign_send_id')
            ->join('contacts', 'contacts.id', '=', 'campaign_sends.contact_id')
            ->where('campaign_sends.campaign_id', $campaign->id)
            ->latest('events.id')
            ->limit(50)
            ->get(['events.id', 'events.type', 'events.payload', 'events.created_at', 'contacts.id as contact_id', 'contacts.email']);

        $recipients = $campaign->sends()->with([
            'contact:id,email,first_name,last_name,permission_status,pipeline_status,last_replied_at',
            'sequenceEnrollment:id,campaign_send_id,status,next_step_position,next_send_at,stop_reason',
        ])->latest('id')->paginate(100, ['id', 'campaign_id', 'contact_id', 'status', 'sent_at', 'failed_at', 'error']);

        return Inertia::render('Campaigns/Show', compact('campaign', 'breakdown', 'events', 'recipients'));
    }

    public function launch(Campaign $campaign)
    {
        abort_unless($campaign->status === 'draft', 422, 'Only draft campaigns can be launched.');

        return $this->start($campaign, 'Campaign launched.');
    }

    public function pause(Campaign $campaign)
    {
        abort_unless(in_array($campaign->status, ['running', 'scheduled']), 422);
        $campaign->update(['status' => 'paused']);

        return back()->with('success', 'Campaign paused.');
    }

    public function resume(Campaign $campaign)
    {
        abort_unless($campaign->status === 'paused', 422);

        return $this->start($campaign, 'Campaign resumed.');
    }

    public function cancel(Campaign $campaign)
    {
        abort_if(in_array($campaign->status, ['completed', 'cancelled'], true), 422);
        $campaign->update(['status' => 'cancelled']);
        $campaign->sends()->whereIn('status', ['queued', 'sending'])->update(['status' => 'cancelled', 'error' => 'Campaign cancelled by administrator.']);
        SequenceEnrollment::whereHas('campaignSend', fn ($query) => $query->where('campaign_id', $campaign->id))
            ->where('status', 'active')->update(['status' => 'stopped', 'stop_reason' => 'campaign_cancelled', 'stopped_at' => now(), 'next_send_at' => null]);

        return back()->with('success', 'Campaign and pending follow-ups cancelled.');
    }

    public function destroy(Campaign $campaign)
    {
        if ($campaign->status === 'running') {
            return back()->with('error', 'Pause the campaign before deleting it.');
        }
        $campaign->delete();

        return redirect('/campaigns')->with('success', 'Campaign deleted.');
    }

    private function start(Campaign $campaign, string $message)
    {
        $mailbox = MailboxSetting::current();
        if (! $mailbox->configured()) {
            return back()->with('error', 'Configure the Hostinger mailbox before launching a campaign.');
        }
        if (blank($mailbox->physical_address)) {
            return back()->with('error', 'Add a physical mailing address in Mailbox settings before launching a campaign.');
        }
        if ($mailbox->automation_paused) {
            return back()->with('error', 'Automation is paused. Resume it in Mailbox settings before launching.');
        }

        if ($campaign->schedule_at?->isFuture()) {
            $campaign->update(['status' => 'scheduled']);

            return back()->with('success', 'Campaign scheduled for '.$campaign->schedule_at->toDayDateTimeString().'.');
        }

        $campaign->update(['status' => 'running']);
        ProcessCampaignJob::dispatch($campaign);

        return back()->with('success', $message);
    }
}
