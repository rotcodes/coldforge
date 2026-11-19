<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MailMessage extends Model
{
    protected $fillable = [
        'mail_folder_id', 'contact_id', 'campaign_send_id', 'remote_uid', 'message_id', 'in_reply_to',
        'references', 'headers', 'thread_key', 'direction', 'status', 'source_type', 'source_id', 'from_email', 'from_name', 'subject',
        'plain_body', 'html_body', 'flags', 'is_read', 'is_starred', 'attachment_count',
        'message_at', 'scheduled_at', 'sent_at', 'failed_at', 'send_attempts', 'send_error', 'delivery_event', 'delivery_details', 'delivery_processed_at', 'idempotency_key',
    ];

    protected function casts(): array
    {
        return [
            'references' => 'array', 'headers' => 'array', 'flags' => 'array', 'delivery_details' => 'array', 'is_read' => 'boolean', 'is_starred' => 'boolean',
            'message_at' => 'datetime', 'scheduled_at' => 'datetime', 'sent_at' => 'datetime', 'failed_at' => 'datetime', 'delivery_processed_at' => 'datetime',
        ];
    }

    public function folder(): BelongsTo
    {
        return $this->belongsTo(MailFolder::class, 'mail_folder_id');
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(MailRecipient::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(MailAttachment::class);
    }
}
