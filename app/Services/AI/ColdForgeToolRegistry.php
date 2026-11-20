<?php

namespace App\Services\AI;

use App\Models\Campaign;
use App\Models\Contact;
use App\Models\MailboxSetting;
use App\Models\MailMessage;
use App\Models\MailSyncLog;
use App\Models\SequenceEnrollment;
use App\Models\Template;
use App\Services\Mailbox\OutboundMailService;
use Illuminate\Support\Arr;

class ColdForgeToolRegistry
{
    public function __construct(private readonly OutboundMailService $outbound) {}

    public function definitions(bool $allowDrafts): array
    {
        $tools = [
            ['name' => 'important_unread', 'description' => 'Recent unread incoming email with classifications.', 'arguments' => ['limit' => 'integer 1-20']],
            ['name' => 'awaiting_response', 'description' => 'Contacts last contacted after their latest reply.', 'arguments' => ['limit' => 'integer 1-50']],
            ['name' => 'search_emails', 'description' => 'Search subject, sender, and plain body.', 'arguments' => ['query' => 'string', 'limit' => 'integer 1-30']],
            ['name' => 'scheduled_messages', 'description' => 'Scheduled outgoing messages.', 'arguments' => ['date' => 'optional YYYY-MM-DD']],
            ['name' => 'campaign_overview', 'description' => 'Campaign statistics and stopped reason data.', 'arguments' => ['campaign_id' => 'optional integer']],
            ['name' => 'pipeline_contacts', 'description' => 'Contacts in a pipeline status.', 'arguments' => ['status' => 'string', 'limit' => 'integer 1-50']],
            ['name' => 'conversation', 'description' => 'A bounded email conversation around one message.', 'arguments' => ['message_id' => 'integer']],
            ['name' => 'sequence_overview', 'description' => 'Follow-up enrollment status and upcoming steps.', 'arguments' => []],
            ['name' => 'contact_lookup', 'description' => 'Find contacts by email, name, company, or business.', 'arguments' => ['query' => 'string', 'limit' => 'integer 1-30']],
            ['name' => 'template_overview', 'description' => 'List reusable email templates and subjects.', 'arguments' => ['limit' => 'integer 1-30']],
            ['name' => 'system_health', 'description' => 'Mailbox, allowance, automation, queue, failures, and latest synchronization health.', 'arguments' => []],
        ];
        if ($allowDrafts) {
            $tools[] = ['name' => 'create_reply_draft', 'description' => 'Create a local editable reply draft; never sends it.', 'arguments' => ['message_id' => 'integer', 'body' => 'string']];
        }

        return $tools;
    }

    public function execute(string $name, array $arguments, bool $allowDrafts): array
    {
        return match ($name) {
            'important_unread' => $this->importantUnread($arguments),
            'awaiting_response' => $this->awaitingResponse($arguments),
            'search_emails' => $this->searchEmails($arguments),
            'scheduled_messages' => $this->scheduledMessages($arguments),
            'campaign_overview' => $this->campaignOverview($arguments),
            'pipeline_contacts' => $this->pipelineContacts($arguments),
            'conversation' => $this->conversation($arguments),
            'sequence_overview' => $this->sequenceOverview(),
            'contact_lookup' => $this->contactLookup($arguments),
            'template_overview' => $this->templateOverview($arguments),
            'system_health' => $this->systemHealth(),
            'create_reply_draft' => $allowDrafts ? $this->createReplyDraft($arguments) : ['error' => 'Draft creation was not authorized by the user request.'],
            default => ['error' => 'Unknown tool.'],
        };
    }

    private function importantUnread(array $args): array
    {
        return MailMessage::where('direction', 'incoming')->where('is_read', false)->with('contact:id,email,pipeline_status')->latest('message_at')->limit($this->limit($args, 20))->get()
            ->map(fn ($message) => ['id' => $message->id, 'from' => $message->from_email, 'subject' => $message->subject, 'at' => $message->message_at, 'preview' => mb_substr((string) $message->plain_body, 0, 240), 'contact_status' => $message->contact?->pipeline_status])->all();
    }

    private function awaitingResponse(array $args): array
    {
        return Contact::whereNotNull('last_contacted_at')->where(fn ($query) => $query->whereNull('last_replied_at')->orWhereColumn('last_replied_at', '<', 'last_contacted_at'))
            ->whereNotIn('pipeline_status', ['lost', 'not_interested', 'won'])->orderByDesc('last_contacted_at')->limit($this->limit($args, 50))->get(['id', 'email', 'business', 'company', 'pipeline_status', 'last_contacted_at', 'last_replied_at'])->toArray();
    }

    private function searchEmails(array $args): array
    {
        $query = mb_substr(trim((string) ($args['query'] ?? '')), 0, 120);
        if ($query === '') {
            return ['error' => 'A search query is required.'];
        }

        return MailMessage::where(fn ($builder) => $builder->where('subject', 'like', "%{$query}%")->orWhere('from_email', 'like', "%{$query}%")->orWhere('plain_body', 'like', "%{$query}%"))
            ->latest('message_at')->limit($this->limit($args, 30))->get(['id', 'direction', 'from_email', 'subject', 'status', 'message_at'])->toArray();
    }

