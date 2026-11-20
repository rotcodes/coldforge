<?php

namespace Tests\Feature;

use App\Jobs\ProcessCampaignJob;
use App\Models\Campaign;
use App\Models\Contact;
use App\Models\MailboxSetting;
use App\Models\MailMessage;
use App\Models\Suppression;
use App\Models\Template;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $user = User::create(['name' => 'Admin', 'email' => 'admin@test.dev', 'password' => 'secret-password']);
        $this->actingAs($user);
    }

    public function test_primary_workspace_pages_render(): void
    {
        $template = $this->template();
        $contact = Contact::create(['email' => 'lead@example.com']);
        foreach (['/', '/mail', '/contacts', "/contacts/{$contact->id}", '/templates', "/templates/{$template->id}/edit", '/campaigns', '/campaigns/create', '/sequences', '/intelligence', '/analytics', '/activity', '/settings/mailbox', '/settings/deliverability', '/settings/retention'] as $url) {
            $this->get($url)->assertOk();
        }
        $this->get('/settings/senders')->assertRedirect('/settings/mailbox');
    }

    public function test_mailbox_password_is_encrypted_and_never_returned(): void
    {
        $this->put('/settings/mailbox', [
            'email' => 'owner@example.com', 'display_name' => 'Owner', 'reply_to' => '', 'password' => 'mailbox-secret',
            'smtp_host' => 'smtp.hostinger.com', 'smtp_port' => 465, 'smtp_encryption' => 'ssl',
            'imap_host' => 'imap.hostinger.com', 'imap_port' => 993, 'imap_encryption' => 'ssl',
            'daily_allowance' => 100, 'timezone' => 'Asia/Karachi', 'signature_html' => '', 'signature_text' => '',
            'physical_address' => '1 Main Street', 'automation_paused' => false, 'track_campaign_opens' => true,
            'track_campaign_clicks' => true, 'ai_processing_enabled' => false, 'ai_model' => 'gpt-6.2-sol',
        ])->assertSessionHasNoErrors();

        $raw = \DB::table('mailbox_settings')->value('password');
        $this->assertStringNotContainsString('mailbox-secret', $raw);
        $this->get('/settings/mailbox')->assertOk()->assertDontSee('mailbox-secret');
        $this->assertSame('mailbox-secret', MailboxSetting::current()->password);
    }

    public function test_campaign_audience_requires_explicit_permission_and_launch_safeguards(): void
    {
        Queue::fake();
        $template = $this->template();
        Contact::create(['email' => 'eligible@example.com', 'permission_status' => 'consented']);
        Contact::create(['email' => 'unknown@example.com', 'permission_status' => 'unknown']);
        MailboxSetting::current()->update(['email' => 'owner@example.com', 'display_name' => 'Owner', 'password' => 'secret', 'physical_address' => '1 Main Street']);

        $this->getJson('/campaigns/count')->assertJsonPath('count', 1);
        $this->post('/campaigns', ['name' => 'Allowed audience', 'template_id' => $template->id, 'send_mode' => 'now', 'daily_cap' => 10])
            ->assertSessionHasNoErrors();
        $campaign = Campaign::first();
        $this->assertSame(1, $campaign->total_recipients);

        $this->post("/campaigns/{$campaign->id}/launch")->assertSessionHas('success');
        $this->assertSame('running', $campaign->fresh()->status);
        Queue::assertPushed(ProcessCampaignJob::class);
    }

    public function test_unsubscribe_creates_durable_suppression(): void
    {
        $contact = Contact::create(['email' => 'lead@example.com', 'permission_status' => 'consented']);
        $url = \URL::signedRoute('unsubscribe', ['contact' => $contact->id]);

        $this->get($url)->assertOk();

        $this->assertSame('suppressed', $contact->fresh()->permission_status);
        $this->assertTrue(Suppression::where('email', 'lead@example.com')->exists());
    }

    public function test_bulk_mail_actions_update_only_selected_messages(): void
    {
        $first = MailMessage::create(['direction' => 'incoming', 'status' => 'synced', 'subject' => 'One']);
        $second = MailMessage::create(['direction' => 'incoming', 'status' => 'synced', 'subject' => 'Two']);
        $third = MailMessage::create(['direction' => 'incoming', 'status' => 'synced', 'subject' => 'Three']);

        $this->post('/mail/messages/bulk-action', ['message_ids' => [$first->id, $second->id], 'action' => 'star'])
            ->assertSessionHas('success');

        $this->assertTrue($first->fresh()->is_starred);
        $this->assertTrue($second->fresh()->is_starred);
        $this->assertFalse($third->fresh()->is_starred);
    }

    public function test_html_only_mail_has_a_safe_readable_body(): void
    {
        $message = MailMessage::create([
            'direction' => 'incoming',
            'status' => 'synced',
            'subject' => 'HTML only',
            'html_body' => '<style>.hidden{display:none}</style><p>Hello&nbsp;<strong>Sam</strong>,</p><script>alert(1)</script><div>Can we talk tomorrow?</div>',
        ]);

        $this->assertSame("Hello Sam,\nCan we talk tomorrow?", $message->readable_body);
        $this->get("/mail?id={$message->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('selected.readable_body', "Hello Sam,\nCan we talk tomorrow?")
                ->where('messages.data.0.readable_body', "Hello Sam,\nCan we talk tomorrow?"));
    }

    private function template(): Template
    {
        return Template::create(['name' => 'Template', 'subject' => 'Hello', 'from_email' => 'owner@example.com', 'html_body' => '<p>Hello</p>', 'text_body' => 'Hello']);
    }
}
