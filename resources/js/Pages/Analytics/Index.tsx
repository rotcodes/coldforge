import { Link } from '@inertiajs/react';
import { Area, AreaChart, CartesianGrid, Legend, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';
import { Bot, MailCheck, MessageSquareReply, ShieldAlert, UserMinus } from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import StatusBadge from '@/Components/StatusBadge';
import { Card } from '@/Components/ui/card';
import { fmt, pct } from '@/lib/utils';

type Props = {
    metrics: { sent: number; failed: number; replies: number; unsubscribes: number };
    daily: { date: string; sent: number; replies: number }[];
    campaigns: { id: number; name: string; status: string; total_recipients: number; sent_count: number; opened_count: number; clicked_count: number; unsubscribed_count: number; bounced_count: number }[];
    pipeline: Record<string, number>;
    sequences: Record<string, number>;
    ai: { requests: number; input_tokens: number; output_tokens: number; failures: number };
};

export default function Analytics({ metrics, daily, campaigns, pipeline, sequences, ai }: Props) {
    const cards = [
        { label: 'Accepted by server', value: metrics.sent, icon: MailCheck },
        { label: 'Contacts replied', value: metrics.replies, icon: MessageSquareReply },
        { label: 'Unsubscribed', value: metrics.unsubscribes, icon: UserMinus },
        { label: 'Failed or uncertain', value: metrics.failed, icon: ShieldAlert },
    ];
    return <AppLayout title="Analytics">
        <div className="mb-6"><h1 className="text-xl font-semibold">Analytics</h1><p className="mt-1 text-sm text-muted-foreground">Operational facts from the last 30 days. SMTP acceptance is not presented as confirmed delivery.</p></div>
        <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">{cards.map((card) => <Card key={card.label} className="p-5"><div className="flex items-center gap-2 text-xs text-muted-foreground"><card.icon className="size-4" />{card.label}</div><div className="mt-3 font-mono text-3xl font-semibold">{fmt(card.value)}</div></Card>)}</div>
        <Card className="mt-4 p-5"><div className="mb-4"><h2 className="text-sm font-semibold">Mail activity</h2><p className="text-xs text-muted-foreground">Accepted messages and contacts with a recorded reply.</p></div><div className="h-72"><ResponsiveContainer width="100%" height="100%"><AreaChart data={daily}><CartesianGrid vertical={false} stroke="hsl(var(--border))" strokeDasharray="3 3" /><XAxis dataKey="date" tickLine={false} axisLine={false} tick={{ fontSize: 11 }} minTickGap={24} /><YAxis allowDecimals={false} tickLine={false} axisLine={false} tick={{ fontSize: 11 }} /><Tooltip contentStyle={{ background: 'hsl(var(--popover))', border: '1px solid hsl(var(--border))' }} /><Legend /><Area dataKey="sent" name="Accepted" stroke="#d4d4d8" fill="#d4d4d822" /><Area dataKey="replies" name="Replies" stroke="#34d399" fill="#34d39918" /></AreaChart></ResponsiveContainer></div></Card>
        <div className="mt-4 grid gap-4 xl:grid-cols-[minmax(0,2fr)_minmax(280px,1fr)]"><Card className="overflow-hidden"><h2 className="border-b p-5 text-sm font-semibold">Campaign performance</h2>{campaigns.length === 0 ? <p className="p-5 text-sm text-muted-foreground">No campaigns yet.</p> : <div className="overflow-x-auto"><table className="w-full text-left text-sm"><thead className="bg-muted/30 text-xs text-muted-foreground"><tr><th className="px-5 py-2.5">Campaign</th><th className="px-3 py-2.5">Status</th><th className="px-3 py-2.5">Sent</th><th className="px-3 py-2.5">Open*</th><th className="px-3 py-2.5">Click*</th><th className="px-5 py-2.5">Opt-outs</th></tr></thead><tbody className="divide-y">{campaigns.map((campaign) => <tr key={campaign.id}><td className="px-5 py-3"><Link href={`/campaigns/${campaign.id}`} className="font-medium hover:underline">{campaign.name}</Link></td><td className="px-3 py-3"><StatusBadge status={campaign.status} /></td><td className="px-3 py-3 font-mono">{campaign.sent_count}/{campaign.total_recipients}</td><td className="px-3 py-3 font-mono">{pct(campaign.opened_count, campaign.sent_count)}%</td><td className="px-3 py-3 font-mono">{pct(campaign.clicked_count, campaign.sent_count)}%</td><td className="px-5 py-3 font-mono">{campaign.unsubscribed_count}</td></tr>)}</tbody></table><p className="px-5 py-3 text-[11px] text-muted-foreground">* Opens and clicks may be distorted by privacy tools and scanners.</p></div>}</Card><div className="space-y-4"><Card className="p-5"><h2 className="text-sm font-semibold">Pipeline</h2><dl className="mt-3 space-y-2">{Object.entries(pipeline).map(([status, total]) => <div key={status} className="flex justify-between text-sm"><dt className="capitalize text-muted-foreground">{status.replaceAll('_', ' ')}</dt><dd className="font-mono">{fmt(total)}</dd></div>)}</dl></Card><Card className="p-5"><div className="flex items-center gap-2"><Bot className="size-4 text-muted-foreground" /><h2 className="text-sm font-semibold">AI usage</h2></div><dl className="mt-3 space-y-2 text-sm"><div className="flex justify-between"><dt className="text-muted-foreground">Requests</dt><dd className="font-mono">{fmt(ai.requests)}</dd></div><div className="flex justify-between"><dt className="text-muted-foreground">Input tokens</dt><dd className="font-mono">{fmt(ai.input_tokens)}</dd></div><div className="flex justify-between"><dt className="text-muted-foreground">Output tokens</dt><dd className="font-mono">{fmt(ai.output_tokens)}</dd></div><div className="flex justify-between"><dt className="text-muted-foreground">Failures</dt><dd className="font-mono">{fmt(ai.failures)}</dd></div></dl></Card><Card className="p-5"><h2 className="text-sm font-semibold">Sequences</h2><dl className="mt-3 space-y-2">{Object.entries(sequences).map(([status, total]) => <div key={status} className="flex justify-between text-sm"><dt className="capitalize text-muted-foreground">{status}</dt><dd className="font-mono">{fmt(total)}</dd></div>)}</dl></Card></div></div>
    </AppLayout>;
}