    private function scheduledMessages(array $args): array
    {
        $query = MailMessage::where('status', 'scheduled')->with('recipients:id,mail_message_id,type,email');
        if (! empty($args['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $args['date'])) {
            $query->whereDate('scheduled_at', $args['date']);
        }

        return $query->orderBy('scheduled_at')->limit(50)->get(['id', 'subject', 'scheduled_at', 'source_type'])->toArray();
    }

    private function campaignOverview(array $args): array
    {
        $query = Campaign::query();
        if (! empty($args['campaign_id'])) {
            $query->whereKey((int) $args['campaign_id']);
        }

        return $query->latest()->limit(empty($args['campaign_id']) ? 10 : 1)->get(['id', 'name', 'status', 'total_recipients', 'sent_count', 'opened_count', 'clicked_count', 'bounced_count', 'unsubscribed_count', 'created_at'])->toArray();
    }

    private function pipelineContacts(array $args): array
    {
        $allowed = ['new', 'contacted', 'replied', 'interested', 'demo_requested', 'demo_scheduled', 'proposal_sent', 'negotiation', 'won', 'lost', 'not_interested'];
        $status = (string) ($args['status'] ?? 'interested');
        if (! in_array($status, $allowed, true)) {
            return ['error' => 'Invalid pipeline status.'];
        }

        return Contact::where('pipeline_status', $status)->latest('updated_at')->limit($this->limit($args, 50))->get(['id', 'email', 'business', 'company', 'last_contacted_at', 'last_replied_at'])->toArray();
    }

    private function conversation(array $args): array
    {
        $message = MailMessage::find((int) ($args['message_id'] ?? 0));
        if (! $message) {
            return ['error' => 'Message not found.'];
        }
        $query = $message->thread_key ? MailMessage::where('thread_key', $message->thread_key) : MailMessage::whereKey($message->id);

        return $query->oldest('message_at')->limit(30)->get()->map(fn ($item) => ['id' => $item->id, 'direction' => $item->direction, 'from' => $item->from_email, 'subject' => $item->subject, 'body' => mb_substr((string) $item->plain_body, 0, 4000), 'at' => $item->message_at])->all();
    }

    private function sequenceOverview(): array
    {
        return SequenceEnrollment::with(['sequence:id,name', 'contact:id,email'])->whereIn('status', ['active', 'paused', 'stopped'])->orderByRaw('next_send_at IS NULL, next_send_at')->limit(50)->get(['id', 'sequence_id', 'contact_id', 'status', 'next_step_position', 'next_send_at', 'stop_reason'])->toArray();
    }

    private function contactLookup(array $args): array
    {
        $search = mb_substr(trim((string) ($args['query'] ?? '')), 0, 120);
        if ($search === '') {
            return ['error' => 'A contact query is required.'];
        }

        return Contact::where(fn ($query) => $query
            ->where('email', 'like', "%{$search}%")
            ->orWhere('first_name', 'like', "%{$search}%")
            ->orWhere('last_name', 'like', "%{$search}%")
            ->orWhere('company', 'like', "%{$search}%")
            ->orWhere('business', 'like', "%{$search}%"))
            ->latest('updated_at')->limit($this->limit($args, 30))
            ->get(['id', 'email', 'first_name', 'last_name', 'company', 'business', 'pipeline_status', 'permission_status', 'tags'])->toArray();
    }

    private function templateOverview(array $args): array
    {
        return Template::latest('updated_at')->limit($this->limit($args, 30))
            ->get(['id', 'name', 'subject', 'updated_at'])->toArray();
    }

    private function systemHealth(): array
    {
        $mailbox = MailboxSetting::current()->refreshAllowance();
        $latestSync = MailSyncLog::latest('id')->first();

        return [
            'mailbox' => [
                'smtp_status' => $mailbox->smtp_status, 'imap_status' => $mailbox->imap_status,
                'last_synced_at' => $mailbox->last_synced_at, 'automation_paused' => $mailbox->automation_paused,
                'sent_today' => $mailbox->sent_today, 'daily_allowance' => $mailbox->daily_allowance,
            ],
            'queue' => [
                'pending' => \DB::table('jobs')->count(), 'failed' => \DB::table('failed_jobs')->count(),
            ],
            'latest_sync' => $latestSync?->only(['status', 'processed', 'created_count', 'updated_count', 'started_at', 'finished_at', 'error']),
        ];
    }

    private function createReplyDraft(array $args): array
    {
        $source = MailMessage::with('recipients')->find((int) ($args['message_id'] ?? 0));
        $body = trim((string) ($args['body'] ?? ''));
        if (! $source || $source->direction !== 'incoming' || $body === '') {
            return ['error' => 'A valid incoming message and non-empty body are required.'];
        }
        $references = array_values(array_filter([...($source->references ?? []), $source->message_id]));
        $draft = $this->outbound->create([
            'subject' => str_starts_with((string) $source->subject, 'Re:') ? $source->subject : 'Re: '.$source->subject,
            'plain_body' => mb_substr($body, 0, 20000), 'in_reply_to' => $source->message_id, 'references' => $references,
            'thread_key' => $source->thread_key, 'contact_id' => $source->contact_id, 'source_type' => 'personal',
        ], [['type' => 'to', 'email' => $source->from_email]], 'draft');

        return ['draft_id' => $draft->id, 'edit_url' => '/mail/messages/'.$draft->id.'/edit', 'status' => 'draft'];
    }

    private function limit(array $args, int $default): int
    {
        return max(1, min($default, (int) Arr::get($args, 'limit', $default)));
    }
}
