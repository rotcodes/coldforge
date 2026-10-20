import { useState } from 'react';
import { Link, router } from '@inertiajs/react';
import { Activity, ExternalLink, Pencil, Send, Sparkles, Trash2 } from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import { confirmDialog, visit } from '@/Components/ConfirmDialog';
import StatusBadge from '@/Components/StatusBadge';
import TierBadge from '@/Components/TierBadge';
import TiltCard from '@/Components/TiltCard';
import ContactDialog from '@/Components/ContactDialog';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/Components/ui/tabs';
import { dateTime } from '@/lib/utils';
import type { Contact, Industry } from '@/types';

interface Send {
    id: number;
    status: string;
    sent_at: string | null;
    opened_at: string | null;
    first_clicked_at: string | null;
    clicked_count: number;
    error: string | null;
    campaign: { id: number; name: string };
}

interface Event {
    id: number;
    type: string;
    payload: Record<string, string> | null;
    created_at: string;
    send: { campaign: { id: number; name: string } };
}

export default function ContactShow({ contact, sends, events, tier, industry }: { contact: Contact; sends: Send[]; events: Event[]; tier: number; industry: Industry | null }) {
    const [editOpen, setEditOpen] = useState(false);
    const name = contact.decision_maker || [contact.first_name, contact.last_name].filter(Boolean).join(' ') || contact.email;

    const basic: [string, React.ReactNode][] = [
        ['Email', contact.email],
        ['Business', contact.business || contact.company],
        ['City / state', contact.city_state],
        ['Website', contact.website ? <a href={contact.website.startsWith('http') ? contact.website : `https://${contact.website}`} target="_blank" rel="noopener" className="inline-flex items-center gap-1 text-primary hover:underline">{contact.website} <ExternalLink className="size-3" /></a> : null],
        ['Country', contact.country],
        ['Source', contact.source_system || contact.source],
        ['Lead score', contact.lead_score != null ? <span className="font-mono">{contact.lead_score}</span> : null],
        ['Confidence', contact.confidence],
    ];

    const intel: [string, React.ReactNode][] = [
        ['Decision maker', contact.decision_maker],
        ['Role', contact.dm_role],
        ['Direct phone', contact.direct_phone],
        ['Main phone', contact.main_phone],
        ['Company LinkedIn', contact.company_linkedin ? <a href={contact.company_linkedin} target="_blank" rel="noopener" className="truncate text-primary hover:underline">{contact.company_linkedin}</a> : null],
        ['DM LinkedIn', contact.dm_linkedin ? <a href={contact.dm_linkedin} target="_blank" rel="noopener" className="truncate text-primary hover:underline">{contact.dm_linkedin}</a> : null],
        ['Notion page', contact.notion_url ? <a href={contact.notion_url} target="_blank" rel="noopener" className="inline-flex items-center gap-1 text-primary hover:underline">Open <ExternalLink className="size-3" /></a> : null],
        ['Remote status', contact.remote_status],
    ];

    return (
        <AppLayout title={name} crumbs={[{ label: 'Contacts', href: '/contacts' }, { label: name }]}>
            <div className="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center">
                <div className="grid size-12 shrink-0 place-items-center rounded-2xl bg-brand text-lg font-semibold text-white shadow-glow">{name.slice(0, 1).toUpperCase()}</div>
                <div className="min-w-0 flex-1">
                    <div className="flex flex-wrap items-center gap-2">
                        <h1 className="truncate text-2xl font-semibold tracking-tight">{name}</h1>
                        <StatusBadge status={contact.status} />
                        <TierBadge tier={tier} />
                        {industry && (
                            <span className="inline-flex items-center gap-1 rounded-md bg-primary/10 px-1.5 py-0.5 text-xs text-primary">
                                <Sparkles className="size-3" /> {industry.label}
                            </span>
                        )}
                    </div>
                    <p className="truncate text-sm text-muted-foreground">
                        {contact.email}
                        {contact.business && <span className="ml-2">· {contact.business}</span>}
                    </p>
                </div>
                <div className="flex gap-2">
                    <Button variant="outline" onClick={() => setEditOpen(true)}>
                        <Pencil /> Edit
                    </Button>
                    <Button
                        variant="outline"
                        aria-label="Delete contact"
                        onClick={() => confirmDialog({ title: 'Delete contact?', description: `${contact.email} and their send history will be permanently removed.`, onConfirm: visit((o) => router.delete(`/contacts/${contact.id}`, o)) })}
                    >
                        <Trash2 className="text-red-500" />
                    </Button>
                </div>
            </div>

            <div className="grid gap-4 lg:grid-cols-3">
                <TiltCard hero className="p-5 lg:col-span-1">
                    <Tabs defaultValue="basic">
                        <TabsList className="w-full">
                            <TabsTrigger value="basic" className="flex-1">Basic</TabsTrigger>
                            <TabsTrigger value="intel" className="flex-1">Lead intel</TabsTrigger>
                            <TabsTrigger value="personalization" className="flex-1">Pitch</TabsTrigger>
                        </TabsList>
                        <TabsContent value="basic">
                            <DetailsList items={basic} />
                            <div className="mt-3 flex justify-between gap-4 text-sm">
                                <span className="text-muted-foreground">Tags</span>
                                <div className="flex flex-wrap justify-end gap-1">
                                    {(contact.tags ?? []).length ? contact.tags!.map((t) => (
                                        <span key={t} className="rounded-md bg-primary/15 px-1.5 py-0.5 text-xs text-primary">{t}</span>
                                    )) : <span className="text-sm text-muted-foreground">—</span>}
                                </div>
                            </div>
                        </TabsContent>
                        <TabsContent value="intel">
                            <DetailsList items={intel} />
                        </TabsContent>
                        <TabsContent value="personalization" className="space-y-3 text-sm">
                            <Block title="Personalized opener" text={contact.personalized_opener} />
                            <Block title="Pain evidence" text={contact.pain_evidence} />
                            <Block title="Recommended offer" text={contact.recommended_offer} />
                            <Block title="Why now trigger" text={contact.why_now_trigger} />
                            <Block title="Custom subject" text={contact.custom_subject} />
                        </TabsContent>
                    </Tabs>
                </TiltCard>

                <div className="space-y-4 lg:col-span-2">
                    <Card className="overflow-hidden">
                        <h2 className="flex items-center gap-2 p-5 pb-3 text-sm font-semibold">
                            <Send className="size-4 text-primary" /> Campaigns received
                        </h2>
                        {sends.length === 0 ? (
                            <p className="px-5 pb-5 text-sm text-muted-foreground">Not part of any campaign yet.</p>
                        ) : (
                            <ul className="divide-y">
                                {sends.map((s) => (
                                    <li key={s.id} className="flex flex-wrap items-center gap-x-4 gap-y-1 px-5 py-3 text-sm">
                                        <Link href={`/campaigns/${s.campaign.id}`} className="min-w-0 flex-1 truncate font-medium hover:text-primary">
                                            {s.campaign.name}
                                        </Link>
                                        <span className="font-mono text-xs text-muted-foreground">{dateTime(s.sent_at)}</span>
                                        {s.opened_at && <StatusBadge status="opened" />}
                                        {s.first_clicked_at && <StatusBadge status="clicked" />}
                                        <StatusBadge status={s.status} />
                                        {s.error && <p className="w-full text-xs text-red-500">{s.error}</p>}
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Card>

                    <Card className="overflow-hidden">
                        <h2 className="flex items-center gap-2 p-5 pb-3 text-sm font-semibold">
                            <Activity className="size-4 text-primary" /> Activity
                        </h2>
                        {events.length === 0 ? (
                            <p className="px-5 pb-5 text-sm text-muted-foreground">No activity yet.</p>
                        ) : (
                            <ul className="space-y-0 px-5 pb-4">
                                {events.map((e) => (
                                    <li key={e.id} className="flex items-center gap-3 border-l py-2 pl-4 text-sm">
                                        <StatusBadge status={e.type} />
                                        <span className="min-w-0 flex-1 truncate text-muted-foreground">
                                            {e.send.campaign.name}
                                            {e.payload?.url && <span className="ml-2 font-mono text-xs">{e.payload.url}</span>}
                                        </span>
                                        <span className="font-mono text-xs text-muted-foreground">{dateTime(e.created_at)}</span>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Card>
                </div>
            </div>

            <ContactDialog open={editOpen} onOpenChange={setEditOpen} contact={contact} tags={[]} statuses={['active', 'bounced', 'unsubscribed', 'complained']} industries={industry ? [{ slug: industry.slug, label: industry.label }] : []} />
        </AppLayout>
    );
}

function DetailsList({ items }: { items: [string, React.ReactNode][] }) {
    return (
        <dl className="space-y-3 text-sm">
            {items.map(([k, v]) => (
                <div key={k} className="flex justify-between gap-4">
                    <dt className="shrink-0 text-muted-foreground">{k}</dt>
                    <dd className="min-w-0 truncate text-right">{v || '—'}</dd>
                </div>
            ))}
        </dl>
    );
}

function Block({ title, text }: { title: string; text: string | null | undefined }) {
    return (
        <div>
            <div className="text-[10px] uppercase tracking-wider text-muted-foreground">{title}</div>
            {text ? <p className="mt-1 text-sm leading-relaxed">{text}</p> : <p className="mt-1 text-sm italic text-muted-foreground/70">— none —</p>}
        </div>
    );
}
