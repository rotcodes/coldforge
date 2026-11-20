<?php

namespace App\Services\AI;

use App\Jobs\SendMailMessageJob;
use App\Models\ActivityLog;
use App\Models\AiAction;
use App\Models\AiConversation;
use App\Models\Contact;
use App\Models\MailMessage;
use App\Models\Suppression;
use App\Services\Mailbox\OutboundMailService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use RuntimeException;
use Throwable;

class IntelligenceActionService
{
    public function __construct(private readonly OutboundMailService $outbound) {}

    public function definitions(): array
    {
        return [
            ['name' => 'create_contact', 'description' => 'Create one contact. New contacts default to unknown permission unless consent evidence is supplied.', 'arguments' => ['email' => 'email', 'first_name' => 'optional string', 'last_name' => 'optional string', 'company' => 'optional string', 'business' => 'optional string', 'tags' => 'optional string array', 'pipeline_status' => 'optional pipeline status', 'permission_status' => 'optional unknown|consented|legitimate_interest', 'consent_source' => 'required for non-unknown permission', 'consent_evidence' => 'required for non-unknown permission']],
            ['name' => 'update_contact', 'description' => 'Update an identified contact using only supplied fields.', 'arguments' => ['contact_id' => 'integer', 'first_name' => 'optional string', 'last_name' => 'optional string', 'company' => 'optional string', 'business' => 'optional string', 'tags' => 'optional string array', 'pipeline_status' => 'optional pipeline status', 'permission_status' => 'optional permission status', 'consent_source' => 'required for eligible permission', 'consent_evidence' => 'required for eligible permission']],
            ['name' => 'create_message_draft', 'description' => 'Create an editable new personal-email draft; never sends it.', 'arguments' => ['to' => 'email', 'subject' => 'string', 'body' => 'string', 'contact_id' => 'optional integer']],
            ['name' => 'create_reply_draft', 'description' => 'Create an editable reply draft to an incoming message; never sends it.', 'arguments' => ['message_id' => 'integer', 'body' => 'string']],
            ['name' => 'queue_message', 'description' => 'Queue an existing reviewed draft for external delivery. Always requires explicit confirmation.', 'arguments' => ['message_id' => 'integer']],
        ];
    }

    public function propose(AiConversation $conversation, int $userId, string $name, array $payload, ?string $summary = null): AiAction
    {
        $payload = $this->validated($name, $payload);

        return AiAction::create([
            'ai_conversation_id' => $conversation->id,
            'user_id' => $userId,
            'action' => $name,
            'risk' => $this->risk($name, $payload),
            'status' => 'proposed',
            'summary' => mb_substr($summary ?: $this->summary($name, $payload), 0, 255),
            'payload' => $payload,
        ]);
    }

    public function shouldAutoExecute(AiConversation $conversation, AiAction $action): bool
    {
        return $conversation->mode === 'operator' && $action->risk === 'low';
    }

    public function execute(AiAction $action, bool $confirmed = false): AiAction
    {
        $action = DB::transaction(function () use ($action, $confirmed) {
            $locked = AiAction::lockForUpdate()->findOrFail($action->id);
            if ($locked->status !== 'proposed') {
                throw new RuntimeException('This action is no longer pending.');
            }
            if ($locked->risk !== 'low' && ! $confirmed) {
                throw new RuntimeException('This action requires explicit confirmation.');
            }
            $locked->update([
                'status' => 'executing',
                'confirmed_at' => $confirmed ? now() : $locked->confirmed_at,
                'error' => null,
            ]);

            return $locked;
        });

        try {
            $result = match ($action->action) {
                'create_contact' => $this->createContact($action->payload),
                'update_contact' => $this->updateContact($action->payload),
                'create_message_draft' => $this->createMessageDraft($action->payload),
                'create_reply_draft' => $this->createReplyDraft($action->payload),
                'queue_message' => $this->queueMessage($action->payload),
                default => throw new RuntimeException('Unsupported action.'),
            };
            $action->update(['status' => 'executed', 'result' => $result, 'executed_at' => now()]);
            ActivityLog::create([
                'user_id' => $action->user_id,
                'action' => 'intelligence:'.$action->action,
                'subject_type' => 'ai_action',
                'subject_id' => $action->id,
                'metadata' => ['risk' => $action->risk, 'result' => $result],
                'created_at' => now(),
            ]);
        } catch (Throwable $exception) {
            $action->update(['status' => 'failed', 'error' => mb_substr($exception->getMessage(), 0, 1000), 'executed_at' => now()]);
        }

        return $action->fresh();
    }

