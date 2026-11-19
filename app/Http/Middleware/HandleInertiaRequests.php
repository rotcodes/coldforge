<?php

namespace App\Http\Middleware;

use App\Models\MailMessage;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'auth' => ['user' => $request->user()?->only('id', 'name', 'email')],
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
            ],
            // Cheap live counter for the sidebar inbox badge. Guest requests return 0.
            'unread_inbox' => fn () => $request->user() ? MailMessage::where('direction', 'incoming')->where('is_read', false)->count() : 0,
        ];
    }
}
