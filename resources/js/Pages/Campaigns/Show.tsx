import { useEffect } from 'react';
import { Link, router, useForm } from '@inertiajs/react';
import { Activity, CalendarClock, Gauge, Loader2, Pause, Play, Rocket, Trash2 } from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import { confirmDialog, visit } from '@/Components/ConfirmDialog';
import StatusBadge from '@/Components/StatusBadge';
import TiltCard from '@/Components/TiltCard';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Progress } from '@/Components/ui/progress';
import { dateTime, fmt, pct } from '@/lib/utils';
import type { Campaign } from '@/types';

interface FeedEvent {
    id: number;
    type: string;
    payload: Record<string, string> | null;
    created_at: string;
    contact_id: number;
    email: string;
}

export default function CampaignShow({ campaign, breakdown, events }: { campaign: Campaign; breakdown: Record<string, number>; events: FeedEvent[] }) {
    const action = useForm({});
    const live = campaign.status === 'running' || campaign.status === 'scheduled';

    // Poll live stats every 5s while running
    useEffect(() => {
        if (!live) return;
        const t = setInterval(() => router.reload({ only: ['campaign', 'breakdown', 'events'] }), 5000);
        return () => clearInterval(t);
    }, [live]);

    const post = (path: string) => action.post(`/campaigns/${campaign.id}/${path}`, { preserveScroll: true });

    const stats = [
        { label: 'Sent', value: fmt(campaign.sent_count), sub: `of ${fmt(campaign.total_recipients)}` },
        { label: 'Opened', value: `${pct(campaign.opened_count, campaign.sent_count)}%`, sub: `${fmt(campaign.opened_count)} unique` },
        { label: 'Clicked', value: `${pct(campaign.clicked_count, campaign.sent_count)}%`, sub: `${fmt(campaign.clicked_count)} unique` },
        { label: 'Unsubscribed', value: fmt(campaign.unsubscribed_count), sub: `${fmt(breakdown.failed ?? 0)} failed` },
    ];

    return (
        <AppLayout title={campaign.name} crumbs={[{ label: 'Campaigns', href: '/campaigns' }, { label: campaign.name }]}>
            <div className="mb-6 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                <div className="min-w-0">
                    <div className="flex items-center gap-2">
                        <h1 className="truncate text-2xl font-semibold tracking-tight">{campaign.name}</h1>
                        <StatusBadge status={campaign.status} />
                    </div>
                    <p className="mt-1 text-sm text-muted-foreground">
                        <Link href={`/templates/${campaign.template_id}/edit`} className="hover:text-foreground">
                            {campaign.template?.name}
                        </Link>
                        {' · '}
                        {campaign.contact_filter?.tags?.length ? `Tags: ${campaign.contact_filter.tags.join(', ')}` : 'All active contacts'}
                    </p>
                </div>
                <div className="flex gap-2">
                    {campaign.status === 'draft' && (
                        <Button onClick={() => post('launch')} disabled={action.processing}>
                            {action.processing ? <Loader2 className="animate-spin" /> : <Rocket />} Launch
                        </Button>
                    )}
                    {live && (
                        <Button variant="outline" onClick={() => post('pause')} disabled={action.processing}>
                            <Pause /> Pause
                        </Button>
                    )}
                    {campaign.status === 'paused' && (
                        <Button onClick={() => post('resume')} disabled={action.processing}>
                            <Play /> Resume
                        </Button>
                    )}
                    {campaign.status !== 'running' && (
                        <Button variant="outline" aria-label="Delete campaign" onClick={() => confirmDialog({ title: 'Delete campaign?', description: `"${campaign.name}" and its full send history will be permanently removed.`, onConfirm: visit((o) => router.delete(`/campaigns/${campaign.id}`, o)) })}>
                            <Trash2 className="text-red-500" />
                        </Button>
                    )}
                </div>
            </div>

            <div className="grid grid-cols-2 gap-3 sm:gap-4 lg:grid-cols-4">
                {stats.map((s) => (
                    <TiltCard key={s.label} hero className="p-4 sm:p-5">
                        <div className="text-xs font-medium text-muted-foreground sm:text-sm">{s.label}</div>
                        <div className="mt-1 font-mono text-2xl font-semibold tracking-tight sm:text-3xl">{s.value}</div>
                        <div className="mt-1 text-xs text-muted-foreground">{s.sub}</div>
                    </TiltCard>
                ))}
            </div>

            <div className="mt-4 grid gap-4 lg:grid-cols-3">
                <Card className="p-5">
                    <h2 className="mb-4 flex items-center gap-2 text-sm font-semibold">
                        <Gauge className="size-4 text-primary" /> Delivery
                    </h2>
                    <div className="mb-1 flex justify-between font-mono text-xs text-muted-foreground">
                        <span>{pct(campaign.sent_count, campaign.total_recipients)}% sent</span>
                        <span>
                            {fmt(campaign.sent_count)}/{fmt(campaign.total_recipients)}
                        </span>
                    </div>
                    <Progress value={pct(campaign.sent_count, campaign.total_recipients)} />
                    <dl className="mt-5 space-y-2.5 text-sm">
                        {['queued', 'sending', 'sent', 'failed', 'bounced'].map((s) => (
                            <div key={s} className="flex items-center justify-between">
                                <dt>
                                    <StatusBadge status={s} />
                                </dt>
                                <dd className="font-mono">{fmt(breakdown[s] ?? 0)}</dd>
                            </div>
                        ))}
                    </dl>
                    <div className="mt-5 space-y-1.5 border-t pt-4 text-xs text-muted-foreground">
                        <div className="flex justify-between">
                            <span>Daily cap</span>
                            <span className="font-mono text-foreground">{fmt(campaign.daily_cap)}/day</span>
                        </div>
                        <div className="flex justify-between">
                            <span>Strategy</span>
                            <span className="text-foreground">{campaign.sender_strategy === 'priority' ? 'Priority' : 'Round robin'}</span>
                        </div>
                        {campaign.schedule_at && (
                            <div className="flex justify-between">
                                <span className="flex items-center gap-1">
                                    <CalendarClock className="size-3" /> Starts
                                </span>
                                <span className="text-foreground">{dateTime(campaign.schedule_at)}</span>
                            </div>
                        )}
                    </div>
                </Card>

                <Card className="overflow-hidden lg:col-span-2">
                    <h2 className="flex items-center gap-2 p-5 pb-3 text-sm font-semibold">
                        <Activity className="size-4 text-primary" /> Event feed
                        {live && <span className="ml-auto text-xs font-normal text-muted-foreground">auto-refreshing</span>}
                    </h2>
                    {events.length === 0 ? (
                        <p className="px-5 pb-6 text-sm text-muted-foreground">{campaign.status === 'draft' ? 'Launch the campaign to start sending.' : 'Waiting for the first event…'}</p>
                    ) : (
                        <ul className="max-h-[420px] divide-y overflow-y-auto">
                            {events.map((e) => (
                                <li key={e.id} className="flex items-center gap-3 px-5 py-2.5 text-sm">
                                    <StatusBadge status={e.type} className="w-24 justify-center" />
                                    <Link href={`/contacts/${e.contact_id}`} className="min-w-0 flex-1 truncate hover:text-primary">
                                        {e.email}
                                        {e.payload?.url && <span className="ml-2 font-mono text-xs text-muted-foreground">{e.payload.url}</span>}
                                        {e.payload?.error && <span className="ml-2 text-xs text-red-500">{e.payload.error}</span>}
                                    </Link>
                                    <span className="hidden font-mono text-xs text-muted-foreground sm:inline">{dateTime(e.created_at)}</span>
                                </li>
                            ))}
                        </ul>
                    )}
                </Card>
            </div>
        </AppLayout>
    );
}
