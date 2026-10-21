import { useState } from 'react';
import { Link, router } from '@inertiajs/react';
import { AnimatePresence, motion } from 'framer-motion';
import { AlertCircle, Check, CheckCheck, Inbox as InboxIcon, Mail, MailOpen, Paperclip, Sparkles, Unlink2 } from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import PageHeader from '@/Components/PageHeader';
import EmptyState from '@/Components/EmptyState';
import Pagination from '@/Components/Pagination';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { cn, dateTime, fmt } from '@/lib/utils';
import type { Paginated } from '@/types';

type Attachment = { contentType: string | null; filename: string | null; sizeBytes: number | null };
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
    plain_body: string | null;
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
    imap: { configured: boolean; mailbox: string | null; last_polled_at: string | null; last_error: string | null };
    counts: { all: number; unread: number; campaign: number; orphan: number };
}

const FILTERS: Array<{ key: Props['filter']; label: string; countKey: keyof Props['counts'] }> = [
    { key: 'all', label: 'All', countKey: 'all' },
    { key: 'unread', label: 'Unread', countKey: 'unread' },
    { key: 'replied-to-campaign', label: 'Replies', countKey: 'campaign' },
    { key: 'orphan', label: 'Orphan', countKey: 'orphan' },
];

export default function InboxIndex({ messages, filter, selected, imap, counts }: Props) {
    const [sel, setSel] = useState<InboxMessage | null>(selected);

    const open = (m: InboxMessage) => {
        setSel(m);
        if (!m.is_read) {
            router.post(`/inbox/${m.id}/read`, {}, { preserveScroll: true, preserveState: true, only: ['messages', 'counts', 'unread_inbox'] });
        }
    };

    const markAll = () => router.post('/inbox/mark-all-read', {}, { preserveScroll: true });

    const changeFilter = (f: Props['filter']) => router.get('/inbox', { filter: f }, { preserveState: false, preserveScroll: false });

    if (!imap.configured) {
        return (
            <AppLayout title="Inbox">
                <PageHeader title="Inbox" description="Replies from your campaigns land here." />
                <MailboxStatus imap={imap} />
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

            {/* Fixed-height split on desktop: list and detail scroll on their own, the page doesn't grow. */}
            <div className="grid gap-4 lg:h-[calc(100dvh-17rem)] lg:min-h-[28rem] lg:grid-cols-5">
                {/* Message list */}
                <Card className="flex max-h-[70dvh] flex-col overflow-hidden p-0 lg:col-span-2 lg:max-h-none">
                    {messages.data.length === 0 ? (
                        <EmptyState icon={InboxIcon} title="No messages" text={filter === 'unread' ? "You're all caught up!" : 'Nothing here yet. Replies will appear within seconds of landing in your mailbox.'} />
                    ) : (
                        <ul className="min-h-0 flex-1 divide-y overflow-y-auto overscroll-contain">
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
                    <div className="shrink-0">
                        <Pagination page={messages} />
                    </div>
                </Card>

                {/* Detail */}
                <Card className="min-h-0 overflow-y-auto overscroll-contain lg:col-span-3">
                    {sel ? <MessageDetail m={sel} /> : (
                        <div className="flex h-full min-h-80 flex-col items-center justify-center gap-2 text-sm text-muted-foreground">
                            <Mail className="size-10 opacity-30" />
                            <span>Select a message</span>
                        </div>
                    )}
                </Card>
            </div>

            <MailboxStatus imap={imap} />
        </AppLayout>
    );
}

function MessageDetail({ m }: { m: InboxMessage }) {
    const body = m.plain_body ?? m.plain_preview;
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
                {body ? (
                    <pre className="whitespace-pre-wrap font-sans text-sm leading-relaxed">{body}</pre>
                ) : (
                    <div className="text-sm italic text-muted-foreground">No text body.</div>
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
                                {a.sizeBytes != null && <span className="font-mono text-[10px] text-muted-foreground">{Math.round(a.sizeBytes / 1024)} KB</span>}
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

function MailboxStatus({ imap }: { imap: Props['imap'] }) {
    if (!imap.configured) {
        return (
            <Card className="mt-4 border-amber-500/30 bg-amber-500/5 p-4">
                <div className="flex items-center gap-2 text-sm font-medium">
                    <AlertCircle className="size-4 text-amber-500" /> Mailbox not connected yet. Add its IMAP login to start receiving replies.
                </div>
                <pre className="mt-3 overflow-x-auto rounded bg-background/60 p-2 font-mono text-xs">{`INBOX_IMAP_USERNAME=you@yourdomain.com
INBOX_IMAP_PASSWORD=<mailbox or app password>`}</pre>
                <p className="mt-2 text-xs text-muted-foreground">
                    Add these to <code className="font-mono">.env</code>, then run <code className="font-mono">php artisan config:cache</code>. The mailbox is checked every minute, read-only.
                </p>
            </Card>
        );
    }

    return (
        <Card className={cn('mt-4 flex flex-wrap items-center gap-2 p-3 text-xs', imap.last_error && 'border-red-500/30 bg-red-500/5')}>
            {imap.last_error ? <AlertCircle className="size-3.5 text-red-500" /> : <Check className="size-3.5 text-emerald-500" />}
            <span className="font-medium">{imap.mailbox}</span>
            <span className="text-muted-foreground">
                checked every minute over IMAP{imap.last_polled_at ? ` · last check ${dateTime(imap.last_polled_at)}` : ' · waiting for first check'}
            </span>
            {imap.last_error && <span className="w-full text-red-600 dark:text-red-400">Last check failed: {imap.last_error}</span>}
        </Card>
    );
}
