<?php

namespace App\Services;

use App\Models\Setting;

/**
 * Operator-editable text used when a contact lacks a template variable.
 * Stored as one JSON blob under the single settings key `variable_fallbacks`.
 */
class TemplateFallbacks
{
    public const KEY = 'variable_fallbacks';

    public const DEFAULTS = [
        'industry_label' => 'business',
        'workflow_examples' => 'daily operations and client work',
        'tracking_examples' => 'orders, clients, and daily operations',
        'business' => 'your business',
        'decision_maker' => '',
        'personalized_opener' => '',
        'pain_evidence' => '',
        'recommended_offer' => '',
    ];

    public static function all(): array
    {
        $raw = Setting::get(self::KEY);
        $stored = $raw ? json_decode($raw, true) : [];

        return array_merge(self::DEFAULTS, is_array($stored) ? $stored : []);
    }

    public static function save(array $values): void
    {
        $clean = array_intersect_key($values, self::DEFAULTS);
        Setting::put(self::KEY, json_encode($clean));
    }
}
