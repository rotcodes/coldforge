import { FormEvent, useState } from 'react';
import { router } from '@inertiajs/react';
import { motion } from 'framer-motion';
import { AlertTriangle, CheckCircle2, Download, Loader2, Search, XCircle } from 'lucide-react';
import SettingsLayout from '@/Layouts/SettingsLayout';
import TiltCard from '@/Components/TiltCard';
import StatusBadge from '@/Components/StatusBadge';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import { cn, dateTime, fmt } from '@/lib/utils';

interface Result {
    name: string;
    status: 'pass' | 'warn' | 'fail';
    records: string[];
    message: string;
    fix: string | null;
}

const ICON = { pass: CheckCircle2, warn: AlertTriangle, fail: XCircle };
const COLOR = { pass: 'text-emerald-500', warn: 'text-amber-500', fail: 'text-red-500' };

type Health = { smtp_status: string; imap_status: string; daily_allowance: number; sent_today: number; automation_paused: boolean };
type Suppression = { email: string; reason: string; source: string | null; suppressed_at: string };
type SendingError = { id: number; subject: string | null; status: string; send_error: string | null; updated_at: string };

export default function Deliverability({ domain, results, health, suppressions, errors }: { domain: string; results: Result[] | null; health: Health; suppressions: Suppression[]; errors: SendingError[] }) {
    const [value, setValue] = useState(domain);
    const [checking, setChecking] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const check = (e: FormEvent) => {
        e.preventDefault();
        router.get(
            '/settings/deliverability',
            { domain: value },
            {
                preserveScroll: true,
                onStart: () => setChecking(true),
                onFinish: () => setChecking(false),
                onError: (errs) => setError(errs.domain ?? 'Invalid domain'),
                onSuccess: () => setError(null),
            },
        );
    };

    return (
        <SettingsLayout title="Deliverability">
            <Card className="mb-6 p-5">
                <form onSubmit={check} className="flex flex-col gap-3 sm:flex-row" noValidate>
                    <div className="flex-1">
                        <Input value={value} onChange={(e) => setValue(e.target.value)} placeholder="digitelly.net" aria-label="Sending domain" className="font-mono" />
                        {error && <p className="mt-1.5 text-xs font-medium text-red-500">{error}</p>}
                    </div>
                    <Button type="submit" disabled={checking || !value}>
                        {checking ? <Loader2 className="animate-spin" /> : <Search />} Check DNS
                    </Button>
                </form>
                <p className="mt-3 text-xs text-muted-foreground">Looks up SPF, common Hostinger DKIM selectors, DMARC, and MX for the configured sending domain.</p>
            </Card>

            {results && (
                <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                    {results.map((r, i) => {
                        const Icon = ICON[r.status];
                        return (
                            <motion.div key={r.name} initial={{ opacity: 0, y: 8 }} animate={{ opacity: 1, y: 0 }} transition={{ delay: i * 0.06, duration: 0.2 }}>
                                <TiltCard hero className="h-full p-5">
                                    <div className="mb-3 flex items-center justify-between">
                                        <div className="flex items-center gap-2">
                                            <Icon className={cn('size-5', COLOR[r.status])} />
                                            <h3 className="font-semibold">{r.name}</h3>
                                        </div>
                                        <StatusBadge status={r.status} />
                                    </div>
                                    <p className="text-sm text-muted-foreground">{r.message}</p>
                                    {r.records.length > 0 && (
                                        <div className="mt-3 space-y-1.5">
                                            {r.records.map((rec, j) => (
                                                <code key={j} className="block break-all rounded-md bg-muted px-2 py-1.5 font-mono text-[11px]">
                                                    {rec}
                                                </code>
                                            ))}
                                        </div>
                                    )}
                                    {r.fix && (
                                        <div className="mt-4 rounded-lg border border-dashed p-3">
                                            <div className="mb-1 text-xs font-medium">How to fix</div>
                                            <pre className="whitespace-pre-wrap break-all font-mono text-[11px] text-muted-foreground">{r.fix}</pre>
                                        </div>
                                    )}
                                </TiltCard>
                            </motion.div>
                        );
                    })}
                </div>
            )}

            <div className="mt-6 grid gap-4 lg:grid-cols-3">
                <Card className="p-5"><h3 className="text-sm font-semibold">Mailbox health</h3><dl className="mt-4 space-y-3 text-sm"><div className="flex justify-between"><dt className="text-muted-foreground">SMTP</dt><dd className="capitalize">{health.smtp_status}</dd></div><div className="flex justify-between"><dt className="text-muted-foreground">IMAP</dt><dd className="capitalize">{health.imap_status}</dd></div><div className="flex justify-between"><dt className="text-muted-foreground">ColdForge allowance</dt><dd className="font-mono">{fmt(health.sent_today)} / {fmt(health.daily_allowance)}</dd></div><div className="flex justify-between"><dt className="text-muted-foreground">Automation</dt><dd>{health.automation_paused ? 'Paused' : 'Active'}</dd></div></dl><p className="mt-4 text-xs text-muted-foreground">SMTP acceptance is recorded as sent/accepted, never as confirmed delivery or inbox placement.</p></Card>
                <Card className="overflow-hidden"><div className="flex items-center justify-between p-5 pb-3"><h3 className="text-sm font-semibold">Suppression list</h3><Button size="sm" variant="ghost" asChild><a href="/exports/suppressions"><Download /> Export</a></Button></div>{suppressions.length === 0 ? <p className="px-5 pb-5 text-sm text-muted-foreground">No suppressed recipients.</p> : <ul className="max-h-72 divide-y overflow-y-auto">{suppressions.map((item) => <li key={item.email} className="px-5 py-2.5 text-sm"><div className="truncate font-medium">{item.email}</div><div className="text-xs capitalize text-muted-foreground">{item.reason.replace('_', ' ')} · {dateTime(item.suppressed_at)}</div></li>)}</ul>}</Card>
                <Card className="overflow-hidden"><h3 className="p-5 pb-3 text-sm font-semibold">Sending errors</h3>{errors.length === 0 ? <p className="px-5 pb-5 text-sm text-muted-foreground">No failed or uncertain messages.</p> : <ul className="max-h-72 divide-y overflow-y-auto">{errors.map((error) => <li key={error.id} className="px-5 py-2.5 text-sm"><div className="flex items-center justify-between gap-2"><span className="truncate font-medium">{error.subject || '(no subject)'}</span><StatusBadge status={error.status} /></div><div className="mt-1 line-clamp-2 text-xs text-muted-foreground">{error.send_error}</div></li>)}</ul>}</Card>
            </div>
        </SettingsLayout>
    );
}
