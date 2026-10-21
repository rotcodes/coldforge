<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WebhookEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'provider', 'event', 'email', 'message_id', 'campaign_send_id',
        'contact_id', 'reason', 'link', 'payload', 'occurred_at', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'occurred_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function send(): BelongsTo
    {
        return $this->belongsTo(CampaignSend::class, 'campaign_send_id');
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /** True if this event signals a deliverability issue the operator should see. */
    public function isNegative(): bool
    {
        return in_array($this->event, ['hard_bounce', 'invalid_email', 'blocked', 'spam', 'error', 'unsubscribed'], true);
    }
}
