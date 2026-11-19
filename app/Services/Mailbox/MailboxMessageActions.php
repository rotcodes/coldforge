<?php

namespace App\Services\Mailbox;

use App\Models\MailboxSetting;
use App\Models\MailFolder;
use App\Models\MailMessage;

class MailboxMessageActions
{
    public function __construct(private readonly MailboxClientFactory $clients) {}

    public function flag(MailMessage $message, string $flag, bool $enabled): void
    {
        $this->remote($message, function ($remote) use ($flag, $enabled) {
            $enabled ? $remote->setFlag($flag) : $remote->unsetFlag($flag);
        });

        $message->update($flag === 'Seen'
            ? ['is_read' => $enabled]
            : ['is_starred' => $enabled]);
        $this->refreshFolderCounts($message->mail_folder_id);
    }

    public function move(MailMessage $message, MailFolder $target): void
    {
        $sourceId = $message->mail_folder_id;
        $this->remote($message, fn ($remote) => $remote->move($target->path, true));
        $message->update(['mail_folder_id' => $target->id, 'remote_uid' => null]);
        $this->refreshFolderCounts($sourceId);
        $this->refreshFolderCounts($target->id);
    }

    public function trash(MailMessage $message): void
    {
        $trash = MailFolder::where('special_use', 'trash')->first();
        if ($trash) {
            $this->move($message, $trash);

            return;
        }

        $folderId = $message->mail_folder_id;
        $this->remote($message, fn ($remote) => $remote->delete(true));
        $message->delete();
        $this->refreshFolderCounts($folderId);
    }

    private function remote(MailMessage $message, callable $action): void
    {
        $message->loadMissing('folder');
        if (! $message->remote_uid || ! $message->folder) {
            return;
        }

        $client = $this->clients->make(MailboxSetting::current())->connect();
        try {
            $folder = $client->getFolderByPath($message->folder->path);
            if (! $folder) {
                throw new \RuntimeException('The source mailbox folder no longer exists.');
            }
            $remote = $folder->query()->getMessageByUid($message->remote_uid);
            $action($remote);
        } finally {
            $client->disconnect();
        }
    }

    private function refreshFolderCounts(?int $folderId): void
    {
        if (! $folderId) {
            return;
        }

        MailFolder::whereKey($folderId)->update([
            'message_count' => MailMessage::where('mail_folder_id', $folderId)->count(),
            'unread_count' => MailMessage::where('mail_folder_id', $folderId)->where('is_read', false)->count(),
        ]);
    }
}
