import { useEffect, useState } from 'react';
import { Link, router } from '@inertiajs/react';
import { AnimatePresence, motion } from 'framer-motion';
import { Area, AreaChart, Bar, BarChart, CartesianGrid, Legend, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';
import { AlertTriangle, Bell, CheckCircle2, Copy, ExternalLink, Mail, MousePointer2, Package, RefreshCw, ShieldAlert, Sparkles, Wifi, WifiOff, XCircle, Zap } from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import PageHeader from '@/Components/PageHeader';
import TiltCard from '@/Components/TiltCard';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Tooltip as UiTooltip, TooltipContent, TooltipTrigger } from '@/Components/ui/tooltip';
import { cn, dateTime, fmt } from '@/lib/utils';
import { toast } from 'sonner';

type DayStat = { date: string; delivered: number; opened: number; clicked: number; bounced: number; blocked: number };
type EventRow = {
    id: number;
    event: string;
    email: string | null;
    reason: string | null;
    link: string | null;
    is_negative: boolean;
    at: string;
    contact: { id: number; business: string | null } | null;
    campaign: { id: number; name: string } | null;
};
type Issue = { severity: 'critical' | 'high' | 'medium'; label: string; detail: string };

interface Props {
    available: boolean;
    account: { company: string | null; email: string | null; plan: Array<{ type: string; credits: number }> } | null;
    credits: number | null;
    sender: { id: number; name: string; daily_quota: number; sent_today: number } | null;
    daily: DayStat[];
    events: EventRow[];
    issues: Issue[];
    webhook_url: string;
    webhook_configured: boolean;
    last_event_at: string | null;
}

const EVENT_META: Record<string, { label: string; cn: string; icon: typeof Mail }> = {
    request:          { label: 'Queued',       cn: 'text-neutral-500 bg-neutral-500/10', icon: Package },
    delivered:        { label: 'Delivered',    cn: 'text-emerald-500 bg-emerald-500/10', icon: CheckCircle2 },
    opened:           { label: 'Opened',       cn: 'text-blue-500 bg-blue-500/10',       icon: Mail },
    unique_opened:    { label: 'First open',   cn: 'text-blue-500 bg-blue-500/10',       icon: Mail },
    proxy_open:       { label: 'Proxy open',   cn: 'text-blue-400 bg-blue-400/10',       icon: Mail },
    unique_proxy_open:{ label: 'First proxy',  cn: 'text-blue-400 bg-blue-400/10',       icon: Mail },
    click:            { label: 'Clicked',      cn: 'text-violet-500 bg-violet-500/10',   icon: MousePointer2 },
    soft_bounce:      { label: 'Soft bounce',  cn: 'text-amber-500 bg-amber-500/10',     icon: AlertTriangle },
    hard_bounce:      { label: 'Hard bounce',  cn: 'text-red-500 bg-red-500/10',         icon: XCircle },
    invalid_email:    { label: 'Invalid',      cn: 'text-red-500 bg-red-500/10',         icon: XCircle },
    blocked:          { label: 'Blocked',      cn: 'text-rose-500 bg-rose-500/10',       icon: ShieldAlert },
    spam:             { label: 'Spam',         cn: 'text-rose-600 bg-rose-600/10',       icon: ShieldAlert },
    unsubscribed:     { label: 'Unsubbed',     cn: 'text-orange-500 bg-orange-500/10',   icon: Bell },
    deferred:         { label: 'Deferred',     cn: 'text-amber-400 bg-amber-400/10',     icon: AlertTriangle },
    error:            { label: 'Error',        cn: 'text-red-600 bg-red-600/10',         icon: XCircle },
};

const ISSUE_STYLES = {
    critical: 'border-red-500/40 bg-red-500/10 text-red-700 dark:text-red-300',
    high: 'border-amber-500/40 bg-amber-500/10 text-amber-700 dark:text-amber-300',
    medium: 'border-sky-500/40 bg-sky-500/10 text-sky-700 dark:text-sky-300',
};

