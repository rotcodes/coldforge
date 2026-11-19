import { FormEvent, useEffect, useState } from 'react';
import axios from 'axios';
import { Link, router } from '@inertiajs/react';
import { AlertTriangle, Archive, ChevronLeft, Copy, FileText, Forward, Inbox, Loader2, Mail, MailOpen, PenLine, RefreshCw, Reply, Search, Send, Sparkles, Star, Trash2 } from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import EmptyState from '@/Components/EmptyState';
import EmailHtml from '@/Components/EmailHtml';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { cn, dateTime, fmt } from '@/lib/utils';
import type { Paginated } from '@/types';

type Folder = {
    id: number;
    path: string;
    name: string;
    special_use: string | null;
    message_count: number;
    unread_count: number;
    sync_status: string;
};

type Recipient = { id: number; type: 'to' | 'cc' | 'bcc'; email: string; name: string | null };
type Attachment = { id: number; filename: string; mime_type: string | null; size_bytes: number; is_downloaded: boolean };
type Message = {
    id: number;
    mail_folder_id: number | null;
    message_id: string | null;
    thread_key: string | null;
    direction: 'incoming' | 'outgoing';
    status: string;
    from_email: string | null;
    from_name: string | null;
    subject: string | null;
    plain_body: string | null;
    html_body: string | null;
    readable_body: string | null;
    is_read: boolean;
    is_starred: boolean;
    attachment_count: number;
    message_at: string | null;
    scheduled_at?: string | null;
    send_error?: string | null;
    recipients: Recipient[];
    attachments?: Attachment[];
    contact?: { id: number; email: string; business: string | null; company: string | null; first_name: string | null; last_name: string | null; pipeline_status: string } | null;
};

type Props = {
    folders: Folder[];
    views: { key: 'drafts' | 'scheduled' | 'outbox'; name: string; count: number }[];
    activeFolder: Folder | null;
    activeView: string | null;
    messages: Paginated<Message>;
    selected: Message | null;
    thread: Message[];
    filters: { q?: string; starred?: string | boolean; unread?: string | boolean };
    mailbox: { configured: boolean; email: string | null; imap_status: string; last_synced_at: string | null; last_error: string | null; ai_enabled: boolean };
};

const iconFor = (folder: Folder) => {
    switch (folder.special_use) {
        case 'sent': return Send;
        case 'drafts': return FileText;
        case 'archive': return Archive;
        case 'trash': return Trash2;
        case 'spam': return AlertTriangle;
        default: return Inbox;
    }
};

const senderLabel = (message: Message) => message.from_name || message.from_email || 'Unknown sender';

