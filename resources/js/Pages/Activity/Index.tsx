import { FormEvent, useState } from 'react';
import { router } from '@inertiajs/react';
import { Activity as ActivityIcon, Search } from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import Pagination from '@/Components/Pagination';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { dateTime } from '@/lib/utils';
import type { Paginated } from '@/types';

type Log = { id: number; action: string; subject_type: string | null; subject_id: number | null; metadata: Record<string, unknown> | null; ip: string | null; created_at: string };
type Filters = { action?: string; subject_type?: string; date_from?: string; date_to?: string };

export default function Activity({ logs, filters, subjectTypes }: { logs: Paginated<Log>; filters: Filters; subjectTypes: string[] }) {
    const [form, setForm] = useState({ action: filters.action ?? '', subject_type: filters.subject_type ?? '', date_from: filters.date_from ?? '', date_to: filters.date_to ?? '' });
    const submit = (event: FormEvent) => { event.preventDefault(); router.get('/activity', form, { preserveState: true, replace: true }); };
    return <AppLayout title="Activity"><div className="mb-6"><h1 className="text-xl font-semibold">Activity</h1><p className="mt-1 text-sm text-muted-foreground">Audit trail for authenticated changes. Request bodies and credentials are never recorded.</p></div><Card className="mb-4 p-4"><form onSubmit={submit} className="grid gap-3 md:grid-cols-5"><Input placeholder="Action contains…" value={form.action} onChange={(event) => setForm({ ...form, action: event.target.value })} /><Select value={form.subject_type || 'all'} onValueChange={(value) => setForm({ ...form, subject_type: value === 'all' ? '' : value })}><SelectTrigger><SelectValue placeholder="Subject" /></SelectTrigger><SelectContent><SelectItem value="all">All subjects</SelectItem>{subjectTypes.map((type) => <SelectItem key={type} value={type}>{type}</SelectItem>)}</SelectContent></Select><Input type="date" value={form.date_from} onChange={(event) => setForm({ ...form, date_from: event.target.value })} /><Input type="date" value={form.date_to} onChange={(event) => setForm({ ...form, date_to: event.target.value })} /><Button type="submit"><Search /> Filter</Button></form></Card><Card className="overflow-hidden">{logs.data.length === 0 ? <div className="p-8 text-center text-sm text-muted-foreground"><ActivityIcon className="mx-auto mb-3 size-6" />No matching activity.</div> : <div className="overflow-x-auto"><table className="w-full text-left text-sm"><thead className="bg-muted/30 text-xs text-muted-foreground"><tr><th className="px-5 py-2.5">Time</th><th className="px-4 py-2.5">Action</th><th className="px-4 py-2.5">Subject</th><th className="px-5 py-2.5">IP</th></tr></thead><tbody className="divide-y">{logs.data.map((log) => <tr key={log.id}><td className="whitespace-nowrap px-5 py-3 text-xs text-muted-foreground">{dateTime(log.created_at)}</td><td className="px-4 py-3 font-mono text-xs">{log.action}</td><td className="px-4 py-3 text-muted-foreground">{log.subject_type || '—'}{log.subject_id ? ` #${log.subject_id}` : ''}</td><td className="px-5 py-3 font-mono text-xs text-muted-foreground">{log.ip || '—'}</td></tr>)}</tbody></table></div>}<Pagination page={logs} /></Card></AppLayout>;
}
