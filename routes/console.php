<?php

use App\Jobs\DispatchDueCampaignsJob;
use App\Jobs\DispatchScheduledMailJob;
use App\Jobs\DispatchSequenceStepsJob;
use App\Jobs\ProcessInboundSignalsJob;
use App\Jobs\SyncMailboxJob;
use App\Models\MailboxSetting;
use Illuminate\Support\Facades\Schedule;

Schedule::job(new DispatchDueCampaignsJob)->everyMinute();
Schedule::job(new DispatchScheduledMailJob)->everyMinute();
Schedule::job(new DispatchSequenceStepsJob)->everyMinute();
Schedule::job(new ProcessInboundSignalsJob)->everyMinute()->withoutOverlapping(5);
Schedule::command('mail:prune-data')->dailyAt('02:30')->withoutOverlapping();
Schedule::call(function () {
    if (MailboxSetting::query()->whereNotNull('email')->exists()) {
        SyncMailboxJob::dispatch();
    }
})->name('mailbox-sync')->everyMinute()->withoutOverlapping(10);
