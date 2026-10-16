<?php

namespace App\Jobs;

use App\Models\Campaign;
use App\Services\Senders\SenderRegistry;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Moves up to (daily_cap - sent today) queued sends into the send queue. Runs every minute per running campaign. */
class ProcessCampaignJob implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    public function __construct(public Campaign $campaign) {}

    public function uniqueId(): string
    {
        return (string) $this->campaign->id;
    }

    public function handle(): void
    {
        $campaign = $this->campaign->fresh();
        if ($campaign?->status !== 'running') {
            return;
        }

        $queued = $campaign->sends()->where('status', 'queued');
        $inFlight = $campaign->sends()->where('status', 'sending')->count();

        if (! $queued->exists()) {
            if ($inFlight === 0) {
                $campaign->update(['status' => 'completed']);
            }

            return;
        }

        $usedToday = $inFlight + $campaign->sends()->where('sent_at', '>=', today())->count();
        $remaining = $campaign->daily_cap - $usedToday;
        if ($remaining <= 0) {
            return;
        }

        foreach ($queued->orderBy('id')->limit($remaining)->get() as $send) {
            $sender = SenderRegistry::pick($campaign->sender_strategy);
            if (! $sender) {
                return; // all senders out of quota or inactive; try again next minute
            }

            $sender->increment('sent_today'); // reserve quota now so a batch can't overshoot it
            $send->update(['status' => 'sending', 'sender_id' => $sender->id]);
            SendEmailJob::dispatch($send);
        }
    }
}
