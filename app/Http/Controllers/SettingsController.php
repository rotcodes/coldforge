<?php

namespace App\Http\Controllers;

use App\Models\MailboxSetting;
use App\Models\MailMessage;
use App\Models\Setting;
use App\Models\Suppression;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;

class SettingsController extends Controller
{
    public function profile()
    {
        return Inertia::render('Settings/Profile');
    }

    public function updateProfile(Request $request)
    {
        $request->user()->update($request->validate([
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', Rule::unique('users')->ignore($request->user())],
        ]));

        return back()->with('success', 'Profile updated.');
    }

    public function updatePassword(Request $request)
    {
        $data = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', Password::min(10)],
        ]);
        $request->user()->update(['password' => $data['password']]);

        return back()->with('success', 'Password changed.');
    }

    public function deliverability(Request $request)
    {
        $domain = strtolower(trim((string) $request->query('domain', Setting::get('deliverability_domain', ''))));
        $domain = preg_replace('#^https?://|/.*$#', '', $domain);

        $results = null;
        if ($domain) {
            $request->validate(['domain' => ['nullable', 'regex:/^[a-z0-9.-]+\.[a-z]{2,}$/i']]);
            Setting::put('deliverability_domain', $domain);
            $results = [$this->spf($domain), $this->dkim($domain), $this->dmarc($domain), $this->mx($domain)];
        }

        $mailbox = MailboxSetting::current()->refreshAllowance();

        return Inertia::render('Settings/Deliverability', [
            'domain' => $domain, 'results' => $results,
            'health' => $mailbox->only(['smtp_status', 'imap_status', 'daily_allowance', 'sent_today', 'automation_paused']),
            'suppressions' => Suppression::latest('suppressed_at')->limit(50)->get(['email', 'reason', 'source', 'suppressed_at']),
            'errors' => MailMessage::whereIn('status', ['failed', 'uncertain'])->latest()->limit(20)->get(['id', 'subject', 'status', 'send_error', 'updated_at']),
        ]);
    }

    public function retention()
    {
        $defaults = config('coldforge.retention');

        return Inertia::render('Settings/Retention', ['retention' => collect($defaults)->mapWithKeys(fn ($default, $key) => [$key => (int) Setting::get('retention.'.$key, $default)])]);
    }

    public function updateRetention(Request $request)
    {
        $data = $request->validate([
            'message_bodies_days' => ['required', 'integer', 'min:0', 'max:3650'],
            'activity_days' => ['required', 'integer', 'min:0', 'max:3650'],
            'sync_logs_days' => ['required', 'integer', 'min:0', 'max:3650'],
            'ai_history_days' => ['required', 'integer', 'min:0', 'max:3650'],
            'downloaded_attachments_days' => ['required', 'integer', 'min:0', 'max:3650'],
        ]);
        foreach ($data as $key => $value) {
            Setting::put('retention.'.$key, $value);
        }

        return back()->with('success', 'Retention policy saved. Zero keeps that data indefinitely.');
    }

    private function txt(string $host): array
    {
        $records = @dns_get_record($host, DNS_TXT) ?: [];

        return array_map(fn ($r) => $r['txt'] ?? implode('', $r['entries'] ?? []), $records);
    }

    private function spf(string $domain): array
    {
        $spf = array_values(array_filter($this->txt($domain), fn ($t) => str_starts_with(strtolower($t), 'v=spf1')));
        $fix = 'Publish the single SPF TXT value shown for this domain in Hostinger Email DNS settings.';

        return match (true) {
            count($spf) === 0 => ['name' => 'SPF', 'status' => 'fail', 'records' => [], 'message' => 'No SPF record found.', 'fix' => $fix],
            count($spf) > 1 => ['name' => 'SPF', 'status' => 'warn', 'records' => $spf, 'message' => 'Multiple SPF records. Mail servers will treat this as invalid.', 'fix' => 'Merge them into a single v=spf1 record.'],
            ! str_contains($spf[0], 'include:') && ! str_contains($spf[0], 'ip4:') && ! str_contains($spf[0], 'ip6:') => ['name' => 'SPF', 'status' => 'warn', 'records' => $spf, 'message' => 'SPF exists but does not authorize an external sending service.', 'fix' => $fix],
            default => ['name' => 'SPF', 'status' => 'pass', 'records' => $spf, 'message' => 'A single SPF authorization record is published.', 'fix' => null],
        };
    }

    private function dkim(string $domain): array
    {
        $found = [];
        foreach (['hostingermail-a', 'hostingermail-b', 'hostingermail-c', 'mail', 'default', 'selector1', 'selector2', 's1', 'k1'] as $selector) {
            $host = "{$selector}._domainkey.{$domain}";
            $cname = @dns_get_record($host, DNS_CNAME) ?: [];
            if ($cname) {
                $found[] = "{$selector} → CNAME {$cname[0]['target']}";

                continue;
            }
            foreach ($this->txt($host) as $txt) {
                if (str_contains($txt, 'p=')) {
                    $found[] = "{$selector} → TXT ".substr($txt, 0, 60).'…';
                }
            }
        }

        return $found
            ? ['name' => 'DKIM', 'status' => 'pass', 'records' => $found, 'message' => count($found).' DKIM selector(s) found.', 'fix' => null]
            : ['name' => 'DKIM', 'status' => 'fail', 'records' => [], 'message' => 'No DKIM record found on common Hostinger selectors.', 'fix' => "Open Hostinger Email → Manage → Domain settings and publish the DKIM records shown for {$domain}."];
    }

    private function dmarc(string $domain): array
    {
        $dmarc = array_values(array_filter($this->txt("_dmarc.{$domain}"), fn ($t) => str_starts_with(strtolower($t), 'v=dmarc1')));
        $fix = "Add a TXT record on _dmarc.{$domain}:\nv=DMARC1; p=none; rua=mailto:dmarc@{$domain}";

        if (! $dmarc) {
            return ['name' => 'DMARC', 'status' => 'fail', 'records' => [], 'message' => 'No DMARC record found. Gmail and Yahoo require one for bulk senders.', 'fix' => $fix];
        }

        return preg_match('/p\s*=\s*none/i', $dmarc[0])
            ? ['name' => 'DMARC', 'status' => 'warn', 'records' => $dmarc, 'message' => 'DMARC is in monitor-only mode (p=none). Fine to start with.', 'fix' => 'Once reports look clean, move to p=quarantine.']
            : ['name' => 'DMARC', 'status' => 'pass', 'records' => $dmarc, 'message' => 'DMARC policy is enforced.', 'fix' => null];
    }

    private function mx(string $domain): array
    {
        $records = @dns_get_record($domain, DNS_MX) ?: [];
        $values = collect($records)->sortBy('pri')->map(fn ($record) => ($record['pri'] ?? 0).' '.$record['target'])->values()->all();

        return $values
            ? ['name' => 'MX', 'status' => 'pass', 'records' => $values, 'message' => 'Mail-exchange records are published.', 'fix' => null]
            : ['name' => 'MX', 'status' => 'fail', 'records' => [], 'message' => 'No MX records were found.', 'fix' => 'Publish the MX records shown in Hostinger Email DNS settings.'];
    }
}
