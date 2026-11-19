<?php

namespace App\Services\Mailbox;

use App\Models\MailboxSetting;
use App\Models\MailFolder;
use App\Models\MailMessage;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Throwable;

class HostingerMailer
{
    public function __construct(private readonly MailboxClientFactory $clients) {}

    public function send(MailMessage $message, MailboxSetting $mailbox): MailDeliveryResult
    {
        $message->loadMissing('recipients', 'attachments');
        $email = $this->build($message, $mailbox);
        $transport = new EsmtpTransport(
            $mailbox->smtp_host,
            (int) $mailbox->smtp_port,
            $mailbox->smtp_encryption === 'ssl' || (int) $mailbox->smtp_port === 465,
        );
        $transport->setUsername($mailbox->email);
        $transport->setPassword((string) $mailbox->password);

        try {
            $transport->send($email);
        } catch (TransportExceptionInterface $e) {
            $error = $this->safe($e);
            if ($this->uncertain($error)) {
                return MailDeliveryResult::uncertain($error);
            }

            return MailDeliveryResult::failed($error, $this->temporary($error));
        }

        try {
            $this->appendSent($email, $mailbox);
        } catch (Throwable $e) {
            Log::warning('Sent email could not be appended to IMAP Sent folder', [
                'mail_message_id' => $message->id,
                'error' => $this->safe($e),
            ]);
        }

        return MailDeliveryResult::accepted();
    }

    private function build(MailMessage $message, MailboxSetting $mailbox): Email
    {
        $email = (new Email)
            ->from(new Address($mailbox->email, $mailbox->display_name ?: ''))
            ->subject($message->subject ?: '(no subject)');

        foreach (['to', 'cc', 'bcc'] as $type) {
            $addresses = $message->recipients->where('type', $type)->map(fn ($recipient) => new Address($recipient->email, $recipient->name ?: ''))->all();
            if ($addresses) {
                $email->{$type}(...$addresses);
            }
        }
        if ($mailbox->reply_to) {
            $email->replyTo($mailbox->reply_to);
        }
        if ($message->plain_body !== null) {
            $email->text($message->plain_body);
        }
        if ($message->html_body !== null) {
            $email->html($message->html_body);
        }

        $email->getHeaders()->addIdHeader('Message-ID', $message->message_id);
        if ($message->in_reply_to) {
            $email->getHeaders()->addIdHeader('In-Reply-To', $message->in_reply_to);
        }
        if ($message->references) {
            $email->getHeaders()->addIdHeader('References', $message->references);
        }
        foreach ($message->headers ?? [] as $name => $value) {
            if (! in_array(strtolower($name), ['message-id', 'in-reply-to', 'references', 'from', 'to', 'cc', 'bcc'], true)) {
                $email->getHeaders()->addTextHeader($name, (string) $value);
            }
        }
        foreach ($message->attachments as $attachment) {
            if ($attachment->is_downloaded && $attachment->storage_path && is_file(storage_path('app/private/'.$attachment->storage_path))) {
                $email->attachFromPath(storage_path('app/private/'.$attachment->storage_path), $attachment->filename, $attachment->mime_type);
            }
        }

        return $email;
    }

    private function appendSent(Email $email, MailboxSetting $mailbox): void
    {
        $client = $this->clients->make($mailbox)->connect();
        try {
            $sentPath = MailFolder::where('special_use', 'sent')->value('path');
            $folder = $sentPath ? $client->getFolderByPath($sentPath) : null;
            if (! $folder) {
                $folder = $client->getFolders(false)->first(fn ($folder) => str_contains(strtolower($folder->path), 'sent'));
            }
            if (! $folder) {
                throw new \RuntimeException('The server Sent folder could not be identified.');
            }
            $folder->appendMessage($email->toString(), ['\\Seen'], now());
        } finally {
            $client->disconnect();
        }
    }

    private function temporary(string $error): bool
    {
        return (bool) preg_match('/\b(421|450|451|452|454)\b|temporar|try again|rate.?limit/i', $error);
    }

    private function uncertain(string $error): bool
    {
        return (bool) preg_match('/timed out|timeout|connection.*closed|lost connection|unexpectedly.*disconnect/i', $error);
    }

    private function safe(Throwable $e): string
    {
        return mb_substr(preg_replace('/(password|token|secret)=?[^\s]*/i', '$1=[redacted]', $e->getMessage()), 0, 1000);
    }
}
