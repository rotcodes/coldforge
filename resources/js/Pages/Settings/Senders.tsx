import { FormEvent, useEffect, useState } from 'react';
import { router, useForm } from '@inertiajs/react';
import { KeyRound, Loader2, Mail, Pencil, Plug, Plus, Server, Trash2 } from 'lucide-react';
import SettingsLayout from '@/Layouts/SettingsLayout';
import { confirmDialog, visit } from '@/Components/ConfirmDialog';
import Field from '@/Components/Field';
import TiltCard from '@/Components/TiltCard';
import EmptyState from '@/Components/EmptyState';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { Input } from '@/Components/ui/input';
import { Progress } from '@/Components/ui/progress';
import { Switch } from '@/Components/ui/switch';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { cn, fmt, pct } from '@/lib/utils';
import type { Sender } from '@/types';

type Method = 'api' | 'smtp';
type SenderRow = Sender & { method: Method };

const LABELS: Record<string, string> = {
    brevo: 'Brevo',
    mailersend: 'MailerSend',
    elastic: 'Elastic Email',
    mailjet: 'Mailjet',
    smtp_generic: 'Generic SMTP',
};

/** SMTP defaults per provider, prefilled when you pick SMTP. */
const SMTP_PRESETS: Record<string, { host: string; port: string; userHint: string; passHint: string }> = {
    brevo: { host: 'smtp-relay.brevo.com', port: '587', userHint: 'Your Brevo SMTP login (Settings → SMTP & API)', passHint: 'Brevo SMTP key (not the API key)' },
    mailersend: { host: 'smtp.mailersend.net', port: '587', userHint: 'SMTP username from MailerSend → Domains', passHint: 'SMTP password' },
    elastic: { host: 'smtp.elasticemail.com', port: '2525', userHint: 'Usually your Elastic account email', passHint: 'SMTP password' },
    mailjet: { host: 'in-v3.mailjet.com', port: '587', userHint: 'Mailjet API key', passHint: 'Mailjet secret key' },
    smtp_generic: { host: '', port: '587', userHint: '', passHint: '' },
};
const PRESET_HOSTS = Object.values(SMTP_PRESETS).map((p) => p.host);

type FormData = {
    name: string;
    provider: Sender['provider'];
    daily_quota: number;
    priority: number;
    is_active: boolean;
    config: { method: Method; api_key: string; host: string; port: string; username: string; password: string; encryption: string };
};

const blank: FormData = {
    name: 'Brevo',
    provider: 'brevo',
    daily_quota: 300,
    priority: 1,
    is_active: true,
    config: { method: 'api', api_key: '', host: SMTP_PRESETS.brevo.host, port: '587', username: '', password: '', encryption: 'tls' },
};

function MethodSwitch({ value, onChange, apiAvailable }: { value: Method; onChange: (m: Method) => void; apiAvailable: boolean }) {
    const options = [
        { value: 'api' as const, label: 'API key', icon: KeyRound, disabled: !apiAvailable },
        { value: 'smtp' as const, label: 'SMTP', icon: Server, disabled: false },
    ];
    return (
        <div role="radiogroup" aria-label="Connection method" className="grid grid-cols-2 gap-1 rounded-xl border bg-muted/40 p-1">
            {options.map((o) => {
                const active = value === o.value;
                return (
                    <button
                        key={o.value}
                        type="button"
                        role="radio"
                        aria-checked={active}
                        disabled={o.disabled}
                        onClick={() => onChange(o.value)}
                        title={o.disabled ? 'Native API driver coming soon. Use SMTP for now.' : undefined}
                        className={cn(
                            'flex h-9 items-center justify-center gap-2 rounded-lg text-sm font-medium transition-all focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:cursor-not-allowed disabled:opacity-40',
                            active ? 'bg-background text-foreground shadow-sm ring-1 ring-border' : 'text-muted-foreground hover:text-foreground',
                        )}
                    >
                        <o.icon className={cn('size-4', active && 'text-primary')} />
                        {o.label}
                        {o.disabled && <span className="text-[10px] font-normal">(soon)</span>}
                    </button>
                );
            })}
        </div>
    );
}

