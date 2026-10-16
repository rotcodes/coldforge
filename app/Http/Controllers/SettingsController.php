<?php

namespace App\Http\Controllers;

use App\Models\Setting;
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
            $results = [$this->spf($domain), $this->dkim($domain), $this->dmarc($domain)];
        }

        return Inertia::render('Settings/Deliverability', ['domain' => $domain, 'results' => $results]);
    }

    private function txt(string $host): array
    {
        $records = @dns_get_record($host, DNS_TXT) ?: [];

        return array_map(fn ($r) => $r['txt'] ?? implode('', $r['entries'] ?? []), $records);
    }

    private function spf(string $domain): array
    {
        $spf = array_values(array_filter($this->txt($domain), fn ($t) => str_starts_with(strtolower($t), 'v=spf1')));
        $fix = "Add a TXT record on {$domain}:\nv=spf1 include:spf.brevo.com ~all";

        return match (true) {
            count($spf) === 0 => ['name' => 'SPF', 'status' => 'fail', 'records' => [], 'message' => 'No SPF record found.', 'fix' => $fix],
            count($spf) > 1 => ['name' => 'SPF', 'status' => 'warn', 'records' => $spf, 'message' => 'Multiple SPF records. Mail servers will treat this as invalid.', 'fix' => 'Merge them into a single v=spf1 record.'],
            ! str_contains($spf[0], 'brevo.com') && ! str_contains($spf[0], 'sendinblue.com') => ['name' => 'SPF', 'status' => 'warn', 'records' => $spf, 'message' => 'SPF exists but does not include Brevo.', 'fix' => 'Add include:spf.brevo.com before the ~all / -all part.'],
            default => ['name' => 'SPF', 'status' => 'pass', 'records' => $spf, 'message' => 'SPF record is valid.', 'fix' => null],
        };
    }

    private function dkim(string $domain): array
    {
        $found = [];
        foreach (['brevo1', 'brevo2', 'mail', 'google', 'default', 'k1', 'mailjet', 'selector1', 'selector2', 's1', 'mlsend'] as $selector) {
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
            : ['name' => 'DKIM', 'status' => 'fail', 'records' => [], 'message' => 'No DKIM record found on common selectors.', 'fix' => "In Brevo → Senders, Domains & Dedicated IPs → Domains, authenticate {$domain} and add the brevo1._domainkey / brevo2._domainkey CNAME records it gives you."];
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
}
