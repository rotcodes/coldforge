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
use Illuminate\Support\Facades\Http;
use RuntimeException;
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

    public function test_long_body_url_and_subject_are_stored(): void
    {
        $url = 'https://mail.hostinger.com/body?sig='.str_repeat('a', 2000);
        $subject = 'Re:'.str_repeat(' long subject', 40);
        $this->fire($this->payload(['eventId' => 'long-1', 'bodyUrl' => $url, 'subject' => $subject]))->assertOk();

        $msg = InboxMessage::first();
        $this->assertSame($url, $msg->body_url);
        $this->assertSame($subject, $msg->subject);
    }

    public function test_bearer_token_required_when_configured(): void
    {
        config(['coldforge.hostinger_mail_webhook_token' => 'hm-token']);

        $this->fire($this->payload(['eventId' => 'tok-0']))->assertStatus(401);
        $this->fire($this->payload(['eventId' => 'tok-1']), 'wrong')->assertStatus(401);
        $this->assertSame(0, InboxMessage::count());

        $this->fire($this->payload(['eventId' => 'tok-2']), 'hm-token')->assertOk();
        $this->assertSame(1, InboxMessage::count());
    }

    public function test_matches_via_references_when_in_reply_to_is_unknown(): void
    {
        [$campaign, $send] = $this->makeSendWith('carol@example.com', '<orig-2@smtp-relay.mailin.fr>');

        $this->fire($this->payload([
            'eventId' => 'ref-1',
            'from' => 'Someone Else <assistant@example.com>',
            'inReplyTo' => '<mangled@client>',
            'references' => ['<older@thread>', '<orig-2@smtp-relay.mailin.fr>'],
        ]))->assertOk();

        $this->assertSame($send->id, InboxMessage::first()->campaign_send_id);
        $this->assertSame(1, $campaign->fresh()->replied_count);
    }

    public function test_internal_error_is_logged_and_answered_200(): void
    {
        InboxMessage::creating(fn () => throw new RuntimeException('db down'));

        $this->fire($this->payload(['eventId' => 'boom']))->assertOk()->assertSee('logged');
    }

    public function test_full_body_is_fetched_from_body_url(): void
    {
        Http::fake(['storage.googleapis.com/*' => Http::response(['plainText' => 'Full reply text', 'html' => '<p>Full reply text</p>'])]);

        $this->fire($this->payload(['eventId' => 'body-1', 'bodyUrl' => 'https://storage.googleapis.com/hmail-webhook/eml/x.json?Signature=s']))->assertOk();

        $msg = InboxMessage::first();
        $this->assertSame('Full reply text', $msg->plain_body);
        $this->assertSame('<p>Full reply text</p>', $msg->html_body);
        $this->assertNotNull($msg->body_fetched_at);
    }

    public function test_failed_body_fetch_keeps_message_and_side_effects(): void
    {
        Http::fake(['storage.googleapis.com/*' => Http::response('expired', 403)]);
        $contact = Contact::create(['email' => 'dave@example.com', 'status' => 'active']);

        $this->fire($this->payload(['eventId' => 'body-2', 'from' => 'dave@example.com', 'bodyUrl' => 'https://storage.googleapis.com/hmail-webhook/eml/y.json']))->assertOk();

        $this->assertNull(InboxMessage::first()->plain_body);
        $this->assertSame('replied', $contact->fresh()->status);
    }

    public function test_inbox_page_renders(): void
    {
        $user = User::create(['name' => 'Admin', 'email' => 'admin@test.dev', 'password' => 'secret-password']);
        $this->actingAs($user);
        $this->get('/inbox')->assertOk()->assertInertia(fn ($p) => $p->component('Inbox/Index'));
    }

    // --- helpers --------------------------------------------------------

    private function fire(?array $payload = null, ?string $bearer = null)
    {
        $headers = $bearer ? ['Authorization' => "Bearer {$bearer}"] : [];

        return $this->postJson("/webhooks/hmail/{$this->secret}", $payload ?? $this->payload([]), $headers);
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
