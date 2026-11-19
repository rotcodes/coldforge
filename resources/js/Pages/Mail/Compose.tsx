import { FormEvent, useRef, useState } from 'react';
import { Link, useForm } from '@inertiajs/react';
import { Bold, CalendarClock, ChevronLeft, Italic, Link2, Loader2, Paperclip, Save, Send } from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import Field from '@/Components/Field';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { Textarea } from '@/Components/ui/textarea';
import { cn } from '@/lib/utils';

type Template = { id: number; name: string; subject: string; html_body: string; text_body: string | null };
type Initial = { to: string; cc: string; bcc: string; subject: string; plain_body: string; html_body: string };

function RichComposer({ value, onChange }: { value: string; onChange: (value: string) => void }) {
    const ref = useRef<HTMLDivElement>(null);
    const run = (command: string, argument?: string) => {
        ref.current?.focus();
        document.execCommand(command, false, argument);
        onChange(ref.current?.innerHTML ?? '');
    };

    return (
        <div className="overflow-hidden rounded-md border border-input">
            <div className="flex items-center gap-1 border-b bg-muted/40 px-2 py-1.5">
                <Button type="button" variant="ghost" size="icon" className="size-7" onClick={() => run('bold')} aria-label="Bold"><Bold className="size-3.5" /></Button>
                <Button type="button" variant="ghost" size="icon" className="size-7" onClick={() => run('italic')} aria-label="Italic"><Italic className="size-3.5" /></Button>
                <Button type="button" variant="ghost" size="icon" className="size-7" onClick={() => { const url = window.prompt('Link URL'); if (url) run('createLink', url); }} aria-label="Insert link"><Link2 className="size-3.5" /></Button>
            </div>
            <div
                ref={ref}
                contentEditable
                suppressContentEditableWarning
                className="min-h-[300px] bg-background px-4 py-3 text-sm leading-6 outline-none"
                dangerouslySetInnerHTML={{ __html: value }}
                onInput={(event) => onChange(event.currentTarget.innerHTML)}
            />
        </div>
    );
}

