<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\CampaignSend;
use App\Models\Contact;
use App\Models\InboxMessage;
use App\Models\Sender;
use App\Models\Setting;
use App\Models\Template;
use App\Models\User;
use App\Services\Inbox\MailboxReader;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class InboxPollTest extends TestCase
{
    use RefreshDatabase;

    private FakeMailbox $mailbox;

    protected function setUp(): void
    {
        parent::setUp();
        config(['coldforge.inbox_imap.username' => 'sameer@digitelly.net', 'coldforge.inbox_imap.password' => 'pw']);
        $this->mailbox = new FakeMailbox;
        $this->app->instance(MailboxReader::class, $this->mailbox);
    }

    public function test_does_nothing_when_mailbox_not_configured(): void
    {
        config(['coldforge.inbox_imap.password' => null]);
        $this->mailbox->add($this->mail(['uid' => 1]));

        $this->artisan('inbox:poll')->assertSuccessful();
        $this->assertSame(0, InboxMessage::count());
        $this->assertNull(Setting::get('imap_last_polled_at'));
    }

    public function test_first_run_uses_look_back_window_and_cursor_starts_at_mailbox_end(): void
    {
        $this->mailbox->uidNext = 41;
        $this->mailbox->add($this->mail(['uid' => 7, 'subject' => 'recent']));

        $this->artisan('inbox:poll')->assertSuccessful();

        $this->assertNull($this->mailbox->lastAfterUid, 'First run must fetch by date, not by UID.');
        $this->assertSame(1, InboxMessage::count());
        $this->assertSame('40', (string) Setting::get('imap_last_uid'));
        $this->assertNull(Setting::get('imap_last_error'));
        $this->assertNotNull(Setting::get('imap_last_polled_at'));
    }

    public function test_next_run_continues_from_cursor_and_dedupes_by_message_id(): void
    {
        Setting::put('imap_uidvalidity', 1);
        Setting::put('imap_last_uid', 10);
        $this->mailbox->add($this->mail(['uid' => 11, 'message_id' => '<a@x>']));
        $this->mailbox->add($this->mail(['uid' => 12, 'message_id' => '<a@x>'])); // same message copied again

        $this->artisan('inbox:poll')->assertSuccessful();

        $this->assertSame(10, $this->mailbox->lastAfterUid);
        $this->assertSame(1, InboxMessage::count());
        $this->assertSame('12', (string) Setting::get('imap_last_uid'));
    }

    public function test_uidvalidity_change_resets_cursor(): void
    {
        Setting::put('imap_uidvalidity', 999);
        Setting::put('imap_last_uid', 500);

        $this->artisan('inbox:poll')->assertSuccessful();

        $this->assertNull($this->mailbox->lastAfterUid);
        $this->assertSame('1', (string) Setting::get('imap_uidvalidity'));
    }

    public function test_reply_to_alias_matches_campaign_send_with_side_effects(): void
    {
        [$campaign, $send, $contact] = $this->makeSendWith('alice@example.com', '<orig-1@smtp-relay.mailin.fr>');
        $this->mailbox->add($this->mail([
            'uid' => 3,
            'from_email' => 'Alice@Example.com',
            'from_name' => 'Alice',
            'to' => ['Luke <luke@digitelly.net>'],
            'in_reply_to' => '<orig-1@smtp-relay.mailin.fr>',
            'subject' => 'Re: Quick question',
            'text' => 'Yes, interested',
        ]));

        $this->artisan('inbox:poll')->assertSuccessful();

        $msg = InboxMessage::first();
        $this->assertSame($send->id, $msg->campaign_send_id);
        $this->assertSame($contact->id, $msg->contact_id);
        $this->assertSame('alice@example.com', $msg->from_email);
        $this->assertSame(['Luke <luke@digitelly.net>'], $msg->to_addresses);
        $this->assertSame('Yes, interested', $msg->plain_body);
        $this->assertSame('imap', $msg->provider);
        $this->assertSame(1, $campaign->fresh()->replied_count);
        $this->assertSame('replied', $contact->fresh()->status);
        $this->assertTrue($send->events()->where('type', 'replied')->exists());
    }

    public function test_matches_via_references_when_in_reply_to_is_unknown(): void
    {
        [$campaign, $send] = $this->makeSendWith('carol@example.com', '<orig-2@smtp-relay.mailin.fr>');
        $this->mailbox->add($this->mail([
            'uid' => 4,
            'from_email' => 'assistant@example.com',
            'in_reply_to' => '<mangled@client>',
            'references' => ['<older@thread>', '<orig-2@smtp-relay.mailin.fr>'],
        ]));

        $this->artisan('inbox:poll')->assertSuccessful();

        $this->assertSame($send->id, InboxMessage::first()->campaign_send_id);
        $this->assertSame(1, $campaign->fresh()->replied_count);
    }

    public function test_orphan_and_html_only_message_is_stored_as_text(): void
    {
        $this->mailbox->add($this->mail(['uid' => 5, 'text' => '', 'html' => '<p>Hello&nbsp;there</p><p>Second line</p>']));

        $this->artisan('inbox:poll')->assertSuccessful();

        $msg = InboxMessage::first();
        $this->assertNull($msg->campaign_send_id);
        $this->assertStringContainsString("Hello\u{00A0}there\nSecond line", $msg->plain_body);
        $this->assertSame('<p>Hello&nbsp;there</p><p>Second line</p>', $msg->html_body);
    }

    public function test_bounced_contact_is_not_flipped_to_replied(): void
    {
        $bouncer = Contact::create(['email' => 'bouncer@example.com', 'status' => 'bounced']);
        $this->mailbox->add($this->mail(['uid' => 6, 'from_email' => 'bouncer@example.com']));

        $this->artisan('inbox:poll')->assertSuccessful();

        $this->assertSame('bounced', $bouncer->fresh()->status);
    }

    public function test_a_bad_message_does_not_block_later_ones(): void
    {
        InboxMessage::creating(function (InboxMessage $m) {
            if ($m->subject === 'poison') {
                throw new RuntimeException('cannot store');
            }
        });
        $this->mailbox->add($this->mail(['uid' => 8, 'subject' => 'poison']));
        $this->mailbox->add($this->mail(['uid' => 9, 'subject' => 'fine']));

        $this->artisan('inbox:poll')->assertSuccessful();

        $this->assertSame(['fine'], InboxMessage::pluck('subject')->all());
    }

    public function test_connection_failure_is_recorded(): void
    {
        $this->mailbox->fail = 'connection refused';

        $this->artisan('inbox:poll')->assertFailed();

        $this->assertSame('connection refused', Setting::get('imap_last_error'));
    }

    public function test_inbox_page_renders_with_mailbox_status(): void
    {
        $this->actingAs(User::create(['name' => 'Admin', 'email' => 'admin@test.dev', 'password' => 'secret-password']));

        $this->get('/inbox')->assertOk()->assertInertia(fn ($p) => $p
            ->component('Inbox/Index')
            ->where('imap.configured', true)
            ->where('imap.mailbox', 'sameer@digitelly.net'));
    }

    // --- helpers --------------------------------------------------------

    private function mail(array $overrides): array
    {
        return array_merge([
            'uid' => 1,
            'message_id' => '<'.bin2hex(random_bytes(6)).'@mail.example>',
            'in_reply_to' => '',
            'references' => [],
            'from_email' => 'x@example.com',
            'from_name' => 'X',
            'to' => ['sameer@digitelly.net'],
            'cc' => [],
            'subject' => 'Re: hello',
            'text' => 'hi',
            'html' => '',
            'attachments' => [],
            'date' => now(),
        ], $overrides);
    }

    private function makeSendWith(string $email, string $messageId): array
    {
        Sender::create(['name' => 'Brevo', 'provider' => 'brevo', 'config' => ['api_key' => 'k'], 'daily_quota' => 300, 'priority' => 1]);
        $template = Template::create(['name' => 'T', 'subject' => 'S', 'from_email' => 'luke@digitelly.net', 'html_body' => '<p>x</p>']);
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

class FakeMailbox implements MailboxReader
{
    public array $messages = [];

    public int $uidNext = 1;

    public ?int $lastAfterUid = -1;

    public ?string $fail = null;

    public function add(array $m): void
    {
        $this->messages[] = $m;
        $this->uidNext = max($this->uidNext, $m['uid'] + 1);
    }

    public function status(): array
    {
        if ($this->fail) {
            throw new RuntimeException($this->fail);
        }

        return ['uidvalidity' => 1, 'uidnext' => $this->uidNext];
    }

    public function fetch(?int $afterUid, CarbonInterface $since): iterable
    {
        $this->lastAfterUid = $afterUid;

        return array_values(array_filter($this->messages, fn ($m) => $afterUid === null || $m['uid'] > $afterUid));
    }
}
