<?php

namespace App\Http\Controllers;

use App\Models\Sequence;
use App\Models\Template;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class SequenceController extends Controller
{
    public function index()
    {
        return Inertia::render('Sequences/Index', [
            'sequences' => Sequence::with(['steps.template:id,name,subject'])
                ->withCount(['enrollments', 'enrollments as active_enrollments_count' => fn ($query) => $query->where('status', 'active')])
                ->latest()->get(),
            'templates' => Template::orderBy('name')->get(['id', 'name', 'subject']),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'business_days_only' => ['boolean'],
            'window_start' => ['nullable', 'date_format:H:i'],
            'window_end' => ['nullable', 'date_format:H:i', 'after:window_start'],
            'timezone' => ['required', 'timezone'],
            'steps' => ['required', 'array', 'min:1', 'max:10'],
            'steps.*.template_id' => ['required', 'exists:templates,id'],
            'steps.*.delay_days' => ['required', 'integer', 'min:1', 'max:365'],
        ]);

        DB::transaction(function () use ($data) {
            $sequence = Sequence::create([
                ...collect($data)->except('steps')->all(),
                'status' => 'draft',
            ]);
            foreach ($data['steps'] as $index => $step) {
                $sequence->steps()->create($step + ['position' => $index + 1]);
            }
        });

        return back()->with('success', 'Follow-up sequence created as a draft.');
    }

    public function status(Request $request, Sequence $sequence)
    {
        $data = $request->validate(['status' => ['required', 'in:active,paused,cancelled']]);
        if ($data['status'] === 'active' && ! $sequence->steps()->exists()) {
            return back()->with('error', 'Add at least one step before activating the sequence.');
        }
        $sequence->update(['status' => $data['status']]);
        if ($data['status'] === 'cancelled') {
            $sequence->enrollments()->where('status', 'active')->update([
                'status' => 'stopped', 'stop_reason' => 'sequence_cancelled', 'stopped_at' => now(), 'next_send_at' => null,
            ]);
        }

        return back()->with('success', 'Sequence status updated.');
    }
}
