<?php

namespace App\Services\Mailbox;

use App\Models\MailboxSetting;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\ClientManager;

class MailboxClientFactory
{
    public function make(MailboxSetting $mailbox): Client
    {
        return (new ClientManager)->make([
            'host' => $mailbox->imap_host,
            'port' => (int) $mailbox->imap_port,
            'encryption' => $mailbox->imap_encryption,
            'validate_cert' => true,
            'username' => $mailbox->email,
            'password' => (string) $mailbox->password,
            'protocol' => 'imap',
            'timeout' => 30,
        ]);
    }
}
