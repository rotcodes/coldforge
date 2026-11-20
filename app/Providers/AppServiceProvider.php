<?php

namespace App\Providers;

use App\Services\AI\CodexClient;
use App\Services\AI\StructuredAiClient;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(StructuredAiClient::class, CodexClient::class);
    }

    public function boot(): void
    {
        RateLimiter::for('mailbox', fn () => Limit::perSecond(2)->by('hostinger-mailbox'));
        RateLimiter::for('ai', fn ($request) => Limit::perMinute(10)->by('ai:'.($request->user()?->id ?: $request->ip())));
    }
}
