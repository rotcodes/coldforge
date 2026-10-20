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

    public const CORE_FIELDS = ['email', 'first_name', 'last_name', 'company', 'country'];

    /** Direct contact columns writable via UI/CSV mapping (excludes source_system/external_id which are owned by the importer). */
    public const FIELDS = [
        'email', 'first_name', 'last_name', 'company', 'country',
        'business', 'industry', 'industry_slug', 'city_state', 'website',
        'decision_maker', 'dm_role', 'direct_phone', 'main_phone',
        'company_linkedin', 'dm_linkedin',
        'personalized_opener', 'pain_evidence', 'recommended_offer',
        'custom_subject', 'why_now_trigger', 'notion_url', 'other_emails',
    ];

    protected $fillable = [
        'email', 'first_name', 'last_name', 'company', 'country',
        'business', 'industry', 'industry_slug', 'city_state', 'website',
        'decision_maker', 'dm_role', 'direct_phone', 'main_phone',
        'company_linkedin', 'dm_linkedin',
        'source_system', 'external_id', 'remote_status', 'remote_reply_status',
        'lead_score', 'confidence',
        'personalized_opener', 'pain_evidence', 'recommended_offer',
        'custom_subject', 'why_now_trigger', 'notion_url', 'other_emails',
        'custom_fields', 'status', 'source', 'tags',
    ];

    protected function casts(): array
    {
        return ['custom_fields' => 'array', 'tags' => 'array', 'lead_score' => 'integer'];
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

    /** Contacts matching a campaign contact_filter. Supports tags, industry_slug, lead_score_min, source_system. */
    public function scopeMatchingFilter(Builder $query, ?array $filter): Builder
    {
        return $query->where('status', 'active')
            ->whereNotNull('email')
            ->where('email', '!=', '')
            ->withAnyTag($filter['tags'] ?? [])
            ->when(! empty($filter['industry_slugs']), fn ($q) => $q->whereIn('industry_slug', $filter['industry_slugs']))
            ->when(! empty($filter['source_system']), fn ($q) => $q->where('source_system', $filter['source_system']))
            ->when(! empty($filter['lead_score_min']), fn ($q) => $q->where('lead_score', '>=', (int) $filter['lead_score_min']))
            ->when(! empty($filter['require_industry']), fn ($q) => $q->whereNotNull('industry_slug'));
    }

    /**
     * Readiness tier (0-4) based on which personalization fields a contact has.
     * 0=no email · 1=email only · 2=+business · 3=+industry_slug · 4=+any rich field (pain/offer/opener).
     */
    public function readinessTier(): int
    {
        if (! $this->email) {
            return 0;
        }
        $hasBusiness = (bool) ($this->business ?: $this->company);
        $hasIndustry = (bool) $this->industry_slug;
        $hasRich = (bool) ($this->personalized_opener || $this->pain_evidence || $this->recommended_offer);

        return match (true) {
            $hasRich && $hasIndustry => 4,
            $hasIndustry => 3,
            $hasBusiness => 2,
            default => 1,
        };
    }

    /**
     * Variables available inside templates as {{name}}.
     * Industry-aware vars resolve against the Industry table + Settings fallbacks.
     */
    public function templateVars(): array
    {
        $fallbacks = \App\Services\TemplateFallbacks::all();
        $industry = $this->industry_slug ? \App\Models\Industry::where('slug', $this->industry_slug)->first() : null;

        $vars = array_merge($this->custom_fields ?? [], [
            // Core
            'email' => $this->email ?? '',
            'first_name' => $this->first_name ?? '',
            'last_name' => $this->last_name ?? '',
            'company' => $this->company ?? '',
            'country' => $this->country ?? '',
            // Rich fields
            'business' => $this->business ?: $this->company ?: ($fallbacks['business'] ?? ''),
            'industry' => $this->industry ?? '',
            'city_state' => $this->city_state ?? '',
            'website' => $this->website ?? '',
            'decision_maker' => $this->decision_maker ?: ($fallbacks['decision_maker'] ?? ''),
            'dm_role' => $this->dm_role ?? '',
            'direct_phone' => $this->direct_phone ?? '',
            'main_phone' => $this->main_phone ?? '',
            // Industry-aware (3 smart vars)
            'industry_label' => $industry?->label ?: ($this->industry ?: ($fallbacks['industry_label'] ?? 'business')),
            'workflow_examples' => $industry?->workflows ?: ($fallbacks['workflow_examples'] ?? 'daily operations and client work'),
            'tracking_examples' => $industry?->tracking ?: ($fallbacks['tracking_examples'] ?? 'orders, clients, and daily operations'),
            // Lead-level personalization (optional, fallbacks blank)
            'personalized_opener' => $this->personalized_opener ?: ($fallbacks['personalized_opener'] ?? ''),
            'pain_evidence' => $this->pain_evidence ?: ($fallbacks['pain_evidence'] ?? ''),
            'recommended_offer' => $this->recommended_offer ?: ($fallbacks['recommended_offer'] ?? ''),
            'why_now_trigger' => $this->why_now_trigger ?? '',
            'custom_subject' => $this->custom_subject ?? '',
        ]);

        return $vars;
    }
}
