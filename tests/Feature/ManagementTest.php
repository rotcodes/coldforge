<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Contact;
use App\Models\Sender;
use App\Models\Template;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** CRUD + settings paths not covered by the send-pipeline tests. */
class ManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::create(['name' => 'Admin', 'email' => 'admin@test.dev', 'password' => 'secret-password']);
        $this->actingAs($this->user);
    }

    public function test_login_rejects_bad_password_and_throttles(): void
    {
        auth()->logout();
        foreach (range(1, 5) as $_) {
            $this->post('/login', ['email' => 'admin@test.dev', 'password' => 'wrong'])->assertSessionHasErrors('email');
        }
        $this->post('/login', ['email' => 'admin@test.dev', 'password' => 'secret-password'])->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_contact_crud_and_validation(): void
    {
        $this->post('/contacts', ['email' => 'not-an-email', 'status' => 'active'])->assertSessionHasErrors('email');

        $this->post('/contacts', ['email' => 'New@Example.com', 'first_name' => 'New', 'status' => 'active', 'tags' => [' vip ', 'vip', 'beta']])
            ->assertSessionHasNoErrors();
        $contact = Contact::firstWhere('email', 'new@example.com');
        $this->assertSame(['vip', 'beta'], $contact->tags);
        $this->assertSame('manual', $contact->source);

        $this->post('/contacts', ['email' => 'new@example.com', 'status' => 'active'])->assertSessionHasErrors('email'); // unique

        $this->put("/contacts/{$contact->id}", ['email' => 'new@example.com', 'company' => 'Acme', 'status' => 'bounced', 'tags' => []])
            ->assertSessionHasNoErrors();
        $this->assertSame('bounced', $contact->fresh()->status);
        $this->assertSame('Acme', $contact->fresh()->company);

        $this->get('/contacts?q=acme')->assertOk()->assertSee('new@example.com');

        $this->delete("/contacts/{$contact->id}")->assertRedirect('/contacts');
        $this->assertModelMissing($contact);
    }

    public function test_template_crud_detects_variables_and_blocks_delete_when_used(): void
    {
        $this->post('/templates', ['name' => 'T', 'subject' => 'x', 'from_email' => 'bad'])->assertSessionHasErrors(['from_email', 'html_body']);

        $this->post('/templates', [
            'name' => 'Intro', 'subject' => 'Hi {{ first_name }}', 'from_email' => 'me@digitelly.net',
            'html_body' => '<p>{{company}} {{industry}}</p>',
        ])->assertRedirect();
        $template = Template::firstWhere('name', 'Intro');
        $this->assertSame(['first_name', 'company', 'industry'], $template->variables);

        $this->put("/templates/{$template->id}", [...$template->only('name', 'from_email', 'html_body'), 'subject' => 'Changed'])->assertSessionHasNoErrors();
        $this->assertSame('Changed', $template->fresh()->subject);

        Campaign::create(['name' => 'C', 'template_id' => $template->id]);
        $this->delete("/templates/{$template->id}")->assertSessionHas('error');
        $this->assertModelExists($template);
    }

    public function test_quick_send_uses_first_active_sender(): void
    {
        Http::fake(['api.brevo.com/*' => Http::response(['messageId' => 'q1'], 201)]);
        $template = Template::create(['name' => 'T', 'subject' => 'Hello {{first_name}}', 'from_email' => 'me@digitelly.net', 'html_body' => '<p>Hi</p>']);

        $this->post("/templates/{$template->id}/test", ['email' => 'me@inbox.dev'])->assertSessionHas('error'); // no sender yet

        Sender::create(['name' => 'Brevo', 'provider' => 'brevo', 'config' => ['api_key' => 'k'], 'daily_quota' => 10, 'priority' => 1]);
        $this->post("/templates/{$template->id}/test", ['email' => 'me@inbox.dev'])->assertSessionHas('success');

        Http::assertSent(fn ($r) => $r['subject'] === '[Test] Hello Friend' && $r['to'][0]['email'] === 'me@inbox.dev');
    }

    public function test_sender_secrets_are_encrypted_masked_and_kept_on_blank_update(): void
    {
        $this->post('/settings/senders', [
            'name' => 'Brevo', 'provider' => 'brevo', 'daily_quota' => 300, 'priority' => 1, 'config' => ['api_key' => 'xkeysib-secret'],
        ])->assertSessionHasNoErrors();
        $sender = Sender::first();

        $raw = \DB::table('senders')->value('config');
        $this->assertStringNotContainsString('xkeysib-secret', $raw);

        $page = $this->get('/settings/senders')->assertOk();
        $this->assertStringNotContainsString('xkeysib-secret', $page->getContent());

        $this->put("/settings/senders/{$sender->id}", [
            'name' => 'Brevo main', 'provider' => 'brevo', 'daily_quota' => 500, 'priority' => 2, 'config' => ['api_key' => '********'],
        ])->assertSessionHasNoErrors();
        $sender->refresh();
        $this->assertSame('xkeysib-secret', $sender->config['api_key']);
        $this->assertSame(500, $sender->daily_quota);

        $this->post('/settings/senders', ['name' => 'SMTP', 'provider' => 'smtp_generic', 'daily_quota' => 100, 'priority' => 3, 'config' => []])
            ->assertSessionHasErrors(['config.host', 'config.port']);

        $this->post("/settings/senders/{$sender->id}/toggle");
        $this->assertFalse($sender->fresh()->is_active);

        Http::fake(['api.brevo.com/v3/account' => Http::sequence()->push(['email' => 'me@digitelly.net'], 200)->push([], 401)]);
        $this->post("/settings/senders/{$sender->id}/test")->assertSessionHas('success');
        $this->post("/settings/senders/{$sender->id}/test")->assertSessionHas('error');

        $this->delete("/settings/senders/{$sender->id}");
        $this->assertModelMissing($sender);
    }

    public function test_brevo_can_connect_over_smtp_or_api(): void
    {
        $this->post('/settings/senders', [
            'name' => 'Brevo SMTP', 'provider' => 'brevo', 'daily_quota' => 300, 'priority' => 1, 'config' => ['method' => 'smtp'],
        ])->assertSessionHasErrors(['config.host', 'config.port', 'config.username', 'config.password']);

        $this->post('/settings/senders', [
            'name' => 'Brevo SMTP', 'provider' => 'brevo', 'daily_quota' => 300, 'priority' => 1,
            'config' => ['method' => 'smtp', 'api_key' => 'stray', 'host' => 'smtp-relay.brevo.com', 'port' => 587, 'username' => 'me@digitelly.net', 'password' => 'smtp-key', 'encryption' => 'tls'],
        ])->assertSessionHasNoErrors();

        $sender = Sender::first();
        $this->assertSame('smtp', \App\Services\Senders\SenderRegistry::method($sender));
        $this->assertInstanceOf(\App\Services\Senders\SmtpSender::class, \App\Services\Senders\SenderRegistry::make($sender));
        $this->assertArrayNotHasKey('api_key', $sender->config); // only SMTP keys stored

        // Edit with masked password keeps the stored SMTP key
        $this->put("/settings/senders/{$sender->id}", [
            'name' => 'Brevo SMTP', 'provider' => 'brevo', 'daily_quota' => 300, 'priority' => 1,
            'config' => ['method' => 'smtp', 'host' => 'smtp-relay.brevo.com', 'port' => 587, 'username' => 'me@digitelly.net', 'password' => '********'],
        ])->assertSessionHasNoErrors();
        $this->assertSame('smtp-key', $sender->fresh()->config['password']);

        // Switch to API
        $this->put("/settings/senders/{$sender->id}", [
            'name' => 'Brevo API', 'provider' => 'brevo', 'daily_quota' => 300, 'priority' => 1, 'config' => ['method' => 'api', 'api_key' => 'xkeysib-1'],
        ])->assertSessionHasNoErrors();
        $this->assertInstanceOf(\App\Services\Senders\BrevoSender::class, \App\Services\Senders\SenderRegistry::make($sender->fresh()));

        // Providers without an API driver are always SMTP, even if "api" is sent
        $this->post('/settings/senders', ['name' => 'Mailjet', 'provider' => 'mailjet', 'daily_quota' => 200, 'priority' => 2, 'config' => ['method' => 'api']])
            ->assertSessionHasErrors('config.host');
    }

    public function test_sender_quota_resets_daily_and_priority_strategy(): void
    {
        $a = Sender::create(['name' => 'A', 'provider' => 'brevo', 'config' => [], 'daily_quota' => 5, 'sent_today' => 5, 'reset_at' => today(), 'priority' => 1]);
        $b = Sender::create(['name' => 'B', 'provider' => 'brevo', 'config' => [], 'daily_quota' => 5, 'sent_today' => 1, 'reset_at' => today(), 'priority' => 2]);

        $this->assertSame($b->id, \App\Services\Senders\SenderRegistry::pick('priority')->id); // A is full

        $a->update(['reset_at' => today()->subDay()]);
        $this->assertSame($a->id, \App\Services\Senders\SenderRegistry::pick('priority')->id); // new day, A resets
        $this->assertSame(0, $a->fresh()->sent_today);
    }

    public function test_profile_and_password_update(): void
    {
        $this->put('/settings/profile', ['name' => 'Sam', 'email' => 'sam@digitelly.net'])->assertSessionHasNoErrors();
        $this->assertSame('sam@digitelly.net', $this->user->fresh()->email);

        $this->put('/settings/password', ['current_password' => 'wrong', 'password' => 'new-password-123', 'password_confirmation' => 'new-password-123'])
            ->assertSessionHasErrors('current_password');
        $this->put('/settings/password', ['current_password' => 'secret-password', 'password' => 'short', 'password_confirmation' => 'short'])
            ->assertSessionHasErrors('password');
        $this->put('/settings/password', ['current_password' => 'secret-password', 'password' => 'new-password-123', 'password_confirmation' => 'new-password-123'])
            ->assertSessionHasNoErrors();
        $this->assertTrue(Hash::check('new-password-123', $this->user->fresh()->password));
    }

    public function test_campaign_requires_matching_contacts_and_schedules_for_later(): void
    {
        $template = Template::create(['name' => 'T', 'subject' => 'S', 'from_email' => 'me@digitelly.net', 'html_body' => '<p>x</p>']);

        $this->post('/campaigns', ['name' => 'Empty', 'template_id' => $template->id, 'send_mode' => 'now', 'daily_cap' => 10, 'sender_strategy' => 'round_robin'])
            ->assertSessionHasErrors('tags');

        Contact::create(['email' => 'a@example.com']);
        $this->post('/campaigns', [
            'name' => 'Later', 'template_id' => $template->id, 'send_mode' => 'later', 'schedule_at' => now()->addDay()->toIso8601String(),
            'daily_cap' => 10, 'sender_strategy' => 'round_robin',
        ])->assertSessionHasNoErrors();

        $campaign = Campaign::first();
        $this->post("/campaigns/{$campaign->id}/launch");
        $this->assertSame('scheduled', $campaign->fresh()->status);

        // Not due yet: scheduler leaves it alone
        (new \App\Jobs\DispatchDueCampaignsJob)->handle();
        $this->assertSame('scheduled', $campaign->fresh()->status);

        // Due: scheduler flips it to running
        $campaign->update(['schedule_at' => now()->subMinute()]);
        (new \App\Jobs\DispatchDueCampaignsJob)->handle();
        $this->assertContains($campaign->fresh()->status, ['running', 'completed']);
    }

    public function test_click_proxy_rejects_non_http_targets(): void
    {
        Contact::create(['email' => 'a@example.com']);
        $template = Template::create(['name' => 'T', 'subject' => 'S', 'from_email' => 'me@digitelly.net', 'html_body' => '<p>x</p>']);
        $campaign = Campaign::create(['name' => 'C', 'template_id' => $template->id]);
        $send = $campaign->sends()->create(['contact_id' => Contact::first()->id]);

        $url = \URL::signedRoute('track.click', ['send' => $send->id, 'u' => rtrim(strtr(base64_encode('javascript:alert(1)'), '+/', '-_'), '=')]);
        $this->get($url)->assertNotFound();
    }

    public function test_deliverability_page_rejects_garbage_domain(): void
    {
        $this->get('/settings/deliverability?domain=not a domain!')->assertSessionHasErrors('domain');
    }
}
