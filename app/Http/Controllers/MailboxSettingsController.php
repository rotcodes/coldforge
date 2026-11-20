<?php

namespace App\Http\Controllers;

use App\Models\MailboxSetting;
use App\Services\Mailbox\MailboxConnectionTester;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class MailboxSettingsController extends Controller
{
    public function index()
    {
        $mailbox = MailboxSetting::current()->refreshAllowance();

        return Inertia::render('Settings/Mailbox', [
            'mailbox' => [
                ...$mailbox->only([
                    'email', 'display_name', 'reply_to', 'smtp_host', 'smtp_port', 'smtp_encryption',
                    'imap_host', 'imap_port', 'imap_encryption', 'daily_allowance', 'sent_today', 'timezone',
                    'smtp_status', 'imap_status', 'last_smtp_test_at', 'last_imap_test_at', 'last_synced_at',
                    'last_connection_error', 'signature_html', 'signature_text', 'physical_address', 'automation_paused',
                    'track_campaign_opens', 'track_campaign_clicks',
                    'ai_processing_enabled', 'ai_model',
                ]),
                'has_password' => filled($mailbox->getRawOriginal('password')),
            ],
            'timezones' => ['Asia/Karachi', 'UTC', 'Europe/London', 'America/New_York', 'America/Chicago', 'America/Denver', 'America/Los_Angeles'],
        ]);
    }

    public function update(Request $request)
    {
        $mailbox = MailboxSetting::current();
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'display_name' => ['required', 'string', 'max:100'],
            'reply_to' => ['nullable', 'email', 'max:255'],
            'password' => [Rule::requiredIf(! $mailbox->getRawOriginal('password')), 'nullable', 'string', 'max:500'],
            'smtp_host' => ['required', 'string', 'max:255'],
            'smtp_port' => ['required', 'integer', 'between:1,65535'],
            'smtp_encryption' => ['required', Rule::in(['ssl', 'tls', 'none'])],
            'imap_host' => ['required', 'string', 'max:255'],
            'imap_port' => ['required', 'integer', 'between:1,65535'],
            'imap_encryption' => ['required', Rule::in(['ssl', 'tls', 'none'])],
            'daily_allowance' => ['required', 'integer', 'min:1', 'max:100000'],
            'timezone' => ['required', 'timezone'],
            'signature_html' => ['nullable', 'string', 'max:20000'],
            'signature_text' => ['nullable', 'string', 'max:5000'],
            'physical_address' => ['nullable', 'string', 'max:500'],
            'automation_paused' => ['boolean'],
            'track_campaign_opens' => ['boolean'],
            'track_campaign_clicks' => ['boolean'],
            'ai_processing_enabled' => ['boolean'],
            'ai_model' => ['required', Rule::in(['gpt-6.2-sol', 'gpt-6.2-astra', 'gpt-6.1-terra'])],
        ]);

        if (blank($data['password'] ?? null)) {
            unset($data['password']);
        }
        $mailbox->update($data + ['smtp_status' => 'untested', 'imap_status' => 'untested', 'last_connection_error' => null]);

        return back()->with('success', 'Mailbox configuration saved. Test SMTP and IMAP before sending or syncing.');
    }

    public function test(Request $request, MailboxConnectionTester $tester)
    {
        $data = $request->validate(['connection' => ['required', Rule::in(['smtp', 'imap'])]]);
        $mailbox = MailboxSetting::current();
        if (! $mailbox->configured()) {
            return back()->with('error', 'Save the mailbox address and password first.');
        }

        $result = $data['connection'] === 'smtp' ? $tester->smtp($mailbox) : $tester->imap($mailbox);
        $prefix = $data['connection'];
        $mailbox->update([
            "{$prefix}_status" => $result['ok'] ? 'connected' : 'failed',
            "last_{$prefix}_test_at" => now(),
            'last_connection_error' => $result['ok'] ? null : $result['error'],
        ]);

        return $result['ok']
            ? back()->with('success', strtoupper($prefix).' connection successful.')
            : back()->with('error', strtoupper($prefix).' connection failed: '.$result['error']);
    }
}
