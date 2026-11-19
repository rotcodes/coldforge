<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MailboxSetting extends Model
{
    protected $fillable = [
        'email', 'display_name', 'reply_to', 'password',
        'smtp_host', 'smtp_port', 'smtp_encryption',
        'imap_host', 'imap_port', 'imap_encryption',
        'daily_allowance', 'sent_today', 'allowance_reset_at', 'timezone',
        'smtp_status', 'imap_status', 'last_smtp_test_at', 'last_imap_test_at',
        'last_synced_at', 'last_connection_error', 'signature_html', 'signature_text', 'physical_address',
        'automation_paused', 'track_campaign_opens', 'track_campaign_clicks',
        'ai_processing_enabled', 'ai_model',
    ];

    protected $hidden = ['password'];

    protected function casts(): array
    {
        return [
            'password' => 'encrypted',
            'allowance_reset_at' => 'date',
            'last_smtp_test_at' => 'datetime',
            'last_imap_test_at' => 'datetime',
            'last_synced_at' => 'datetime',
            'automation_paused' => 'boolean',
            'track_campaign_opens' => 'boolean',
            'track_campaign_clicks' => 'boolean',
            'ai_processing_enabled' => 'boolean',
        ];
    }

    public static function current(): self
    {
        return self::firstOrCreate([], [
            'smtp_host' => 'smtp.hostinger.com',
            'smtp_port' => 465,
            'smtp_encryption' => 'ssl',
            'imap_host' => 'imap.hostinger.com',
            'imap_port' => 993,
            'imap_encryption' => 'ssl',
            'daily_allowance' => 100,
            'timezone' => 'Asia/Karachi',
        ]);
    }

    public function configured(): bool
    {
        return filled($this->email) && filled($this->password);
    }

    public function refreshAllowance(): self
    {
        if (! $this->allowance_reset_at || $this->allowance_reset_at->lt(today($this->timezone))) {
            $this->forceFill(['sent_today' => 0, 'allowance_reset_at' => today($this->timezone)])->save();
        }

        return $this;
    }
}
