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
    custom_fields: Record<string, string> | null;
    status: ContactStatus;
    source: string;
    tags: string[] | null;
    created_at: string;
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
