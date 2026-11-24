<?php

namespace Tests\Feature;

use App\Jobs\ProcessCampaignJob;
use App\Jobs\SendEmailJob;
use App\Models\Campaign;
use App\Models\CampaignSend;
use App\Models\Contact;
use App\Models\MailboxSetting;
use App\Models\Template;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class WholesaleRampTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        User::create(['name' => 'Admin', 'email' => 'admin@test.dev', 'password' => 'secret-password']);
    }

    public function test_import_keeps_only_explicit_wholesale_matches_and_only_new_rows_are_eligible(): void
    {
        $path = storage_path('framework/testing/wholesale-import.csv');
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        file_put_contents($path, implode("\n", [
            'Company,Contact,Email,Phone,Website,Amazon URL,Category,Source,Status,Assigned Rep,Created',
            'Acme Wholesale,Alex Stone,alex@acme.test,111,acmewholesale.test,https://amazon.test/acme,Industrial & Scientific,Amazon US List,new,Rep A,2026-09-03',
            'Retail Only,Rita Shop,rita@retail.test,222,retail.test,https://amazon.test/retail,Fashion,Amazon US List,new,,2026-09-03',
            'Legacy Distribution,Lee Old,lee@legacy.test,333,legacydistribution.test,https://amazon.test/legacy,Electronics,Amazon US List,interested,Rep B,2026-09-03',
            'Broken Distributor,No Address,,444,brokendistributor.test,https://amazon.test/broken,Tools & Home Improvement,Amazon US List,new,,2026-09-03',
        ]));

        $this->assertSame(0, Artisan::call('outreach:import-wholesale', ['file' => $path]));

        $this->assertDatabaseCount('contacts', 2);
        $eligible = Contact::where('email', 'alex@acme.test')->firstOrFail();
        $this->assertSame('legitimate_interest', $eligible->permission_status);
        $this->assertContains('wholesale-outreach-eligible', $eligible->tags);
        $this->assertSame('amazon_wholesale', $eligible->source_system);
        $interested = Contact::where('email', 'lee@legacy.test')->firstOrFail();
        $this->assertSame('unknown', $interested->permission_status);
        $this->assertSame('interested', $interested->pipeline_status);
        $this->assertNotContains('wholesale-outreach-eligible', $interested->tags);
        $this->assertDatabaseMissing('contacts', ['email' => 'rita@retail.test']);
    }

    public function test_ramp_planner_creates_40_50_60_batches_and_skips_requested_dates(): void
    {
        $this->mailbox();
        $this->template();
        foreach (range(1, 151) as $number) {
            Contact::create([
                'email' => "lead{$number}@example.test",
                'company' => "Distributor {$number}",
                'industry_slug' => 'wholesale_distribution',
                'source_system' => 'amazon_wholesale',
                'status' => 'active',
                'permission_status' => 'legitimate_interest',
                'tags' => ['wholesale-outreach-eligible'],
            ]);
        }

        $this->assertSame(0, Artisan::call('outreach:schedule-wholesale-ramp', [
            '--start-date' => '2030-01-07',
            '--skip-date' => ['2030-01-08'],
            '--interval' => 120,
            '--max' => 60,
        ]));

        $campaigns = Campaign::orderBy('schedule_at')->get();
        $this->assertSame([40, 50, 60, 1], $campaigns->pluck('total_recipients')->all());
        $this->assertSame([40, 50, 60, 1], $campaigns->pluck('daily_cap')->all());
        $this->assertSame([120, 120, 120, 120], $campaigns->pluck('send_interval_seconds')->all());
        $this->assertSame(['scheduled'], $campaigns->pluck('status')->unique()->values()->all());
        $this->assertSame(151, CampaignSend::count());
        $this->assertSame(['2030-01-07', '2030-01-09', '2030-01-10', '2030-01-11'], $campaigns->map(fn ($campaign) => $campaign->schedule_at->setTimezone('America/Chicago')->toDateString())->all());
    }

    public function test_campaign_interval_delays_every_send_after_the_first(): void
    {
        Carbon::setTestNow('2030-01-07 17:00:00');
        Queue::fake([SendEmailJob::class]);
        $this->mailbox();
        $template = $this->template();
        $campaign = Campaign::create([
            'name' => 'Interval test',
            'template_id' => $template->id,
            'status' => 'running',
            'daily_cap' => 3,
            'send_interval_seconds' => 120,
        ]);
        foreach (range(1, 3) as $number) {
            $contact = Contact::create(['email' => "interval{$number}@example.test", 'status' => 'active', 'permission_status' => 'legitimate_interest']);
            CampaignSend::create(['campaign_id' => $campaign->id, 'contact_id' => $contact->id, 'status' => 'queued']);
        }

        (new ProcessCampaignJob($campaign))->handle();

        Queue::assertPushed(SendEmailJob::class, 3);
        $jobs = Queue::pushed(SendEmailJob::class)->values();
        $this->assertNull($jobs[0]->delay);
        $this->assertSame('2030-01-07 17:02:00', $jobs[1]->delay->format('Y-m-d H:i:s'));
        $this->assertSame('2030-01-07 17:04:00', $jobs[2]->delay->format('Y-m-d H:i:s'));
        $this->assertSame(['sending'], $campaign->sends()->pluck('status')->unique()->values()->all());
    }

    private function mailbox(): MailboxSetting
    {
        $mailbox = MailboxSetting::current();
        $mailbox->update([
            'email' => 'sameer@digitelly.net',
            'display_name' => 'Sameer at Digitelly',
            'password' => 'secret',
            'physical_address' => '1 Main Street',
            'smtp_status' => 'connected',
            'imap_status' => 'connected',
            'daily_allowance' => 60,
            'automation_paused' => false,
        ]);

        return $mailbox;
    }

    private function template(): Template
    {
        return Template::create([
            'name' => 'Distributor outreach — orders and inventory',
            'subject' => 'Orders and inventory at {{company}}',
            'from_email' => 'sameer@digitelly.net',
            'html_body' => '<p>{{personalized_opener}}</p>',
            'text_body' => '{{personalized_opener}}',
        ]);
    }
}
