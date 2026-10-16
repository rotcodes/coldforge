<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CampaignSend extends Model
{
    protected $fillable = [
        'campaign_id', 'contact_id', 'sender_id', 'status', 'message_id', 'queued_at', 'sent_at', 'failed_at', 'error',
        'opened_at', 'first_clicked_at', 'clicked_count', 'unsubscribed_at',
    ];

    protected function casts(): array
    {
        return [
            'queued_at' => 'datetime', 'sent_at' => 'datetime', 'failed_at' => 'datetime',
            'opened_at' => 'datetime', 'first_clicked_at' => 'datetime', 'unsubscribed_at' => 'datetime',
        ];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(Sender::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(Event::class);
    }

    public function log(string $type, array $payload = [], ?string $ip = null, ?string $userAgent = null): Event
    {
        return $this->events()->create([
            'type' => $type,
            'payload' => $payload ?: null,
            'ip' => $ip,
            'user_agent' => $userAgent ? substr($userAgent, 0, 255) : null,
            'created_at' => now(),
        ]);
    }
}
