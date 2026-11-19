import { FormEvent, useEffect, useRef, useState } from 'react';
import axios from 'axios';
import { Link, router } from '@inertiajs/react';
import { Bot, FilePenLine, Loader2, MessageSquarePlus, Send, Sparkles, Trash2, User } from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import EmptyState from '@/Components/EmptyState';
import { confirmDialog, visit } from '@/Components/ConfirmDialog';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Textarea } from '@/Components/ui/textarea';
import { cn } from '@/lib/utils';

type ConversationSummary = { id: number; title: string; updated_at: string };
type Message = { id: number; role: 'user' | 'assistant'; content: string; tool_calls?: unknown[] | null; created_at: string };
type Conversation = { id: number; title: string; messages: Message[] } | null;

type Props = {
    conversations: ConversationSummary[];
    conversation: Conversation;
    available: boolean;
    usage: { requests: number; tokens: number };
};

export default function IntelligenceIndex({ conversations, conversation, available, usage }: Props) {
    const [messages, setMessages] = useState<Message[]>(conversation?.messages ?? []);
    const [input, setInput] = useState('');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [suggestions, setSuggestions] = useState<string[]>([]);
    const [draft, setDraft] = useState<{ draft_id: number; edit_url: string; status: string } | null>(null);
    const bottomRef = useRef<HTMLDivElement>(null);

    useEffect(() => {
        setMessages(conversation?.messages ?? []);
        setSuggestions([]);
        setDraft(null);
        setError(null);
    }, [conversation?.id]);

    useEffect(() => bottomRef.current?.scrollIntoView({ behavior: 'smooth' }), [messages, busy]);

    const submit = async (event?: FormEvent, suggested?: string) => {
        event?.preventDefault();
        const message = (suggested ?? input).trim();
        if (!message || busy || !conversation) return;

        const optimistic: Message = { id: -Date.now(), role: 'user', content: message, created_at: new Date().toISOString() };
        setMessages((current) => [...current, optimistic]);
        setInput('');
        setBusy(true);
        setError(null);
        setSuggestions([]);
        setDraft(null);

        try {
            const { data } = await axios.post(`/intelligence/conversations/${conversation.id}/chat`, { message });
            setMessages((current) => [...current, data.message]);
            setSuggestions(data.suggestions ?? []);
            setDraft(data.draft?.draft_id ? data.draft : null);
            router.reload({ only: ['conversations', 'usage'] });
        } catch (exception: any) {
            setMessages((current) => current.filter((item) => item.id !== optimistic.id));
            setInput(message);
            setError(exception?.response?.data?.message ?? 'ColdForge Intelligence could not answer that request.');
        } finally {
            setBusy(false);
        }
    };

    const removeConversation = () => {
        if (!conversation) return;
        confirmDialog({
            title: 'Delete this conversation?',
            description: 'The Intelligence chat history will be permanently removed. Mail and drafts are not affected.',
            onConfirm: visit((options) => router.delete(`/intelligence/conversations/${conversation.id}`, options)),
        });
    };

    return (
        <AppLayout title="Intelligence">
            <div className="mb-4 flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                <div>
                    <div className="flex items-center gap-2"><Sparkles className="size-5" /><h1 className="text-xl font-semibold tracking-tight">ColdForge Intelligence</h1></div>
                    <p className="mt-1 text-sm text-muted-foreground">Ask about mail, campaigns, follow-ups, or pipeline work. It can create editable drafts, but never sends.</p>
                </div>
                <div className="flex items-center gap-2">
                    <span className="text-xs text-muted-foreground">Today: {usage.requests} requests · {usage.tokens.toLocaleString()} tokens</span>
                    <Button onClick={() => router.post('/intelligence/conversations')}><MessageSquarePlus /> New chat</Button>
                </div>
            </div>

            {!available && (
                <div className="mb-4 rounded-md border border-amber-500/30 bg-amber-500/5 px-4 py-3 text-sm text-amber-200">
                    Intelligence is unavailable. Enable AI processing in <Link className="underline" href="/settings/mailbox">Mailbox settings</Link> and configure GROQ_API_KEY.
                </div>
            )}

            <div className="grid min-h-[calc(100vh-11rem)] overflow-hidden rounded-lg border bg-card lg:grid-cols-[240px_minmax(0,1fr)]">
                <aside className="hidden border-r bg-background/30 lg:block">
                    <div className="border-b px-4 py-3 text-[10px] font-semibold uppercase tracking-[0.16em] text-muted-foreground">Conversations</div>
                    <div className="space-y-1 p-2">
                        {conversations.map((item) => (
                            <Link key={item.id} href={`/intelligence?conversation=${item.id}`} className={cn('block rounded-md px-3 py-2.5 transition-colors', conversation?.id === item.id ? 'bg-accent text-foreground' : 'text-muted-foreground hover:bg-accent/60 hover:text-foreground')}>
                                <div className="truncate text-sm font-medium">{item.title}</div>
                                <div className="mt-1 text-[10px]">{new Date(item.updated_at).toLocaleString()}</div>
                            </Link>
                        ))}
                    </div>
                </aside>

                <section className="flex min-h-[calc(100vh-11rem)] min-w-0 flex-col">
                    {conversation ? (
                        <>
                            <div className="flex items-center justify-between border-b px-4 py-3">
                                <div className="min-w-0"><div className="truncate text-sm font-medium">{conversation.title}</div><div className="text-xs text-muted-foreground">Read-only tools · explicit draft creation only</div></div>
                                <Button variant="ghost" size="icon" title="Delete conversation" onClick={removeConversation}><Trash2 /></Button>
                            </div>
                            <div className="min-h-0 flex-1 space-y-5 overflow-y-auto p-4 lg:p-6">
                                {messages.length === 0 && (
                                    <EmptyState icon={Bot} title="What should we look into?" text="Try “Show important unread mail”, “Which contacts need a reply?”, or “Draft a reply to message 42”." />
                                )}
                                {messages.map((message) => (
                                    <div key={message.id} className={cn('flex gap-3', message.role === 'user' && 'justify-end')}>
                                        {message.role === 'assistant' && <div className="grid size-8 shrink-0 place-items-center rounded-full border bg-muted"><Bot className="size-4" /></div>}
                                        <div className={cn('max-w-3xl whitespace-pre-wrap rounded-lg px-4 py-3 text-sm leading-6', message.role === 'user' ? 'bg-primary text-primary-foreground' : 'border bg-background/50')}>
                                            {message.content}
                                            {message.role === 'assistant' && message.tool_calls && message.tool_calls.length > 0 && <div className="mt-2 text-[10px] uppercase tracking-wide text-muted-foreground">Used {message.tool_calls.length} bounded workspace tool{message.tool_calls.length === 1 ? '' : 's'}</div>}
                                        </div>
                                        {message.role === 'user' && <div className="grid size-8 shrink-0 place-items-center rounded-full border bg-muted"><User className="size-4" /></div>}
                                    </div>
                                ))}
                                {busy && <div className="flex items-center gap-3 text-sm text-muted-foreground"><div className="grid size-8 place-items-center rounded-full border bg-muted"><Loader2 className="size-4 animate-spin" /></div>Reviewing ColdForge data…</div>}
                                {error && <div className="rounded-md border border-red-500/30 bg-red-500/5 px-4 py-3 text-sm text-red-300">{error}</div>}
                                {draft && <Card className="flex items-center justify-between gap-3 p-4"><div><div className="flex items-center gap-2 text-sm font-medium"><FilePenLine className="size-4" /> Editable reply draft created</div><p className="mt-1 text-xs text-muted-foreground">Nothing was sent. Review recipients and content before sending.</p></div><Button asChild variant="outline"><Link href={draft.edit_url}>Review draft</Link></Button></Card>}
                                {suggestions.length > 0 && <div className="flex flex-wrap gap-2">{suggestions.map((suggestion) => <Button key={suggestion} variant="outline" size="sm" onClick={() => submit(undefined, suggestion)} disabled={busy}>{suggestion}</Button>)}</div>}
                                <div ref={bottomRef} />
                            </div>
                            <form onSubmit={submit} className="border-t p-4">
                                <div className="mx-auto flex max-w-4xl items-end gap-2">
                                    <Textarea value={input} onChange={(event) => setInput(event.target.value)} onKeyDown={(event) => { if (event.key === 'Enter' && !event.shiftKey) { event.preventDefault(); submit(); } }} placeholder="Ask ColdForge…" rows={2} disabled={!available || busy} className="min-h-12 resize-none" />
                                    <Button type="submit" size="icon" disabled={!available || busy || !input.trim()} title="Send"><Send /></Button>
                                </div>
                                <p className="mt-2 text-center text-[10px] text-muted-foreground">Email text is treated as untrusted evidence. AI output may be wrong; review every draft.</p>
                            </form>
                        </>
                    ) : (
                        <div className="grid flex-1 place-items-center p-8"><EmptyState icon={Sparkles} title="Start an Intelligence chat" text="Create a conversation to inspect your workspace with bounded, review-only AI tools." action={<Button onClick={() => router.post('/intelligence/conversations')}><MessageSquarePlus /> New chat</Button>} /></div>
                    )}
                </section>
            </div>
        </AppLayout>
    );
}
