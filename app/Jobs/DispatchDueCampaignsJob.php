<?php

namespace App\Jobs;

use App\Models\Campaign;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Scheduled every minute: starts due campaigns and tops up every running one. */
class DispatchDueCampaignsJob implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        Campaign::where('status', 'scheduled')
            ->where('schedule_at', '<=', now())
            ->update(['status' => 'running']);

        Campaign::where('status', 'running')->each(fn (Campaign $c) => ProcessCampaignJob::dispatch($c));
    }
}
