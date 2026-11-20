import { FormEvent, useState } from 'react';
import { router, useForm } from '@inertiajs/react';
import { AlertTriangle, CheckCircle2, Loader2, Mail, PauseCircle, PlugZap, RefreshCw, ShieldCheck } from 'lucide-react';
import SettingsLayout from '@/Layouts/SettingsLayout';
import Field from '@/Components/Field';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { Switch } from '@/Components/ui/switch';
import { Textarea } from '@/Components/ui/textarea';
import { cn, fmt } from '@/lib/utils';

type Mailbox = {
    email: string | null;
    display_name: string | null;
    reply_to: string | null;
    smtp_host: string;
    smtp_port: number;
    smtp_encryption: 'ssl' | 'tls' | 'none';
    imap_host: string;
    imap_port: number;
    imap_encryption: 'ssl' | 'tls' | 'none';
    daily_allowance: number;
    sent_today: number;
    timezone: string;
    smtp_status: string;
    imap_status: string;
    last_smtp_test_at: string | null;
    last_imap_test_at: string | null;
    last_synced_at: string | null;
    last_connection_error: string | null;
    signature_html: string | null;
    signature_text: string | null;
    physical_address: string | null;
    automation_paused: boolean;
    track_campaign_opens: boolean;
    track_campaign_clicks: boolean;
    ai_processing_enabled: boolean;
    ai_model: 'gpt-6.2-sol' | 'gpt-6.2-astra' | 'gpt-6.1-terra';
    has_password: boolean;
};

const statusStyle: Record<string, string> = {
    connected: 'text-emerald-400',
    failed: 'text-red-400',
    untested: 'text-amber-400',
    unconfigured: 'text-muted-foreground',
};

function ConnectionStatus({ label, status, at }: { label: string; status: string; at: string | null }) {
    const connected = status === 'connected';
    return (
        <div className="flex items-center gap-3 rounded-md border bg-background/40 px-3 py-2.5">
            {connected ? <CheckCircle2 className="size-4 text-emerald-400" /> : <AlertTriangle className={cn('size-4', statusStyle[status])} />}
            <div className="min-w-0 flex-1">
                <div className="text-sm font-medium">{label}</div>
                <div className={cn('text-xs capitalize', statusStyle[status])}>{status.replace('_', ' ')}</div>
            </div>
            {at && <div className="text-right text-[11px] text-muted-foreground">Tested<br />{new Date(at).toLocaleString()}</div>}
        </div>
    );
}

