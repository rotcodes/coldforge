<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Campaign extends Model
{
    protected $fillable = [
        'name', 'template_id', 'sender_strategy', 'contact_filter', 'schedule_at', 'status', 'daily_cap', 'total_recipients',
        'sent_count', 'opened_count', 'clicked_count', 'bounced_count', 'unsubscribed_count', 'replied_count',
    ];

    protected function casts(): array
    {
        return ['contact_filter' => 'array', 'schedule_at' => 'datetime'];
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(Template::class);
    }

    public function sends(): HasMany
    {
        return $this->hasMany(CampaignSend::class);
    }
}
