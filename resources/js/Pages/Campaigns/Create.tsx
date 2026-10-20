import { FormEvent, useEffect, useState } from 'react';
import axios from 'axios';
import { Link, useForm } from '@inertiajs/react';
import { AnimatePresence, motion } from 'framer-motion';
import { AlertTriangle, ArrowLeft, ArrowRight, CalendarClock, Check, FileText, Loader2, RefreshCw, Rocket, Sparkles, Users, Wand2, Zap } from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import Field from '@/Components/Field';
import TagInput from '@/Components/TagInput';
import TierBadge from '@/Components/TierBadge';
import TiltCard from '@/Components/TiltCard';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { Switch } from '@/Components/ui/switch';
import { cn, date, fmt } from '@/lib/utils';
import type { Industry } from '@/types';

interface Props {
    templates: { id: number; name: string; subject: string; from_email: string; updated_at: string; variables: string[] | null }[];
    tags: string[];
    activeCount: number;
    senderCount: number;
    defaultCap: number;
    industries: Pick<Industry, 'slug' | 'label'>[];
}

const STEPS = [
    { label: 'Template', icon: FileText },
    { label: 'Audience', icon: Users },
    { label: 'Readiness', icon: Wand2 },
    { label: 'Schedule', icon: CalendarClock },
];

type Breakdown = { count: number; tiers: { t1: number; t2: number; t3: number; t4: number } };