export default function Compose({ mailbox, templates, source, mode, initial, editing }: { mailbox: { email: string | null; display_name: string | null; timezone: string; signature_html: string | null; signature_text: string | null }; templates: Template[]; source: { id: number } | null; mode: string; initial: Initial; editing: { id: number; status: string; scheduled_at: string | null } | null }) {
    const [showCopies, setShowCopies] = useState(!!(initial.cc || initial.bcc));
    const [bodyMode, setBodyMode] = useState<'rich' | 'plain'>(initial.html_body ? 'rich' : 'plain');
    const initialAction = editing?.status === 'scheduled' ? 'schedule' : editing?.status === 'draft' ? 'draft' : 'send';
    const [action, setAction] = useState<'draft' | 'send' | 'schedule'>(initialAction);
    const form = useForm({
        ...initial,
        action: initialAction as 'draft' | 'send' | 'schedule',
        scheduled_at: editing?.scheduled_at ?? '',
        source_message_id: source?.id ?? null as number | null,
        editing_message_id: editing?.id ?? null as number | null,
        attachments: [] as File[],
    });

    const insertTemplate = (id: string) => {
        const template = templates.find((item) => item.id === Number(id));
        if (!template) return;
        form.setData((data) => ({ ...data, subject: template.subject, html_body: template.html_body, plain_body: template.text_body ?? '' }));
        setBodyMode('rich');
    };

    const submit = (event: FormEvent, nextAction = action) => {
        event.preventDefault();
        form.transform((data) => ({
            ...data,
            action: nextAction,
            html_body: bodyMode === 'rich' ? data.html_body : '',
            plain_body: bodyMode === 'plain' ? data.plain_body : data.plain_body,
            scheduled_at: nextAction === 'schedule' && data.scheduled_at ? new Date(data.scheduled_at).toISOString() : null,
        }));
        form.post('/mail/messages', { forceFormData: true });
    };

    return (
        <AppLayout title={mode === 'reply' ? 'Reply' : mode === 'forward' ? 'Forward' : mode === 'edit' ? 'Edit message' : 'Compose'} crumbs={[{ label: 'Mail', href: '/mail' }, { label: mode === 'edit' ? 'Edit' : mode === 'compose' ? 'Compose' : mode === 'reply' ? 'Reply' : 'Forward' }]}>
            <div className="mx-auto max-w-4xl">
                <div className="mb-4 flex items-center justify-between gap-3">
                    <div className="flex items-center gap-3">
                        <Button asChild variant="ghost" size="icon"><Link href="/mail"><ChevronLeft /></Link></Button>
                        <div><h1 className="text-lg font-semibold">{mode === 'reply' ? 'Reply' : mode === 'forward' ? 'Forward message' : mode === 'edit' ? 'Edit outgoing message' : 'New message'}</h1><p className="text-xs text-muted-foreground">From {mailbox.display_name ? `${mailbox.display_name} <${mailbox.email}>` : mailbox.email}</p></div>
                    </div>
                    {templates.length > 0 && <Select onValueChange={insertTemplate}><SelectTrigger className="w-44"><SelectValue placeholder="Insert template" /></SelectTrigger><SelectContent>{templates.map((template) => <SelectItem key={template.id} value={String(template.id)}>{template.name}</SelectItem>)}</SelectContent></Select>}
                </div>

                <Card className="overflow-hidden">
                    <form onSubmit={submit} noValidate>
                        <div className="space-y-3 border-b p-4">
                            <Field label="To" htmlFor="compose-to" error={form.errors.to}>
                                <div className="flex gap-2"><Input id="compose-to" type="text" value={form.data.to} onChange={(e) => form.setData('to', e.target.value)} placeholder="client@example.com, another@example.com" /><Button type="button" variant="ghost" size="sm" onClick={() => setShowCopies((value) => !value)}>Cc/Bcc</Button></div>
                            </Field>
                            {showCopies && <div className="grid gap-3 sm:grid-cols-2"><Field label="Cc" htmlFor="compose-cc" error={form.errors.cc}><Input id="compose-cc" value={form.data.cc} onChange={(e) => form.setData('cc', e.target.value)} /></Field><Field label="Bcc" htmlFor="compose-bcc" error={form.errors.bcc}><Input id="compose-bcc" value={form.data.bcc} onChange={(e) => form.setData('bcc', e.target.value)} /></Field></div>}
                            <Field label="Subject" htmlFor="compose-subject" error={form.errors.subject}><Input id="compose-subject" value={form.data.subject} onChange={(e) => form.setData('subject', e.target.value)} /></Field>
                        </div>

                        <div className="p-4">
                            <div className="mb-3 flex items-center justify-between">
                                <div className="flex rounded-md border p-0.5 text-xs"><button type="button" onClick={() => setBodyMode('rich')} className={cn('rounded px-2.5 py-1', bodyMode === 'rich' && 'bg-accent text-foreground')}>Rich text</button><button type="button" onClick={() => setBodyMode('plain')} className={cn('rounded px-2.5 py-1', bodyMode === 'plain' && 'bg-accent text-foreground')}>Plain text</button></div>
                                <label className="inline-flex cursor-pointer items-center gap-2 rounded-md border px-2.5 py-1.5 text-xs text-muted-foreground hover:text-foreground"><Paperclip className="size-3.5" /> Attach files<input type="file" multiple className="sr-only" accept=".pdf,.doc,.docx,.xls,.xlsx,.csv,.txt,.png,.jpg,.jpeg,.gif,.webp,.zip" onChange={(e) => form.setData('attachments', Array.from(e.target.files ?? []))} /></label>
                            </div>
                            {bodyMode === 'rich' ? <RichComposer value={form.data.html_body} onChange={(value) => form.setData('html_body', value)} /> : <Textarea rows={14} value={form.data.plain_body} onChange={(e) => form.setData('plain_body', e.target.value)} className="resize-y leading-6" />}
                            {!!form.data.attachments.length && <div className="mt-3 flex flex-wrap gap-2">{form.data.attachments.map((file) => <span key={`${file.name}-${file.size}`} className="rounded-md border px-2 py-1 text-xs text-muted-foreground">{file.name}</span>)}</div>}
                            {(form.errors.html_body || form.errors.plain_body || form.errors.attachments) && <p className="mt-2 text-xs text-red-400">{form.errors.html_body || form.errors.plain_body || form.errors.attachments}</p>}
                        </div>

                        <div className="flex flex-col gap-3 border-t bg-muted/20 p-4 sm:flex-row sm:items-center sm:justify-between">
                            <div className="flex items-center gap-2">
                                <Select value={action} onValueChange={(value) => { setAction(value as typeof action); form.setData('action', value as typeof action); }}><SelectTrigger className="w-40"><SelectValue /></SelectTrigger><SelectContent><SelectItem value="send">Send now</SelectItem><SelectItem value="schedule">Schedule</SelectItem><SelectItem value="draft">Save draft</SelectItem></SelectContent></Select>
                                {action === 'schedule' && <Input type="datetime-local" value={form.data.scheduled_at} onChange={(e) => form.setData('scheduled_at', e.target.value)} className="w-auto" />}
                            </div>
                            <div className="flex justify-end gap-2">
                                <Button type="button" variant="outline" onClick={(event) => submit(event, 'draft')} disabled={form.processing}>{form.processing && action === 'draft' ? <Loader2 className="animate-spin" /> : <Save />} Save draft</Button>
                                <Button type="submit" disabled={form.processing || !form.data.to || !form.data.subject}>{form.processing ? <Loader2 className="animate-spin" /> : action === 'schedule' ? <CalendarClock /> : <Send />}{action === 'schedule' ? 'Schedule' : action === 'draft' ? 'Save' : 'Send'}</Button>
                            </div>
                        </div>
                    </form>
                </Card>
                <p className="mt-3 text-xs text-muted-foreground">Scheduled times display in {mailbox.timezone}. Sending is recorded in UTC and respects the configured daily recipient allowance.</p>
            </div>
        </AppLayout>
    );
}
