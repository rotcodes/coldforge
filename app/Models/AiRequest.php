<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiRequest extends Model
{
    protected $fillable = ['user_id', 'task', 'model', 'prompt_version', 'status', 'input_tokens', 'output_tokens', 'duration_ms', 'error', 'metadata'];

    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }
}
