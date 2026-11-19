<?php

namespace App\Services\Sequences;

use App\Models\CampaignSend;
use App\Models\Sequence;
use App\Models\SequenceEnrollment;
use Carbon\CarbonImmutable;

class SequenceScheduler
{
    public function enroll(CampaignSend $send): ?SequenceEnrollment
    {
        $send->loadMissing('campaign.sequence.steps');
        $sequence = $send->campaign->sequence;
        $first = $sequence?->steps->first();
        if (! $sequence || $sequence->status !== 'active' || ! $first) {
            return null;
        }

        return SequenceEnrollment::firstOrCreate(
            ['sequence_id' => $sequence->id, 'campaign_send_id' => $send->id],
            [
                'contact_id' => $send->contact_id,
                'status' => 'active',
                'next_step_position' => $first->position,
                'next_send_at' => $this->nextAt($sequence, $first->delay_days),
            ],
        );
    }

    public function advance(SequenceEnrollment $enrollment): void
    {
        $enrollment->loadMissing('sequence.steps');
        $next = $enrollment->sequence->steps->firstWhere('position', '>', $enrollment->next_step_position);
        if (! $next) {
            $enrollment->update(['status' => 'completed', 'next_send_at' => null]);

            return;
        }

        $enrollment->update([
            'next_step_position' => $next->position,
            'next_send_at' => $this->nextAt($enrollment->sequence, $next->delay_days),
        ]);
    }

    public function stop(SequenceEnrollment $enrollment, string $reason): void
    {
        $enrollment->update(['status' => 'stopped', 'stop_reason' => $reason, 'stopped_at' => now(), 'next_send_at' => null]);
    }

    private function nextAt(Sequence $sequence, int $delayDays): CarbonImmutable
    {
        $next = CarbonImmutable::now($sequence->timezone)->addDays($delayDays);
        if ($sequence->business_days_only) {
            while ($next->isWeekend()) {
                $next = $next->addDay();
            }
        }
        if ($sequence->window_start) {
            [$hour, $minute] = array_map('intval', explode(':', $sequence->window_start));
            $next = $next->setTime($hour, $minute);
        }

        return $next->utc();
    }
}
