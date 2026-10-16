import { FormEvent } from 'react';
import { Link, useForm } from '@inertiajs/react';
import { Area, AreaChart, CartesianGrid, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';
import { ArrowUpRight, Loader2, Plus, Send, Upload } from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import TiltCard from '@/Components/TiltCard';
import StatusBadge from '@/Components/StatusBadge';
import PageHeader from '@/Components/PageHeader';
import Field from '@/Components/Field';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { fmt, pct } from '@/lib/utils';
import type { Campaign } from '@/types';

type Point = { date: string; value: number };
type Stat = { label: string; value: number; suffix: string; spark: Point[] };

interface Props {
    stats: Stat[];
    chart: Point[];
    campaigns: Campaign[];
    templates: { id: number; name: string }[];
    contactCount: number;
}

function Sparkline({ data, id }: { data: Point[]; id: string }) {
    return (
        <ResponsiveContainer width="100%" height={40}>
            <AreaChart data={data} margin={{ top: 4, right: 0, bottom: 0, left: 0 }} accessibilityLayer={false}>
                <defs>
                    <linearGradient id={id} x1="0" y1="0" x2="0" y2="1">
                        <stop offset="0%" stopColor="#8b5cf6" stopOpacity={0.35} />
                        <stop offset="100%" stopColor="#8b5cf6" stopOpacity={0} />
                    </linearGradient>
                </defs>
                <Area type="monotone" dataKey="value" stroke="#8b5cf6" strokeWidth={1.75} fill={`url(#${id})`} isAnimationActive={false} />
            </AreaChart>
        </ResponsiveContainer>
    );
}

function QuickSend({ templates }: { templates: Props['templates'] }) {
    const form = useForm({ template_id: templates[0] ? String(templates[0].id) : '', email: '' });
    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post(`/templates/${form.data.template_id}/test`, { preserveScroll: true, onSuccess: () => form.reset('email') });
    };

    return (
        <TiltCard hero className="p-5">
            <div className="mb-4 flex items-center gap-2">
                <div className="grid size-8 place-items-center rounded-lg bg-brand text-white shadow-glow">
                    <Send className="size-4" />
                </div>
                <div>
                    <h2 className="text-sm font-semibold">Quick send</h2>
                    <p className="text-xs text-muted-foreground">Fire a template to one inbox to check it.</p>
                </div>
            </div>
            {templates.length === 0 ? (
                <p className="text-sm text-muted-foreground">
                    <Link href="/templates/create" className="text-primary hover:underline">
                        Create a template
                    </Link>{' '}
                    first.
                </p>
            ) : (
                <form onSubmit={submit} className="space-y-3" noValidate>
                    <Field label="Template">
                        <Select value={form.data.template_id} onValueChange={(v) => form.setData('template_id', v)}>
                            <SelectTrigger>
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {templates.map((t) => (
                                    <SelectItem key={t.id} value={String(t.id)}>
                                        {t.name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </Field>
                    <Field label="Send to" htmlFor="qs-email" error={form.errors.email}>
                        <Input id="qs-email" type="email" placeholder="you@company.com" value={form.data.email} onChange={(e) => form.setData('email', e.target.value)} />
                    </Field>
                    <Button type="submit" className="w-full" disabled={form.processing || !form.data.email}>
                        {form.processing ? <Loader2 className="animate-spin" /> : <Send />} Send test
                    </Button>
                </form>
            )}
        </TiltCard>
    );
}

export default function Dashboard({ stats, chart, campaigns, templates, contactCount }: Props) {
    return (
        <AppLayout title="Dashboard">
            <PageHeader
                title="Overview"
                description={`Last 7 days · ${fmt(contactCount)} active contacts`}
                actions={
                    <>
                        <Button variant="outline" asChild>
                            <Link href="/contacts">
                                <Upload /> Import
                            </Link>
                        </Button>
                        <Button asChild>
                            <Link href="/campaigns/create">
                                <Plus /> New campaign
                            </Link>
                        </Button>
                    </>
                }
            />

            <div className="grid grid-cols-2 gap-3 sm:gap-4 lg:grid-cols-4">
                {stats.map((s, i) => (
                    <TiltCard key={s.label} hero className="overflow-hidden p-4 sm:p-5">
                        <div className="text-xs font-medium text-muted-foreground sm:text-sm">{s.label}</div>
                        <div className="mt-1 font-mono text-2xl font-semibold tracking-tight sm:text-3xl">
                            {fmt(s.value)}
                            <span className="text-lg text-muted-foreground">{s.suffix}</span>
                        </div>
                        <div className="-mx-1 mt-2">
                            <Sparkline data={s.spark} id={`spark-${i}`} />
                        </div>
                    </TiltCard>
                ))}
            </div>

            <div className="mt-4 grid gap-4 lg:grid-cols-3">
                <Card className="p-5 lg:col-span-2">
                    <div className="mb-4 flex items-baseline justify-between">
                        <h2 className="text-sm font-semibold">Sends per day</h2>
                        <span className="text-xs text-muted-foreground">Last 30 days</span>
                    </div>
                    <div className="h-64">
                        <ResponsiveContainer width="100%" height="100%">
                            <AreaChart data={chart} margin={{ top: 4, right: 4, bottom: 0, left: -20 }} accessibilityLayer={false}>
                                <defs>
                                    <linearGradient id="main" x1="0" y1="0" x2="0" y2="1">
                                        <stop offset="0%" stopColor="#6366f1" stopOpacity={0.4} />
                                        <stop offset="100%" stopColor="#a855f7" stopOpacity={0} />
                                    </linearGradient>
                                </defs>
                                <CartesianGrid vertical={false} stroke="currentColor" className="text-border" strokeDasharray="3 3" />
                                <XAxis dataKey="date" tickLine={false} axisLine={false} tick={{ fontSize: 11, fill: 'hsl(var(--muted-foreground))' }} interval="preserveStartEnd" minTickGap={24} />
                                <YAxis allowDecimals={false} tickLine={false} axisLine={false} tick={{ fontSize: 11, fill: 'hsl(var(--muted-foreground))' }} />
                                <Tooltip
                                    cursor={{ stroke: 'hsl(var(--border))' }}
                                    contentStyle={{ background: 'hsl(var(--popover))', border: '1px solid hsl(var(--border))', borderRadius: 10, fontSize: 12 }}
                                    labelStyle={{ color: 'hsl(var(--muted-foreground))' }}
                                    formatter={(v) => [v, 'Sent']}
                                />
                                <Area type="monotone" dataKey="value" stroke="#6366f1" strokeWidth={2} fill="url(#main)" />
                            </AreaChart>
                        </ResponsiveContainer>
                    </div>
                </Card>
                <QuickSend templates={templates} />
            </div>

            <TiltCard className="mt-4 overflow-hidden">
                <div className="flex items-center justify-between p-5 pb-3">
                    <h2 className="text-sm font-semibold">Recent campaigns</h2>
                    <Link href="/campaigns" className="flex items-center gap-1 text-xs text-muted-foreground hover:text-foreground">
                        View all <ArrowUpRight className="size-3" />
                    </Link>
                </div>
                {campaigns.length === 0 ? (
                    <p className="px-5 pb-6 text-sm text-muted-foreground">No campaigns yet.</p>
                ) : (
                    <ul className="divide-y divide-border">
                        {campaigns.map((c) => (
                            <li key={c.id}>
                                <Link href={`/campaigns/${c.id}`} className="flex flex-wrap items-center gap-x-4 gap-y-2 px-5 py-3 transition-colors hover:bg-accent/50 focus-visible:bg-accent/50 focus-visible:outline-none">
                                    <div className="min-w-0 flex-1">
                                        <div className="truncate text-sm font-medium">{c.name}</div>
                                        <div className="truncate text-xs text-muted-foreground">{c.template?.name}</div>
                                    </div>
                                    <StatusBadge status={c.status} />
                                    <div className="flex gap-4 font-mono text-xs text-muted-foreground">
                                        <span>
                                            <span className="text-foreground">{fmt(c.sent_count)}</span>/{fmt(c.total_recipients)} sent
                                        </span>
                                        <span>
                                            <span className="text-foreground">{pct(c.opened_count, c.sent_count)}%</span> open
                                        </span>
                                        <span>
                                            <span className="text-foreground">{pct(c.clicked_count, c.sent_count)}%</span> click
                                        </span>
                                    </div>
                                </Link>
                            </li>
                        ))}
                    </ul>
                )}
            </TiltCard>
        </AppLayout>
    );
}
