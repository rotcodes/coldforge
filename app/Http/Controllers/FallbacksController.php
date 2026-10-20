<?php

namespace App\Http\Controllers;

use App\Services\TemplateFallbacks;
use Illuminate\Http\Request;
use Inertia\Inertia;

class FallbacksController extends Controller
{
    public function index()
    {
        return Inertia::render('Settings/Fallbacks', [
            'fallbacks' => TemplateFallbacks::all(),
            'defaults' => TemplateFallbacks::DEFAULTS,
        ]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'fallbacks' => ['required', 'array'],
            'fallbacks.*' => ['nullable', 'string', 'max:500'],
        ]);
        TemplateFallbacks::save($data['fallbacks']);

        return back()->with('success', 'Fallbacks saved.');
    }
}
