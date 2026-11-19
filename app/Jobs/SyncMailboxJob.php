<?php

namespace App\Jobs;

use App\Services\Mailbox\MailboxSynchronizer;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SyncMailboxJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 1800;

    public int $uniqueFor = 1800;

    public function __construct(public bool $full = false) {}

    public function uniqueId(): string
    {
        return 'mailbox-sync';
    }

    public function handle(MailboxSynchronizer $synchronizer): void
    {
        $synchronizer->sync($this->full);
    }
}
