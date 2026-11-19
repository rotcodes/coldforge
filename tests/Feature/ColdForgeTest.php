<?php

namespace Tests\Feature;

use App\Jobs\DispatchSequenceStepsJob;
use App\Jobs\SendMailMessageJob;
use App\Models\AiClassification;
use App\Models\AiConversation;
use App\Models\AiRequest;
use App\Models\Campaign;
use App\Models\CampaignSend;
use App\Models\Contact;
use App\Models\MailboxSetting;
use App\Models\MailMessage;
use App\Models\Sequence;
use App\Models\SequenceEnrollment;
use App\Models\Template;
use App\Models\User;
use App\Services\Deliverability\InboundSignalProcessor;
use App\Services\EmailRenderer;
use App\Services\Mailbox\HostingerMailer;
use App\Services\Mailbox\MailDeliveryResult;
use App\Services\Mailbox\OutboundMailService;
use App\Services\Sequences\SequenceScheduler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class ColdForgeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $user = User::create(['name' => 'Admin', 'email' => 'admin@test.dev', 'password' => 'secret-password']);
        $this->actingAs($user);
    }

    public function test_unified_outbound_service_adds_signature_and_sends_once(): void
    {
        $mailbox = $this->mailbox();
        $mailbox->update(['signature_text' => "Sameer\nDigitelly"]);
        $message = app(OutboundMailService::class)->create([
            'subject' => 'Hello', 'plain_body' => 'Body', 'source_type' => 'personal', 'idempotency_key' => 'once',
        ], [['type' => 'to', 'email' => 'client@example.com']], 'send');

        $this->assertStringContainsString("--\nSameer", $message->plain_body);
        $mailer = Mockery::mock(HostingerMailer::class);
        $mailer->shouldReceive('send')->once()->andReturn(MailDeliveryResult::accepted());
        $this->app->instance(HostingerMailer::class, $mailer);

        SendMailMessageJob::dispatchSync($message);
        SendMailMessageJob::dispatchSync($message->fresh());

        $this->assertSame('sent', $message->fresh()->status);
        $this->assertSame(1, $mailbox->fresh()->sent_today);
    }

    public function test_campaign_tracking_respects_privacy_controls_and_address(): void
    {
        $this->mailbox()->update(['track_campaign_opens' => false, 'track_campaign_clicks' => false, 'physical_address' => '1 Main Street, Karachi']);
        $template = $this->template('<a href="https://example.com">Visit</a>');
        $contact = Contact::create(['email' => 'lead@example.com', 'permission_status' => 'consented']);
        $campaign = Campaign::create(['name' => 'Campaign', 'template_id' => $template->id]);
        $send = CampaignSend::create(['campaign_id' => $campaign->id, 'contact_id' => $contact->id]);

        $mail = EmailRenderer::render($template, $contact, $send);

        $this->assertStringContainsString('href="https://example.com"', $mail['html']);
        $this->assertStringNotContainsString('/t/o/', $mail['html']);
        $this->assertStringContainsString('1 Main Street, Karachi', $mail['html']);
        $this->assertStringContainsString('Unsubscribe', $mail['html']);
    }

    public function test_due_sequence_stops_immediately_when_contact_replied(): void
    {
        $template = $this->template();
        $contact = Contact::create(['email' => 'lead@example.com', 'status' => 'replied', 'permission_status' => 'consented', 'last_replied_at' => now()]);
        $sequence = Sequence::create(['name' => 'Follow up', 'status' => 'active']);
        $sequence->steps()->create(['template_id' => $template->id, 'position' => 1, 'delay_days' => 1]);
        $campaign = Campaign::create(['name' => 'Campaign', 'template_id' => $template->id, 'sequence_id' => $sequence->id, 'status' => 'completed']);
        $send = CampaignSend::create(['campaign_id' => $campaign->id, 'contact_id' => $contact->id, 'status' => 'sent']);
        $enrollment = SequenceEnrollment::create([
            'sequence_id' => $sequence->id, 'campaign_send_id' => $send->id, 'contact_id' => $contact->id,
            'status' => 'active', 'next_step_position' => 1, 'next_send_at' => now()->subMinute(),
        ]);

        app(DispatchSequenceStepsJob::class)->handle(app(OutboundMailService::class), app(SequenceScheduler::class));

        $this->assertSame('stopped', $enrollment->fresh()->status);
        $this->assertSame('recipient_replied', $enrollment->fresh()->stop_reason);
        $this->assertSame(0, MailMessage::where('source_type', 'sequence')->count());
    }

    public function test_ai_assistance_is_opt_in_and_schema_validated(): void
    {
        config(['services.groq.api_key' => 'test-key']);
        $this->mailbox()->update(['ai_processing_enabled' => true]);
        $message = MailMessage::create(['direction' => 'incoming', 'status' => 'synced', 'subject' => 'Pricing', 'from_email' => 'client@example.com', 'plain_body' => 'Can you send pricing?']);
        Http::fake(['api.groq.com/*' => Http::response([
            'choices' => [['message' => ['content' => json_encode([
                'summary' => 'Client requested pricing.', 'intent' => 'pricing_request', 'sentiment' => 'neutral', 'urgency' => 'normal',
                'confidence' => 0.91, 'reasoning' => 'The sender explicitly requested pricing.', 'action_items' => ['Share pricing'], 'next_action' => 'Review and reply.',
                'suggested_reply' => 'Thanks for reaching out. I will share the relevant pricing details.',
            ])]]],
        ])]);

        $this->postJson("/mail/messages/{$message->id}/ai", ['action' => 'suggest_reply'])
            ->assertOk()->assertJsonPath('intent', 'pricing_request');

        $this->assertDatabaseHas('ai_classifications', ['mail_message_id' => $message->id, 'intent' => 'pricing_request']);
        Http::assertSentCount(1);
        $this->assertSame('openai/gpt-oss-120b', AiClassification::first()->model);
    }

    public function test_intelligence_uses_bounded_tools_and_records_request_history(): void
    {
        config(['services.groq.api_key' => 'test-key']);
        $this->mailbox()->update(['ai_processing_enabled' => true]);
        $conversation = AiConversation::create(['user_id' => auth()->id()]);
        Http::fakeSequence()
            ->push(['choices' => [['message' => ['content' => json_encode(['tool_calls' => []])]]], 'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 4]])
            ->push(['choices' => [['message' => ['content' => json_encode(['answer' => 'No retrieval was needed.', 'suggestions' => ['Show unread mail'], 'draft' => null])]]], 'usage' => ['prompt_tokens' => 12, 'completion_tokens' => 6]]);

        $this->postJson("/intelligence/conversations/{$conversation->id}/chat", ['message' => 'What can you help me with?'])
            ->assertOk()->assertJsonPath('message.content', 'No retrieval was needed.');

        $this->assertSame(2, AiRequest::where('status', 'completed')->count());
        $this->assertSame(2, $conversation->messages()->count());
    }

    public function test_hard_bounce_suppresses_contact_and_stops_follow_ups(): void
    {
        $template = $this->template();
        $contact = Contact::create(['email' => 'missing@example.com', 'permission_status' => 'consented']);
        $sequence = Sequence::create(['name' => 'Follow up', 'status' => 'active']);
        $sequence->steps()->create(['template_id' => $template->id, 'position' => 1, 'delay_days' => 1]);
        $campaign = Campaign::create(['name' => 'Campaign', 'template_id' => $template->id, 'sequence_id' => $sequence->id]);
        $send = CampaignSend::create(['campaign_id' => $campaign->id, 'contact_id' => $contact->id, 'status' => 'sent']);
        SequenceEnrollment::create(['sequence_id' => $sequence->id, 'campaign_send_id' => $send->id, 'contact_id' => $contact->id, 'status' => 'active', 'next_step_position' => 1, 'next_send_at' => now()]);
        $outgoing = MailMessage::create(['contact_id' => $contact->id, 'campaign_send_id' => $send->id, 'direction' => 'outgoing', 'status' => 'sent', 'sent_at' => now()]);
        $outgoing->recipients()->create(['type' => 'to', 'email' => $contact->email]);
        MailMessage::create([
            'direction' => 'incoming', 'status' => 'synced', 'from_email' => 'mailer-daemon@example.net', 'subject' => 'Delivery Status Notification (Failure)',
            'plain_body' => "Final-Recipient: rfc822; missing@example.com\nStatus: 5.1.1\nDiagnostic-Code: smtp; 550 User unknown",
        ]);

        $this->assertSame(1, app(InboundSignalProcessor::class)->process());
        $this->assertSame('suppressed', $contact->fresh()->permission_status);
        $this->assertSame('bounced', $send->fresh()->status);
        $this->assertSame('stopped', SequenceEnrollment::first()->fresh()->status);
        $this->assertDatabaseHas('suppressions', ['email' => 'missing@example.com', 'reason' => 'hard_bounce']);
    }

    private function mailbox(): MailboxSetting
    {
        $mailbox = MailboxSetting::current();
        $mailbox->update([
            'email' => 'owner@example.com', 'display_name' => 'Owner', 'password' => 'secret',
            'physical_address' => '1 Main Street', 'smtp_status' => 'connected', 'imap_status' => 'connected',
        ]);

        return $mailbox;
    }

    private function template(string $html = '<p>Hello {{first_name}}</p>'): Template
    {
        return Template::create(['name' => 'Template', 'subject' => 'Hello', 'from_email' => 'owner@example.com', 'html_body' => $html, 'text_body' => 'Hello']);
    }
}