export default function BrevoIndex(props: Props) {
    const [autoRefresh, setAutoRefresh] = useState(true);

    // Poll every 10s for new events / stats while on this page.
    useEffect(() => {
        if (!autoRefresh) return;
        const t = setInterval(() => router.reload({ only: ['events', 'daily', 'issues', 'sender', 'credits', 'last_event_at'] }), 10000);
        return () => clearInterval(t);
    }, [autoRefresh]);

    const copyWebhook = async () => {
        try {
            await navigator.clipboard.writeText(props.webhook_url);
            toast.success('Webhook URL copied');
        } catch {
            toast.error('Could not copy');
        }
    };

    const quotaPct = props.sender ? Math.min(100, (props.sender.sent_today / Math.max(1, props.sender.daily_quota)) * 100) : 0;
    const remaining = props.sender ? Math.max(0, props.sender.daily_quota - props.sender.sent_today) : 0;

    const connected = !!props.last_event_at;
    const lastEventAgo = props.last_event_at ? timeAgo(new Date(props.last_event_at)) : null;

    return (
        <AppLayout title="Brevo">
            <PageHeader
                title="Brevo"
                description="Your sending account's live pulse — quota, credits, deliverability events, and alerts."
                actions={
                    <Button variant={autoRefresh ? 'secondary' : 'outline'} onClick={() => setAutoRefresh((a) => !a)}>
                        <RefreshCw className={cn('size-4', autoRefresh && 'animate-spin')} style={autoRefresh ? { animationDuration: '3s' } : undefined} /> {autoRefresh ? 'Auto-refreshing' : 'Paused'}
                    </Button>
                }
            />

            {!props.available && (
                <div className="mb-4 flex items-center gap-3 rounded-xl border border-amber-500/30 bg-amber-500/10 px-4 py-3 text-sm text-amber-700 dark:text-amber-300">
                    <AlertTriangle className="size-4 shrink-0" />
                    No active Brevo API key found. Add one in <Link href="/settings/senders" className="underline">Settings → Senders</Link>.
                </div>
            )}

            {/* Top row: quota, credits, webhook status */}
            <div className="grid gap-3 sm:gap-4 lg:grid-cols-3">
                <TiltCard hero className="p-5">
                    <div className="mb-3 flex items-center gap-2">
                        <span className="grid size-9 place-items-center rounded-lg bg-primary/15 text-primary"><Zap className="size-4" /></span>
                        <div>
                            <div className="text-xs font-medium text-muted-foreground">Daily quota (ColdForge)</div>
                            <div className="text-xs text-muted-foreground/70">Per sender · resets midnight local</div>
                        </div>
                    </div>
                    {props.sender ? (
                        <>
                            <div className="flex items-baseline gap-1">
                                <span className="font-mono text-3xl font-semibold tracking-tight">{fmt(props.sender.sent_today)}</span>
                                <span className="text-sm text-muted-foreground">/ {fmt(props.sender.daily_quota)}</span>
                            </div>
                            <div className="mt-2 h-2 overflow-hidden rounded-full bg-muted">
                                <motion.div
                                    initial={{ width: 0 }}
                                    animate={{ width: `${quotaPct}%` }}
                                    transition={{ duration: 0.6, ease: 'easeOut' }}
                                    className={cn('h-full rounded-full', quotaPct > 90 ? 'bg-red-500' : quotaPct > 70 ? 'bg-amber-500' : 'bg-brand')}
                                />
                            </div>
                            <div className="mt-2 flex justify-between text-xs text-muted-foreground">
                                <span>{quotaPct.toFixed(0)}% used today</span>
                                <span className="font-mono">{fmt(remaining)} left</span>
                            </div>
                        </>
                    ) : (
                        <div className="text-sm text-muted-foreground">No Brevo sender configured.</div>
                    )}
                </TiltCard>

                <TiltCard hero className="p-5">
                    <div className="mb-3 flex items-center gap-2">
                        <span className="grid size-9 place-items-center rounded-lg bg-emerald-500/15 text-emerald-500"><Package className="size-4" /></span>
                        <div>
                            <div className="text-xs font-medium text-muted-foreground">Brevo email credits</div>
                            <div className="text-xs text-muted-foreground/70">Remaining this month</div>
                        </div>
                    </div>
                    {props.credits !== null ? (
                        <>
                            <div className="font-mono text-3xl font-semibold tracking-tight">{fmt(props.credits)}</div>
                            <div className="mt-2 text-xs text-muted-foreground">
                                {props.account?.plan.filter((p) => p.type !== 'sms').map((p, i) => (
                                    <div key={i} className="flex justify-between">
                                        <span className="capitalize">{p.type.replace(/([A-Z])/g, ' $1').trim()}</span>
                                        <span className="font-mono">{fmt(p.credits)}</span>
                                    </div>
                                ))}
                            </div>
                        </>
                    ) : (
                        <div className="text-sm text-muted-foreground">Credits unavailable (API offline).</div>
                    )}
                </TiltCard>

                <TiltCard hero className="p-5">
                    <div className="mb-3 flex items-center gap-2">
                        <span className={cn('grid size-9 place-items-center rounded-lg', connected ? 'bg-emerald-500/15 text-emerald-500' : 'bg-neutral-500/15 text-neutral-500')}>
                            {connected ? <Wifi className="size-4" /> : <WifiOff className="size-4" />}
                        </span>
                        <div>
                            <div className="text-xs font-medium text-muted-foreground">Webhook feed</div>
                            <div className="text-xs text-muted-foreground/70">{props.webhook_configured ? 'URL ready' : 'Not configured'}</div>
                        </div>
                    </div>
                    {connected ? (
                        <>
                            <div className="flex items-baseline gap-2">
                                <span className="text-xl font-semibold text-emerald-500">Live</span>
                                <span className="text-xs text-muted-foreground">last event {lastEventAgo}</span>
                            </div>
                            <div className="mt-2 text-xs text-muted-foreground">Receiving events from Brevo in real time.</div>
                        </>
                    ) : (
                        <>
                            <div className="text-xl font-semibold text-muted-foreground">No events yet</div>
                            <div className="mt-2 text-xs text-muted-foreground">
                                {props.webhook_configured
                                    ? 'Add the URL below in your Brevo transactional settings.'
                                    : 'Generate BREVO_WEBHOOK_SECRET in your .env first.'}
                            </div>
                        </>
                    )}
                </TiltCard>
            </div>

            {/* Issue banner strip */}
            {props.issues.length > 0 && (
                <div className="mt-4 space-y-2">
                    {props.issues.map((i, k) => (
                        <motion.div
                            key={k}
                            initial={{ opacity: 0, y: -6 }}
                            animate={{ opacity: 1, y: 0 }}
                            transition={{ delay: k * 0.05 }}
                            className={cn('flex items-start gap-3 rounded-xl border px-4 py-3 text-sm', ISSUE_STYLES[i.severity])}
                        >
                            <AlertTriangle className="mt-0.5 size-4 shrink-0" />
                            <div className="flex-1">
                                <div className="font-medium">{i.label}</div>
                                <div className="text-xs opacity-80">{i.detail}</div>
                            </div>
                        </motion.div>
                    ))}
                </div>
            )}

            {/* Chart + live feed */}
            <div className="mt-4 grid gap-4 lg:grid-cols-3">
                <Card className="p-5 lg:col-span-2">
                    <div className="mb-4 flex items-baseline justify-between">
                        <h2 className="flex items-center gap-2 text-sm font-semibold"><Sparkles className="size-4 text-primary" /> 7-day activity</h2>
                        <span className="text-xs text-muted-foreground">from Brevo webhooks</span>
                    </div>
                    <div className="h-64">
                        <ResponsiveContainer width="100%" height="100%">
                            <BarChart data={props.daily} margin={{ top: 4, right: 4, bottom: 0, left: -20 }} accessibilityLayer={false}>
                                <CartesianGrid vertical={false} stroke="currentColor" className="text-border" strokeDasharray="3 3" />
                                <XAxis dataKey="date" tickLine={false} axisLine={false} tick={{ fontSize: 11, fill: 'hsl(var(--muted-foreground))' }} />
                                <YAxis allowDecimals={false} tickLine={false} axisLine={false} tick={{ fontSize: 11, fill: 'hsl(var(--muted-foreground))' }} />
                                <Tooltip
                                    cursor={{ fill: 'hsl(var(--accent) / 0.3)' }}
                                    contentStyle={{ background: 'hsl(var(--popover))', border: '1px solid hsl(var(--border))', borderRadius: 10, fontSize: 12 }}
                                />
                                <Legend wrapperStyle={{ fontSize: 11, paddingTop: 8 }} />
                                <Bar dataKey="delivered" stackId="a" fill="#10b981" radius={[0, 0, 0, 0]} />
                                <Bar dataKey="opened" stackId="a" fill="#3b82f6" />
                                <Bar dataKey="clicked" stackId="a" fill="#8b5cf6" />
                                <Bar dataKey="bounced" stackId="a" fill="#f59e0b" />
                                <Bar dataKey="blocked" stackId="a" fill="#ef4444" radius={[4, 4, 0, 0]} />
                            </BarChart>
                        </ResponsiveContainer>
                    </div>
                </Card>

                <Card className="flex flex-col overflow-hidden p-0 lg:col-span-1">
                    <div className="flex items-center justify-between border-b px-4 py-3">
                        <h2 className="flex items-center gap-2 text-sm font-semibold"><Bell className="size-4 text-primary" /> Live events</h2>
                        <span className="font-mono text-xs text-muted-foreground">{props.events.length}</span>
                    </div>
                    <div className="max-h-[520px] flex-1 overflow-y-auto">
                        {props.events.length === 0 ? (
                            <div className="p-6 text-center text-sm text-muted-foreground">No events yet. Once your webhook is live, events stream in here.</div>
                        ) : (
                            <ul className="divide-y">
                                <AnimatePresence initial={false}>
                                    {props.events.map((e) => <EventItem key={e.id} e={e} />)}
                                </AnimatePresence>
                            </ul>
                        )}
                    </div>
                </Card>
            </div>

            {/* Webhook setup card */}
            <Card className="mt-4 p-5">
                <div className="mb-3 flex items-center gap-2">
                    <span className="grid size-9 place-items-center rounded-lg bg-primary/15 text-primary"><Bell className="size-4" /></span>
                    <div>
                        <h2 className="text-sm font-semibold">Webhook setup</h2>
                        <p className="text-xs text-muted-foreground">One-time: paste this URL into Brevo's transactional webhook settings.</p>
                    </div>
                </div>
                {props.webhook_configured ? (
                    <div className="space-y-3">
                        <div className="flex items-center gap-2 rounded-lg border bg-muted/40 px-3 py-2 font-mono text-xs">
                            <code className="min-w-0 flex-1 truncate text-foreground">{props.webhook_url}</code>
                            <Button size="sm" variant="outline" onClick={copyWebhook}><Copy className="size-3" /> Copy</Button>
                        </div>
                        <ol className="list-decimal space-y-1 pl-5 text-sm text-muted-foreground">
                            <li>Open Brevo → <strong>Transactional → Settings → Webhooks</strong></li>
                            <li>Click <strong>Add a new webhook</strong></li>
                            <li>Paste the URL above</li>
                            <li>Check <strong>all event types</strong> (sent, delivered, opened, clicked, bounced, blocked, spam, unsubscribed, invalid, error, deferred)</li>
                            <li>Save. Events will start streaming here within seconds.</li>
                        </ol>
                        <a href="https://app.brevo.com/settings/webhook/list" target="_blank" rel="noopener" className="inline-flex items-center gap-1 text-sm text-primary hover:underline">
                            Open Brevo webhook settings <ExternalLink className="size-3" />
                        </a>
                    </div>
                ) : (
                    <div className="rounded-lg border border-amber-500/30 bg-amber-500/10 p-3 text-sm text-amber-700 dark:text-amber-300">
                        <p className="mb-2">Generate a secret and add to your <code className="rounded bg-background/50 px-1 font-mono text-xs">.env</code>:</p>
                        <pre className="overflow-x-auto rounded bg-background/60 p-2 font-mono text-xs">BREVO_WEBHOOK_SECRET={'<random 48-char hex string>'}</pre>
                        <p className="mt-2 text-xs">Then run <code className="font-mono">php artisan config:cache</code> and refresh.</p>
                    </div>
                )}
            </Card>
        </AppLayout>
    );
}

