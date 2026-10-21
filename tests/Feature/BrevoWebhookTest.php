<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\CampaignSend;
use App\Models\Contact;
use App\Models\Sender;
use App\Models\Template;
use App\Models\User;
use App\Models\WebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BrevoWebhookTest extends TestCase
{
    use RefreshDatabase;

    private string $secret;

    protected function setUp(): void
    {
        parent::setUp();
        $this->secret = 'test-webhook-secret-'.bin2hex(random_bytes(16));
        config(['coldforge.brevo_webhook_secret' => $this->secret]);
    }

    public function test_wrong_secret_is_404(): void
    {
        $this->postJson('/webhooks/brevo/wrong-secret', ['event' => 'delivered'])->assertNotFound();
        $this->assertSame(0, WebhookEvent::count());
    }

    public function test_empty_body_is_ok_but_no_event_stored(): void
    {
        $this->postJson("/webhooks/brevo/{$this->secret}", [])->assertOk();
        $this->assertSame(0, WebhookEvent::count());
    }

    public function test_delivered_event_logs_but_no_side_effect(): void
    {
        [, $send] = $this->makeCampaignWithSend();

        $this->postWebhook([
            'event' => 'delivered',
            'email' => $send->contact->email,
            'message-id' => '<test-msg@brevo>',
            'ts' => now()->timestamp,
        ])->assertOk();

        $this->assertSame(1, WebhookEvent::count());
        $this->assertSame('delivered', WebhookEvent::first()->event);
        // Contact + send untouched
        $this->assertSame('active', $send->contact->fresh()->status);
        $this->assertNull($send->fresh()->opened_at);
    }

    public function test_hard_bounce_marks_contact_bounced_and_updates_campaign(): void
    {
        [$campaign, $send] = $this->makeCampaignWithSend('bouncer@example.com');
        $send->update(['message_id' => '<bounce-abc@brevo>']);

        $this->postWebhook([
            'event' => 'hard_bounce',
            'email' => 'bouncer@example.com',
            'message-id' => '<bounce-abc@brevo>',
            'reason' => 'User unknown',
            'ts' => now()->timestamp,
        ])->assertOk();

        $this->assertSame('bounced', $send->contact->fresh()->status);
        $this->assertSame('bounced', $send->fresh()->status);
        $this->assertSame(1, $campaign->fresh()->bounced_count);
        $this->assertTrue($send->events()->where('type', 'bounced')->exists());
    }

    public function test_invalid_email_treated_as_hard_bounce(): void
    {
        [$campaign, $send] = $this->makeCampaignWithSend('bad@example.com');
        $send->update(['message_id' => '<invalid-xyz@brevo>']);

        $this->postWebhook([
            'event' => 'invalid_email',
            'email' => 'bad@example.com',
            'message-id' => '<invalid-xyz@brevo>',
        ])->assertOk();

        $this->assertSame('bounced', $send->contact->fresh()->status);
        $this->assertSame(1, $campaign->fresh()->bounced_count);
    }

    public function test_spam_marks_contact_complained_and_counts_unsub(): void
    {
        [$campaign, $send] = $this->makeCampaignWithSend('reporter@example.com');
        $send->update(['message_id' => '<spam-123@brevo>']);

        $this->postWebhook([
            'event' => 'spam',
            'email' => 'reporter@example.com',
            'message-id' => '<spam-123@brevo>',
        ])->assertOk();

        $this->assertSame('complained', $send->contact->fresh()->status);
        $this->assertSame(1, $campaign->fresh()->unsubscribed_count);
        $this->assertTrue($send->events()->where('type', 'complained')->exists());
    }

    public function test_unsubscribed_event_marks_contact_unsubscribed(): void
    {
        [$campaign, $send] = $this->makeCampaignWithSend('unsub@example.com');
        $send->update(['message_id' => '<unsub-abc@brevo>']);

        $this->postWebhook([
            'event' => 'unsubscribed',
            'email' => 'unsub@example.com',
            'message-id' => '<unsub-abc@brevo>',
        ])->assertOk();

        $this->assertSame('unsubscribed', $send->contact->fresh()->status);
        $this->assertSame(1, $campaign->fresh()->unsubscribed_count);
    }

    public function test_blocked_event_marks_contact_unsubscribed(): void
    {
        [, $send] = $this->makeCampaignWithSend('blocked@example.com');
        $send->update(['message_id' => '<blk-abc@brevo>']);

        $this->postWebhook([
            'event' => 'blocked',
            'email' => 'blocked@example.com',
            'message-id' => '<blk-abc@brevo>',
            'reason' => 'reputation',
        ])->assertOk();

        $this->assertSame('unsubscribed', $send->contact->fresh()->status);
    }

    public function test_opened_updates_send_and_increments_campaign_once(): void
    {
        [$campaign, $send] = $this->makeCampaignWithSend();
        $send->update(['message_id' => '<open-1@brevo>']);

        // First open counts
        $this->postWebhook(['event' => 'opened', 'email' => $send->contact->email, 'message-id' => '<open-1@brevo>'])->assertOk();
        $this->assertNotNull($send->fresh()->opened_at);
        $this->assertSame(1, $campaign->fresh()->opened_count);

        // Second open does NOT double-count
        $this->postWebhook(['event' => 'opened', 'email' => $send->contact->email, 'message-id' => '<open-1@brevo>'])->assertOk();
        $this->assertSame(1, $campaign->fresh()->opened_count);
    }

    public function test_click_updates_send_and_increments_per_click(): void
    {
        [$campaign, $send] = $this->makeCampaignWithSend();
        $send->update(['message_id' => '<click-1@brevo>']);

        $this->postWebhook([
            'event' => 'click',
            'email' => $send->contact->email,
            'message-id' => '<click-1@brevo>',
            'link' => 'https://example.com/landing',
        ])->assertOk();
        $this->postWebhook([
            'event' => 'click',
            'email' => $send->contact->email,
            'message-id' => '<click-1@brevo>',
            'link' => 'https://example.com/other',
        ])->assertOk();

        $this->assertNotNull($send->fresh()->first_clicked_at);
        $this->assertSame(2, $send->fresh()->clicked_count);
        // Campaign counter only ticks on FIRST click (not per click)
        $this->assertSame(1, $campaign->fresh()->clicked_count);
    }

    public function test_event_persists_even_when_message_id_unknown(): void
    {
        $this->postWebhook([
            'event' => 'delivered',
            'email' => 'orphan@example.com',
            'message-id' => '<no-match@brevo>',
        ])->assertOk();

        $this->assertSame(1, WebhookEvent::count());
        $this->assertNull(WebhookEvent::first()->campaign_send_id);
    }

    public function test_brevo_dashboard_page_renders(): void
    {
        $user = User::create(['name' => 'Admin', 'email' => 'admin@test.dev', 'password' => 'secret-password']);
        $this->actingAs($user);

        $this->get('/brevo')->assertOk()->assertInertia(fn ($p) => $p->component('Brevo/Index'));
    }

    // --- helpers --------------------------------------------------------

    private function postWebhook(array $payload)
    {
        return $this->postJson("/webhooks/brevo/{$this->secret}", $payload);
    }

    /** @return array{0: Campaign, 1: CampaignSend} */
    private function makeCampaignWithSend(string $email = 'alice@example.com'): array
    {
        Sender::create(['name' => 'Brevo', 'provider' => 'brevo', 'config' => ['api_key' => 'k'], 'daily_quota' => 300, 'priority' => 1]);
        $template = Template::create(['name' => 'T', 'subject' => 'S', 'from_email' => 'me@digitelly.net', 'html_body' => '<p>x</p>']);
        $contact = Contact::create(['email' => $email]);
        $campaign = Campaign::create(['name' => 'C', 'template_id' => $template->id, 'daily_cap' => 50]);
        $send = CampaignSend::create(['campaign_id' => $campaign->id, 'contact_id' => $contact->id, 'status' => 'sent', 'sent_at' => now()]);

        return [$campaign, $send];
    }
}
