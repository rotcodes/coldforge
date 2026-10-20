export interface User {
    id: number;
    name: string;
    email: string;
}

export type PageProps<T extends Record<string, unknown> = Record<string, unknown>> = T & {
    auth: { user: User | null };
    flash: { success?: string | null; error?: string | null };
};

export interface Paginated<T> {
    data: T[];
    current_page: number;
    last_page: number;
    total: number;
    from: number | null;
    to: number | null;
    prev_page_url: string | null;
    next_page_url: string | null;
}

export type ContactStatus = 'active' | 'bounced' | 'unsubscribed' | 'complained';

export interface Contact {
    id: number;
    email: string;
    first_name: string | null;
    last_name: string | null;
    company: string | null;
    country: string | null;
    business: string | null;
    industry: string | null;
    industry_slug: string | null;
    city_state: string | null;
    website: string | null;
    decision_maker: string | null;
    dm_role: string | null;
    direct_phone: string | null;
    main_phone: string | null;
    company_linkedin: string | null;
    dm_linkedin: string | null;
    source_system: string | null;
    external_id: string | null;
    remote_status: string | null;
    remote_reply_status: string | null;
    lead_score: number | null;
    confidence: string | null;
    personalized_opener: string | null;
    pain_evidence: string | null;
    recommended_offer: string | null;
    custom_subject: string | null;
    why_now_trigger: string | null;
    notion_url: string | null;
    other_emails: string | null;
    custom_fields: Record<string, string> | null;
    status: ContactStatus;
    source: string;
    tags: string[] | null;
    created_at: string;
    tier?: number;
}

export interface Industry {
    id: number;
    slug: string;
    label: string;
    workflows: string;
    tracking: string;
    aliases: string[] | null;
    is_active: boolean;
    contact_count?: number;
}

export interface Template {
    id: number;
    name: string;
    subject: string;
    from_name: string | null;
    from_email: string;
    reply_to: string | null;
    html_body: string;
    text_body: string | null;
    variables: string[] | null;
    updated_at: string;
    campaigns_count?: number;
}

export type CampaignStatus = 'draft' | 'scheduled' | 'running' | 'paused' | 'completed' | 'failed';

export interface Campaign {
    id: number;
    name: string;
    template_id: number;
    template?: { id: number; name: string; subject?: string };
    sender_strategy: 'round_robin' | 'priority';
    contact_filter: { tags?: string[] } | null;
    schedule_at: string | null;
    status: CampaignStatus;
    daily_cap: number;
    total_recipients: number;
    sent_count: number;
    opened_count: number;
    clicked_count: number;
    bounced_count: number;
    unsubscribed_count: number;
    created_at: string;
}

export interface Sender {
    id: number;
    name: string;
    provider: 'brevo' | 'mailersend' | 'elastic' | 'mailjet' | 'smtp_generic';
    config: Record<string, string | number>;
    daily_quota: number;
    sent_today: number;
    is_active: boolean;
    priority: number;
}
