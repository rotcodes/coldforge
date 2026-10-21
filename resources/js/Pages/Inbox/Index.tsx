import { useState } from 'react';
import { Link, router } from '@inertiajs/react';
import { AnimatePresence, motion } from 'framer-motion';
import { AlertCircle, Check, CheckCheck, Copy, ExternalLink, Inbox as InboxIcon, Mail, MailOpen, Paperclip, Sparkles, Unlink2 } from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import PageHeader from '@/Components/PageHeader';
import EmptyState from '@/Components/EmptyState';
import Pagination from '@/Components/Pagination';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { toast } from 'sonner';
import { cn, dateTime, fmt } from '@/lib/utils';
import type { Paginated } from '@/types';

type Attachment = { contentType: string; filename: string; sizeBytes: number; fileUrl: string };
type InboxMessage = {
    id: number;
    event_id: string;
    mailbox_address: string;
    message_id: string | null;
    in_reply_to: string | null;
    from_email: string;
    from_name: string | null;
    to_addresses: string[] | null;
    cc_addresses: string[] | null;
    subject: string | null;
    plain_preview: string | null;
    html_preview: string | null;
    body_url: string | null;
    attachments: Attachment[] | null;
    classification: string | null;
    is_read: boolean;
    received_at: string;
    campaign_send_id: number | null;
    contact_id: number | null;
    contact?: { id: number; email: string; business: string | null; company: string | null } | null;
    send?: { id: number; campaign_id: number; contact_id: number; campaign?: { id: number; name: string } } | null;
};

interface Props {
    messages: Paginated<InboxMessage>;
    filter: 'all' | 'unread' | 'replied-to-campaign' | 'orphan';
    selected: InboxMessage | null;
    webhook_url: string;
    webhook_configured: boolean;
    counts: { all: number; unread: number; campaign: number; orphan: number };
}

const FILTERS: Array<{ key: Props['filter']; label: string; countKey: keyof Props['counts'] }> = [
    { key: 'all', label: 'All', countKey: 'all' },
    { key: 'unread', label: 'Unread', countKey: 'unread' },
    { key: 'replied-to-campaign', label: 'Replies', countKey: 'campaign' },
    { key: 'orphan', label: 'Orphan', countKey: 'orphan' },
];

