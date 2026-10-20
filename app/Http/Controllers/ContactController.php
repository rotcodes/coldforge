<?php

namespace App\Http\Controllers;

use App\Models\Contact;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class ContactController extends Controller
{
    public function index(Request $request)
    {
        $contacts = Contact::query()
            ->when($request->q, fn ($q, $s) => $q->where(fn ($q) => $q
                ->where('email', 'like', "%$s%")
                ->orWhere('first_name', 'like', "%$s%")
                ->orWhere('last_name', 'like', "%$s%")
                ->orWhere('company', 'like', "%$s%")
                ->orWhere('business', 'like', "%$s%")))
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->when($request->industry, fn ($q, $i) => $q->where('industry_slug', $i))
            ->when($request->source, fn ($q, $s) => $q->where('source_system', $s))
            ->when($request->tag, fn ($q, $t) => $q->withAnyTag([$t]))
            ->when($request->min_score, fn ($q, $v) => $q->where('lead_score', '>=', (int) $v))
            ->when($request->has_email === '1', fn ($q) => $q->whereNotNull('email')->where('email', '!=', ''))
            ->latest('id')
            ->paginate(25)
            ->withQueryString()
            ->through(fn ($c) => array_merge($c->toArray(), ['tier' => $c->readinessTier()]));

        return Inertia::render('Contacts/Index', [
            'contacts' => $contacts,
            'filters' => $request->only('q', 'status', 'tag', 'industry', 'source', 'min_score', 'has_email'),
            'tags' => self::allTags(),
            'statuses' => Contact::STATUSES,
            'industries' => \App\Models\Industry::where('is_active', true)->orderBy('label')->get(['slug', 'label']),
        ]);
    }

    public function show(Contact $contact)
    {
        $sends = $contact->sends()->with('campaign:id,name')->latest('id')->get();
        $events = \App\Models\Event::whereIn('campaign_send_id', $sends->pluck('id'))
            ->with('send.campaign:id,name')
            ->latest('id')->limit(100)->get();
        $tier = $contact->readinessTier();
        $industry = $contact->industry_slug ? \App\Models\Industry::firstWhere('slug', $contact->industry_slug) : null;

        return Inertia::render('Contacts/Show', compact('contact', 'sends', 'events', 'tier', 'industry'));
    }

    public function store(Request $request)
    {
        Contact::create($this->validated($request) + ['source' => 'manual']);

        return back()->with('success', 'Contact added.');
    }

    public function update(Request $request, Contact $contact)
    {
        $contact->update($this->validated($request, $contact));

        return back()->with('success', 'Contact updated.');
    }

    public function destroy(Contact $contact)
    {
        $contact->delete();

        return redirect('/contacts')->with('success', 'Contact deleted.');
    }

    private function validated(Request $request, ?Contact $contact = null): array
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255', Rule::unique('contacts')->ignore($contact)],
            'first_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['nullable', 'string', 'max:100'],
            'company' => ['nullable', 'string', 'max:150'],
            'country' => ['nullable', 'string', 'max:100'],
            'business' => ['nullable', 'string', 'max:255'],
            'industry' => ['nullable', 'string', 'max:255'],
            'industry_slug' => ['nullable', 'string', 'max:60', Rule::exists('industries', 'slug')],
            'city_state' => ['nullable', 'string', 'max:255'],
            'website' => ['nullable', 'string', 'max:255'],
            'decision_maker' => ['nullable', 'string', 'max:255'],
            'dm_role' => ['nullable', 'string', 'max:255'],
            'direct_phone' => ['nullable', 'string', 'max:50'],
            'main_phone' => ['nullable', 'string', 'max:50'],
            'status' => ['required', Rule::in(Contact::STATUSES)],
            'tags' => ['array'],
            'tags.*' => ['string', 'max:50'],
            'custom_fields' => ['nullable', 'array'],
            'personalized_opener' => ['nullable', 'string'],
            'pain_evidence' => ['nullable', 'string'],
            'recommended_offer' => ['nullable', 'string'],
        ]);
        $data['email'] = strtolower($data['email']);
        $data['tags'] = array_values(array_unique(array_filter(array_map('trim', $data['tags'] ?? []))));

        return $data;
    }

    public static function allTags(): array
    {
        return Contact::whereNotNull('tags')->pluck('tags')->flatten()->filter()->unique()->sort()->values()->all();
    }
}
