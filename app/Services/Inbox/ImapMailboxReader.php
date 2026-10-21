<?php

namespace App\Services\Inbox;

use Carbon\CarbonInterface;
use Webklex\PHPIMAP\Address;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\Folder;
use Webklex\PHPIMAP\Message;

/** Read-only IMAP access (EXAMINE + BODY.PEEK): never marks, moves or deletes mail. */
class ImapMailboxReader implements MailboxReader
{
    private ?Client $client = null;

    private ?Folder $folder = null;

    public function __construct(private readonly array $config) {}

    public function status(): array
    {
        $status = $this->folder()->examine();

        return ['uidvalidity' => (int) ($status['uidvalidity'] ?? 0), 'uidnext' => (int) ($status['uidnext'] ?? 1)];
    }

    public function fetch(?int $afterUid, CarbonInterface $since): iterable
    {
        $query = $this->folder()->query()->leaveUnread()->setFetchFlags(false);

        $messages = $afterUid === null
            ? $query->since($since)->get()
            : $query->getByUidGreater($afterUid);

        foreach ($messages->sortBy(fn (Message $m) => $m->getUid()) as $message) {
            yield $this->normalize($message);
        }
    }

    private function folder(): Folder
    {
        if (! $this->folder) {
            $this->client = (new ClientManager)->make([
                'host' => $this->config['host'],
                'port' => $this->config['port'],
                'encryption' => $this->config['encryption'],
                'validate_cert' => true,
                'username' => $this->config['username'],
                'password' => $this->config['password'],
                'protocol' => 'imap',
                'timeout' => 30,
            ])->connect();
            $this->folder = $this->client->getFolderByPath($this->config['folder']);
        }

        return $this->folder;
    }

    private function normalize(Message $m): array
    {
        $from = $m->getFrom()->first();

        return [
            'uid' => (int) $m->getUid(),
            'message_id' => (string) ($m->getMessageId()->first() ?: ''),
            'in_reply_to' => (string) ($m->getInReplyTo()->first() ?: ''),
            'references' => array_map('strval', $m->getReferences()->all()),
            'from_email' => $from instanceof Address ? $from->mail : '',
            'from_name' => $from instanceof Address ? $from->personal : '',
            'to' => $this->addresses($m->getTo()->all()),
            'cc' => $this->addresses($m->getCc()->all()),
            'subject' => (string) ($m->getSubject()->first() ?: ''),
            'text' => $m->getTextBody(),
            'html' => $m->getHTMLBody(),
            'attachments' => $m->getAttachments()->map(fn ($a) => [
                'filename' => $a->name,
                'contentType' => $a->content_type,
                'sizeBytes' => $a->size,
            ])->values()->all(),
            'date' => $m->getDate()->first() ?: null,
        ];
    }

    private function addresses(array $list): array
    {
        return array_values(array_map(fn ($a) => $a instanceof Address ? $a->full : (string) $a, $list));
    }

    public function __destruct()
    {
        $this->client?->disconnect();
    }
}