    public function reject(AiAction $action): AiAction
    {
        if ($action->status !== 'proposed') {
            throw new RuntimeException('This action is no longer pending.');
        }
        $action->update(['status' => 'rejected']);

        return $action->fresh();
    }

    private function validated(string $name, array $payload): array
    {
        $pipeline = ['new', 'contacted', 'replied', 'interested', 'demo_requested', 'demo_scheduled', 'proposal_sent', 'negotiation', 'won', 'lost', 'not_interested'];
        $rules = match ($name) {
            'create_contact' => [
                'email' => ['required', 'email:rfc', 'max:255', 'unique:contacts,email'],
                'first_name' => ['nullable', 'string', 'max:255'], 'last_name' => ['nullable', 'string', 'max:255'],
                'company' => ['nullable', 'string', 'max:255'], 'business' => ['nullable', 'string', 'max:255'],
                'tags' => ['nullable', 'array', 'max:20'], 'tags.*' => ['string', 'max:50'],
                'pipeline_status' => ['nullable', Rule::in($pipeline)],
                'permission_status' => ['nullable', Rule::in(['unknown', 'consented', 'legitimate_interest'])],
                'consent_source' => ['nullable', 'string', 'max:255'], 'consent_evidence' => ['nullable', 'string', 'max:2000'],
            ],
            'update_contact' => [
                'contact_id' => ['required', 'integer', 'exists:contacts,id'],
                'first_name' => ['nullable', 'string', 'max:255'], 'last_name' => ['nullable', 'string', 'max:255'],
                'company' => ['nullable', 'string', 'max:255'], 'business' => ['nullable', 'string', 'max:255'],
                'tags' => ['nullable', 'array', 'max:20'], 'tags.*' => ['string', 'max:50'],
                'pipeline_status' => ['nullable', Rule::in($pipeline)],
                'permission_status' => ['nullable', Rule::in(Contact::PERMISSION_STATUSES)],
                'consent_source' => ['nullable', 'string', 'max:255'], 'consent_evidence' => ['nullable', 'string', 'max:2000'],
                'notes' => ['nullable', 'string', 'max:5000'],
            ],
            'create_message_draft' => [
                'to' => ['required', 'email:rfc', 'max:255'], 'subject' => ['required', 'string', 'max:255'],
                'body' => ['required', 'string', 'max:20000'], 'contact_id' => ['nullable', 'integer', 'exists:contacts,id'],
            ],
            'create_reply_draft' => ['message_id' => ['required', 'integer', 'exists:mail_messages,id'], 'body' => ['required', 'string', 'max:20000']],
            'queue_message' => ['message_id' => ['required', 'integer', 'exists:mail_messages,id']],
            default => throw new RuntimeException('Unknown requested action.'),
        };
        $data = Validator::make($payload, $rules)->validate();
        $permission = $data['permission_status'] ?? null;
        if (in_array($permission, ['consented', 'legitimate_interest'], true) && (blank($data['consent_source'] ?? null) || blank($data['consent_evidence'] ?? null))) {
            throw new RuntimeException('Eligible permission requires both consent source and consent evidence.');
        }

        return $data;
    }

    private function risk(string $name, array $payload): string
    {
        if ($name === 'queue_message') {
            return 'high';
        }
        if (in_array($name, ['create_contact', 'update_contact'], true) || isset($payload['permission_status'])) {
            return 'medium';
        }

        return 'low';
    }

    private function summary(string $name, array $payload): string
    {
        return match ($name) {
            'create_contact' => 'Create contact '.$payload['email'],
            'update_contact' => 'Update contact #'.$payload['contact_id'],
            'create_message_draft' => 'Create draft to '.$payload['to'].' — '.$payload['subject'],
            'create_reply_draft' => 'Create reply draft for message #'.$payload['message_id'],
            'queue_message' => 'Queue draft #'.$payload['message_id'].' for external delivery',
            default => $name,
        };
    }

