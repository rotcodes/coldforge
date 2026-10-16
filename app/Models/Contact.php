<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Contact extends Model
{
    use HasFactory;

    public const STATUSES = ['active', 'bounced', 'unsubscribed', 'complained'];

    public const FIELDS = ['email', 'first_name', 'last_name', 'company', 'country'];

    protected $fillable = ['email', 'first_name', 'last_name', 'company', 'country', 'custom_fields', 'status', 'source', 'tags'];

    protected function casts(): array
    {
        return ['custom_fields' => 'array', 'tags' => 'array'];
    }

    public function sends(): HasMany
    {
        return $this->hasMany(CampaignSend::class);
    }

    /** Contacts having ANY of the given tags. LIKE on the JSON text keeps it portable (MySQL + SQLite). */
    public function scopeWithAnyTag(Builder $query, array $tags): Builder
    {
        $tags = array_filter($tags);

        return $query->when($tags, fn ($q) => $q->where(function ($q) use ($tags) {
            foreach ($tags as $tag) {
                $q->orWhere('tags', 'like', '%'.json_encode((string) $tag).'%');
            }
        }));
    }

    /** Contacts matching a campaign contact_filter ({tags: string[]}). Only active contacts are ever sendable. */
    public function scopeMatchingFilter(Builder $query, ?array $filter): Builder
    {
        return $query->where('status', 'active')->withAnyTag($filter['tags'] ?? []);
    }

    /** Variables available inside templates as {{name}}. */
    public function templateVars(): array
    {
        return array_merge($this->custom_fields ?? [], [
            'email' => $this->email,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'company' => $this->company,
            'country' => $this->country,
        ]);
    }
}
