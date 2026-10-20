<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Industry extends Model
{
    protected $fillable = ['slug', 'label', 'workflows', 'tracking', 'aliases', 'is_active'];

    protected function casts(): array
    {
        return ['aliases' => 'array', 'is_active' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::saving(function (Industry $i) {
            $i->slug = Str::slug($i->slug ?: $i->label, '_');
            $i->aliases = array_values(array_unique(array_filter(array_map(fn ($a) => strtolower(trim((string) $a)), $i->aliases ?? []))));
        });
    }

    /** All aliases plus the label and slug as searchable keys. */
    public function matchKeys(): array
    {
        return array_values(array_unique(array_filter([
            strtolower($this->slug),
            strtolower($this->label),
            ...($this->aliases ?? []),
        ])));
    }
}
