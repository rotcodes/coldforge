<?php

namespace App\Http\Controllers;

use App\Jobs\SendMailMessageJob;
use App\Models\MailboxSetting;
use App\Models\MailMessage;
use App\Models\Template;
use App\Services\Mailbox\OutboundMailService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class ComposeController extends Controller
{
    public function create(Request $request)
    {
        $source = $request->filled('message')
            ? MailMessage::with('recipients')->find($request->integer('message'))
            : null;
        $mode = $source ? $request->input('mode', 'reply') : 'compose';

        return Inertia::render('Mail/Compose', [
            'mailbox' => MailboxSetting::current()->only('email', 'display_name', 'timezone', 'signature_html', 'signature_text'),
            'templates' => Template::orderBy('name')->get(['id', 'name', 'subject', 'html_body', 'text_body']),
            'source' => $source,
            'mode' => $mode,
            'initial' => $this->initial($source, $mode),
            'editing' => null,
        ]);
    }

    public function edit(MailMessage $message)
    {
        abort_unless($message->direction === 'outgoing' && in_array($message->status, ['draft', 'scheduled', 'failed'], true), 422);
        $message->load('recipients');

        return Inertia::render('Mail/Compose', [
            'mailbox' => MailboxSetting::current()->only('email', 'display_name', 'timezone', 'signature_html', 'signature_text'),
            'templates' => Template::orderBy('name')->get(['id', 'name', 'subject', 'html_body', 'text_body']),
            'source' => null,
            'mode' => 'edit',
            'initial' => [
                'to' => $message->recipients->where('type', 'to')->pluck('email')->implode(', '),
                'cc' => $message->recipients->where('type', 'cc')->pluck('email')->implode(', '),
                'bcc' => $message->recipients->where('type', 'bcc')->pluck('email')->implode(', '),
                'subject' => $message->subject ?? '', 'plain_body' => $message->plain_body ?? '', 'html_body' => $message->html_body ?? '',
            ],
            'editing' => ['id' => $message->id, 'status' => $message->status, 'scheduled_at' => $message->scheduled_at?->setTimezone(MailboxSetting::current()->timezone)->format('Y-m-d\TH:i')],
        ]);
    }

    public function store(Request $request, OutboundMailService $outbound)
    {
        $data = $request->validate([
            'to' => ['required', 'string', 'max:5000'],
            'cc' => ['nullable', 'string', 'max:5000'],
            'bcc' => ['nullable', 'string', 'max:5000'],
            'subject' => ['required', 'string', 'max:255'],
            'plain_body' => ['nullable', 'string'],
            'html_body' => ['nullable', 'string'],
            'action' => ['required', Rule::in(['draft', 'send', 'schedule'])],
            'scheduled_at' => ['nullable', 'required_if:action,schedule', 'date', 'after:now'],
            'source_message_id' => ['nullable', 'exists:mail_messages,id'],
            'editing_message_id' => ['nullable', 'exists:mail_messages,id'],
            'attachments' => ['array', 'max:10'],
            'attachments.*' => ['file', 'max:10240', 'mimes:pdf,doc,docx,xls,xlsx,csv,txt,png,jpg,jpeg,gif,webp,zip'],
        ]);

        $recipients = collect(['to', 'cc', 'bcc'])->flatMap(function ($type) use ($data) {
            return collect(preg_split('/[,;\n]+/', (string) ($data[$type] ?? ''), -1, PREG_SPLIT_NO_EMPTY))
                ->map(fn ($email) => ['type' => $type, 'email' => strtolower(trim($email))]);
        })->values()->all();
        foreach ($recipients as $recipient) {
            validator(['email' => $recipient['email']], ['email' => ['required', 'email:rfc', 'max:255']])->validate();
        }

        $editing = ! empty($data['editing_message_id']) ? MailMessage::findOrFail($data['editing_message_id']) : null;
        if ($editing) {
            abort_unless($editing->direction === 'outgoing' && in_array($editing->status, ['draft', 'scheduled', 'failed'], true), 422);
            $status = match ($data['action']) {
                'send' => 'queued', 'schedule' => 'scheduled', default => 'draft'
            };
            DB::transaction(function () use ($editing, $data, $recipients, $status) {
                $editing->update([
                    'subject' => $data['subject'], 'plain_body' => $data['plain_body'] ?: null, 'html_body' => $data['html_body'] ?: null,
                    'status' => $status, 'scheduled_at' => $data['action'] === 'schedule' ? $data['scheduled_at'] : null,
                    'failed_at' => null, 'send_error' => null,
                ]);
                $editing->recipients()->delete();
                foreach ($recipients as $recipient) {
                    $editing->recipients()->create($recipient);
                }
            });
            $message = $editing;
        } else {
            $source = ! empty($data['source_message_id']) ? MailMessage::find($data['source_message_id']) : null;
            $references = array_values(array_filter([...($source?->references ?? []), $source?->message_id]));
            $message = $outbound->create([
                'subject' => $data['subject'],
                'plain_body' => $data['plain_body'] ?: null,
                'html_body' => $data['html_body'] ?: null,
                'scheduled_at' => $data['action'] === 'schedule' ? $data['scheduled_at'] : null,
                'in_reply_to' => $source?->message_id,
                'references' => $references ?: null,
                'thread_key' => $source?->thread_key,
                'contact_id' => $source?->contact_id,
                'source_type' => 'personal',
            ], $recipients, $data['action']);
        }

        foreach ($request->file('attachments', []) as $file) {
            $path = $file->store('mail-attachments');
            $message->attachments()->create([
                'filename' => $file->getClientOriginalName(),
                'mime_type' => $file->getMimeType(),
                'size_bytes' => $file->getSize(),
                'storage_path' => $path,
                'is_downloaded' => true,
            ]);
        }
        $message->update(['attachment_count' => $message->attachments()->count()]);

        if ($data['action'] === 'send') {
            SendMailMessageJob::dispatch($message);
        }

        return redirect('/mail')->with('success', match ($data['action']) {
            'send' => 'Message queued for sending.',
            'schedule' => 'Message scheduled.',
            default => 'Draft saved.',
        });
    }

    public function cancel(MailMessage $message)
    {
        abort_unless(in_array($message->status, ['draft', 'scheduled', 'queued', 'failed'], true), 422);
        $message->update(['status' => 'cancelled', 'scheduled_at' => null]);

        return back()->with('success', 'Outgoing message cancelled.');
    }

    public function retry(MailMessage $message)
    {
        abort_unless($message->status === 'failed', 422);
        $message->update(['status' => 'queued', 'failed_at' => null, 'send_error' => null]);
        SendMailMessageJob::dispatch($message);

        return back()->with('success', 'Message queued for another attempt.');
    }

    private function initial(?MailMessage $source, string $mode): array
    {
        if (! $source) {
            return ['to' => '', 'cc' => '', 'bcc' => '', 'subject' => '', 'plain_body' => '', 'html_body' => ''];
        }
        if ($mode === 'forward') {
            $header = "\n\n---------- Forwarded message ----------\nFrom: {$source->from_name} <{$source->from_email}>\nDate: {$source->message_at}\nSubject: {$source->subject}\n\n";

            return ['to' => '', 'cc' => '', 'bcc' => '', 'subject' => str_starts_with($source->subject, 'Fwd:') ? $source->subject : 'Fwd: '.$source->subject, 'plain_body' => $header.$source->plain_body, 'html_body' => ''];
        }

        return [
            'to' => $source->from_email ?? '',
            'cc' => '', 'bcc' => '',
            'subject' => str_starts_with((string) $source->subject, 'Re:') ? $source->subject : 'Re: '.$source->subject,
            'plain_body' => '', 'html_body' => '',
        ];
    }
}
