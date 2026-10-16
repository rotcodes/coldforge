<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Per-sender TPS limit for SendEmailJob (Brevo allows ~10/sec).
        RateLimiter::for('sender', fn ($job) => Limit::perSecond(config('coldforge.sender_tps'))->by('sender:'.$job->send->sender_id));
    }
}
