<?php

namespace App\Services\Mailbox;

use App\Models\Contact;
use App\Models\MailboxSetting;
use App\Models\MailFolder;
use App\Models\MailMessage;
use App\Models\MailSyncLog;
use App\Models\SequenceEnrollment;
use Illuminate\Support\Facades\DB;
use Throwable;
use Webklex\PHPIMAP\Address;
use Webklex\PHPIMAP\Folder;
use Webklex\PHPIMAP\Message;

class MailboxSynchronizer
{
    public function __construct(private readonly MailboxClientFactory $clients) {}

    /** Discover folders and synchronize new mail. Full mode imports all accessible history, newest first. */
    public function sync(bool $full = false): array
    {
        $mailbox = MailboxSetting::current();
        if (! $mailbox->configured()) {
            throw new \RuntimeException('Mailbox credentials are not configured.');
        }

        $client = $this->clients->make($mailbox)->connect();
        $totals = ['folders' => 0, 'processed' => 0, 'created' => 0, 'updated' => 0];

        try {
            foreach ($client->getFoldersWithStatus(false) as $remote) {
                if ($remote->no_select) {
                    continue;
                }
                $folder = $this->folder($remote);
                $totals['folders']++;
                $result = $this->syncFolder($remote, $folder, $full || ! $folder->last_synced_at);
                foreach (['processed', 'created', 'updated'] as $key) {
                    $totals[$key] += $result[$key];
                }
            }

            $mailbox->update([
                'imap_status' => 'connected',
                'last_imap_test_at' => now(),
                'last_synced_at' => now(),
                'last_connection_error' => null,
            ]);
        } catch (Throwable $e) {
            $mailbox->update(['imap_status' => 'failed', 'last_connection_error' => $this->safe($e)]);
            throw $e;
        } finally {
            $client->disconnect();
        }

        return $totals;
    }

    private function folder(Folder $remote): MailFolder
    {
        $status = $remote->status ?? [];

        return MailFolder::updateOrCreate(['path' => $remote->path], [
            'name' => $remote->full_name ?: $remote->name,
            'delimiter' => $remote->delimiter,
            'special_use' => $this->specialUse($remote->path),
            'uid_validity' => $status['uidvalidity'] ?? null,
            'uid_next' => $status['uidnext'] ?? null,
            'message_count' => $status['messages'] ?? 0,
            'unread_count' => $status['unseen'] ?? 0,
        ]);
    }

    private function syncFolder(Folder $remote, MailFolder $folder, bool $full): array
    {
        $log = MailSyncLog::create([
            'mail_folder_id' => $folder->id,
            'type' => $full ? 'historical' : 'incremental',
            'status' => 'running',
            'started_at' => now(),
        ]);
        $counts = ['processed' => 0, 'created' => 0, 'updated' => 0];
        $maxUid = (int) ($folder->last_synced_uid ?? 0);

        try {
            $query = $remote->query()->leaveUnread()->setFetchFlags(true)->setFetchOrderDesc();
            if (! $full && $maxUid > 0) {
                $messages = $query->getByUidGreater($maxUid);
                $this->storeChunk($messages, $folder, $counts, $maxUid);
            } else {
                $query->whereAll()->chunked(function ($messages) use ($folder, &$counts, &$maxUid) {
                    $this->storeChunk($messages, $folder, $counts, $maxUid);
                }, 50);
            }

            $folder->update([
                'last_synced_uid' => max($maxUid, (int) ($folder->last_synced_uid ?? 0)),
                'sync_status' => 'synced',
                'last_synced_at' => now(),
                'last_error' => null,
            ]);
            $log->update($counts + ['status' => 'completed', 'finished_at' => now()]);
        } catch (Throwable $e) {
            $folder->update(['sync_status' => 'failed', 'last_error' => $this->safe($e)]);
            $log->update($counts + ['status' => 'failed', 'error' => $this->safe($e), 'finished_at' => now()]);
            throw $e;
        }

        return $counts;
    }

