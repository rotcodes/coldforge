import { FormEvent, useEffect, useMemo, useRef, useState } from 'react';
import axios from 'axios';
import { useForm } from '@inertiajs/react';
import Editor, { type OnMount } from '@monaco-editor/react';
import { Code2, Eye, Loader2, Monitor, RefreshCw, Save, Smartphone, Sparkles } from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import Field from '@/Components/Field';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import { Textarea } from '@/Components/ui/textarea';
import { Tabs, TabsList, TabsTrigger } from '@/Components/ui/tabs';
import { useTheme } from '@/lib/theme';
import { cn, fillVars } from '@/lib/utils';
import type { Template } from '@/types';

const SAMPLE: Record<string, string> = {
    email: 'alex@acme.com',
    first_name: 'Alex',
    last_name: 'Morgan',
    company: 'Acme Inc',
    business: 'Acme Equipment Co',
    industry: 'Construction equipment',
    industry_label: 'equipment dealership',
    workflow_examples: 'equipment inquiries, sales follow-ups, parts requests, and service work across the dealership',
    tracking_examples: 'customer inquiries, sales follow-ups, parts requests, service jobs, and customer history',
    city_state: 'Fort Wayne, IN',
    website: 'acme.com',
    decision_maker: 'Richard',
    dm_role: 'Operations Manager',
    country: 'US',
    unsubscribe_url: '#',
};

const STARTER = `<!DOCTYPE html>
<html>
<body style="margin:0;padding:24px;font-family:-apple-system,Segoe UI,Helvetica,Arial,sans-serif;font-size:15px;line-height:1.6;color:#1f2937">
  <p>Hey,</p>
  <p>Quick question &mdash; how do you currently manage your daily business work like {{workflow_examples}}?</p>
  <p>If some of it is still manual, we can help you build software where everything is easier to manage from one place. You can track {{tracking_examples}} without using too many separate tools.</p>
  <p>Would you be open to a quick chat call to discuss it? I can show you some demos as well.</p>
  <p style="margin-top:24px">you can find us here: digitelly.net</p>
  <p>Regards,<br>Luke Wright<br>luke@digitelly.net</p>
</body>
</html>
`;

/** Smart variable sidebar grouped by purpose. Clicking inserts {{name}} at the editor caret. */
const VAR_GROUPS: { name: string; vars: { key: string; label: string; help: string }[] }[] = [
    { name: 'Industry (smart)', vars: [
        { key: 'industry_label', label: 'Industry label', help: 'e.g. "equipment dealership" — editable in Industries' },
        { key: 'workflow_examples', label: 'Workflow examples', help: 'Comma-separated workflows for this industry' },
        { key: 'tracking_examples', label: 'Tracking examples', help: 'Comma-separated things software could track' },
    ] },
    { name: 'Contact', vars: [
        { key: 'email', label: 'Email', help: '' },
        { key: 'business', label: 'Business', help: 'Business/company name with fallback' },
        { key: 'industry', label: 'Industry (raw)', help: 'Free-text industry from CSV' },
        { key: 'city_state', label: 'City / state', help: '' },
        { key: 'website', label: 'Website', help: '' },
        { key: 'decision_maker', label: 'Decision maker', help: '' },
        { key: 'dm_role', label: 'DM role', help: '' },
        { key: 'first_name', label: 'First name', help: '' },
        { key: 'last_name', label: 'Last name', help: '' },
    ] },
    { name: 'Advanced (lead-level)', vars: [
        { key: 'personalized_opener', label: 'Opener', help: 'Lead-level opener text if researched' },
        { key: 'pain_evidence', label: 'Pain', help: 'Lead-level pain description' },
        { key: 'recommended_offer', label: 'Offer', help: 'Lead-level recommended offer' },
        { key: 'why_now_trigger', label: 'Why now', help: '' },
    ] },
];