export default function InboxIndex({ messages, filter, selected, webhook_url, webhook_configured, counts }: Props) {
    const [sel, setSel] = useState<InboxMessage | null>(selected);

    const open = (m: InboxMessage) => {
        setSel(m);
        if (!m.is_read) {
            router.post(`/inbox/${m.id}/read`, {}, { preserveScroll: true, preserveState: true, only: ['messages', 'counts', 'unread_inbox'] });
        }
    };

    const markAll = () => router.post('/inbox/mark-all-read', {}, { preserveScroll: true });

    const copyUrl = async () => {
        try { await navigator.clipboard.writeText(webhook_url); toast.success('Webhook URL copied'); }
        catch { toast.error('Could not copy'); }
    };

    const changeFilter = (f: Props['filter']) => router.get('/inbox', { filter: f }, { preserveState: false, preserveScroll: false });

    if (!webhook_configured) {
        return (
            <AppLayout title="Inbox">
                <PageHeader title="Inbox" description="Replies from your campaigns land here." />
                <SetupGuide webhook_url={webhook_url} configured={false} onCopy={copyUrl} />
            </AppLayout>
        );
    }

    return (
        <AppLayout title="Inbox">
            <PageHeader
                title="Inbox"
                description={counts.unread > 0 ? `${counts.unread} unread · ${counts.all} total` : `${counts.all} messages`}
                actions={counts.unread > 0 && <Button variant="outline" onClick={markAll}><CheckCheck /> Mark all read</Button>}
            />

            {/* Filter chips */}
            <div className="mb-4 flex flex-wrap gap-2">
                {FILTERS.map((f) => (
                    <button
                        key={f.key}
                        type="button"
                        onClick={() => changeFilter(f.key)}
                        className={cn(
                            'inline-flex items-center gap-2 rounded-full border px-3 py-1 text-xs font-medium transition-colors',
                            filter === f.key ? 'border-primary bg-primary/10 text-primary' : 'border-border text-muted-foreground hover:border-primary/40',
                        )}
                    >
                        {f.label}
                        <span className="rounded bg-muted px-1.5 py-0.5 font-mono text-[10px]">{fmt(counts[f.countKey])}</span>
                    </button>
                ))}
            </div>

            <div className="grid gap-4 lg:grid-cols-5">
                {/* Message list */}
                <Card className="overflow-hidden p-0 lg:col-span-2">
                    {messages.data.length === 0 ? (
                        <EmptyState icon={InboxIcon} title="No messages" text={filter === 'unread' ? "You're all caught up!" : 'Nothing here yet. Replies will appear within seconds of landing in your mailbox.'} />
                    ) : (
                        <ul className="divide-y">
                            <AnimatePresence initial={false}>
                                {messages.data.map((m) => (
                                    <motion.li
                                        key={m.id}
                                        initial={{ opacity: 0, y: 4 }}
                                        animate={{ opacity: 1, y: 0 }}
                                        exit={{ opacity: 0 }}
                                        transition={{ duration: 0.15 }}
                                    >
                                        <button
                                            type="button"
                                            onClick={() => open(m)}
                                            className={cn(
                                                'w-full px-4 py-3 text-left transition-colors hover:bg-accent/40 focus-visible:bg-accent/40 focus-visible:outline-none',
                                                sel?.id === m.id && 'bg-primary/5',
                                                !m.is_read && 'font-medium',
                                            )}
                                        >
                                            <div className="flex items-start gap-2">
                                                <span className={cn('mt-1 size-2 shrink-0 rounded-full', m.is_read ? 'bg-muted-foreground/30' : 'bg-primary')} />
                                                <div className="min-w-0 flex-1">
                                                    <div className="flex items-center gap-2">
                                                        <span className="min-w-0 flex-1 truncate text-sm">{m.from_name || m.from_email}</span>
                                                        <span className="shrink-0 font-mono text-[10px] text-muted-foreground">{dateTime(m.received_at)}</span>
                                                    </div>
                                                    <div className="truncate text-xs text-muted-foreground">{m.subject || '(no subject)'}</div>
                                                    {m.plain_preview && (
                                                        <div className="mt-1 line-clamp-2 text-xs text-muted-foreground/80">{m.plain_preview}</div>
                                                    )}
                                                    {m.send?.campaign && (
                                                        <div className="mt-1 inline-flex items-center gap-1 rounded bg-primary/10 px-1.5 py-0.5 text-[10px] text-primary">
                                                            <Sparkles className="size-2.5" /> {m.send.campaign.name}
                                                        </div>
                                                    )}
                                                </div>
                                            </div>
                                        </button>
                                    </motion.li>
                                ))}
                            </AnimatePresence>
                        </ul>
                    )}
                    <Pagination page={messages} />
                </Card>

                {/* Detail */}
                <Card className="lg:col-span-3">
                    {sel ? <MessageDetail m={sel} /> : (
                        <div className="flex h-full min-h-80 flex-col items-center justify-center gap-2 text-sm text-muted-foreground">
                            <Mail className="size-10 opacity-30" />
                            <span>Select a message</span>
                        </div>
                    )}
                </Card>
            </div>

            {/* Webhook URL (collapsible footer so operator can re-copy if needed) */}
            <SetupGuide webhook_url={webhook_url} configured onCopy={copyUrl} />
        </AppLayout>
    );
}

