<?php

namespace App\Http\Controllers;

use App\Jobs\SyncMailboxJob;
use App\Models\MailboxSetting;
use App\Models\MailFolder;
use App\Models\MailMessage;
use App\Services\AI\StructuredAiClient;
use App\Services\Mailbox\MailboxMessageActions;
use Illuminate\Http\Request;
use Inertia\Inertia;

class MailController extends Controller
{
    public function index(Request $request, StructuredAiClient $ai)
    {
        $mailbox = MailboxSetting::current();
        $folders = MailFolder::query()
            ->orderByRaw("CASE special_use WHEN 'inbox' THEN 1 WHEN 'sent' THEN 2 WHEN 'drafts' THEN 3 WHEN 'outbox' THEN 4 WHEN 'archive' THEN 5 WHEN 'spam' THEN 6 WHEN 'trash' THEN 7 ELSE 8 END")
            ->orderBy('name')
            ->get();

        $folder = $request->filled('folder')
            ? $folders->firstWhere('id', (int) $request->input('folder'))
            : $folders->firstWhere('special_use', 'inbox') ?? $folders->first();
        $view = $request->input('view');
        if ($view) {
            $folder = null;
        }

        $views = collect([
            ['key' => 'drafts', 'name' => 'Drafts', 'count' => MailMessage::where('status', 'draft')->count()],
            ['key' => 'scheduled', 'name' => 'Scheduled', 'count' => MailMessage::where('status', 'scheduled')->count()],
            ['key' => 'outbox', 'name' => 'Outbox', 'count' => MailMessage::whereIn('status', ['queued', 'processing', 'uncertain', 'failed'])->count()],
        ]);

        $query = MailMessage::query()
            ->with(['recipients:id,mail_message_id,type,email,name', 'attachments:id,mail_message_id,filename,mime_type,size_bytes,is_downloaded'])
            ->when($folder, fn ($q) => $q->where('mail_folder_id', $folder->id))
            ->when($view === 'drafts', fn ($q) => $q->where('status', 'draft'))
            ->when($view === 'scheduled', fn ($q) => $q->where('status', 'scheduled'))
            ->when($view === 'outbox', fn ($q) => $q->whereIn('status', ['queued', 'processing', 'uncertain', 'failed']))
            ->when($request->boolean('starred'), fn ($q) => $q->where('is_starred', true))
            ->when($request->boolean('unread'), fn ($q) => $q->where('is_read', false))
            ->when($request->input('q'), function ($q, $search) {
                $q->where(fn ($q) => $q
                    ->where('subject', 'like', "%{$search}%")
                    ->orWhere('from_email', 'like', "%{$search}%")
                    ->orWhere('from_name', 'like', "%{$search}%")
                    ->orWhere('plain_body', 'like', "%{$search}%"));
            })
            ->latest('message_at');

        $messages = $query->paginate(40)->withQueryString();
        $messages->getCollection()->each->append('readable_body');
        $selected = $request->filled('id')
            ? MailMessage::with(['recipients', 'attachments', 'contact:id,email,business,company,first_name,last_name,pipeline_status'])->find($request->integer('id'))
            : $messages->first();
        $selected?->append('readable_body');

        $thread = $selected?->thread_key
            ? MailMessage::with('recipients')->where('thread_key', $selected->thread_key)->oldest('message_at')->get()
            : collect();
        $thread->each->append('readable_body');

        return Inertia::render('Mail/Index', [
            'folders' => $folders,
            'views' => $views,
            'activeFolder' => $folder,
            'activeView' => $view,
            'messages' => $messages,
            'selected' => $selected,
            'thread' => $thread,
            'filters' => $request->only('q', 'starred', 'unread'),
            'mailbox' => [
                'configured' => $mailbox->configured(),
                'email' => $mailbox->email,
                'imap_status' => $mailbox->imap_status,
                'last_synced_at' => $mailbox->last_synced_at,
                'last_error' => $mailbox->last_connection_error,
                'ai_enabled' => $mailbox->ai_processing_enabled && $ai->available(),
            ],
        ]);
    }

    public function sync(Request $request)
    {
        $mailbox = MailboxSetting::current();
        if (! $mailbox->configured()) {
            return back()->with('error', 'Configure the Hostinger mailbox before synchronizing.');
        }

        SyncMailboxJob::dispatch($request->boolean('full'));

        return back()->with('success', $request->boolean('full') ? 'Historical mailbox import started.' : 'Mailbox synchronization started.');
    }

    public function action(Request $request, MailMessage $message, MailboxMessageActions $actions)
    {
        $data = $request->validate([
            'action' => ['required', 'in:read,unread,star,unstar,move,trash,restore'],
            'folder_id' => ['nullable', 'required_if:action,move', 'exists:mail_folders,id'],
        ]);

        match ($data['action']) {
            'read' => $actions->flag($message, 'Seen', true),
            'unread' => $actions->flag($message, 'Seen', false),
            'star' => $actions->flag($message, 'Flagged', true),
            'unstar' => $actions->flag($message, 'Flagged', false),
            'move' => $actions->move($message, MailFolder::findOrFail($data['folder_id'])),
            'trash' => $actions->trash($message),
            'restore' => $actions->move($message, MailFolder::where('special_use', 'inbox')->firstOrFail()),
        };

        return back()->with('success', 'Mailbox updated.');
    }

    public function bulkAction(Request $request, MailboxMessageActions $actions)
    {
        $data = $request->validate([
            'message_ids' => ['required', 'array', 'min:1', 'max:200'],
            'message_ids.*' => ['integer', 'distinct', 'exists:mail_messages,id'],
            'action' => ['required', 'in:read,unread,star,unstar,archive,trash,restore,move'],
            'folder_id' => ['nullable', 'required_if:action,move', 'exists:mail_folders,id'],
        ]);
        $target = match ($data['action']) {
            'archive' => MailFolder::where('special_use', 'archive')->firstOrFail(),
            'restore' => MailFolder::where('special_use', 'inbox')->firstOrFail(),
            'move' => MailFolder::findOrFail($data['folder_id']),
            default => null,
        };

        foreach (MailMessage::whereIn('id', $data['message_ids'])->get() as $message) {
            match ($data['action']) {
                'read' => $actions->flag($message, 'Seen', true),
                'unread' => $actions->flag($message, 'Seen', false),
                'star' => $actions->flag($message, 'Flagged', true),
                'unstar' => $actions->flag($message, 'Flagged', false),
                'trash' => $actions->trash($message),
                default => $actions->move($message, $target),
            };
        }

        return back()->with('success', count($data['message_ids']).' messages updated.');
    }
}
