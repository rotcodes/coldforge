<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Event extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['campaign_send_id', 'type', 'payload', 'ip', 'user_agent', 'created_at'];

    protected function casts(): array
    {
        return ['payload' => 'array'];
    }

    public function send(): BelongsTo
    {
        return $this->belongsTo(CampaignSend::class, 'campaign_send_id');
    }
}
