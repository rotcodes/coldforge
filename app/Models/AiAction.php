<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiAction extends Model
{
    protected $fillable = [
        'ai_conversation_id', 'user_id', 'action', 'risk', 'status', 'summary', 'payload',
        'result', 'error', 'confirmed_at', 'executed_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array', 'result' => 'array',
            'confirmed_at' => 'datetime', 'executed_at' => 'datetime',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AiConversation::class, 'ai_conversation_id');
    }
}
