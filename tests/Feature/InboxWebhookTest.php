<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\CampaignSend;
use App\Models\Contact;
use App\Models\InboxMessage;
use App\Models\Sender;
use App\Models\Template;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InboxWebhookTest extends TestCase
{
    use RefreshDatabase;

    private string $secret;

    protected function setUp(): void
    {
        parent::setUp();
        $this->secret = 'test-hmail-secret';
        config(['coldforge.hostinger_mail_webhook_secret' => $this->secret]);
    }

    public function test_wrong_secret_404(): void
    {
        $this->postJson('/webhooks/hmail/wrong', ['event' => 'message.received'])->assertNotFound();
        $this->assertSame(0, InboxMessage::count());
    }

    public function test_non_message_received_event_ignored(): void
    {
        $this->postJson("/webhooks/hmail/{$this->secret}", ['event' => 'other'])->assertOk();
        $this->assertSame(0, InboxMessage::count());
    }

    public function test_duplicate_event_id_is_deduped(): void
    {
        $payload = $this->payload(['eventId' => 'same-id', 'from' => 'A <a@b.co>']);
        $this->fire($payload)->assertOk();
        $this->fire($payload)->assertOk(); // second POST with same eventId
        $this->assertSame(1, InboxMessage::count());
    }

    public function test_matches_reply_to_campaign_send(): void
    {
        [$campaign, $send, $contact] = $this->makeSendWith('alice@example.com', '<original-msg-id@brevo>');

        $this->fire($this->payload([
            'eventId' => 'reply-1',
            'from' => 'Alice <alice@example.com>',
            'inReplyTo' => '<original-msg-id@brevo>',
            'subject' => 'Re: Quick question',
            'plainBody' => 'Yes, interested',
        ]))->assertOk();

        $msg = InboxMessage::first();
        $this->assertSame($send->id, $msg->campaign_send_id);
        $this->assertSame($contact->id, $msg->contact_id);
        $this->assertSame('alice@example.com', $msg->from_email);
        $this->assertSame('Alice', $msg->from_name);
        $this->assertSame(1, $campaign->fresh()->replied_count);
        $this->assertSame('replied', $contact->fresh()->status);
        $this->assertTrue($send->events()->where('type', 'replied')->exists());
    }

    public function test_orphan_message_still_stored_without_links(): void
    {
        $this->fire($this->payload([
            'eventId' => 'orphan-1',
            'from' => 'Stranger <noone@nowhere.co>',
            'inReplyTo' => '<unknown@brevo>',
            'subject' => 'random',
        ]))->assertOk();

        $msg = InboxMessage::first();
        $this->assertNull($msg->campaign_send_id);
        $this->assertNull($msg->contact_id);
        $this->assertSame('noone@nowhere.co', $msg->from_email);
    }

    public function test_from_without_name_parses(): void
    {
        $this->fire($this->payload(['eventId' => 'noname', 'from' => 'bare@example.com']))->assertOk();
        $msg = InboxMessage::first();
        $this->assertSame('bare@example.com', $msg->from_email);
        $this->assertNull($msg->from_name);
    }

    public function test_active_contact_flips_to_replied_but_bounced_stays_bounced(): void
    {
        $bouncer = Contact::create(['email' => 'bouncer@example.com', 'status' => 'bounced']);
        $this->fire($this->payload(['eventId' => 'b-1', 'from' => 'B <bouncer@example.com>']))->assertOk();
        $this->assertSame('bounced', $bouncer->fresh()->status, 'Bounced contacts should not revert to replied.');
    }

    public function test_inbox_page_renders(): void
    {
        $user = User::create(['name' => 'Admin', 'email' => 'admin@test.dev', 'password' => 'secret-password']);
        $this->actingAs($user);
        $this->get('/inbox')->assertOk()->assertInertia(fn ($p) => $p->component('Inbox/Index'));
    }

    // --- helpers --------------------------------------------------------

    private function fire(?array $payload = null)
    {
        return $this->postJson("/webhooks/hmail/{$this->secret}", $payload ?? $this->payload([]));
    }

    private function payload(array $overrides): array
    {
        $data = array_merge([
            'eventId' => 'evt-'.bin2hex(random_bytes(4)),
            'mailboxAddress' => 'me@digitelly.net',
            'messageId' => '<reply@mail.co>',
            'inReplyTo' => '',
            'references' => [],
            'subject' => 'Re:',
            'from' => 'X <x@x.co>',
            'to' => ['Admin <me@digitelly.net>'],
            'cc' => [],
            'date' => now()->toRfc2822String(),
            'plainBody' => 'hi',
            'htmlBody' => '',
            'bodyUrl' => null,
            'attachments' => [],
        ], $overrides);

        return [
            'id' => 'delivery-'.bin2hex(random_bytes(4)),
            'event' => 'message.received',
            'timestamp' => now()->toIso8601String(),
            'data' => $data,
        ];
    }

    private function makeSendWith(string $email, string $messageId): array
    {
        Sender::create(['name' => 'Brevo', 'provider' => 'brevo', 'config' => ['api_key' => 'k'], 'daily_quota' => 300, 'priority' => 1]);
        $template = Template::create(['name' => 'T', 'subject' => 'S', 'from_email' => 'me@digitelly.net', 'html_body' => '<p>x</p>']);
        $contact = Contact::create(['email' => $email, 'status' => 'active']);
        $campaign = Campaign::create(['name' => 'C', 'template_id' => $template->id, 'daily_cap' => 50]);
        $send = CampaignSend::create([
            'campaign_id' => $campaign->id,
            'contact_id' => $contact->id,
            'status' => 'sent',
            'sent_at' => now(),
            'message_id' => $messageId,
        ]);

        return [$campaign, $send, $contact];
    }
}
