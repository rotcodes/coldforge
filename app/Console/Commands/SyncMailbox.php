<?php

namespace App\Console\Commands;

use App\Services\Mailbox\MailboxSynchronizer;
use Illuminate\Console\Command;

class SyncMailbox extends Command
{
    protected $signature = 'mail:sync {--full : Import all accessible historical messages}';

    protected $description = 'Discover Hostinger folders and synchronize mailbox messages';

    public function handle(MailboxSynchronizer $synchronizer): int
    {
        $result = $synchronizer->sync((bool) $this->option('full'));
        $this->info("Synced {$result['folders']} folders; {$result['created']} created, {$result['updated']} updated.");

        return self::SUCCESS;
    }
}
