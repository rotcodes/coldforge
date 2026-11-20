<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiConversation extends Model
{
    protected $fillable = ['user_id', 'title', 'mode'];

    public function messages(): HasMany
    {
        return $this->hasMany(AiConversationMessage::class);
    }

    public function actions(): HasMany
    {
        return $this->hasMany(AiAction::class);
    }
}
