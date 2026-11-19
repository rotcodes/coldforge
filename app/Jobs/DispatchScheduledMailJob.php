<?php

namespace App\Jobs;

use App\Models\MailboxSetting;
use App\Models\MailMessage;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class DispatchScheduledMailJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        if (MailboxSetting::current()->automation_paused) {
            return;
        }

        MailMessage::where('status', 'scheduled')
            ->where('scheduled_at', '<=', now())
            ->orderBy('scheduled_at')
            ->limit(100)
            ->get()
            ->each(function (MailMessage $message) {
                $message->update(['status' => 'queued']);
                SendMailMessageJob::dispatch($message);
            });
    }
}
