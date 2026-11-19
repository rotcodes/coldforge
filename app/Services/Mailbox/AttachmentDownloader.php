<?php

namespace App\Services\Mailbox;

use App\Models\MailAttachment;
use App\Models\MailboxSetting;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class AttachmentDownloader
{
    private const MAX_BYTES = 25 * 1024 * 1024;

    public function __construct(private readonly MailboxClientFactory $clients) {}

    public function ensureLocal(MailAttachment $attachment): string
    {
        if ($attachment->is_downloaded && $attachment->storage_path && Storage::disk('local')->exists($attachment->storage_path)) {
            return $attachment->storage_path;
        }
        if ($attachment->size_bytes > self::MAX_BYTES) {
            throw new \RuntimeException('This attachment exceeds the 25 MB download limit.');
        }

        $attachment->loadMissing('message.folder');
        $message = $attachment->message;
        if (! $message?->remote_uid || ! $message->folder) {
            throw new \RuntimeException('The remote attachment is no longer available.');
        }

        $client = $this->clients->make(MailboxSetting::current())->connect();
        try {
            $folder = $client->getFolderByPath($message->folder->path);
            $remoteMessage = $folder?->query()->getMessageByUid($message->remote_uid);
            $remote = $remoteMessage?->getAttachments()->first(function ($candidate) use ($attachment) {
                return ($attachment->remote_id && in_array($attachment->remote_id, [(string) $candidate->id, (string) $candidate->hash], true))
                    || $candidate->name === $attachment->filename
                    || $candidate->filename === $attachment->filename;
            });
            if (! $remote) {
                throw new \RuntimeException('The attachment could not be found on the mail server.');
            }
            $content = $remote->getContent();
            if (strlen($content) > self::MAX_BYTES) {
                throw new \RuntimeException('This attachment exceeds the 25 MB download limit.');
            }
            $path = 'mail-downloads/'.$message->id.'/'.Str::uuid().'-'.basename($attachment->filename);
            if (! Storage::disk('local')->put($path, $content)) {
                throw new \RuntimeException('The attachment could not be stored securely.');
            }
            $attachment->update(['storage_path' => $path, 'is_downloaded' => true, 'size_bytes' => strlen($content)]);

            return $path;
        } finally {
            $client->disconnect();
        }
    }
}