    private function createContact(array $payload): array
    {
        $contact = Contact::create([
            ...$payload,
            'email' => strtolower($payload['email']),
            'status' => 'active',
            'permission_status' => $payload['permission_status'] ?? 'unknown',
            'pipeline_status' => $payload['pipeline_status'] ?? 'new',
            'source' => 'coldforge_intelligence',
        ]);

        return ['contact_id' => $contact->id, 'email' => $contact->email, 'url' => '/contacts/'.$contact->id];
    }

    private function updateContact(array $payload): array
    {
        $contact = Contact::findOrFail($payload['contact_id']);
        unset($payload['contact_id']);
        if (in_array($payload['permission_status'] ?? null, ['consented', 'legitimate_interest'], true)
            && Suppression::where('email', strtolower($contact->email))->exists()) {
            throw new RuntimeException('A suppressed contact cannot be made eligible through Intelligence. Review the suppression manually.');
        }
        if (($payload['permission_status'] ?? null) === 'suppressed') {
            Suppression::updateOrCreate(['email' => strtolower($contact->email)], [
                'reason' => 'manual', 'source' => 'coldforge_intelligence',
                'evidence' => $payload['consent_evidence'] ?? 'Suppressed through an approved Intelligence action.',
                'suppressed_at' => now(),
            ]);
            $payload['status'] = 'unsubscribed';
        }
        $contact->update($payload);

        return ['contact_id' => $contact->id, 'email' => $contact->email, 'url' => '/contacts/'.$contact->id];
    }

    private function createMessageDraft(array $payload): array
    {
        $recipient = strtolower($payload['to']);
        if (! empty($payload['contact_id']) && Contact::whereKey($payload['contact_id'])->where('email', '!=', $recipient)->exists()) {
            throw new RuntimeException('The selected contact does not match the recipient email.');
        }
        $message = $this->outbound->create([
            'subject' => $payload['subject'], 'plain_body' => $payload['body'],
            'contact_id' => $payload['contact_id'] ?? Contact::where('email', $recipient)->value('id'),
            'source_type' => 'personal',
        ], [['type' => 'to', 'email' => $recipient]], 'draft');

        return ['message_id' => $message->id, 'status' => 'draft', 'url' => '/mail/messages/'.$message->id.'/edit'];
    }

    private function createReplyDraft(array $payload): array
    {
        $source = MailMessage::findOrFail($payload['message_id']);
        if ($source->direction !== 'incoming' || blank($source->from_email)) {
            throw new RuntimeException('Replies require a valid incoming message.');
        }
        $references = array_values(array_filter([...($source->references ?? []), $source->message_id]));
        $message = $this->outbound->create([
            'subject' => str_starts_with((string) $source->subject, 'Re:') ? $source->subject : 'Re: '.$source->subject,
            'plain_body' => $payload['body'], 'in_reply_to' => $source->message_id, 'references' => $references,
            'thread_key' => $source->thread_key, 'contact_id' => $source->contact_id, 'source_type' => 'personal',
        ], [['type' => 'to', 'email' => $source->from_email]], 'draft');

        return ['message_id' => $message->id, 'status' => 'draft', 'url' => '/mail/messages/'.$message->id.'/edit'];
    }

    private function queueMessage(array $payload): array
    {
        $message = MailMessage::with('recipients')->findOrFail($payload['message_id']);
        if ($message->direction !== 'outgoing' || $message->status !== 'draft') {
            throw new RuntimeException('Only an outgoing draft can be queued.');
        }
        foreach ($message->recipients->where('type', 'to') as $recipient) {
            if (Suppression::where('email', strtolower($recipient->email))->exists()) {
                throw new RuntimeException('Recipient '.$recipient->email.' is suppressed.');
            }
            $contact = Contact::where('email', strtolower($recipient->email))->first();
            if (! $message->in_reply_to && (! $contact
                || ! in_array($contact->status, ['active', 'replied'], true)
                || ! in_array($contact->permission_status, ['consented', 'legitimate_interest'], true))) {
                throw new RuntimeException('New outbound email requires a contact with eligible permission: '.$recipient->email);
            }
        }
        $message->update(['status' => 'queued', 'send_error' => null]);
        SendMailMessageJob::dispatch($message);

        return ['message_id' => $message->id, 'status' => 'queued', 'url' => '/mail?view=outbox&id='.$message->id];
    }
}