function EventItem({ e }: { e: EventRow }) {
    const meta = EVENT_META[e.event] ?? { label: e.event, cn: 'text-neutral-500 bg-neutral-500/10', icon: Mail };
    const Icon = meta.icon;
    return (
        <motion.li
            initial={{ opacity: 0, x: -8 }}
            animate={{ opacity: 1, x: 0 }}
            exit={{ opacity: 0, x: 8 }}
            transition={{ duration: 0.2 }}
            className={cn('flex items-start gap-3 px-4 py-2.5 text-sm', e.is_negative && 'bg-red-500/5')}
        >
            <span className={cn('mt-0.5 grid size-6 shrink-0 place-items-center rounded-md', meta.cn)}><Icon className="size-3" /></span>
            <div className="min-w-0 flex-1">
                <div className="flex items-center gap-2">
                    <span className="text-xs font-semibold">{meta.label}</span>
                    <UiTooltip>
                        <TooltipTrigger asChild>
                            <span className="font-mono text-[10px] text-muted-foreground">{timeAgo(new Date(e.at))}</span>
                        </TooltipTrigger>
                        <TooltipContent>{dateTime(e.at)}</TooltipContent>
                    </UiTooltip>
                </div>
                <div className="truncate text-xs text-muted-foreground">{e.email ?? '—'}</div>
                {e.reason && <div className="mt-0.5 truncate text-[10px] text-red-500">{e.reason}</div>}
                {e.link && <div className="mt-0.5 truncate text-[10px] text-violet-500">{e.link}</div>}
                {e.campaign && (
                    <Link href={`/campaigns/${e.campaign.id}`} className="mt-0.5 inline-block text-[10px] text-primary hover:underline">
                        {e.campaign.name}
                    </Link>
                )}
            </div>
        </motion.li>
    );
}

function timeAgo(d: Date): string {
    const s = Math.floor((Date.now() - d.getTime()) / 1000);
    if (s < 60) return `${s}s ago`;
    const m = Math.floor(s / 60);
    if (m < 60) return `${m}m ago`;
    const h = Math.floor(m / 60);
    if (h < 24) return `${h}h ago`;
    const days = Math.floor(h / 24);
    return `${days}d ago`;
}
