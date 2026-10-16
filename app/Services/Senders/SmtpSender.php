<?php

namespace App\Services\Senders;

use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Throwable;

/** Generic SMTP relay (works for any provider that gives you SMTP credentials, incl. Mailjet/MailerSend/Elastic). */
class SmtpSender implements SenderInterface
{
    public function __construct(private readonly array $config) {}

    private function transport(): EsmtpTransport
    {
        $port = (int) ($this->config['port'] ?? 587);
        $transport = new EsmtpTransport($this->config['host'] ?? 'localhost', $port, ($this->config['encryption'] ?? '') === 'ssl' || $port === 465);
        $transport->setUsername($this->config['username'] ?? '');
        $transport->setPassword($this->config['password'] ?? '');

        return $transport;
    }

    public function sendTransactional(array $payload): SenderResult
    {
        $email = (new Email)
            ->from(new Address($payload['from_email'], $payload['from_name'] ?? ''))
            ->to(new Address($payload['to_email'], $payload['to_name'] ?? ''))
            ->subject($payload['subject'])
            ->html($payload['html']);

        if (! empty($payload['text'])) {
            $email->text($payload['text']);
        }
        if (! empty($payload['reply_to'])) {
            $email->replyTo($payload['reply_to']);
        }
        foreach ($payload['headers'] ?? [] as $name => $value) {
            $email->getHeaders()->addTextHeader($name, $value);
        }

        try {
            $sent = $this->transport()->send($email);

            return SenderResult::sent($sent?->getMessageId());
        } catch (TransportExceptionInterface $e) {
            return SenderResult::failed('SMTP: '.$e->getMessage(), true);
        }
    }

    public function getQuotaInfo(): array
    {
        return [];
    }

    public function testConnection(): bool
    {
        try {
            $this->transport()->start();

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
