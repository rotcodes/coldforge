<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Template extends Model
{
    protected $fillable = ['name', 'subject', 'from_name', 'from_email', 'reply_to', 'html_body', 'text_body', 'variables'];

    protected function casts(): array
    {
        return ['variables' => 'array'];
    }

    protected static function booted(): void
    {
        static::saving(fn (Template $t) => $t->variables = self::detectVariables($t->subject.' '.$t->html_body.' '.$t->text_body));
    }

    public static function detectVariables(string $text): array
    {
        preg_match_all('/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/', $text, $m);

        return array_values(array_unique($m[1]));
    }

    public function campaigns(): HasMany
    {
        return $this->hasMany(Campaign::class);
    }
}
