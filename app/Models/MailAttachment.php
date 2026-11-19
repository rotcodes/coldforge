<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MailAttachment extends Model
{
    protected $fillable = [
        'mail_message_id', 'remote_id', 'filename', 'mime_type', 'size_bytes', 'disposition',
        'content_id', 'storage_path', 'is_downloaded', 'scan_status', 'scanned_at', 'scan_result',
    ];

    protected function casts(): array
    {
        return ['is_downloaded' => 'boolean', 'scanned_at' => 'datetime'];
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(MailMessage::class, 'mail_message_id');
    }
}