export default function CampaignCreate({ templates, tags, activeCount, senderCount, defaultCap, industries }: Props) {
    const [step, setStep] = useState(0);
    const [breakdown, setBreakdown] = useState<Breakdown>({ count: activeCount, tiers: { t1: 0, t2: 0, t3: 0, t4: 0 } });
    const [breakdownLoading, setBreakdownLoading] = useState(false);
    const [preview, setPreview] = useState<any>(null);
    const [previewBusy, setPreviewBusy] = useState(false);

    const form = useForm({
        name: '',
        template_id: templates[0]?.id ?? 0,
        tags: [] as string[],
        industry_slugs: [] as string[],
        source_system: '' as '' | 'INTEL' | 'CRM',
        lead_score_min: '' as string,
        require_industry: true,
        send_mode: 'now' as 'now' | 'later',
        schedule_at: '',
        daily_cap: defaultCap,
        sender_strategy: 'round_robin' as 'round_robin' | 'priority',
    });

    useEffect(() => {
        setBreakdownLoading(true);
        const t = setTimeout(async () => {
            try {
                const { data } = await axios.get('/campaigns/count', {
                    params: {
                        tags: form.data.tags,
                        industry_slugs: form.data.industry_slugs,
                        source_system: form.data.source_system || undefined,
                        lead_score_min: form.data.lead_score_min || undefined,
                        require_industry: form.data.require_industry ? 1 : 0,
                    },
                });
                setBreakdown(data);
            } finally {
                setBreakdownLoading(false);
            }
        }, 300);
        return () => clearTimeout(t);
    }, [form.data.tags, form.data.industry_slugs, form.data.source_system, form.data.lead_score_min, form.data.require_industry]);

    useEffect(() => {
        const e = form.errors;
        if (e.template_id) setStep(0);
        else if (e.tags || (e as any).industry_slugs) setStep(1);
    }, [form.errors]);

    const loadPreview = async () => {
        if (!form.data.template_id) return;
        setPreviewBusy(true);
        try {
            const { data } = await axios.post('/campaigns/preview', { template_id: form.data.template_id });
            setPreview(data);
        } catch {
            setPreview(null);
        } finally {
            setPreviewBusy(false);
        }
    };

    useEffect(() => { if (step === 2) loadPreview(); }, [step, form.data.template_id]);

    const template = templates.find((t) => t.id === form.data.template_id);
    const canNext = step === 0 ? !!template : step === 1 ? breakdown.count > 0 : step === 2 ? breakdown.count > 0 : true;

    const submit = (e: FormEvent) => {
        e.preventDefault();
        if (step < 3) { if (canNext) setStep(step + 1); return; }
        form.transform((d) => ({ ...d, schedule_at: d.send_mode === 'later' && d.schedule_at ? new Date(d.schedule_at).toISOString() : null }));
        form.post('/campaigns');
    };

    const days = breakdown.count && form.data.daily_cap ? Math.ceil(breakdown.count / form.data.daily_cap) : 0;

    const toggleIndustry = (slug: string) => {
        form.setData('industry_slugs', form.data.industry_slugs.includes(slug)
            ? form.data.industry_slugs.filter((s) => s !== slug)
            : [...form.data.industry_slugs, slug]);
    };

    return (
        <AppLayout title="New campaign" crumbs={[{ label: 'Campaigns', href: '/campaigns' }, { label: 'New' }]}>
            <div className="mx-auto max-w-4xl">
                <ol className="mb-8 flex items-center gap-2">
                    {STEPS.map((s, i) => (
                        <li key={s.label} className="flex flex-1 items-center gap-2">
                            <button
                                type="button"
                                onClick={() => i < step && setStep(i)}
                                disabled={i > step}
                                className={cn('flex items-center gap-2 rounded-lg text-sm font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring', i <= step ? 'text-foreground' : 'text-muted-foreground')}
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
                        <Link href="/settings/senders" className="underline">add one</Link>.
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
                                            No templates. <Link href="/templates/create" className="text-primary hover:underline">Create one first</Link>.
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
                                                                {selected && (<span className="grid size-5 place-items-center rounded-full bg-brand text-white"><Check className="size-3" /></span>)}
                                                            </div>
                                                            <p className="mt-1 truncate text-sm text-muted-foreground">{t.subject}</p>
                                                            <p className="mt-3 text-xs text-muted-foreground">{t.from_email} · {date(t.updated_at)}</p>
                                                            {t.variables && t.variables.length > 0 && (
                                                                <div className="mt-2 flex flex-wrap gap-1">
                                                                    {t.variables.slice(0, 4).map((v) => (
                                                                        <span key={v} className="rounded bg-muted px-1.5 py-0.5 font-mono text-[10px] text-muted-foreground">{v}</span>
                                                                    ))}
                                                                    {t.variables.length > 4 && <span className="text-[10px] text-muted-foreground">+{t.variables.length - 4}</span>}
                                                                </div>
                                                            )}
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
                                    <p className="mb-5 text-sm text-muted-foreground">Combine filters to narrow down. Only active contacts with an email are included.</p>
                                    <div className="grid gap-4 md:grid-cols-[1fr_220px]">
                                        <div className="space-y-4">
                                            <Card className="p-5">
                                                <Field label="Tags (any match)" error={form.errors.tags} hint="Leave empty to not filter by tag.">
                                                    <TagInput value={form.data.tags} onChange={(t) => form.setData('tags', t)} suggestions={tags} placeholder="Pick or type tags…" />
                                                </Field>
                                            </Card>
                                            <Card className="p-5">
                                                <div className="mb-2 text-sm font-medium">Industries (any match)</div>
                                                <div className="flex flex-wrap gap-1.5">
                                                    {industries.map((i) => {
                                                        const selected = form.data.industry_slugs.includes(i.slug);
                                                        return (
                                                            <button key={i.slug} type="button" onClick={() => toggleIndustry(i.slug)}
                                                                className={cn('rounded-md border px-2 py-1 text-xs transition-colors', selected ? 'border-primary bg-primary/10 text-primary' : 'border-border text-muted-foreground hover:border-primary/40')}>
                                                                {i.label}
                                                            </button>
                                                        );
                                                    })}
                                                </div>
                                            </Card>
                                            <Card className="grid gap-4 p-5 sm:grid-cols-2">
                                                <Field label="Source system">
                                                    <Select value={form.data.source_system || 'all'} onValueChange={(v) => form.setData('source_system', v === 'all' ? '' : v as any)}>
                                                        <SelectTrigger><SelectValue /></SelectTrigger>
                                                        <SelectContent>
                                                            <SelectItem value="all">Any</SelectItem>
                                                            <SelectItem value="INTEL">Pure Intelligence</SelectItem>
                                                            <SelectItem value="CRM">US CRM</SelectItem>
                                                        </SelectContent>
                                                    </Select>
                                                </Field>
                                                <Field label="Minimum lead score">
                                                    <Select value={form.data.lead_score_min || 'all'} onValueChange={(v) => form.setData('lead_score_min', v === 'all' ? '' : v)}>
                                                        <SelectTrigger><SelectValue /></SelectTrigger>
                                                        <SelectContent>
                                                            <SelectItem value="all">Any</SelectItem>
                                                            <SelectItem value="60">≥ 60</SelectItem>
                                                            <SelectItem value="70">≥ 70</SelectItem>
                                                            <SelectItem value="80">≥ 80</SelectItem>
                                                            <SelectItem value="90">≥ 90</SelectItem>
                                                        </SelectContent>
                                                    </Select>
                                                </Field>
                                            </Card>
                                        </div>
                                        <TiltCard hero className="flex flex-col items-center justify-center p-6 text-center">
                                            <Users className="mb-2 size-5 text-primary" />
                                            <div className="font-mono text-4xl font-semibold tracking-tight">{breakdownLoading ? <Loader2 className="mx-auto size-7 animate-spin text-muted-foreground" /> : fmt(breakdown.count)}</div>
                                            <div className="mt-1 text-xs text-muted-foreground">recipients</div>
                                        </TiltCard>
                                    </div>
                                </div>
                            )}

                            {step === 2 && (
                                <div>
                                    <h2 className="mb-1 text-lg font-semibold">Readiness check</h2>
                                    <p className="mb-5 text-sm text-muted-foreground">How personalized will your sends actually be?</p>

                                    <Card className="mb-4 p-5">
                                        <div className="mb-3 flex items-center gap-2 text-sm font-medium">
                                            <Sparkles className="size-4 text-primary" /> Audience readiness breakdown
                                        </div>
                                        <ReadinessBars tiers={breakdown.tiers} total={breakdown.count} />
                                        <div className="mt-4 flex items-start justify-between gap-3 rounded-lg border bg-muted/30 p-3">
                                            <div className="flex-1">
                                                <div className="text-sm font-medium">Require mapped industry</div>
                                                <p className="text-xs text-muted-foreground">
                                                    On: contacts without a matched industry are skipped (full personalization enforced).<br />
                                                    Off: they get the template with fallback phrases from Settings → Fallbacks.
                                                </p>
                                            </div>
                                            <Switch checked={form.data.require_industry} onCheckedChange={(v) => form.setData('require_industry', v)} />
                                        </div>
                                    </Card>

                                    <Card className="overflow-hidden">
                                        <div className="flex items-center justify-between border-b px-4 py-2">
                                            <div className="text-sm font-medium">Preview with a real contact</div>
                                            <Button type="button" variant="ghost" size="sm" onClick={loadPreview} disabled={previewBusy}>
                                                <RefreshCw className={cn('size-3.5', previewBusy && 'animate-spin')} /> Shuffle
                                            </Button>
                                        </div>
                                        {preview ? (
                                            <div>
                                                <div className="border-b px-4 py-2 text-xs">
                                                    <div className="flex items-center gap-2">
                                                        <span className="font-mono">{preview.contact.email}</span>
                                                        <TierBadge tier={preview.contact.tier} />
                                                        <span className="text-muted-foreground">{preview.contact.business || 'no business'}</span>
                                                    </div>
                                                    <div className="mt-1 truncate font-medium">Subject: {preview.subject}</div>
                                                    {preview.used_fallback.length > 0 && (
                                                        <div className="mt-1 text-amber-600">⚠ Used fallback for: {preview.used_fallback.join(', ')}</div>
                                                    )}
                                                </div>
                                                <iframe title="Preview" sandbox="" srcDoc={preview.html} className="h-[400px] w-full bg-white" />
                                            </div>
                                        ) : (
                                            <div className="p-8 text-center text-sm text-muted-foreground">
                                                {previewBusy ? <Loader2 className="mx-auto size-5 animate-spin" /> : 'Click Shuffle to render a preview.'}
                                            </div>
                                        )}
                                    </Card>
                                </div>
                            )}

                            {step === 3 && (
                                <div>
                                    <h2 className="mb-1 text-lg font-semibold">Schedule & limits</h2>
                                    <p className="mb-5 text-sm text-muted-foreground">Warm inboxes slowly. A low daily cap keeps you out of spam.</p>
                                    <Card className="space-y-5 p-5">
                                        <Field label="Campaign name" htmlFor="name" error={form.errors.name}>
                                            <Input id="name" autoFocus value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} placeholder={`${template?.name ?? 'Campaign'}, ${new Date().toLocaleDateString(undefined, { month: 'short', day: 'numeric' })}`} />
                                        </Field>
                                        <div className="grid gap-3 sm:grid-cols-2">
                                            {(['now', 'later'] as const).map((mode) => (
                                                <button key={mode} type="button" onClick={() => form.setData('send_mode', mode)}
                                                    className={cn('flex items-center gap-3 rounded-xl border p-4 text-left transition-all focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
                                                        form.data.send_mode === mode ? 'border-primary bg-primary/5 shadow-glow' : 'hover:border-primary/40')}>
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
                                            <Field label="Daily cap" htmlFor="daily_cap" error={form.errors.daily_cap} hint={days ? `${fmt(breakdown.count)} recipients ≈ ${days} day${days > 1 ? 's' : ''}` : undefined}>
                                                <Input id="daily_cap" type="number" min={1} value={form.data.daily_cap} onChange={(e) => form.setData('daily_cap', Number(e.target.value))} />
                                            </Field>
                                            <Field label="Sender strategy" error={form.errors.sender_strategy}>
                                                <Select value={form.data.sender_strategy} onValueChange={(v) => form.setData('sender_strategy', v as 'round_robin' | 'priority')}>
                                                    <SelectTrigger><SelectValue /></SelectTrigger>
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
                        {step < 3 ? (
                            <Button type="submit" disabled={!canNext}>Continue <ArrowRight /></Button>
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

function ReadinessBars({ tiers, total }: { tiers: { t1: number; t2: number; t3: number; t4: number }; total: number }) {
    const bars = [
        { label: 'T4 Rich data', count: tiers.t4, color: 'bg-primary' },
        { label: 'T3 Industry mapped', count: tiers.t3, color: 'bg-emerald-500' },
        { label: 'T2 Business only', count: tiers.t2, color: 'bg-amber-500' },
        { label: 'T1 Email only', count: tiers.t1, color: 'bg-neutral-400' },
    ];
    return (
        <div className="space-y-1.5">
            {bars.map((b) => {
                const pct = total ? (b.count / total) * 100 : 0;
                return (
                    <div key={b.label} className="flex items-center gap-3 text-xs">
                        <div className="w-32 shrink-0 text-muted-foreground">{b.label}</div>
                        <div className="relative h-4 flex-1 overflow-hidden rounded bg-muted">
                            <div className={cn('h-full transition-all duration-500', b.color)} style={{ width: `${pct}%` }} />
                        </div>
                        <div className="w-16 shrink-0 text-right font-mono text-muted-foreground">{fmt(b.count)}</div>
                    </div>
                );
            })}
        </div>
    );
}
