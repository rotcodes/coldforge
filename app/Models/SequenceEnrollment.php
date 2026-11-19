<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SequenceEnrollment extends Model
{
    protected $fillable = ['sequence_id', 'campaign_send_id', 'contact_id', 'status', 'next_step_position', 'next_send_at', 'stop_reason', 'stopped_at'];

    protected function casts(): array
    {
        return ['next_send_at' => 'datetime', 'stopped_at' => 'datetime'];
    }

    public function sequence(): BelongsTo
    {
        return $this->belongsTo(Sequence::class);
    }

    public function campaignSend(): BelongsTo
    {
        return $this->belongsTo(CampaignSend::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(SequenceDelivery::class);
    }
}
