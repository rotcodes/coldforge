<?php

namespace App\Services;

/**
 * Guess the ColdForge target field for a given CSV column header.
 * Recognises Pure Intelligence / US CRM headers natively, falls back
 * to common synonyms, otherwise returns `custom:<slug>`.
 */
class CsvHeaderMapper
{
    /** CSV header (case/space-insensitive) → target field. */
    public const MAP = [
        'email' => 'email',
        'email address' => 'email',
        'business' => 'business',
        'company' => 'company',
        'company name' => 'company',
        'organization' => 'company',
        'organisation' => 'company',
        'first name' => 'first_name',
        'first_name' => 'first_name',
        'given name' => 'first_name',
        'fname' => 'first_name',
        'last name' => 'last_name',
        'last_name' => 'last_name',
        'surname' => 'last_name',
        'family name' => 'last_name',
        'lname' => 'last_name',
        'country' => 'country',
        'industry' => 'industry',
        'city state' => 'city_state',
        'city / state' => 'city_state',
        'city/state' => 'city_state',
        'city' => 'city_state',
        'location' => 'city_state',
        'website' => 'website',
        'url' => 'website',
        'decision maker' => 'decision_maker',
        'decision_maker' => 'decision_maker',
        'contact name' => 'decision_maker',
        'decision maker role' => 'dm_role',
        'decision_maker_role' => 'dm_role',
        'role' => 'dm_role',
        'title' => 'dm_role',
        'direct phone' => 'direct_phone',
        'phone' => 'direct_phone',
        'main phone' => 'main_phone',
        'company linkedin' => 'company_linkedin',
        'linkedin' => 'company_linkedin',
        'decision maker linkedin' => 'dm_linkedin',
        'source' => 'source_system',
        'lead id' => 'external_id',
        'lead_id' => 'external_id',
        'id' => 'external_id',
        'status' => 'remote_status',
        'reply status' => 'remote_reply_status',
        'reply_status' => 'remote_reply_status',
        'lead score' => 'lead_score',
        'lead_score' => 'lead_score',
        'score' => 'lead_score',
        'confidence' => 'confidence',
        'personalized opener' => 'personalized_opener',
        'personalized_opener' => 'personalized_opener',
        'opener' => 'personalized_opener',
        'pain evidence' => 'pain_evidence',
        'pain_evidence' => 'pain_evidence',
        'pain' => 'pain_evidence',
        'recommended offer' => 'recommended_offer',
        'recommended_offer' => 'recommended_offer',
        'offer' => 'recommended_offer',
        'email subject' => 'custom_subject',
        'email_subject' => 'custom_subject',
        'subject' => 'custom_subject',
        'why now trigger' => 'why_now_trigger',
        'why_now_trigger' => 'why_now_trigger',
        'why now' => 'why_now_trigger',
        'notion url' => 'notion_url',
        'notion_url' => 'notion_url',
        'other emails' => 'other_emails',
        'other_emails' => 'other_emails',
        'tags' => 'tags',
    ];

    /** Headers that should be stored inside custom_fields rather than their own column. */
    public const CUSTOM = [
        'employees' => 'employees',
        'recommended channel' => 'recommended_channel',
        'source notes' => 'source_notes',
        'source urls' => 'source_urls',
        'recipients' => 'recipients',
    ];

    public static function guess(string $header): string
    {
        $key = strtolower(trim(preg_replace('/\s+/', ' ', $header)));
        if (isset(self::MAP[$key])) {
            return self::MAP[$key];
        }
        if (isset(self::CUSTOM[$key])) {
            return 'custom:'.self::CUSTOM[$key];
        }

        return 'custom:'.preg_replace('/[^a-z0-9]+/', '_', $key);
    }

    /** Detect a known format by scanning the headers — purely informational for the UI. */
    public static function detectFormat(array $headers): ?string
    {
        $lower = array_map(fn ($h) => strtolower(trim($h)), $headers);
        $signature = ['source', 'business', 'lead id', 'personalized opener', 'pain evidence', 'recommended offer'];
        $hits = count(array_intersect($signature, $lower));

        return $hits >= 5 ? 'Pure Intelligence / US CRM (29 columns)' : null;
    }
}