export default function MailboxSettings({ mailbox, timezones }: { mailbox: Mailbox; timezones: string[] }) {
    const [testing, setTesting] = useState<'smtp' | 'imap' | null>(null);
    const form = useForm({
        email: mailbox.email ?? '',
        display_name: mailbox.display_name ?? '',
        reply_to: mailbox.reply_to ?? '',
        password: '',
        smtp_host: mailbox.smtp_host,
        smtp_port: mailbox.smtp_port,
        smtp_encryption: mailbox.smtp_encryption,
        imap_host: mailbox.imap_host,
        imap_port: mailbox.imap_port,
        imap_encryption: mailbox.imap_encryption,
        daily_allowance: mailbox.daily_allowance,
        timezone: mailbox.timezone,
        signature_html: mailbox.signature_html ?? '',
        signature_text: mailbox.signature_text ?? '',
        physical_address: mailbox.physical_address ?? '',
        automation_paused: mailbox.automation_paused,
        track_campaign_opens: mailbox.track_campaign_opens,
        track_campaign_clicks: mailbox.track_campaign_clicks,
        ai_processing_enabled: mailbox.ai_processing_enabled,
        ai_model: mailbox.ai_model,
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.put('/settings/mailbox', { preserveScroll: true, onSuccess: () => form.reset('password') });
    };

    const test = (connection: 'smtp' | 'imap') => {
        setTesting(connection);
        router.post('/settings/mailbox/test', { connection }, { preserveScroll: true, onFinish: () => setTesting(null) });
    };

    const used = Math.min(100, (mailbox.sent_today / Math.max(1, mailbox.daily_allowance)) * 100);

    return (
        <SettingsLayout title="Mailbox">
            <div className="mb-6 flex flex-col gap-3 border-b pb-5 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <h2 className="text-base font-semibold">Hostinger mailbox</h2>
                    <p className="mt-1 max-w-2xl text-sm text-muted-foreground">One secure mailbox powers personal mail, scheduled messages, campaign delivery, and reply synchronization.</p>
                </div>
                <div className={cn('inline-flex items-center gap-2 rounded-md border px-3 py-2 text-xs font-medium', mailbox.automation_paused ? 'border-amber-500/40 text-amber-300' : 'text-muted-foreground')}>
                    <PauseCircle className="size-4" /> Automation {mailbox.automation_paused ? 'paused' : 'active'}
                </div>
            </div>

            <form onSubmit={submit} className="space-y-5" noValidate>
                <Card className="p-5">
                    <div className="mb-5 flex items-center gap-2">
                        <Mail className="size-4 text-muted-foreground" />
                        <h3 className="text-sm font-semibold">Identity and credentials</h3>
                    </div>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field label="Email address" htmlFor="mailbox-email" error={form.errors.email}>
                            <Input id="mailbox-email" type="email" autoComplete="username" value={form.data.email} onChange={(e) => form.setData('email', e.target.value)} placeholder="sameer@digitelly.net" />
                        </Field>
                        <Field label="Display name" htmlFor="mailbox-name" error={form.errors.display_name}>
                            <Input id="mailbox-name" value={form.data.display_name} onChange={(e) => form.setData('display_name', e.target.value)} placeholder="Sameer" />
                        </Field>
                        <Field label="Reply-to address" htmlFor="mailbox-reply" error={form.errors.reply_to} hint="Optional; defaults to the mailbox address.">
                            <Input id="mailbox-reply" type="email" value={form.data.reply_to} onChange={(e) => form.setData('reply_to', e.target.value)} />
                        </Field>
                        <Field label="Mailbox password" htmlFor="mailbox-password" error={form.errors.password} hint={mailbox.has_password ? 'Stored encrypted. Leave blank to keep the current password.' : 'Use the mailbox or app password from Hostinger.'}>
                            <Input id="mailbox-password" type="password" autoComplete="new-password" value={form.data.password} onChange={(e) => form.setData('password', e.target.value)} placeholder={mailbox.has_password ? '••••••••••••' : ''} />
                        </Field>
                    </div>
                </Card>

                <div className="grid gap-5 lg:grid-cols-2">
                    <Card className="p-5">
                        <div className="mb-4 flex items-center justify-between">
                            <div>
                                <h3 className="text-sm font-semibold">SMTP · outgoing mail</h3>
                                <p className="mt-1 text-xs text-muted-foreground">TLS certificates are always verified.</p>
                            </div>
                            <Button type="button" variant="outline" size="sm" onClick={() => test('smtp')} disabled={testing !== null || !mailbox.has_password}>
                                {testing === 'smtp' ? <Loader2 className="animate-spin" /> : <PlugZap />} Test SMTP
                            </Button>
                        </div>
                        <div className="mb-4"><ConnectionStatus label="SMTP connection" status={mailbox.smtp_status} at={mailbox.last_smtp_test_at} /></div>
                        <div className="grid gap-4 sm:grid-cols-[1fr_110px]">
                            <Field label="Host" htmlFor="smtp-host" error={form.errors.smtp_host}>
                                <Input id="smtp-host" value={form.data.smtp_host} onChange={(e) => form.setData('smtp_host', e.target.value)} className="font-mono text-xs" />
                            </Field>
                            <Field label="Port" htmlFor="smtp-port" error={form.errors.smtp_port}>
                                <Input id="smtp-port" type="number" value={form.data.smtp_port} onChange={(e) => form.setData('smtp_port', Number(e.target.value))} />
                            </Field>
                            <Field label="Encryption" error={form.errors.smtp_encryption} className="sm:col-span-2">
                                <Select value={form.data.smtp_encryption} onValueChange={(value) => form.setData('smtp_encryption', value as Mailbox['smtp_encryption'])}>
                                    <SelectTrigger><SelectValue /></SelectTrigger>
                                    <SelectContent><SelectItem value="ssl">SSL/TLS</SelectItem><SelectItem value="tls">STARTTLS</SelectItem><SelectItem value="none">None</SelectItem></SelectContent>
                                </Select>
                            </Field>
                        </div>
                    </Card>

                    <Card className="p-5">
                        <div className="mb-4 flex items-center justify-between">
                            <div>
                                <h3 className="text-sm font-semibold">IMAP · mailbox sync</h3>
                                <p className="mt-1 text-xs text-muted-foreground">Read, folder, flag, and Sent synchronization.</p>
                            </div>
                            <Button type="button" variant="outline" size="sm" onClick={() => test('imap')} disabled={testing !== null || !mailbox.has_password}>
                                {testing === 'imap' ? <Loader2 className="animate-spin" /> : <RefreshCw />} Test IMAP
                            </Button>
                        </div>
                        <div className="mb-4"><ConnectionStatus label="IMAP connection" status={mailbox.imap_status} at={mailbox.last_imap_test_at} /></div>
                        <div className="grid gap-4 sm:grid-cols-[1fr_110px]">
                            <Field label="Host" htmlFor="imap-host" error={form.errors.imap_host}>
                                <Input id="imap-host" value={form.data.imap_host} onChange={(e) => form.setData('imap_host', e.target.value)} className="font-mono text-xs" />
                            </Field>
                            <Field label="Port" htmlFor="imap-port" error={form.errors.imap_port}>
                                <Input id="imap-port" type="number" value={form.data.imap_port} onChange={(e) => form.setData('imap_port', Number(e.target.value))} />
                            </Field>
                            <Field label="Encryption" error={form.errors.imap_encryption} className="sm:col-span-2">
                                <Select value={form.data.imap_encryption} onValueChange={(value) => form.setData('imap_encryption', value as Mailbox['imap_encryption'])}>
                                    <SelectTrigger><SelectValue /></SelectTrigger>
                                    <SelectContent><SelectItem value="ssl">SSL/TLS</SelectItem><SelectItem value="tls">STARTTLS</SelectItem><SelectItem value="none">None</SelectItem></SelectContent>
                                </Select>
                            </Field>
                        </div>
                    </Card>
                </div>

                <Card className="p-5">
                    <div className="mb-5 flex items-center gap-2"><ShieldCheck className="size-4 text-muted-foreground" /><h3 className="text-sm font-semibold">Limits, timezone, and safeguards</h3></div>
                    <div className="grid gap-5 lg:grid-cols-3">
                        <Field label="Configured daily allowance" htmlFor="daily-allowance" error={form.errors.daily_allowance} hint="ColdForge usage only; Hostinger webmail sends may not be visible immediately.">
                            <Input id="daily-allowance" type="number" min={1} value={form.data.daily_allowance} onChange={(e) => form.setData('daily_allowance', Number(e.target.value))} />
                            <div className="mt-2 h-1 overflow-hidden rounded bg-muted"><div className="h-full bg-foreground/70" style={{ width: `${used}%` }} /></div>
                            <div className="mt-1 text-[11px] text-muted-foreground">{fmt(mailbox.sent_today)} used · {fmt(Math.max(0, form.data.daily_allowance - mailbox.sent_today))} remaining</div>
                        </Field>
                        <Field label="Display timezone" error={form.errors.timezone}>
                            <Select value={form.data.timezone} onValueChange={(value) => form.setData('timezone', value)}>
                                <SelectTrigger><SelectValue /></SelectTrigger>
                                <SelectContent>{timezones.map((timezone) => <SelectItem key={timezone} value={timezone}>{timezone}</SelectItem>)}</SelectContent>
                            </Select>
                        </Field>
                        <div className="space-y-3">
                            <label className="flex items-start justify-between gap-3 rounded-md border p-3">
                                <span><span className="block text-sm font-medium">Emergency pause</span><span className="mt-0.5 block text-xs text-muted-foreground">Stops scheduled and campaign automation.</span></span>
                                <Switch checked={form.data.automation_paused} onCheckedChange={(value) => form.setData('automation_paused', value)} />
                            </label>
                        </div>
                    </div>
                </Card>

                <Card className="p-5">
                    <h3 className="mb-4 text-sm font-semibold">Signature and campaign tracking</h3>
                    <div className="grid gap-4 lg:grid-cols-2">
                        <Field label="HTML signature" htmlFor="signature-html" error={form.errors.signature_html} hint="Sanitized before display in the mail workspace.">
                            <Textarea id="signature-html" rows={6} value={form.data.signature_html} onChange={(e) => form.setData('signature_html', e.target.value)} className="font-mono text-xs" placeholder="<p>Sameer<br>Digitelly</p>" />
                        </Field>
                        <Field label="Plain-text signature" htmlFor="signature-text" error={form.errors.signature_text}>
                            <Textarea id="signature-text" rows={6} value={form.data.signature_text} onChange={(e) => form.setData('signature_text', e.target.value)} placeholder={'Sameer\nDigitelly'} />
                        </Field>
                    </div>
                    <div className="mt-4 grid gap-3 sm:grid-cols-2">
                        <label className="flex items-center justify-between rounded-md border p-3"><span className="text-sm">Track campaign opens</span><Switch checked={form.data.track_campaign_opens} onCheckedChange={(value) => form.setData('track_campaign_opens', value)} /></label>
                        <label className="flex items-center justify-between rounded-md border p-3"><span className="text-sm">Track campaign clicks</span><Switch checked={form.data.track_campaign_clicks} onCheckedChange={(value) => form.setData('track_campaign_clicks', value)} /></label>
                    </div>
                    <Field label="Physical mailing address" htmlFor="physical-address" error={form.errors.physical_address} hint="Required before campaign launch; included in campaign footers for compliance." className="mt-4">
                        <Textarea id="physical-address" rows={3} value={form.data.physical_address} onChange={(e) => form.setData('physical_address', e.target.value)} placeholder="Company, street, city, country" />
                    </Field>
                    <p className="mt-3 text-xs text-muted-foreground">Tracking applies only to campaigns. Personal correspondence stays private by default. Opens and clicks can be distorted by privacy tools and automated scanners.</p>
                    <div className="mt-5 border-t pt-5">
                        <h4 className="text-sm font-semibold">Codex intelligence</h4>
                        <div className="mt-3 grid gap-3 sm:grid-cols-2">
                            <label className="flex items-center justify-between rounded-md border p-3"><span><span className="block text-sm">Allow AI email processing</span><span className="text-xs text-muted-foreground">Off by default. Sends only the selected email when you request assistance.</span></span><Switch checked={form.data.ai_processing_enabled} onCheckedChange={(value) => form.setData('ai_processing_enabled', value)} /></label>
                            <Field label="Codex model" error={form.errors.ai_model}><Select value={form.data.ai_model} onValueChange={(value) => form.setData('ai_model', value as Mailbox['ai_model'])}><SelectTrigger><SelectValue /></SelectTrigger><SelectContent><SelectItem value="gpt-6.2-sol">GPT-6.2 Sol · everyday</SelectItem><SelectItem value="gpt-6.2-astra">GPT-6.2 Astra · complex</SelectItem><SelectItem value="gpt-6.1-terra">GPT-6.1 Terra · efficient</SelectItem></SelectContent></Select></Field>
                        </div>
                    </div>
                </Card>

                {mailbox.last_connection_error && <div className="rounded-md border border-red-500/30 bg-red-500/5 px-4 py-3 text-sm text-red-300">{mailbox.last_connection_error}</div>}

                <div className="flex justify-end border-t pt-5">
                    <Button type="submit" disabled={form.processing || !form.isDirty}>{form.processing && <Loader2 className="animate-spin" />} Save mailbox</Button>
                </div>
            </form>
        </SettingsLayout>
    );
}