export default function TemplateEditor({ template, fields }: { template: Template | null; fields: string[] }) {
    const { theme } = useTheme();
    const form = useForm({
        name: template?.name ?? '',
        subject: template?.subject ?? '',
        from_name: template?.from_name ?? '',
        from_email: template?.from_email ?? '',
        reply_to: template?.reply_to ?? '',
        html_body: template?.html_body ?? STARTER,
        text_body: template?.text_body ?? '',
    });
    const editorRef = useRef<Parameters<OnMount>[0] | null>(null);
    const [preview, setPreview] = useState(form.data.html_body);
    const [pane, setPane] = useState<'code' | 'preview'>('code');
    const [device, setDevice] = useState<'desktop' | 'mobile'>('desktop');
    const [livePreview, setLivePreview] = useState<{ subject: string; html: string; contact: { email: string; business: string | null; industry: string | null; tier: number }; used_fallback: string[] } | null>(null);
    const [livePreviewBusy, setLivePreviewBusy] = useState(false);

    useEffect(() => {
        const t = setTimeout(() => setPreview(form.data.html_body), 300);
        return () => clearTimeout(t);
    }, [form.data.html_body]);

    const detected = useMemo(() => {
        const found = new Set<string>();
        for (const m of `${form.data.subject} ${form.data.html_body}`.matchAll(/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/g)) found.add(m[1]);
        return [...found];
    }, [form.data.subject, form.data.html_body]);

    const save = (e?: FormEvent) => {
        e?.preventDefault();
        template ? form.put(`/templates/${template.id}`, { preserveScroll: true }) : form.post('/templates');
    };

    useEffect(() => {
        const onKey = (e: KeyboardEvent) => {
            if ((e.ctrlKey || e.metaKey) && e.key === 's') { e.preventDefault(); save(); }
        };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    });

    const insertVar = (name: string) => {
        const ed = editorRef.current;
        const token = `{{${name}}}`;
        if (ed) {
            ed.executeEdits('insert-var', [{ range: ed.getSelection()!, text: token, forceMoveMarkers: true }]);
            ed.focus();
        } else {
            form.setData('html_body', form.data.html_body + token);
        }
        setPane('code');
    };

    const renderLivePreview = async () => {
        if (!template?.id) return;
        setLivePreviewBusy(true);
        try {
            const { data } = await axios.post('/campaigns/preview', { template_id: template.id });
            setLivePreview(data);
        } catch (e: any) {
            setLivePreview(null);
        } finally {
            setLivePreviewBusy(false);
        }
    };

    const title = template ? template.name : 'New template';

    return (
        <AppLayout title={title} crumbs={[{ label: 'Templates', href: '/templates' }, { label: title }]}>
            <form onSubmit={save} noValidate>
                <div className="mb-4 flex flex-col gap-3 sm:flex-row sm:items-start">
                    <div className="flex-1">
                        <Input
                            value={form.data.name}
                            onChange={(e) => form.setData('name', e.target.value)}
                            placeholder="Template name"
                            aria-label="Template name"
                            className="h-11 border-transparent bg-transparent px-0 text-xl font-semibold shadow-none focus-visible:border-input focus-visible:px-3"
                        />
                        {form.errors.name && <p className="text-xs font-medium text-red-500">{form.errors.name}</p>}
                    </div>
                    <Button type="submit" disabled={form.processing}>
                        {form.processing ? <Loader2 className="animate-spin" /> : <Save />} Save
                    </Button>
                </div>

                <Card className="mb-4 grid gap-4 p-4 sm:grid-cols-2 lg:grid-cols-4">
                    <Field label="Subject" htmlFor="subject" error={form.errors.subject} className="sm:col-span-2">
                        <Input id="subject" value={form.data.subject} onChange={(e) => form.setData('subject', e.target.value)} placeholder="Quick question about your {{industry_label}}" />
                    </Field>
                    <Field label="From name" htmlFor="from_name" error={form.errors.from_name}>
                        <Input id="from_name" value={form.data.from_name} onChange={(e) => form.setData('from_name', e.target.value)} placeholder="Luke Wright" />
                    </Field>
                    <Field label="From email" htmlFor="from_email" error={form.errors.from_email}>
                        <Input id="from_email" type="email" value={form.data.from_email} onChange={(e) => form.setData('from_email', e.target.value)} placeholder="luke@digitelly.net" />
                    </Field>
                    <Field label="Reply-to" htmlFor="reply_to" error={form.errors.reply_to} hint="Optional" className="sm:col-span-2 lg:col-span-1">
                        <Input id="reply_to" type="email" value={form.data.reply_to} onChange={(e) => form.setData('reply_to', e.target.value)} />
                    </Field>
                    <div className="flex flex-wrap items-end gap-1.5 sm:col-span-2 lg:col-span-3">
                        <span className="mr-1 pb-1 text-xs text-muted-foreground">Variables used:</span>
                        {detected.length ? (
                            detected.map((v) => (
                                <span key={v} className={cn('rounded-md px-2 py-0.5 font-mono text-xs', fields.includes(v) ? 'bg-primary/15 text-primary' : 'bg-amber-500/15 text-amber-600 dark:text-amber-400')} title={fields.includes(v) ? undefined : 'Not a known variable — it will render as fallback or empty'}>
                                    {v}
                                </span>
                            ))
                        ) : (
                            <span className="pb-1 text-xs text-muted-foreground">none</span>
                        )}
                    </div>
                </Card>

                <div className="mb-3 flex flex-wrap items-center gap-2">
                    <Tabs value={pane} onValueChange={(v) => setPane(v as 'code' | 'preview')} className="xl:hidden">
                        <TabsList>
                            <TabsTrigger value="code"><Code2 className="mr-1.5 size-3.5" /> HTML</TabsTrigger>
                            <TabsTrigger value="preview"><Eye className="mr-1.5 size-3.5" /> Preview</TabsTrigger>
                        </TabsList>
                    </Tabs>
                    {template && (
                        <Button type="button" variant="outline" size="sm" onClick={renderLivePreview} disabled={livePreviewBusy}>
                            {livePreviewBusy ? <Loader2 className="animate-spin" /> : <Sparkles />} Preview with real contact
                        </Button>
                    )}
                    <div className="ml-auto hidden gap-1 xl:flex">
                        <Button type="button" variant={device === 'desktop' ? 'secondary' : 'ghost'} size="icon" className="size-8" onClick={() => setDevice('desktop')} aria-label="Desktop preview">
                            <Monitor />
                        </Button>
                        <Button type="button" variant={device === 'mobile' ? 'secondary' : 'ghost'} size="icon" className="size-8" onClick={() => setDevice('mobile')} aria-label="Mobile preview">
                            <Smartphone />
                        </Button>
                    </div>
                </div>

                <div className="grid gap-4 xl:grid-cols-[1fr_1fr_220px]">
                    <Card className={cn('overflow-hidden', pane !== 'code' && 'hidden xl:block')}>
                        <Editor
                            height="560px"
                            language="html"
                            theme={theme === 'dark' ? 'vs-dark' : 'light'}
                            value={form.data.html_body}
                            onChange={(v) => form.setData('html_body', v ?? '')}
                            onMount={(ed) => (editorRef.current = ed)}
                            options={{ minimap: { enabled: false }, fontSize: 13, fontFamily: 'JetBrains Mono Variable, monospace', wordWrap: 'on', scrollBeyondLastLine: false, padding: { top: 12 }, tabSize: 2 }}
                            loading={<Loader2 className="size-5 animate-spin text-muted-foreground" />}
                        />
                    </Card>

                    <Card className={cn('flex flex-col overflow-hidden bg-neutral-100 dark:bg-neutral-900', pane !== 'preview' && 'hidden xl:flex')}>
                        {livePreview && (
                            <div className="border-b bg-background/80 px-3 py-2 text-xs backdrop-blur">
                                <div className="flex items-center gap-2">
                                    <span className="rounded-md bg-emerald-500/10 px-1.5 py-0.5 font-medium text-emerald-600">Live render</span>
                                    <span className="truncate text-muted-foreground">
                                        {livePreview.contact.email} · {livePreview.contact.business || 'no business'} · {livePreview.contact.industry || 'no industry'}
                                    </span>
                                    <Button type="button" variant="ghost" size="icon" className="ml-auto size-6" onClick={renderLivePreview} disabled={livePreviewBusy} aria-label="Refresh">
                                        <RefreshCw className={cn('size-3', livePreviewBusy && 'animate-spin')} />
                                    </Button>
                                </div>
                                <div className="mt-1 truncate font-medium">Subject: {livePreview.subject}</div>
                                {livePreview.used_fallback.length > 0 && (
                                    <div className="mt-1 text-amber-600">⚠ Used fallback for: {livePreview.used_fallback.join(', ')}</div>
                                )}
                            </div>
                        )}
                        <iframe
                            title="Email preview"
                            sandbox=""
                            srcDoc={livePreview ? livePreview.html : fillVars(preview, SAMPLE)}
                            className={cn('flex-1 bg-white transition-all duration-300', device === 'mobile' ? 'mx-auto w-[375px] border-x' : 'w-full')}
                        />
                    </Card>

                    <Card className="hidden h-fit max-h-[560px] overflow-y-auto p-3 xl:block">
                        <div className="mb-2 flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                            <Sparkles className="size-3.5 text-primary" /> Variables
                        </div>
                        {VAR_GROUPS.map((g) => (
                            <div key={g.name} className="mb-3">
                                <div className="mb-1 text-[10px] font-semibold uppercase tracking-wider text-muted-foreground/70">{g.name}</div>
                                <ul className="space-y-0.5">
                                    {g.vars.map((v) => (
                                        <li key={v.key}>
                                            <button
                                                type="button"
                                                onClick={() => insertVar(v.key)}
                                                className="group w-full rounded-md px-2 py-1 text-left transition-colors hover:bg-accent focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                                title={v.help}
                                            >
                                                <div className="font-mono text-[11px] text-primary">{`{{${v.key}}}`}</div>
                                                <div className="text-[10px] text-muted-foreground group-hover:text-foreground">{v.label}</div>
                                            </button>
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        ))}
                        <p className="mt-2 border-t pt-2 text-[10px] leading-relaxed text-muted-foreground">
                            Industry vars are editable in <strong>Industries</strong>. Blank-field fallbacks are editable in <strong>Settings → Fallbacks</strong>.
                        </p>
                    </Card>
                </div>
                {form.errors.html_body && <p className="mt-2 text-xs font-medium text-red-500">{form.errors.html_body}</p>}

                <Card className="mt-4 p-4">
                    <Field label="Plain-text version" htmlFor="text_body" error={form.errors.text_body} hint="Optional but recommended. Plain-text alternatives help deliverability.">
                        <Textarea id="text_body" rows={5} className="font-mono text-xs" value={form.data.text_body} onChange={(e) => form.setData('text_body', e.target.value)} />
                    </Field>
                </Card>
            </form>
        </AppLayout>
    );
}