function SenderDialog({ open, onOpenChange, sender, providers, apiProviders }: { open: boolean; onOpenChange: (o: boolean) => void; sender: SenderRow | null; providers: string[]; apiProviders: string[] }) {
    const form = useForm<FormData>(blank);

    useEffect(() => {
        if (!open) return;
        form.clearErrors();
        if (!sender) {
            form.setData(blank);
            return;
        }
        const preset = SMTP_PRESETS[sender.provider];
        const cfg = Object.fromEntries(Object.entries(sender.config).map(([k, v]) => [k, String(v)]));
        form.setData({
            name: sender.name,
            provider: sender.provider,
            daily_quota: sender.daily_quota,
            priority: sender.priority,
            is_active: sender.is_active,
            config: { ...blank.config, host: preset.host, port: preset.port, ...cfg, method: sender.method },
        });
    }, [open, sender]);

    const errors = form.errors as Record<string, string>;
    const p = form.data.provider;
    const method = form.data.config.method;
    const apiAvailable = apiProviders.includes(p);
    const preset = SMTP_PRESETS[p];
    const secretHint = sender ? 'Leave as is to keep the saved value.' : undefined;

    const setConfig = (key: keyof FormData['config'], value: string) => form.setData('config', { ...form.data.config, [key]: value });

    const changeProvider = (v: string) => {
        const next = v as Sender['provider'];
        form.setData((d) => {
            const host = !d.config.host || PRESET_HOSTS.includes(d.config.host) ? SMTP_PRESETS[next].host : d.config.host;
            return {
                ...d,
                provider: next,
                name: !sender && (d.name === LABELS[d.provider] || !d.name) ? LABELS[next] : d.name,
                config: { ...d.config, method: apiProviders.includes(next) ? d.config.method : 'smtp', host, port: host === SMTP_PRESETS[next].host ? SMTP_PRESETS[next].port : d.config.port },
            };
        });
    };

    const submit = (e: FormEvent) => {
        e.preventDefault();
        const opts = { preserveScroll: true, onSuccess: () => onOpenChange(false) };
        sender ? form.put(`/settings/senders/${sender.id}`, opts) : form.post('/settings/senders', opts);
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>{sender ? 'Edit sender' : 'Add sender'}</DialogTitle>
                    <DialogDescription>Credentials are encrypted at rest and never sent back to the browser.</DialogDescription>
                </DialogHeader>
                <form onSubmit={submit} className="space-y-5" noValidate>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field label="Provider" error={errors.provider}>
                            <Select value={p} onValueChange={changeProvider}>
                                <SelectTrigger>
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {providers.map((pr) => (
                                        <SelectItem key={pr} value={pr}>
                                            {LABELS[pr]}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </Field>
                        <Field label="Name" htmlFor="s-name" error={errors.name}>
                            <Input id="s-name" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} />
                        </Field>
                    </div>

                    {p !== 'smtp_generic' && (
                        <Field label="Connect with">
                            <MethodSwitch value={method} onChange={(m) => setConfig('method', m)} apiAvailable={apiAvailable} />
                        </Field>
                    )}

                    {method === 'smtp' || p === 'smtp_generic' ? (
                        <div className="grid gap-4 rounded-xl border border-dashed p-4 sm:grid-cols-2">
                            <Field label="SMTP host" htmlFor="s-host" error={errors['config.host']} className="sm:col-span-2">
                                <Input id="s-host" className="font-mono" value={form.data.config.host} onChange={(e) => setConfig('host', e.target.value)} placeholder="smtp.example.com" />
                            </Field>
                            <Field label="Port" htmlFor="s-port" error={errors['config.port']}>
                                <Input id="s-port" type="number" className="font-mono" value={form.data.config.port} onChange={(e) => setConfig('port', e.target.value)} />
                            </Field>
                            <Field label="Encryption" error={errors['config.encryption']}>
                                <Select value={form.data.config.encryption || 'tls'} onValueChange={(v) => setConfig('encryption', v)}>
                                    <SelectTrigger>
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="tls">STARTTLS (587 / 2525)</SelectItem>
                                        <SelectItem value="ssl">SSL/TLS (465)</SelectItem>
                                        <SelectItem value="none">None</SelectItem>
                                    </SelectContent>
                                </Select>
                            </Field>
                            <Field label="Login" htmlFor="s-user" error={errors['config.username']} hint={preset.userHint || undefined} className="sm:col-span-2">
                                <Input id="s-user" autoComplete="off" value={form.data.config.username} onChange={(e) => setConfig('username', e.target.value)} />
                            </Field>
                            <Field label="Password / SMTP key" htmlFor="s-pass" error={errors['config.password']} hint={secretHint ?? (preset.passHint || undefined)} className="sm:col-span-2">
                                <Input id="s-pass" type="password" autoComplete="new-password" className="font-mono" value={form.data.config.password} onChange={(e) => setConfig('password', e.target.value)} />
                            </Field>
                        </div>
                    ) : (
                        <Field label="API key" htmlFor="s-key" error={errors['config.api_key']} hint={secretHint ?? 'Leave blank to use BREVO_API_KEY from .env.'}>
                            <Input id="s-key" type="password" autoComplete="off" className="font-mono" value={form.data.config.api_key} onChange={(e) => setConfig('api_key', e.target.value)} placeholder="xkeysib-…" />
                        </Field>
                    )}

                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field label="Daily quota" htmlFor="s-quota" error={errors.daily_quota} hint="Brevo free plan: 300/day">
                            <Input id="s-quota" type="number" min={1} value={form.data.daily_quota} onChange={(e) => form.setData('daily_quota', Number(e.target.value))} />
                        </Field>
                        <Field label="Priority" htmlFor="s-prio" error={errors.priority} hint="1 = used first">
                            <Input id="s-prio" type="number" min={1} value={form.data.priority} onChange={(e) => form.setData('priority', Number(e.target.value))} />
                        </Field>
                    </div>

                    <DialogFooter>
                        <Button type="button" variant="ghost" onClick={() => onOpenChange(false)}>
                            Cancel
                        </Button>
                        <Button type="submit" disabled={form.processing}>
                            {form.processing && <Loader2 className="animate-spin" />}
                            {sender ? 'Save' : 'Add sender'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

export default function Senders({ senders, providers, apiProviders }: { senders: SenderRow[]; providers: string[]; apiProviders: string[] }) {
    const [open, setOpen] = useState(false);
    const [editing, setEditing] = useState<SenderRow | null>(null);
    const [testing, setTesting] = useState<number | null>(null);

    const edit = (s: SenderRow | null) => {
        setEditing(s);
        setOpen(true);
    };

    const test = (s: SenderRow) => {
        setTesting(s.id);
        router.post(`/settings/senders/${s.id}/test`, {}, { preserveScroll: true, onFinish: () => setTesting(null) });
    };

    return (
        <SettingsLayout title="Senders">
            <div className="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <p className="text-sm text-muted-foreground">API keys and SMTP relays used to deliver campaigns. Quotas reset daily.</p>
                <Button onClick={() => edit(null)} className="self-start sm:self-auto">
                    <Plus /> Add sender
                </Button>
            </div>

            {senders.length === 0 ? (
                <Card>
                    <EmptyState icon={Mail} title="No senders" text="Add a Brevo API key or any SMTP relay to start sending." />
                </Card>
            ) : (
                <div className="grid gap-4 md:grid-cols-2">
                    {senders.map((s) => {
                        const MethodIcon = s.method === 'smtp' ? Server : KeyRound;
                        return (
                            <TiltCard key={s.id} className={cn('p-5', !s.is_active && 'opacity-60')}>
                                <div className="flex items-start justify-between gap-3">
                                    <div className="flex min-w-0 items-center gap-3">
                                        <div className="grid size-10 shrink-0 place-items-center rounded-xl bg-brand text-sm font-semibold text-white">{s.name.slice(0, 1).toUpperCase()}</div>
                                        <div className="min-w-0">
                                            <div className="truncate font-medium">{s.name}</div>
                                            <div className="flex items-center gap-1.5 text-xs text-muted-foreground">
                                                {LABELS[s.provider]}
                                                <span className="inline-flex items-center gap-1 rounded-md bg-primary/10 px-1.5 py-0.5 text-[11px] font-medium text-primary">
                                                    <MethodIcon className="size-3" /> {s.method === 'smtp' ? 'SMTP' : 'API'}
                                                </span>
                                                · priority {s.priority}
                                            </div>
                                        </div>
                                    </div>
                                    <Switch checked={s.is_active} onCheckedChange={() => router.post(`/settings/senders/${s.id}/toggle`, {}, { preserveScroll: true })} aria-label="Active" />
                                </div>
                                {s.method === 'smtp' && s.config.host && <div className="mt-3 truncate font-mono text-xs text-muted-foreground">{`${s.config.host}:${s.config.port}`}</div>}
                                <div className="mt-5">
                                    <div className="mb-1.5 flex justify-between font-mono text-xs text-muted-foreground">
                                        <span>Sent today</span>
                                        <span>
                                            <span className="text-foreground">{fmt(s.sent_today)}</span> / {fmt(s.daily_quota)}
                                        </span>
                                    </div>
                                    <Progress value={pct(s.sent_today, s.daily_quota)} className="h-1.5" />
                                </div>
                                <div className="mt-5 flex gap-2">
                                    <Button variant="outline" size="sm" onClick={() => test(s)} disabled={testing === s.id}>
                                        {testing === s.id ? <Loader2 className="animate-spin" /> : <Plug />} Test
                                    </Button>
                                    <Button variant="outline" size="sm" onClick={() => edit(s)}>
                                        <Pencil /> Edit
                                    </Button>
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        className="ml-auto text-muted-foreground hover:text-red-500"
                                        aria-label="Delete sender"
                                        onClick={() =>
                                            confirmDialog({
                                                title: 'Remove sender?',
                                                description: `${s.name} and its saved credentials will be deleted. Sends already assigned to it will fail.`,
                                                confirmLabel: 'Remove',
                                                onConfirm: visit((o) => router.delete(`/settings/senders/${s.id}`, { preserveScroll: true, ...o })),
                                            })
                                        }
                                    >
                                        <Trash2 />
                                    </Button>
                                </div>
                            </TiltCard>
                        );
                    })}
                </div>
            )}

            <SenderDialog open={open} onOpenChange={setOpen} sender={editing} providers={providers} apiProviders={apiProviders} />
        </SettingsLayout>
    );
}
