<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MailSyncLog extends Model
{
    protected $fillable = [
        'mail_folder_id', 'type', 'status', 'processed', 'created_count', 'updated_count',
        'error', 'started_at', 'finished_at',
    ];

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'finished_at' => 'datetime'];
    }
}
