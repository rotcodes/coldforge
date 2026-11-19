<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ActivityController extends Controller
{
    public function __invoke(Request $request)
    {
        return Inertia::render('Activity/Index', [
            'logs' => ActivityLog::query()
                ->when($request->action, fn ($query, $value) => $query->where('action', 'like', "%{$value}%"))
                ->when($request->subject_type, fn ($query, $value) => $query->where('subject_type', $value))
                ->when($request->date_from, fn ($query, $value) => $query->where('created_at', '>=', $value.' 00:00:00'))
                ->when($request->date_to, fn ($query, $value) => $query->where('created_at', '<=', $value.' 23:59:59'))
                ->latest('id')->paginate(50)->withQueryString(),
            'filters' => $request->only('action', 'subject_type', 'date_from', 'date_to'),
            'subjectTypes' => ActivityLog::whereNotNull('subject_type')->distinct()->orderBy('subject_type')->pluck('subject_type'),
        ]);
    }
}
