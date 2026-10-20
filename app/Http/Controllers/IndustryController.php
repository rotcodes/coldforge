<?php

namespace App\Http\Controllers;

use App\Models\Contact;
use App\Models\Industry;
use App\Services\IndustryMatcher;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class IndustryController extends Controller
{
    public function index()
    {
        $industries = Industry::orderByDesc('is_active')->orderBy('label')->get()
            ->map(fn ($i) => array_merge($i->toArray(), [
                'contact_count' => Contact::where('industry_slug', $i->slug)->count(),
            ]));

        $unmappedCount = Contact::whereNotNull('industry')
            ->where('industry', '!=', '')
            ->whereNull('industry_slug')
            ->count();

        $unmappedSamples = Contact::whereNotNull('industry')
            ->where('industry', '!=', '')
            ->whereNull('industry_slug')
            ->select('industry')
            ->selectRaw('COUNT(*) as count')
            ->groupBy('industry')
            ->orderByDesc('count')
            ->limit(10)
            ->get();

        return Inertia::render('Industries/Index', compact('industries', 'unmappedCount', 'unmappedSamples'));
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        Industry::create($data);
        IndustryMatcher::clearCache();

        return back()->with('success', 'Industry added.');
    }

    public function update(Request $request, Industry $industry)
    {
        $data = $this->validated($request, $industry);
        $industry->update($data);
        IndustryMatcher::clearCache();

        return back()->with('success', 'Industry updated.');
    }

    public function destroy(Industry $industry)
    {
        if (Contact::where('industry_slug', $industry->slug)->exists()) {
            return back()->with('error', 'This industry is in use by contacts. Reassign them first.');
        }
        $industry->delete();
        IndustryMatcher::clearCache();

        return back()->with('success', 'Industry deleted.');
    }

    /** Re-run the fuzzy matcher across contacts that have raw `industry` text but no slug. */
    public function rematch()
    {
        $matcher = new IndustryMatcher;
        $matched = 0;
        Contact::whereNotNull('industry')
            ->where('industry', '!=', '')
            ->whereNull('industry_slug')
            ->chunkById(500, function ($contacts) use ($matcher, &$matched) {
                foreach ($contacts as $c) {
                    $slug = $matcher->match($c->industry);
                    if ($slug) {
                        $c->update(['industry_slug' => $slug]);
                        $matched++;
                    }
                }
            });

        return back()->with('success', "Matched {$matched} contacts to industries.");
    }

    /** Bulk-assign a slug to all contacts whose raw `industry` text equals the given value. */
    public function assignUnmapped(Request $request)
    {
        $data = $request->validate([
            'raw_industry' => ['required', 'string'],
            'slug' => ['required', 'string', Rule::exists('industries', 'slug')],
        ]);

        $count = Contact::where('industry', $data['raw_industry'])
            ->whereNull('industry_slug')
            ->update(['industry_slug' => $data['slug']]);

        return back()->with('success', "Assigned {$count} contacts to this industry.");
    }

    private function validated(Request $request, ?Industry $industry = null): array
    {
        $data = $request->validate([
            'slug' => ['nullable', 'string', 'max:60', 'regex:/^[a-z0-9_]+$/', Rule::unique('industries', 'slug')->ignore($industry)],
            'label' => ['required', 'string', 'max:150'],
            'workflows' => ['required', 'string', 'max:1000'],
            'tracking' => ['required', 'string', 'max:1000'],
            'aliases' => ['array'],
            'aliases.*' => ['string', 'max:100'],
            'is_active' => ['boolean'],
        ]);
        $data['is_active'] = $data['is_active'] ?? true;

        return $data;
    }
}
