<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\CampaignSend;
use App\Models\Contact;
use App\Models\Sender;
use App\Models\Template;
use App\Models\User;
use App\Services\EmailRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ColdForgeTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::create(['name' => 'Admin', 'email' => 'admin@test.dev', 'password' => 'secret-password']);
    }

    public function test_guests_see_login_and_can_sign_in(): void
    {
        $this->get('/')->assertRedirect('/login');
        $this->get('/login')->assertOk();
        $this->post('/login', ['email' => 'admin@test.dev', 'password' => 'secret-password'])->assertRedirect('/');
        $this->get('/')->assertOk();
    }

    public function test_pages_render(): void
    {
        $this->seed();
        $this->actingAs($this->user);
        $t = Template::first();

        foreach (['/', '/contacts', '/contacts/'.Contact::first()->id, '/templates', '/templates/create', "/templates/{$t->id}/edit",
            '/campaigns', '/campaigns/create', '/settings/senders', '/settings/profile', '/settings/deliverability'] as $url) {
            $this->get($url)->assertOk();
        }
    }

    public function test_csv_import(): void
    {
        Storage::fake();
        $this->actingAs($this->user);
        $csv = UploadedFile::fake()->createWithContent('leads.csv', file_get_contents(database_path('samples/sample_contacts.csv'))."not-an-email,X,Y,Z,US,saas,Software\n");

        $preview = $this->post('/csv-imports', ['file' => $csv])->assertOk()->json();
        $this->assertSame(13, $preview['total_rows']);
        $this->assertCount(10, $preview['rows']);

        $mapping = ['email' => 'email', 'first_name' => 'first_name', 'last_name' => 'last_name', 'company' => 'company',
            'country' => 'country', 'tags' => 'tags', 'industry' => 'custom:industry'];
        $this->postJson("/csv-imports/{$preview['id']}/start", ['mapping' => $mapping, 'tags' => ['imported']])->assertOk();

        $status = $this->getJson("/api/csv-imports/{$preview['id']}")->json();
        $this->assertSame('done', $status['status']);
        $this->assertSame(12, $status['imported_count']);
        $this->assertSame(1, $status['failed_count']);

        $ava = Contact::firstWhere('email', 'ava.chen@example.com');
        $this->assertEqualsCanonicalizing(['imported', 'saas', 'founders'], $ava->tags);
        $this->assertSame('Software', $ava->custom_fields['industry']);
        $this->assertSame(3, Contact::withAnyTag(['founders'])->count());
    }

    public function test_campaign_launch_sends_via_brevo_and_tracking_works(): void
    {
        Http::fake(['api.brevo.com/v3/smtp/email' => Http::response(['messageId' => '<abc@smtp-relay.brevo.com>'], 201)]);
        $this->actingAs($this->user);

        Sender::create(['name' => 'Brevo', 'provider' => 'brevo', 'config' => ['api_key' => 'test-key'], 'daily_quota' => 300, 'priority' => 1]);
        $template = Template::create([
            'name' => 'T', 'subject' => 'Hi {{first_name}}', 'from_email' => 'me@digitelly.net', 'from_name' => 'Me',
            'html_body' => '<p>Hello {{first_name}} at {{company}}</p><a href="https://example.org/page?a=1&amp;b=2">link</a>',
        ]);
        $this->assertSame(['first_name', 'company'], $template->variables);

        Contact::create(['email' => 'a@example.com', 'first_name' => 'Ann', 'company' => 'Acme', 'tags' => ['vip']]);
        Contact::create(['email' => 'b@example.com', 'first_name' => 'Bob', 'tags' => ['vip']]);
        Contact::create(['email' => 'c@example.com', 'first_name' => 'Cid', 'tags' => ['other']]);
        Contact::create(['email' => 'd@example.com', 'tags' => ['vip'], 'status' => 'unsubscribed']);

        $this->getJson('/campaigns/count?tags[]=vip')->assertJson(['count' => 2]);

        $this->post('/campaigns', [
            'name' => 'VIP push', 'template_id' => $template->id, 'tags' => ['vip'], 'send_mode' => 'now',
            'daily_cap' => 50, 'sender_strategy' => 'round_robin',
        ])->assertRedirect();

        $campaign = Campaign::first();
        $this->assertSame('draft', $campaign->status);
        $this->assertSame(2, $campaign->total_recipients);
        $this->assertSame(2, CampaignSend::where('status', 'queued')->count());

        $this->post("/campaigns/{$campaign->id}/launch")->assertRedirect();

        $send = CampaignSend::whereHas('contact', fn ($q) => $q->where('email', 'a@example.com'))->first();
        $this->assertSame('sent', $send->status);
        $this->assertNotNull($send->sent_at);
        $this->assertSame('<abc@smtp-relay.brevo.com>', $send->message_id);
        $this->assertSame(2, $campaign->fresh()->sent_count);
        $this->assertSame(2, Sender::first()->sent_today);
        $this->assertTrue($send->events()->where('type', 'sent')->exists());

        Http::assertSent(function ($request) use ($campaign) {
            $body = $request->data();
            if (($body['to'][0]['email'] ?? null) !== 'a@example.com') {
                return false;
            }

            return $request->hasHeader('api-key', 'test-key')
                && $body['to'][0]['email'] === 'a@example.com'
                && $body['subject'] === 'Hi Ann'
                && str_contains($body['htmlContent'], 'Hello Ann at Acme')
                && str_contains($body['htmlContent'], '/t/o/')
                && str_contains($body['htmlContent'], '/t/c/')
                && str_contains($body['htmlContent'], 'Unsubscribe')
                && str_starts_with($body['headers']['List-Unsubscribe'], '<http')
                && $body['tags'] === ['campaign-'.$campaign->id];
        });

        // Tracking: pull real URLs out of the rendered email
        $html = EmailRenderer::render($template, $send->contact, $send)['html'];
        preg_match('/src="([^"]+\/t\/o\/[^"]+)"/', $html, $open);
        preg_match('/href="([^"]+\/t\/c\/[^"]+)"/', $html, $click);
        preg_match('/href="([^"]+\/u\/[^"]+)"/', $html, $unsub);

        $this->get(html_entity_decode($open[1]))->assertOk()->assertHeader('Content-Type', 'image/gif');
        $this->assertNotNull($send->fresh()->opened_at);
        $this->assertSame(1, $campaign->fresh()->opened_count);

        $this->get(html_entity_decode($click[1]))->assertRedirect('https://example.org/page?a=1&b=2');
        $this->assertSame(1, $send->fresh()->clicked_count);
        $this->assertSame(1, $campaign->fresh()->clicked_count);

        $this->get(html_entity_decode($unsub[1]))->assertOk()->assertSee('unsubscribed');
        $this->assertSame('unsubscribed', $send->contact->fresh()->status);
        $this->assertSame(1, $campaign->fresh()->unsubscribed_count);

        // Tampered signature is rejected
        $this->get(str_replace('signature=', 'signature=x', html_entity_decode($open[1])))->assertForbidden();
    }

    public function test_daily_cap_and_pause(): void
    {
        Http::fake(['api.brevo.com/*' => Http::response(['messageId' => 'm'], 201)]);
        $this->actingAs($this->user);
        Sender::create(['name' => 'Brevo', 'provider' => 'brevo', 'config' => ['api_key' => 'k'], 'daily_quota' => 300, 'priority' => 1]);
        $template = Template::create(['name' => 'T', 'subject' => 'S', 'from_email' => 'me@digitelly.net', 'html_body' => '<p>x</p>']);
        foreach (range(1, 5) as $i) {
            Contact::create(['email' => "u$i@example.com"]);
        }

        $this->post('/campaigns', ['name' => 'Capped', 'template_id' => $template->id, 'send_mode' => 'now', 'daily_cap' => 2, 'sender_strategy' => 'priority']);
        $campaign = Campaign::first();
        $this->post("/campaigns/{$campaign->id}/launch");

        $this->assertSame(2, $campaign->fresh()->sent_count);
        $this->assertSame(3, $campaign->sends()->where('status', 'queued')->count());

        // Scheduler tick on the same day sends nothing more
        (new \App\Jobs\DispatchDueCampaignsJob)->handle();
        $this->assertSame(2, $campaign->fresh()->sent_count);

        $this->post("/campaigns/{$campaign->id}/pause");
        $this->assertSame('paused', $campaign->fresh()->status);
    }

    public function test_failed_send_is_recorded_without_retry_on_4xx(): void
    {
        Http::fake(['api.brevo.com/*' => Http::response(['message' => 'Invalid sender'], 400)]);
        $this->actingAs($this->user);
        Sender::create(['name' => 'Brevo', 'provider' => 'brevo', 'config' => ['api_key' => 'k'], 'daily_quota' => 300, 'priority' => 1]);
        $template = Template::create(['name' => 'T', 'subject' => 'S', 'from_email' => 'me@digitelly.net', 'html_body' => '<p>x</p>']);
        Contact::create(['email' => 'x@example.com']);

        $this->post('/campaigns', ['name' => 'Bad', 'template_id' => $template->id, 'send_mode' => 'now', 'daily_cap' => 10, 'sender_strategy' => 'round_robin']);
        $this->post('/campaigns/'.Campaign::first()->id.'/launch');

        $send = CampaignSend::first();
        $this->assertSame('failed', $send->status);
        $this->assertStringContainsString('Invalid sender', $send->error);
        $this->assertSame(0, Sender::first()->sent_today); // reserved quota released
    }
}
