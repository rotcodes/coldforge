<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiClassification extends Model
{
    protected $fillable = ['mail_message_id', 'intent', 'sentiment', 'urgency', 'confidence', 'summary', 'reasoning', 'manually_corrected', 'metadata', 'model'];

    protected function casts(): array
    {
        return ['confidence' => 'float', 'manually_corrected' => 'boolean', 'metadata' => 'array'];
    }
}