export default function MailIndex({ folders, views, activeFolder, activeView, messages, selected, thread, filters, mailbox }: Props) {
    const [search, setSearch] = useState(filters.q ?? '');
    const [syncing, setSyncing] = useState(false);
    const [aiBusy, setAiBusy] = useState(false);
    const [aiResult, setAiResult] = useState<{ summary: string; intent: string; sentiment: string; urgency: string; confidence: number; reasoning: string; action_items: string[]; next_action: string; suggested_reply: string } | null>(null);
    const [selectedIds, setSelectedIds] = useState<number[]>([]);
    useEffect(() => setAiResult(null), [selected?.id]);
    useEffect(() => setSelectedIds([]), [activeFolder?.id, activeView, messages.current_page]);

    const folderUrl = (folder: Folder) => `/mail?folder=${folder.id}`;
    const selectUrl = (message: Message) => {
        const params = new URLSearchParams();
        if (activeFolder) params.set('folder', String(activeFolder.id));
        if (activeView) params.set('view', activeView);
        if (filters.q) params.set('q', filters.q);
        if (filters.unread) params.set('unread', '1');
        if (filters.starred) params.set('starred', '1');
        params.set('id', String(message.id));
        return `/mail?${params.toString()}`;
    };

    const runSearch = (event: FormEvent) => {
        event.preventDefault();
        router.get('/mail', { folder: activeFolder?.id, view: activeView || undefined, q: search || undefined }, { preserveState: true, replace: true });
    };

    const sync = (full = false) => {
        setSyncing(true);
        router.post('/mail/sync', { full }, { preserveScroll: true, onFinish: () => setSyncing(false) });
    };

    const updateMessage = (action: 'read' | 'unread' | 'star' | 'unstar' | 'move' | 'trash' | 'restore', folderId?: number) => {
        if (!selected) return;
        router.post(`/mail/messages/${selected.id}/action`, { action, folder_id: folderId }, { preserveScroll: true });
    };

    const cancelOutgoing = () => selected && router.post(`/mail/messages/${selected.id}/cancel`, {}, { preserveScroll: true });
    const retryOutgoing = () => selected && router.post(`/mail/messages/${selected.id}/retry`, {}, { preserveScroll: true });
    const runAi = async (action: 'suggest_reply' | 'summarize' | 'action_items' | 'next_action' | 'shorter' | 'professional' | 'friendly' = 'suggest_reply') => {
        if (!selected) return;
        setAiBusy(true);
        try {
            const { data } = await axios.post(`/mail/messages/${selected.id}/ai`, { action, draft: aiResult?.suggested_reply });
            setAiResult(data);
        } finally {
            setAiBusy(false);
        }
    };
    const bulk = (bulkAction: 'read' | 'unread' | 'star' | 'unstar' | 'archive' | 'trash' | 'restore') => {
        if (!selectedIds.length) return;
        router.post('/mail/messages/bulk-action', { message_ids: selectedIds, action: bulkAction }, { preserveScroll: true, onSuccess: () => setSelectedIds([]) });
    };
    const toggleSelected = (id: number) => setSelectedIds((current) => current.includes(id) ? current.filter((value) => value !== id) : [...current, id]);

    const archiveFolder = folders.find((folder) => folder.special_use === 'archive');

    return (
        <AppLayout title="Mail">
            {!mailbox.configured ? (
                <div className="mb-4 flex items-center justify-between gap-3 rounded-md border border-amber-500/30 bg-amber-500/5 px-4 py-3 text-sm">
                    <div className="flex items-center gap-2 text-amber-200"><AlertTriangle className="size-4" /> Connect your Hostinger mailbox to begin synchronization.</div>
                    <Button asChild size="sm" variant="outline"><Link href="/settings/mailbox">Configure mailbox</Link></Button>
                </div>
            ) : mailbox.last_error ? (
                <div className="mb-4 rounded-md border border-red-500/30 bg-red-500/5 px-4 py-3 text-sm text-red-300">{mailbox.last_error}</div>
            ) : null}

            <div className="mb-4 flex items-center justify-between gap-4">
                <div>
                    <h1 className="text-xl font-semibold tracking-tight">Mail</h1>
                    <p className="mt-0.5 text-xs text-muted-foreground">
                        {mailbox.email || 'Mailbox not connected'}
                        {mailbox.last_synced_at && ` · synced ${new Date(mailbox.last_synced_at).toLocaleString()}`}
                    </p>
                </div>
                <div className="flex gap-2">
                    {mailbox.configured && <Button asChild size="sm"><Link href="/mail/compose"><PenLine /> Compose</Link></Button>}
                    {mailbox.configured && folders.length === 0 && <Button variant="outline" size="sm" onClick={() => sync(true)} disabled={syncing}>{syncing ? <Loader2 className="animate-spin" /> : <RefreshCw />} Import mailbox</Button>}
                    {mailbox.configured && folders.length > 0 && <Button variant="outline" size="sm" onClick={() => sync(false)} disabled={syncing}>{syncing ? <Loader2 className="animate-spin" /> : <RefreshCw />} Sync</Button>}
                </div>
            </div>

            <div className="grid min-h-[calc(100vh-10rem)] overflow-hidden rounded-lg border bg-card lg:grid-cols-[190px_340px_minmax(0,1fr)] xl:grid-cols-[210px_380px_minmax(0,1fr)]">
                <aside className="hidden border-r bg-background/30 lg:block">
                    <div className="border-b px-3 py-3 text-[10px] font-semibold uppercase tracking-[0.16em] text-muted-foreground">Folders</div>
                    <nav className="space-y-0.5 p-2">
                        {views.map((view) => (
                            <Link key={view.key} href={`/mail?view=${view.key}`} className={cn('flex h-8 items-center gap-2 rounded-md px-2 text-sm transition-colors', activeView === view.key ? 'bg-accent text-foreground' : 'text-muted-foreground hover:bg-accent/70 hover:text-foreground')}>
                                {view.key === 'drafts' ? <FileText className="size-3.5" /> : view.key === 'scheduled' ? <RefreshCw className="size-3.5" /> : <Send className="size-3.5" />}
                                <span className="min-w-0 flex-1 truncate">{view.name}</span>
                                {view.count > 0 && <span className="font-mono text-[10px] text-foreground">{view.count}</span>}
                            </Link>
                        ))}
                        <div className="my-2 border-t" />
                        {folders.map((folder) => {
                            const Icon = iconFor(folder);
                            const active = activeFolder?.id === folder.id;
                            return (
                                <Link key={folder.id} href={folderUrl(folder)} className={cn('flex h-8 items-center gap-2 rounded-md px-2 text-sm transition-colors', active ? 'bg-accent text-foreground' : 'text-muted-foreground hover:bg-accent/70 hover:text-foreground')}>
                                    <Icon className="size-3.5" />
                                    <span className="min-w-0 flex-1 truncate">{folder.name.replace(/^INBOX[./]/i, '')}</span>
                                    {folder.unread_count > 0 && <span className="font-mono text-[10px] text-foreground">{folder.unread_count > 999 ? '999+' : folder.unread_count}</span>}
                                </Link>
                            );
                        })}
                    </nav>
                </aside>

                <section className={cn('border-r', selected && 'hidden lg:block')}>
                    <div className="border-b p-3">
                        <form onSubmit={runSearch} className="relative">
                            <Search className="absolute left-2.5 top-1/2 size-3.5 -translate-y-1/2 text-muted-foreground" />
                            <Input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Search mail" className="h-8 pl-8 text-sm" />
                        </form>
                        <div className="mt-2 flex items-center justify-between text-xs text-muted-foreground">
                            <label className="flex items-center gap-2"><input type="checkbox" className="size-3.5 accent-primary" checked={messages.data.length > 0 && selectedIds.length === messages.data.length} onChange={(event) => setSelectedIds(event.target.checked ? messages.data.map((message) => message.id) : [])} /><span>{activeView ? views.find((view) => view.key === activeView)?.name : activeFolder?.name ?? 'Messages'}</span></label><span>{fmt(messages.total)}</span>
                        </div>
                        {selectedIds.length > 0 && <div className="mt-2 flex flex-wrap items-center gap-1 rounded-md border bg-card p-1"><span className="px-2 text-[11px] text-muted-foreground">{selectedIds.length} selected</span><Button size="sm" variant="ghost" onClick={() => bulk('read')}>Read</Button><Button size="sm" variant="ghost" onClick={() => bulk('unread')}>Unread</Button><Button size="sm" variant="ghost" onClick={() => bulk('star')}><Star /> Star</Button>{activeFolder?.special_use === 'trash' ? <Button size="sm" variant="ghost" onClick={() => bulk('restore')}><Inbox /> Restore</Button> : <><Button size="sm" variant="ghost" onClick={() => bulk('archive')}><Archive /> Archive</Button><Button size="sm" variant="ghost" onClick={() => bulk('trash')}><Trash2 /> Trash</Button></>}</div>}
                    </div>
                    {messages.data.length === 0 ? (
                        <EmptyState icon={MailOpen} title="No messages" text={mailbox.configured ? 'Synchronize the mailbox or try another folder.' : 'Connect your mailbox to import mail.'} />
                    ) : (
                        <div className="divide-y">
                            {messages.data.map((message) => (
                                <div key={message.id} className={cn('flex items-start gap-2 px-3 py-3 transition-colors hover:bg-accent/60', selected?.id === message.id && 'bg-accent', !message.is_read && 'bg-foreground/[0.025]')}>
                                    <input type="checkbox" aria-label={`Select ${message.subject || 'message'}`} className="mt-1 size-3.5 shrink-0 accent-primary" checked={selectedIds.includes(message.id)} onChange={() => toggleSelected(message.id)} />
                                    <Link href={selectUrl(message)} className="min-w-0 flex-1">
                                    <div className="flex items-center gap-2">
                                        <span className={cn('min-w-0 flex-1 truncate text-sm', !message.is_read && 'font-semibold')}>{senderLabel(message)}</span>
                                        {message.is_starred && <Star className="size-3 fill-current text-amber-400" />}
                                        <span className="shrink-0 text-[10px] text-muted-foreground">{message.message_at ? new Date(message.message_at).toLocaleDateString(undefined, { month: 'short', day: 'numeric' }) : ''}</span>
                                    </div>
                                    <div className={cn('mt-1 truncate text-xs', !message.is_read ? 'text-foreground' : 'text-muted-foreground')}>{message.subject || '(no subject)'}</div>
                                    <div className="mt-1 line-clamp-1 text-xs text-muted-foreground">{message.readable_body?.replace(/\s+/g, ' ') || 'No text preview'}</div>
                                    </Link>
                                </div>
                            ))}
                        </div>
                    )}
                </section>

                <main className={cn('min-w-0', !selected && 'hidden lg:block')}>
                    {selected ? (
                        <div className="h-full">
                            <div className="flex items-center gap-3 border-b px-4 py-3 lg:px-6">
                                <Button asChild variant="ghost" size="icon" className="lg:hidden"><Link href={activeFolder ? folderUrl(activeFolder) : '/mail'}><ChevronLeft /></Link></Button>
                                <div className="min-w-0 flex-1">
                                    <h2 className="truncate text-base font-semibold">{selected.subject || '(no subject)'}</h2>
                                    <div className="mt-0.5 text-xs text-muted-foreground">{selected.direction === 'outgoing' ? selected.status : thread.length > 1 ? `${thread.length} messages in this conversation` : 'Single message'}{selected.scheduled_at && ` · ${dateTime(selected.scheduled_at)}`}</div>
                                </div>
                                <div className="flex gap-1">
                                    {['draft', 'scheduled', 'queued'].includes(selected.status) && <Button variant="ghost" size="sm" onClick={cancelOutgoing}>Cancel</Button>}
                                    {['draft', 'scheduled', 'failed'].includes(selected.status) && <Button asChild variant="ghost" size="sm"><Link href={`/mail/messages/${selected.id}/edit`}><PenLine /> Edit</Link></Button>}
                                    {selected.status === 'failed' && <Button variant="ghost" size="sm" onClick={retryOutgoing}><RefreshCw /> Retry</Button>}
                                    {mailbox.ai_enabled && selected.direction === 'incoming' && <Button variant="ghost" size="sm" onClick={() => runAi()} disabled={aiBusy}>{aiBusy ? <Loader2 className="animate-spin" /> : <Sparkles />} AI brief</Button>}
                                    <Button variant="ghost" size="icon" title={selected.is_read ? 'Mark unread' : 'Mark read'} onClick={() => updateMessage(selected.is_read ? 'unread' : 'read')}>{selected.is_read ? <Mail className="size-4" /> : <MailOpen className="size-4" />}</Button>
                                    <Button variant="ghost" size="icon" title={selected.is_starred ? 'Remove star' : 'Star'} onClick={() => updateMessage(selected.is_starred ? 'unstar' : 'star')}><Star className={cn('size-4', selected.is_starred && 'fill-current text-amber-400')} /></Button>
                                    {archiveFolder && activeFolder?.id !== archiveFolder.id && <Button variant="ghost" size="icon" title="Archive" onClick={() => updateMessage('move', archiveFolder.id)}><Archive className="size-4" /></Button>}
                                    {activeFolder?.special_use === 'trash' ? <Button variant="ghost" size="sm" title="Restore to inbox" onClick={() => updateMessage('restore')}><Inbox className="size-4" /> Restore</Button> : <Button variant="ghost" size="icon" title="Move to trash" onClick={() => updateMessage('trash')}><Trash2 className="size-4" /></Button>}
                                    <Button asChild variant="ghost" size="sm"><Link href={`/mail/compose?message=${selected.id}&mode=reply`}><Reply /> Reply</Link></Button>
                                    <Button asChild variant="ghost" size="sm"><Link href={`/mail/compose?message=${selected.id}&mode=forward`}><Forward /> Forward</Link></Button>
                                </div>
                            </div>
                            <div className="max-h-[calc(100vh-14rem)] overflow-y-auto px-4 py-5 lg:px-6">
                                {selected.send_error && <div className="mb-4 rounded-md border border-amber-500/30 bg-amber-500/5 px-3 py-2 text-xs text-amber-200">{selected.send_error}</div>}
                                {aiResult && (
                                    <div className="mb-5 rounded-md border bg-background/50 p-4">
                                        <div className="flex items-center gap-2 text-xs font-semibold uppercase tracking-[0.12em] text-muted-foreground"><Sparkles className="size-3.5" /> AI draft · review before use</div>
                                        <p className="mt-3 text-sm">{aiResult.summary}</p>
                                        <div className="mt-2 text-xs capitalize text-muted-foreground">{aiResult.intent} · {aiResult.sentiment} · {aiResult.urgency} urgency · {Math.round(aiResult.confidence * 100)}% confidence</div>
                                        {aiResult.action_items?.length > 0 && <ul className="mt-3 list-disc space-y-1 pl-5 text-xs text-muted-foreground">{aiResult.action_items.map((item) => <li key={item}>{item}</li>)}</ul>}
                                        {aiResult.next_action && <p className="mt-3 text-xs"><span className="font-medium">Next action:</span> {aiResult.next_action}</p>}
                                        <div className="mt-4 whitespace-pre-wrap rounded-md border bg-card p-3 text-sm">{aiResult.suggested_reply}</div>
                                        <div className="mt-3 flex flex-wrap gap-2"><Button size="sm" variant="outline" onClick={() => navigator.clipboard.writeText(aiResult.suggested_reply)}><Copy /> Copy draft</Button><Button size="sm" variant="ghost" onClick={() => runAi('shorter')} disabled={aiBusy}>Shorter</Button><Button size="sm" variant="ghost" onClick={() => runAi('professional')} disabled={aiBusy}>Professional</Button><Button size="sm" variant="ghost" onClick={() => runAi('friendly')} disabled={aiBusy}>Friendly</Button></div>
                                    </div>
                                )}
                                {(thread.length ? thread : [selected]).map((message) => (
                                    <article key={message.id} className="mb-5 border-b pb-5 last:border-0">
                                        <div className="mb-4 flex items-start gap-3">
                                            <div className="grid size-8 shrink-0 place-items-center rounded-full border bg-muted text-xs font-medium">{senderLabel(message).slice(0, 1).toUpperCase()}</div>
                                            <div className="min-w-0 flex-1">
                                                <div className="truncate text-sm font-medium">{senderLabel(message)}</div>
                                                <div className="truncate text-xs text-muted-foreground">{message.from_email}</div>
                                            </div>
                                            <time className="shrink-0 text-[11px] text-muted-foreground">{message.message_at ? dateTime(message.message_at) : ''}</time>
                                        </div>
                                        {message.html_body
                                            ? <EmailHtml html={message.html_body} subject={message.subject} />
                                            : <div className="whitespace-pre-wrap break-words text-sm leading-6 text-foreground/90">{message.readable_body || 'This message has no readable body.'}</div>}
                                        {!!message.attachments?.length && (
                                            <div className="mt-5 flex flex-wrap gap-2">{message.attachments.map((attachment) => <div key={attachment.id} className="flex items-center overflow-hidden rounded-md border text-xs text-muted-foreground"><a href={`/mail/attachments/${attachment.id}/download`} className="px-2.5 py-1.5 transition-colors hover:bg-accent hover:text-foreground">{attachment.filename} · {fmt(attachment.size_bytes)} B</a>{(['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'application/pdf', 'text/plain', 'text/csv'].includes(attachment.mime_type ?? '')) && <a href={`/mail/attachments/${attachment.id}/preview`} target="_blank" rel="noreferrer" className="border-l px-2.5 py-1.5 transition-colors hover:bg-accent hover:text-foreground">Preview</a>}</div>)}</div>
                                        )}
                                    </article>
                                ))}
                                {selected.contact && (
                                    <div className="mt-6 rounded-md border bg-background/40 p-4">
                                        <div className="text-[10px] font-semibold uppercase tracking-[0.14em] text-muted-foreground">Linked contact</div>
                                        <Link href={`/contacts/${selected.contact.id}`} className="mt-2 block text-sm font-medium hover:underline">{selected.contact.business || selected.contact.company || selected.contact.email}</Link>
                                        <div className="mt-1 text-xs capitalize text-muted-foreground">{selected.contact.pipeline_status.replace('_', ' ')}</div>
                                    </div>
                                )}
                            </div>
                        </div>
                    ) : (
                        <div className="grid h-full place-items-center p-8 text-center text-sm text-muted-foreground">Select a message to read the conversation.</div>
                    )}
                </main>
            </div>
        </AppLayout>
    );
}
