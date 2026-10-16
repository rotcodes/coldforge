<?php

namespace App\Http\Controllers;

use App\Models\Sender;
use App\Services\Senders\SenderRegistry;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class SenderController extends Controller
{
    private const SECRETS = ['api_key', 'password'];

    public function index()
    {
        $senders = Sender::orderBy('priority')->orderBy('id')->get()->each->refreshQuota()->map(fn (Sender $s) => [
            ...$s->toArray(),
            'method' => SenderRegistry::method($s),
            // Never send secrets to the browser; just say whether one is set.
            'config' => collect($s->config ?? [])->map(fn ($v, $k) => in_array($k, self::SECRETS) ? ($v ? '********' : '') : $v),
        ]);

        return Inertia::render('Settings/Senders', [
            'senders' => $senders,
            'providers' => Sender::PROVIDERS,
            'apiProviders' => SenderRegistry::API_PROVIDERS,
        ]);
    }

    public function store(Request $request)
    {
        Sender::create($this->validated($request) + ['reset_at' => today()]);

        return back()->with('success', 'Sender added.');
    }

    public function update(Request $request, Sender $sender)
    {
        $data = $this->validated($request, $sender);
        // Blank / masked secret means "keep the current one".
        foreach (self::SECRETS as $key) {
            if (in_array($data['config'][$key] ?? '', ['', '********'], true) && isset($sender->config[$key])) {
                $data['config'][$key] = $sender->config[$key];
            }
        }
        $sender->update($data);

        return back()->with('success', 'Sender saved.');
    }

    public function toggle(Sender $sender)
    {
        $sender->update(['is_active' => ! $sender->is_active]);

        return back();
    }

    public function destroy(Sender $sender)
    {
        $sender->delete();

        return back()->with('success', 'Sender removed.');
    }

    public function test(Sender $sender)
    {
        return SenderRegistry::make($sender)->testConnection()
            ? back()->with('success', "{$sender->name}: connection OK.")
            : back()->with('error', "{$sender->name}: connection failed. Check the credentials.");
    }

    private function validated(Request $request, ?Sender $sender = null): array
    {
        // Brevo can use its API or SMTP; every other provider connects over SMTP.
        $smtp = ! in_array($request->input('provider'), SenderRegistry::API_PROVIDERS, true) || $request->input('config.method') === 'smtp';
        $hasPassword = ! empty($sender?->config['password']);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'provider' => ['required', Rule::in(Sender::PROVIDERS)],
            'daily_quota' => ['required', 'integer', 'min:1'],
            'priority' => ['required', 'integer', 'min:1', 'max:100'],
            'is_active' => ['boolean'],
            'config' => ['array'],
            'config.method' => ['nullable', Rule::in(['api', 'smtp'])],
            'config.api_key' => ['nullable', 'string', 'max:500'],
            'config.host' => [Rule::requiredIf($smtp), 'nullable', 'string', 'max:255'],
            'config.port' => [Rule::requiredIf($smtp), 'nullable', 'integer', 'between:1,65535'],
            'config.username' => [Rule::requiredIf($smtp), 'nullable', 'string', 'max:255'],
            'config.password' => [Rule::requiredIf($smtp && ! $hasPassword), 'nullable', 'string', 'max:500'],
            'config.encryption' => ['nullable', Rule::in(['tls', 'ssl', 'none'])],
        ], [
            'config.host.required' => 'SMTP host is required.',
            'config.port.required' => 'SMTP port is required.',
            'config.username.required' => 'SMTP login is required.',
            'config.password.required' => 'SMTP password / key is required.',
        ]);

        // Keep only the keys that belong to the chosen method.
        $config = $data['config'] ?? [];
        $data['config'] = $smtp
            ? ['method' => 'smtp'] + array_filter(array_intersect_key($config, array_flip(['host', 'port', 'username', 'password', 'encryption'])), fn ($v) => $v !== null)
            : ['method' => 'api', 'api_key' => $config['api_key'] ?? ''];

        return $data;
    }
}
