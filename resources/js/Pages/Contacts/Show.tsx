import { useState } from 'react';
import { Link, router } from '@inertiajs/react';
import { Activity, Pencil, Send, Trash2 } from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import { confirmDialog, visit } from '@/Components/ConfirmDialog';
import StatusBadge from '@/Components/StatusBadge';
import TiltCard from '@/Components/TiltCard';
import ContactDialog from '@/Components/ContactDialog';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { dateTime } from '@/lib/utils';
import type { Contact } from '@/types';

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

export default function ContactShow({ contact, sends, events }: { contact: Contact; sends: Send[]; events: Event[] }) {
    const [editOpen, setEditOpen] = useState(false);
    const name = [contact.first_name, contact.last_name].filter(Boolean).join(' ') || contact.email;

    const details: [string, string | null][] = [
        ['Email', contact.email],
        ['Company', contact.company],
        ['Country', contact.country],
        ['Source', contact.source],
        ...Object.entries(contact.custom_fields ?? {}).map(([k, v]) => [k.replace(/_/g, ' '), String(v)] as [string, string]),
    ];

    return (
        <AppLayout title={name} crumbs={[{ label: 'Contacts', href: '/contacts' }, { label: name }]}>
            <div className="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center">
                <div className="grid size-12 shrink-0 place-items-center rounded-2xl bg-brand text-lg font-semibold text-white shadow-glow">{name.slice(0, 1).toUpperCase()}</div>
                <div className="min-w-0 flex-1">
                    <div className="flex items-center gap-2">
                        <h1 className="truncate text-2xl font-semibold tracking-tight">{name}</h1>
                        <StatusBadge status={contact.status} />
                    </div>
                    <p className="truncate text-sm text-muted-foreground">{contact.email}</p>
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
                <TiltCard hero className="p-5">
                    <h2 className="mb-4 text-sm font-semibold">Details</h2>
                    <dl className="space-y-3 text-sm">
                        {details.map(([k, v]) => (
                            <div key={k} className="flex justify-between gap-4">
                                <dt className="capitalize text-muted-foreground">{k}</dt>
                                <dd className="truncate text-right">{v || '—'}</dd>
                            </div>
                        ))}
                        <div className="flex justify-between gap-4">
                            <dt className="text-muted-foreground">Tags</dt>
                            <dd className="flex flex-wrap justify-end gap-1">
                                {(contact.tags ?? []).length ? (
                                    contact.tags!.map((t) => (
                                        <span key={t} className="rounded-md bg-primary/15 px-1.5 py-0.5 text-xs text-primary">
                                            {t}
                                        </span>
                                    ))
                                ) : (
                                    '—'
                                )}
                            </dd>
                        </div>
                    </dl>
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

            <ContactDialog open={editOpen} onOpenChange={setEditOpen} contact={contact} tags={[]} statuses={['active', 'bounced', 'unsubscribed', 'complained']} />
        </AppLayout>
    );
}