function MessageDetail({ m }: { m: InboxMessage }) {
    return (
        <div className="p-5">
            <div className="mb-3">
                <h2 className="text-base font-semibold">{m.subject || '(no subject)'}</h2>
                <div className="mt-1 flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
                    <span className="font-medium text-foreground">{m.from_name || m.from_email}</span>
                    <span>&lt;{m.from_email}&gt;</span>
                    <span className="ml-auto font-mono">{dateTime(m.received_at)}</span>
                </div>
                <div className="mt-1 text-xs text-muted-foreground">
                    To: {(m.to_addresses ?? []).join(', ')}
                </div>
            </div>

            {m.send?.campaign && m.contact ? (
                <div className="mb-3 flex items-center gap-2 rounded-lg border border-primary/30 bg-primary/5 px-3 py-2 text-xs">
                    <Sparkles className="size-3.5 text-primary" />
                    Reply to campaign{' '}
                    <Link href={`/campaigns/${m.send.campaign.id}`} className="font-medium text-primary hover:underline">{m.send.campaign.name}</Link>
                    {' · '}
                    <Link href={`/contacts/${m.contact.id}`} className="font-medium text-primary hover:underline">
                        {m.contact.business || m.contact.company || m.contact.email}
                    </Link>
                </div>
            ) : (
                <div className="mb-3 flex items-center gap-2 rounded-lg border border-amber-500/30 bg-amber-500/10 px-3 py-2 text-xs text-amber-700 dark:text-amber-300">
                    <Unlink2 className="size-3.5" />
                    Orphan — this message doesn't match any sent campaign.
                </div>
            )}

            <div className="rounded-lg border bg-muted/20 p-4">
                {m.plain_preview ? (
                    <pre className="whitespace-pre-wrap font-sans text-sm leading-relaxed">{m.plain_preview}</pre>
                ) : (
                    <div className="text-sm italic text-muted-foreground">No preview text in webhook. Full body available at bodyUrl.</div>
                )}
                {m.plain_preview && m.plain_preview.length >= 190 && (
                    <div className="mt-2 text-xs text-muted-foreground">
                        Preview truncated to 200 chars by Hostinger webhook.
                        {m.body_url && <> Full body:{' '}
                            <a href={m.body_url} target="_blank" rel="noopener" className="inline-flex items-center gap-1 text-primary hover:underline">
                                open <ExternalLink className="size-3" />
                            </a>
                        </>}
                    </div>
                )}
            </div>

            {(m.attachments ?? []).length > 0 && (
                <div className="mt-3">
                    <div className="mb-2 text-xs font-semibold uppercase tracking-wider text-muted-foreground">Attachments ({m.attachments!.length})</div>
                    <ul className="space-y-1">
                        {m.attachments!.map((a, i) => (
                            <li key={i} className="flex items-center gap-2 rounded border px-3 py-2 text-sm">
                                <Paperclip className="size-3.5 text-muted-foreground" />
                                <span className="min-w-0 flex-1 truncate">{a.filename}</span>
                                <span className="font-mono text-[10px] text-muted-foreground">{Math.round(a.sizeBytes / 1024)} KB</span>
                                <a href={a.fileUrl} target="_blank" rel="noopener" className="text-primary hover:underline">Download</a>
                            </li>
                        ))}
                    </ul>
                </div>
            )}

            {m.contact && (
                <div className="mt-3 flex gap-2">
                    <Button variant="outline" size="sm" asChild>
                        <a href={`mailto:${m.from_email}?subject=Re: ${encodeURIComponent(m.subject || '')}&In-Reply-To=${encodeURIComponent(m.message_id || '')}`}>
                            <Mail className="size-3.5" /> Reply in mail app
                        </a>
                    </Button>
                    <Button variant="outline" size="sm" asChild>
                        <Link href={`/contacts/${m.contact.id}`}>
                            View contact
                        </Link>
                    </Button>
                </div>
            )}
        </div>
    );
}

function SetupGuide({ webhook_url, configured, onCopy }: { webhook_url: string; configured: boolean; onCopy: () => void }) {
    const [open, setOpen] = useState(!configured);
    return (
        <Card className={cn('mt-4 p-4', !configured && 'border-amber-500/30 bg-amber-500/5')}>
            <button
                type="button"
                onClick={() => setOpen((o) => !o)}
                className="flex w-full items-center gap-2 text-left text-sm font-medium"
            >
                {configured ? <Check className="size-4 text-emerald-500" /> : <AlertCircle className="size-4 text-amber-500" />}
                <span>{configured ? 'Webhook setup' : 'Webhook not configured yet — set it up to start receiving replies'}</span>
                <span className="ml-auto text-xs text-muted-foreground">{open ? 'Hide' : 'Show'}</span>
            </button>
            {open && (
                <div className="mt-3 space-y-3">
                    {configured ? (
                        <>
                            <div className="flex items-center gap-2 rounded-lg border bg-muted/40 px-3 py-2 font-mono text-xs">
                                <code className="min-w-0 flex-1 truncate">{webhook_url}</code>
                                <Button size="sm" variant="outline" onClick={onCopy}><Copy className="size-3" /> Copy</Button>
                            </div>
                            <ol className="list-decimal space-y-1 pl-5 text-sm text-muted-foreground">
                                <li>Hostinger hpanel → Emails → {`<your mailbox>`} → Agentic Mail → Webhooks → Add webhook</li>
                                <li>Paste the URL above, event: <code className="rounded bg-background/50 px-1 font-mono text-xs">message.received</code></li>
                                <li>Save. Replies will start appearing here within seconds.</li>
                            </ol>
                        </>
                    ) : (
                        <div className="rounded-lg border border-amber-500/30 bg-amber-500/10 p-3 text-sm text-amber-700 dark:text-amber-300">
                            <p className="mb-2">Generate a secret and add to <code className="rounded bg-background/50 px-1 font-mono text-xs">.env</code>:</p>
                            <pre className="overflow-x-auto rounded bg-background/60 p-2 font-mono text-xs">HOSTINGER_MAIL_WEBHOOK_SECRET={'<random 48-char hex>'}</pre>
                            <p className="mt-2 text-xs">Then run <code className="font-mono">php artisan config:cache</code> and refresh.</p>
                        </div>
                    )}
                </div>
            )}
        </Card>
    );
}
