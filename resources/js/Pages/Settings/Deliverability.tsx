import { FormEvent, useState } from 'react';
import { router } from '@inertiajs/react';
import { motion } from 'framer-motion';
import { AlertTriangle, CheckCircle2, Loader2, Search, XCircle } from 'lucide-react';
import SettingsLayout from '@/Layouts/SettingsLayout';
import TiltCard from '@/Components/TiltCard';
import StatusBadge from '@/Components/StatusBadge';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import { cn } from '@/lib/utils';

interface Result {
    name: string;
    status: 'pass' | 'warn' | 'fail';
    records: string[];
    message: string;
    fix: string | null;
}

const ICON = { pass: CheckCircle2, warn: AlertTriangle, fail: XCircle };
const COLOR = { pass: 'text-emerald-500', warn: 'text-amber-500', fail: 'text-red-500' };

export default function Deliverability({ domain, results }: { domain: string; results: Result[] | null }) {
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
                <p className="mt-3 text-xs text-muted-foreground">Looks up SPF, DKIM (common selectors incl. brevo1/brevo2/google) and DMARC for the domain in your From address.</p>
            </Card>

            {results && (
                <div className="grid gap-4 lg:grid-cols-3">
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
        </SettingsLayout>
    );
}
