<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MailFolder extends Model
{
    protected $fillable = [
        'path', 'name', 'delimiter', 'special_use', 'uid_validity', 'uid_next', 'last_synced_uid',
        'message_count', 'unread_count', 'sync_status', 'last_synced_at', 'last_error',
    ];

    protected function casts(): array
    {
        return ['last_synced_at' => 'datetime'];
    }

    public function messages(): HasMany
    {
        return $this->hasMany(MailMessage::class);
    }
}
