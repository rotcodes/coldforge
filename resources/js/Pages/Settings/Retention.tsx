import { FormEvent } from 'react';
import { useForm } from '@inertiajs/react';
import { Database, Loader2 } from 'lucide-react';
import SettingsLayout from '@/Layouts/SettingsLayout';
import Field from '@/Components/Field';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';

type Retention = { message_bodies_days: number; activity_days: number; sync_logs_days: number; ai_history_days: number; downloaded_attachments_days: number };
const labels: Record<keyof Retention, { label: string; hint: string }> = {
    message_bodies_days: { label: 'Synchronized message bodies', hint: 'Headers and message metadata remain after bodies are removed.' },
    activity_days: { label: 'Activity logs', hint: 'Authenticated mutation audit history.' },
    sync_logs_days: { label: 'Mailbox synchronization logs', hint: 'Folder checkpoints are retained separately.' },
    ai_history_days: { label: 'AI requests and conversations', hint: 'AI classifications attached to messages are retained.' },
    downloaded_attachments_days: { label: 'Cached attachment files', hint: 'Remote metadata remains and clean files can be downloaded again.' },
};

export default function RetentionSettings({ retention }: { retention: Retention }) {
    const form = useForm(retention);
    const submit = (event: FormEvent) => { event.preventDefault(); form.put('/settings/retention', { preserveScroll: true }); };
    return <SettingsLayout title="Retention"><Card className="p-5"><div className="mb-5 flex items-center gap-2"><Database className="size-4 text-muted-foreground" /><div><h2 className="text-sm font-semibold">Data-retention policy</h2><p className="text-xs text-muted-foreground">Applied daily at 02:30. Use zero to retain a category indefinitely.</p></div></div><form onSubmit={submit} className="space-y-4">{(Object.keys(labels) as (keyof Retention)[]).map((key) => <Field key={key} label={labels[key].label} hint={labels[key].hint} error={form.errors[key]}><div className="flex items-center gap-2"><Input type="number" min={0} max={3650} value={form.data[key]} onChange={(event) => form.setData(key, Number(event.target.value))} className="max-w-40" /><span className="text-sm text-muted-foreground">days</span></div></Field>)}<div className="flex justify-end border-t pt-4"><Button type="submit" disabled={form.processing || !form.isDirty}>{form.processing && <Loader2 className="animate-spin" />} Save policy</Button></div></form></Card></SettingsLayout>;
}
