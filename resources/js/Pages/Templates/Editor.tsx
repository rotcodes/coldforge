import { FormEvent, useEffect, useMemo, useRef, useState } from 'react';
import { useForm } from '@inertiajs/react';
import Editor, { type OnMount } from '@monaco-editor/react';
import { Braces, Code2, Eye, Loader2, Monitor, Save, Smartphone } from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import Field from '@/Components/Field';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import { Textarea } from '@/Components/ui/textarea';
import { Tabs, TabsList, TabsTrigger } from '@/Components/ui/tabs';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuLabel, DropdownMenuTrigger } from '@/Components/ui/dropdown-menu';
import { useTheme } from '@/lib/theme';
import { cn, fillVars } from '@/lib/utils';
import type { Template } from '@/types';

const SAMPLE: Record<string, string> = {
    email: 'alex@acme.com',
    first_name: 'Alex',
    last_name: 'Morgan',
    company: 'Acme Inc',
    country: 'US',
    unsubscribe_url: '#',
};

const STARTER = `<!DOCTYPE html>
<html>
<body style="margin:0;padding:24px;font-family:-apple-system,Segoe UI,Helvetica,Arial,sans-serif;font-size:15px;line-height:1.6;color:#1f2937">
  <p>Hi {{first_name}},</p>
  <p>Write something short and specific to {{company}} here.</p>
  <p>Cheers,<br>Your name</p>
</body>
</html>
`;

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

    // Debounced live preview (300ms)
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

    // Ctrl/Cmd+S to save
    useEffect(() => {
        const onKey = (e: KeyboardEvent) => {
            if ((e.ctrlKey || e.metaKey) && e.key === 's') {
                e.preventDefault();
                save();
            }
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
                        <Input id="subject" value={form.data.subject} onChange={(e) => form.setData('subject', e.target.value)} placeholder="Quick question, {{first_name}}" />
                    </Field>
                    <Field label="From name" htmlFor="from_name" error={form.errors.from_name}>
                        <Input id="from_name" value={form.data.from_name} onChange={(e) => form.setData('from_name', e.target.value)} placeholder="Sam from Digitelly" />
                    </Field>
                    <Field label="From email" htmlFor="from_email" error={form.errors.from_email}>
                        <Input id="from_email" type="email" value={form.data.from_email} onChange={(e) => form.setData('from_email', e.target.value)} placeholder="sam@digitelly.net" />
                    </Field>
                    <Field label="Reply-to" htmlFor="reply_to" error={form.errors.reply_to} hint="Optional" className="sm:col-span-2 lg:col-span-1">
                        <Input id="reply_to" type="email" value={form.data.reply_to} onChange={(e) => form.setData('reply_to', e.target.value)} />
                    </Field>
                    <div className="flex flex-wrap items-end gap-1.5 sm:col-span-2 lg:col-span-3">
                        <span className="mr-1 pb-1 text-xs text-muted-foreground">Variables used:</span>
                        {detected.length ? (
                            detected.map((v) => (
                                <span key={v} className={cn('rounded-md px-2 py-0.5 font-mono text-xs', fields.includes(v) ? 'bg-primary/15 text-primary' : 'bg-amber-500/15 text-amber-600 dark:text-amber-400')} title={fields.includes(v) ? undefined : 'Not a known contact field; it will render empty'}>
                                    {v}
                                </span>
                            ))
                        ) : (
                            <span className="pb-1 text-xs text-muted-foreground">none</span>
                        )}
                    </div>
                </Card>

                <div className="mb-3 flex flex-wrap items-center gap-2">
                    <Tabs value={pane} onValueChange={(v) => setPane(v as 'code' | 'preview')} className="lg:hidden">
                        <TabsList>
                            <TabsTrigger value="code">
                                <Code2 className="mr-1.5 size-3.5" /> HTML
                            </TabsTrigger>
                            <TabsTrigger value="preview">
                                <Eye className="mr-1.5 size-3.5" /> Preview
                            </TabsTrigger>
                        </TabsList>
                    </Tabs>
                    <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                            <Button type="button" variant="outline" size="sm">
                                <Braces /> Insert variable
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="start" className="max-h-72 overflow-y-auto">
                            <DropdownMenuLabel className="text-xs text-muted-foreground">Contact fields</DropdownMenuLabel>
                            {fields.map((f) => (
                                <DropdownMenuItem key={f} onSelect={() => insertVar(f)} className="font-mono text-xs">
                                    {`{{${f}}}`}
                                </DropdownMenuItem>
                            ))}
                        </DropdownMenuContent>
                    </DropdownMenu>
                    <div className="ml-auto hidden gap-1 lg:flex">
                        <Button type="button" variant={device === 'desktop' ? 'secondary' : 'ghost'} size="icon" className="size-8" onClick={() => setDevice('desktop')} aria-label="Desktop preview">
                            <Monitor />
                        </Button>
                        <Button type="button" variant={device === 'mobile' ? 'secondary' : 'ghost'} size="icon" className="size-8" onClick={() => setDevice('mobile')} aria-label="Mobile preview">
                            <Smartphone />
                        </Button>
                    </div>
                </div>

                <div className="grid gap-4 lg:grid-cols-2">
                    <Card className={cn('overflow-hidden', pane !== 'code' && 'hidden lg:block')}>
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
                    <Card className={cn('flex justify-center overflow-hidden bg-neutral-100 p-0 dark:bg-neutral-900', pane !== 'preview' && 'hidden lg:flex')}>
                        <iframe
                            title="Email preview"
                            sandbox=""
                            srcDoc={fillVars(preview, SAMPLE)}
                            className={cn('h-[560px] bg-white transition-all duration-300', device === 'mobile' ? 'w-[375px] border-x' : 'w-full')}
                        />
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
