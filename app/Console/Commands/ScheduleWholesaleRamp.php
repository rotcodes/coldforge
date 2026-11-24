<?php

namespace App\Console\Commands;

use App\Models\Campaign;
use App\Models\CampaignSend;
use App\Models\Contact;
use App\Models\MailboxSetting;
use App\Models\Template;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class ScheduleWholesaleRamp extends Command
{
    protected $signature = 'outreach:schedule-wholesale-ramp
        {--tag=wholesale-outreach-eligible : Contact eligibility tag}
        {--template=Distributor outreach — orders and inventory : Existing template name}
        {--start-date= : First date in YYYY-MM-DD; defaults to today in the selected timezone}
        {--start-time=11:00 : First-send local time}
        {--timezone=America/Chicago : IANA timezone for the recipient window}
        {--interval=120 : Seconds between recipients}
        {--max=60 : Maximum recipients on any day}
        {--skip-date=* : YYYY-MM-DD dates to skip, repeatable}
        {--include-weekends : Allow Saturday and Sunday campaigns}
        {--dry-run : Show the plan without creating campaigns}';

    protected $description = 'Create visible wholesale outreach campaigns ramping 40, 50, then at most 60 recipients per business day.';

    public function handle(): int
    {
        $timezone = (string) $this->option('timezone');
        $time = (string) $this->option('start-time');
        $interval = (int) $this->option('interval');
        $maximum = (int) $this->option('max');
        $skipDates = array_values(array_unique(array_filter((array) $this->option('skip-date'))));
        if ($interval < 60 || $interval > 3600) {
            throw new RuntimeException('Interval must be between 60 and 3600 seconds.');
        }
        if ($maximum < 1 || $maximum > 60) {
            throw new RuntimeException('The ramp maximum must be between 1 and 60.');
        }
        if (! preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time)) {
            throw new RuntimeException('Start time must use HH:MM format.');
        }
        foreach ($skipDates as $date) {
            if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                throw new RuntimeException('Every skip date must use YYYY-MM-DD format.');
            }
        }

        try {
            $now = CarbonImmutable::now($timezone);
            $date = (string) ($this->option('start-date') ?: $now->toDateString());
            $cursor = CarbonImmutable::parse($date.' '.$time, $timezone);
        } catch (Throwable $exception) {
            throw new RuntimeException('Invalid start date or timezone: '.$exception->getMessage(), previous: $exception);
        }
        if ($cursor->lessThanOrEqualTo($now)) {
            $cursor = $cursor->addDay();
        }
        $cursor = $this->nextAllowedDate($cursor, $skipDates);

        $mailbox = MailboxSetting::current();
        if (! $mailbox->configured() || $mailbox->automation_paused || blank($mailbox->physical_address)) {
            throw new RuntimeException('Mailbox configuration, physical address, or automation state is not ready.');
        }
        if ($mailbox->daily_allowance < $maximum) {
            throw new RuntimeException("Mailbox daily allowance ({$mailbox->daily_allowance}) is below the requested maximum ({$maximum}).");
        }
        $template = Template::where('name', (string) $this->option('template'))->first();
        if (! $template) {
            throw new RuntimeException('The approved outreach template was not found.');
        }
        if (Campaign::where('name', 'like', 'Wholesale distributor ramp —%')->whereNotIn('status', ['cancelled'])->exists()) {
            throw new RuntimeException('A wholesale ramp already exists. Refusing to create duplicate scheduled campaigns.');
        }

        $tag = (string) $this->option('tag');
        $contacts = Contact::matchingFilter(['tags' => [$tag], 'source_system' => 'amazon_wholesale'])
            ->whereNull('last_contacted_at')
            ->whereNull('last_replied_at')
            ->whereDoesntHave('sends', fn ($query) => $query->whereIn('status', ['queued', 'sending', 'sent']))
            ->orderBy('id')
            ->get();
        if ($contacts->isEmpty()) {
            throw new RuntimeException('No eligible unscheduled wholesale contacts remain.');
        }

        $plan = [];
        $offset = 0;
        $day = 0;
        while ($offset < $contacts->count()) {
            $cap = min($day === 0 ? 40 : ($day === 1 ? 50 : $maximum), $maximum);
            $batch = $contacts->slice($offset, $cap)->values();
            $plan[] = [
                'date' => $cursor->toDateString(),
                'starts' => $cursor->format('Y-m-d H:i T'),
                'ends' => $cursor->addSeconds(max(0, $batch->count() - 1) * $interval)->format('Y-m-d H:i T'),
                'count' => $batch->count(),
                'schedule_at' => $cursor->utc(),
                'contact_ids' => $batch->pluck('id')->all(),
            ];
            $offset += $batch->count();
            $day++;
            $cursor = $this->nextAllowedDate($cursor->addDay(), $skipDates);
        }

        $this->table(['Date', 'Start', 'End', 'Recipients'], collect($plan)->map(fn ($row) => [$row['date'], $row['starts'], $row['ends'], $row['count']]));
        $this->line('Total eligible recipients: '.$contacts->count());
        $this->line('Interval: '.$interval.' seconds; maximum daily batch: '.$maximum.'.');
        if ($this->option('dry-run')) {
            $this->info('Dry run complete; no campaigns created.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($plan, $template, $tag, $interval) {
            $now = now();
            foreach ($plan as $row) {
                $campaign = Campaign::create([
                    'name' => 'Wholesale distributor ramp — '.$row['date'].' — '.$row['count'],
                    'template_id' => $template->id,
                    'sequence_id' => null,
                    'sender_strategy' => 'single_mailbox',
                    'contact_filter' => [
                        'tags' => [$tag],
                        'industry_slugs' => ['wholesale_distribution'],
                        'source_system' => 'amazon_wholesale',
                        'lead_score_min' => null,
                        'require_industry' => true,
                    ],
                    'schedule_at' => $row['schedule_at'],
                    'status' => 'scheduled',
                    'daily_cap' => $row['count'],
                    'send_interval_seconds' => $interval,
                    'total_recipients' => $row['count'],
                ]);
                CampaignSend::insert(collect($row['contact_ids'])->map(fn ($contactId) => [
                    'campaign_id' => $campaign->id,
                    'contact_id' => $contactId,
                    'status' => 'queued',
                    'queued_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all());
                if ($campaign->sends()->count() !== $row['count']) {
                    throw new RuntimeException('Campaign recipient count mismatch; transaction rolled back.');
                }
            }
        });

        $this->info('Wholesale ramp scheduled successfully.');

        return self::SUCCESS;
    }

    /** @param array<int, string> $skipDates */
    private function nextAllowedDate(CarbonImmutable $date, array $skipDates): CarbonImmutable
    {
        while ((! $this->option('include-weekends') && $date->isWeekend()) || in_array($date->toDateString(), $skipDates, true)) {
            $date = $date->addDay();
        }

        return $date;
    }
}
