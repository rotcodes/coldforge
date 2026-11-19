<?php

namespace App\Services\Mailbox;

use App\Models\MailboxSetting;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Throwable;
use Webklex\PHPIMAP\ClientManager;

class MailboxConnectionTester
{
    /** @return array{ok: bool, error: ?string} */
    public function smtp(MailboxSetting $mailbox): array
    {
        try {
            $implicitTls = $mailbox->smtp_encryption === 'ssl' || (int) $mailbox->smtp_port === 465;
            $transport = new EsmtpTransport($mailbox->smtp_host, (int) $mailbox->smtp_port, $implicitTls);
            $transport->setUsername($mailbox->email);
            $transport->setPassword((string) $mailbox->password);
            $transport->start();
            $transport->stop();

            return ['ok' => true, 'error' => null];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $this->safe($e)];
        }
    }

    /** @return array{ok: bool, error: ?string} */
    public function imap(MailboxSetting $mailbox): array
    {
        try {
            $client = (new ClientManager)->make([
                'host' => $mailbox->imap_host,
                'port' => (int) $mailbox->imap_port,
                'encryption' => $mailbox->imap_encryption,
                'validate_cert' => true,
                'username' => $mailbox->email,
                'password' => (string) $mailbox->password,
                'protocol' => 'imap',
                'timeout' => 20,
            ])->connect();
            $client->getFolders(false);
            $client->disconnect();

            return ['ok' => true, 'error' => null];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $this->safe($e)];
        }
    }

    private function safe(Throwable $e): string
    {
        return mb_substr(preg_replace('/(password|token|secret)=?[^\s]*/i', '$1=[redacted]', $e->getMessage()), 0, 500);
    }
}
