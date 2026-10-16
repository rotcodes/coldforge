import { FormEvent, useEffect, useState } from 'react';
import axios from 'axios';
import { Link, useForm } from '@inertiajs/react';
import { AnimatePresence, motion } from 'framer-motion';
import { AlertTriangle, ArrowLeft, ArrowRight, CalendarClock, Check, FileText, Loader2, Rocket, Users, Zap } from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import Field from '@/Components/Field';
import TagInput from '@/Components/TagInput';
import TiltCard from '@/Components/TiltCard';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { cn, date, fmt } from '@/lib/utils';

interface Props {
    templates: { id: number; name: string; subject: string; from_email: string; updated_at: string }[];
    tags: string[];
    activeCount: number;
    senderCount: number;
    defaultCap: number;
}

const STEPS = [
    { label: 'Template', icon: FileText },
    { label: 'Audience', icon: Users },
    { label: 'Schedule', icon: CalendarClock },
];

export default function CampaignCreate({ templates, tags, activeCount, senderCount, defaultCap }: Props) {
    const [step, setStep] = useState(0);
    const [count, setCount] = useState<number | null>(activeCount);
    const form = useForm({
        name: '',
        template_id: templates[0]?.id ?? 0,
        tags: [] as string[],
        send_mode: 'now' as 'now' | 'later',
        schedule_at: '',
        daily_cap: defaultCap,
        sender_strategy: 'round_robin' as 'round_robin' | 'priority',
    });

    // Live recipient count
    useEffect(() => {
        setCount(null);
        const t = setTimeout(() => axios.get('/campaigns/count', { params: { tags: form.data.tags } }).then((r) => setCount(r.data.count)), 200);
        return () => clearTimeout(t);
    }, [form.data.tags]);

    // Jump back to the step that owns a server-side error
    useEffect(() => {
        const e = form.errors;
        if (e.template_id) setStep(0);
        else if (e.tags) setStep(1);
    }, [form.errors]);

    const template = templates.find((t) => t.id === form.data.template_id);
    const canNext = step === 0 ? !!template : step === 1 ? (count ?? 0) > 0 : true;

    const submit = (e: FormEvent) => {
        e.preventDefault();
        if (step < 2) {
            if (canNext) setStep(step + 1);
            return;
        }
        form.transform((d) => ({ ...d, schedule_at: d.send_mode === 'later' && d.schedule_at ? new Date(d.schedule_at).toISOString() : null }));
        form.post('/campaigns');
    };

    const days = count && form.data.daily_cap ? Math.ceil(count / form.data.daily_cap) : 0;

    return (
        <AppLayout title="New campaign" crumbs={[{ label: 'Campaigns', href: '/campaigns' }, { label: 'New' }]}>
            <div className="mx-auto max-w-4xl">
                {/* Stepper */}
                <ol className="mb-8 flex items-center gap-2">
                    {STEPS.map((s, i) => (
                        <li key={s.label} className="flex flex-1 items-center gap-2">
                            <button
                                type="button"
                                onClick={() => i < step && setStep(i)}
                                disabled={i > step}
                                className={cn(
                                    'flex items-center gap-2 rounded-lg text-sm font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
                                    i <= step ? 'text-foreground' : 'text-muted-foreground',
                                )}
                            >
                                <span className={cn('grid size-8 shrink-0 place-items-center rounded-full border transition-all', i < step ? 'border-transparent bg-brand text-white' : i === step ? 'border-primary text-primary shadow-glow' : 'border-border')}>
                                    {i < step ? <Check className="size-4" /> : <s.icon className="size-4" />}
                                </span>
                                <span className="hidden sm:inline">{s.label}</span>
                            </button>
                            {i < STEPS.length - 1 && <span className={cn('h-px flex-1 transition-colors', i < step ? 'bg-primary' : 'bg-border')} />}
                        </li>
                    ))}
                </ol>

                {senderCount === 0 && (
                    <div className="mb-4 flex items-center gap-2 rounded-xl border border-amber-500/30 bg-amber-500/10 px-4 py-3 text-sm text-amber-700 dark:text-amber-300">
                        <AlertTriangle className="size-4 shrink-0" />
                        No active sender yet. Sends will wait until you{' '}
                        <Link href="/settings/senders" className="underline">
                            add one
                        </Link>
                        .
                    </div>
                )}

                <form onSubmit={submit} noValidate>
                    <AnimatePresence mode="wait">
                        <motion.div key={step} initial={{ opacity: 0, x: 12 }} animate={{ opacity: 1, x: 0 }} exit={{ opacity: 0, x: -12 }} transition={{ duration: 0.18, ease: 'easeOut' }}>
                            {step === 0 && (
                                <div>
                                    <h2 className="mb-1 text-lg font-semibold">Pick a template</h2>
                                    <p className="mb-5 text-sm text-muted-foreground">What are we sending?</p>
                                    {templates.length === 0 ? (
                                        <Card className="p-8 text-center text-sm text-muted-foreground">
                                            No templates.{' '}
                                            <Link href="/templates/create" className="text-primary hover:underline">
                                                Create one first
                                            </Link>
                                            .
                                        </Card>
                                    ) : (
                                        <div className="grid gap-3 sm:grid-cols-2">
                                            {templates.map((t) => {
                                                const selected = t.id === form.data.template_id;
                                                return (
                                                    <TiltCard key={t.id} className={cn('relative', selected && 'ring-2 ring-primary')}>
                                                        <button type="button" onClick={() => form.setData('template_id', t.id)} className="w-full rounded-xl p-4 text-left focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring">
                                                            <div className="flex items-start justify-between gap-2">
                                                                <span className="font-medium">{t.name}</span>
                                                                {selected && (
                                                                    <span className="grid size-5 place-items-center rounded-full bg-brand text-white">
                                                                        <Check className="size-3" />
                                                                    </span>
                                                                )}
                                                            </div>
                                                            <p className="mt-1 truncate text-sm text-muted-foreground">{t.subject}</p>
                                                            <p className="mt-3 text-xs text-muted-foreground">
                                                                {t.from_email} · {date(t.updated_at)}
                                                            </p>
                                                        </button>
                                                    </TiltCard>
                                                );
                                            })}
                                        </div>
                                    )}
                                    {form.errors.template_id && <p className="mt-2 text-xs font-medium text-red-500">{form.errors.template_id}</p>}
                                </div>
                            )}

                            {step === 1 && (
                                <div>
                                    <h2 className="mb-1 text-lg font-semibold">Choose the audience</h2>
                                    <p className="mb-5 text-sm text-muted-foreground">Only active contacts are included. Bounced and unsubscribed are always skipped.</p>
                                    <div className="grid gap-4 md:grid-cols-[1fr_220px]">
                                        <Card className="p-5">
                                            <Field label="Contacts tagged with any of" error={form.errors.tags} hint="Leave empty to send to every active contact.">
                                                <TagInput value={form.data.tags} onChange={(t) => form.setData('tags', t)} suggestions={tags} placeholder="Pick or type tags…" />
                                            </Field>
                                        </Card>
                                        <TiltCard hero className="flex flex-col items-center justify-center p-6 text-center">
                                            <Users className="mb-2 size-5 text-primary" />
                                            <div className="font-mono text-4xl font-semibold tracking-tight">{count === null ? <Loader2 className="mx-auto size-7 animate-spin text-muted-foreground" /> : fmt(count)}</div>
                                            <div className="mt-1 text-xs text-muted-foreground">recipients</div>
                                        </TiltCard>
                                    </div>
                                </div>
                            )}

                            {step === 2 && (
                                <div>
                                    <h2 className="mb-1 text-lg font-semibold">Schedule & limits</h2>
                                    <p className="mb-5 text-sm text-muted-foreground">Warm inboxes slowly. A low daily cap keeps you out of spam.</p>
                                    <Card className="space-y-5 p-5">
                                        <Field label="Campaign name" htmlFor="name" error={form.errors.name}>
                                            <Input id="name" autoFocus value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} placeholder={`${template?.name ?? 'Campaign'}, ${new Date().toLocaleDateString(undefined, { month: 'short', day: 'numeric' })}`} />
                                        </Field>
                                        <div className="grid gap-3 sm:grid-cols-2">
                                            {(['now', 'later'] as const).map((mode) => (
                                                <button
                                                    key={mode}
                                                    type="button"
                                                    onClick={() => form.setData('send_mode', mode)}
                                                    className={cn(
                                                        'flex items-center gap-3 rounded-xl border p-4 text-left transition-all focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
                                                        form.data.send_mode === mode ? 'border-primary bg-primary/5 shadow-glow' : 'hover:border-primary/40',
                                                    )}
                                                >
                                                    {mode === 'now' ? <Zap className="size-5 text-primary" /> : <CalendarClock className="size-5 text-primary" />}
                                                    <div>
                                                        <div className="text-sm font-medium">{mode === 'now' ? 'Send on launch' : 'Schedule for later'}</div>
                                                        <div className="text-xs text-muted-foreground">{mode === 'now' ? 'Starts as soon as you hit Launch' : 'Starts automatically at a set time'}</div>
                                                    </div>
                                                </button>
                                            ))}
                                        </div>
                                        {form.data.send_mode === 'later' && (
                                            <Field label="Start at" htmlFor="schedule_at" error={form.errors.schedule_at}>
                                                <Input id="schedule_at" type="datetime-local" value={form.data.schedule_at} onChange={(e) => form.setData('schedule_at', e.target.value)} className="w-full sm:w-64" />
                                            </Field>
                                        )}
                                        <div className="grid gap-4 sm:grid-cols-2">
                                            <Field label="Daily cap" htmlFor="daily_cap" error={form.errors.daily_cap} hint={days ? `${fmt(count ?? 0)} recipients ≈ ${days} day${days > 1 ? 's' : ''}` : undefined}>
                                                <Input id="daily_cap" type="number" min={1} value={form.data.daily_cap} onChange={(e) => form.setData('daily_cap', Number(e.target.value))} />
                                            </Field>
                                            <Field label="Sender strategy" error={form.errors.sender_strategy}>
                                                <Select value={form.data.sender_strategy} onValueChange={(v) => form.setData('sender_strategy', v as 'round_robin' | 'priority')}>
                                                    <SelectTrigger>
                                                        <SelectValue />
                                                    </SelectTrigger>
                                                    <SelectContent>
                                                        <SelectItem value="round_robin">Round robin (spread load)</SelectItem>
                                                        <SelectItem value="priority">Priority (fill first sender first)</SelectItem>
                                                    </SelectContent>
                                                </Select>
                                            </Field>
                                        </div>
                                    </Card>
                                </div>
                            )}
                        </motion.div>
                    </AnimatePresence>

                    <div className="mt-6 flex items-center justify-between">
                        <Button type="button" variant="ghost" onClick={() => setStep(step - 1)} className={cn(step === 0 && 'invisible')}>
                            <ArrowLeft /> Back
                        </Button>
                        {step < 2 ? (
                            <Button type="submit" disabled={!canNext}>
                                Continue <ArrowRight />
                            </Button>
                        ) : (
                            <Button type="submit" disabled={form.processing || !form.data.name}>
                                {form.processing ? <Loader2 className="animate-spin" /> : <Rocket />} Create draft
                            </Button>
                        )}
                    </div>
                </form>
            </div>
        </AppLayout>
    );
}
