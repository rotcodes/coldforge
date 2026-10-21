<?php

namespace App\Http\Controllers;

use App\Models\InboxMessage;
use App\Models\Setting;
use Illuminate\Http\Request;
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
            'imap' => [
                'configured' => (bool) (config('coldforge.inbox_imap.username') && config('coldforge.inbox_imap.password')),
                'mailbox' => config('coldforge.inbox_imap.username'),
                'last_polled_at' => Setting::get('imap_last_polled_at'),
                'last_error' => Setting::get('imap_last_error'),
            ],
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
}
