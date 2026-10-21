<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InboxMessage extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'provider', 'event_id', 'delivery_id', 'mailbox_address',
        'message_id', 'in_reply_to', 'references',
        'from_email', 'from_name', 'to_addresses', 'cc_addresses',
        'subject', 'plain_preview', 'html_preview', 'plain_body', 'html_body',
        'body_url', 'body_fetched_at', 'attachments',
        'campaign_send_id', 'contact_id', 'classification', 'is_read',
        'received_at', 'created_at',
    ];

    /** Kept for later processing; the inbox UI renders plain text only. */
    protected $hidden = ['html_body'];

    protected function casts(): array
    {
        return [
            'references' => 'array',
            'to_addresses' => 'array',
            'cc_addresses' => 'array',
            'attachments' => 'array',
            'is_read' => 'boolean',
            'body_fetched_at' => 'datetime',
            'received_at' => 'datetime',
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
}
