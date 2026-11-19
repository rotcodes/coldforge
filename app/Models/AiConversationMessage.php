<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiConversationMessage extends Model
{
    protected $fillable = ['ai_conversation_id', 'role', 'content', 'tool_calls', 'tool_results'];

    protected function casts(): array
    {
        return ['tool_calls' => 'array', 'tool_results' => 'array'];
    }
}
