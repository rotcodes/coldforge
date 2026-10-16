<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Sender extends Model
{
    public const PROVIDERS = ['brevo', 'mailersend', 'elastic', 'mailjet', 'smtp_generic'];

    protected $fillable = ['name', 'provider', 'config', 'daily_quota', 'sent_today', 'reset_at', 'is_active', 'priority'];

    protected $hidden = ['config'];

    protected function casts(): array
    {
        return ['config' => 'encrypted:array', 'is_active' => 'boolean', 'reset_at' => 'date'];
    }

    /** Resets the daily counter once per day (lazy, no extra cron needed). */
    public function refreshQuota(): self
    {
        if (! $this->reset_at || $this->reset_at->lt(today())) {
            $this->forceFill(['sent_today' => 0, 'reset_at' => today()])->save();
        }

        return $this;
    }

    public function hasQuota(): bool
    {
        return $this->refreshQuota()->sent_today < $this->daily_quota;
    }
}