    private function storeChunk(iterable $messages, MailFolder $folder, array &$counts, int &$maxUid): void
    {
        foreach ($messages as $message) {
            if (! $message instanceof Message) {
                continue;
            }
            $uid = (int) $message->getUid();
            $maxUid = max($maxUid, $uid);
            $counts['processed']++;

            DB::transaction(function () use ($message, $folder, $uid, &$counts) {
                $data = $this->normalize($message, $folder);
                $existing = MailMessage::where('mail_folder_id', $folder->id)->where('remote_uid', $uid)->first();
                if (! $existing && ! empty($data['message_id'])) {
                    $existing = MailMessage::where('message_id', $data['message_id'])
                        ->where('direction', 'outgoing')
                        ->whereNull('remote_uid')
                        ->first();
                }
                if ($existing) {
                    $existing->update($data + ['mail_folder_id' => $folder->id, 'remote_uid' => $uid]);
                    $local = $existing;
                } else {
                    $local = MailMessage::create($data + ['mail_folder_id' => $folder->id, 'remote_uid' => $uid]);
                }
                $existing ? $counts['updated']++ : $counts['created']++;

                $local->recipients()->delete();
                foreach (['to' => $message->getTo()->all(), 'cc' => $message->getCc()->all(), 'bcc' => $message->getBcc()->all()] as $type => $addresses) {
                    foreach ($addresses as $address) {
                        if ($address instanceof Address && $address->mail) {
                            $local->recipients()->create(['type' => $type, 'email' => strtolower($address->mail), 'name' => $address->personal ?: null]);
                        }
                    }
                }

                if (! $existing) {
                    foreach ($message->getAttachments() as $attachment) {
                        $local->attachments()->create([
                            'remote_id' => (string) ($attachment->id ?? ''),
                            'filename' => $attachment->name ?: 'attachment',
                            'mime_type' => $attachment->content_type ?: null,
                            'size_bytes' => (int) ($attachment->size ?? 0),
                            'disposition' => $attachment->disposition ?: null,
                            'content_id' => $attachment->content_id ?: null,
                        ]);
                    }
                }

                if ($local->direction === 'incoming' && $local->contact_id && ($local->in_reply_to || $local->references)) {
                    $replyIds = array_values(array_filter([$local->in_reply_to, ...($local->references ?? [])]));
                    $isReply = MailMessage::where('direction', 'outgoing')->whereIn('message_id', $replyIds)->exists();
                    if ($isReply) {
                        $local->contact()->update(['status' => 'replied', 'pipeline_status' => 'replied', 'last_replied_at' => $local->message_at ?? now()]);
                        SequenceEnrollment::where('contact_id', $local->contact_id)->where('status', 'active')->update([
                            'status' => 'stopped', 'stop_reason' => 'recipient_replied', 'stopped_at' => now(), 'next_send_at' => null,
                        ]);
                    }
                }
            });
        }
    }

    private function normalize(Message $message, MailFolder $folder): array
    {
        $from = $message->getFrom()->first();
        $messageId = $this->id((string) ($message->getMessageId()->first() ?: ''));
        $inReplyTo = $this->id((string) ($message->getInReplyTo()->first() ?: ''));
        $references = array_values(array_filter(array_map(fn ($value) => $this->id((string) $value), $message->getReferences()->all())));
        $flagsRaw = $message->getFlags()->toArray();
        $flags = array_values(array_unique(array_map('strtolower', [...array_keys($flagsRaw), ...array_values($flagsRaw)])));
        $fromEmail = $from instanceof Address ? strtolower($from->mail) : null;
        $direction = in_array($folder->special_use, ['sent', 'drafts', 'outbox'], true) ? 'outgoing' : 'incoming';
        $contactEmail = $direction === 'incoming' ? $fromEmail : null;
        $htmlBody = $message->getHTMLBody() ?: null;
        $plainBody = $message->getTextBody() ?: MailMessage::htmlToPlainText($htmlBody);

        return [
            'contact_id' => $contactEmail ? Contact::where('email', $contactEmail)->value('id') : null,
            'message_id' => $messageId ?: null,
            'in_reply_to' => $inReplyTo ?: null,
            'references' => $references ?: null,
            'thread_key' => $references[0] ?? ($inReplyTo ?: ($messageId ?: 'uid:'.$folder->id.':'.$message->getUid())),
            'direction' => $direction,
            'status' => $folder->special_use === 'drafts' ? 'draft' : 'synced',
            'from_email' => $fromEmail,
            'from_name' => $from instanceof Address ? ($from->personal ?: null) : null,
            'subject' => mb_substr((string) ($message->getSubject()->first() ?: '(no subject)'), 0, 255),
            'plain_body' => $plainBody,
            'html_body' => $htmlBody,
            'flags' => $flags,
            'is_read' => in_array('seen', $flags, true) || in_array('\\seen', $flags, true),
            'is_starred' => in_array('flagged', $flags, true) || in_array('\\flagged', $flags, true),
            'attachment_count' => $message->getAttachments()->count(),
            'message_at' => $message->getDate()->first() ?: now(),
        ];
    }

    private function specialUse(string $path): ?string
    {
        $name = strtolower(preg_replace('/^inbox[.\/]/i', '', $path));

        return match (true) {
            $name === 'inbox' => 'inbox',
            str_contains($name, 'sent') => 'sent',
            str_contains($name, 'draft') => 'drafts',
            str_contains($name, 'archive') => 'archive',
            str_contains($name, 'junk') || str_contains($name, 'spam') => 'spam',
            str_contains($name, 'trash') || str_contains($name, 'deleted') => 'trash',
            str_contains($name, 'outbox') => 'outbox',
            default => null,
        };
    }

    private function id(string $value): string
    {
        return trim($value, " \t\n\r<>");
    }

    private function safe(Throwable $e): string
    {
        return mb_substr(preg_replace('/(password|token|secret)=?[^\s]*/i', '$1=[redacted]', $e->getMessage()), 0, 500);
    }
}
