import { FormEvent } from 'react';
import { router, useForm } from '@inertiajs/react';
import { CalendarClock, ListRestart, Pause, Play, Plus, Trash2 } from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import Field from '@/Components/Field';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { Switch } from '@/Components/ui/switch';

type Template = { id: number; name: string; subject: string };
type Step = { id: number; position: number; delay_days: number; template: Template };
type Sequence = { id: number; name: string; status: string; business_days_only: boolean; window_start: string | null; window_end: string | null; timezone: string; steps: Step[]; enrollments_count: number; active_enrollments_count: number };

export default function SequenceIndex({ sequences, templates }: { sequences: Sequence[]; templates: Template[] }) {
    const form = useForm({
        name: '', business_days_only: true, window_start: '09:00', window_end: '17:00', timezone: 'Asia/Karachi',
        steps: [{ template_id: templates[0]?.id ?? 0, delay_days: 2 }],
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post('/sequences', { preserveScroll: true, onSuccess: () => form.reset() });
    };
    const addStep = () => form.setData('steps', [...form.data.steps, { template_id: templates[0]?.id ?? 0, delay_days: 3 }]);
    const removeStep = (index: number) => form.setData('steps', form.data.steps.filter((_, stepIndex) => stepIndex !== index));
    const updateStep = (index: number, patch: Partial<(typeof form.data.steps)[number]>) => form.setData('steps', form.data.steps.map((step, stepIndex) => stepIndex === index ? { ...step, ...patch } : step));
    const setStatus = (sequence: Sequence, status: 'active' | 'paused' | 'cancelled') => router.post(`/sequences/${sequence.id}/status`, { status }, { preserveScroll: true });

    return (
        <AppLayout title="Sequences">
            <div className="mb-6">
                <h1 className="text-xl font-semibold tracking-tight">Follow-up sequences</h1>
                <p className="mt-1 text-sm text-muted-foreground">Reusable follow-ups that stop immediately on replies, suppression, ineligibility, pause, or cancellation.</p>
            </div>

            <div className="grid gap-6 xl:grid-cols-[minmax(0,1fr)_420px]">
                <div className="space-y-3">
                    {sequences.length === 0 && <Card className="p-8 text-center text-sm text-muted-foreground">No sequences yet. Build the first one from the panel.</Card>}
                    {sequences.map((sequence) => (
                        <Card key={sequence.id} className="p-5">
                            <div className="flex items-start justify-between gap-4">
                                <div>
                                    <div className="flex items-center gap-2"><ListRestart className="size-4 text-muted-foreground" /><h2 className="font-semibold">{sequence.name}</h2><span className="rounded border px-1.5 py-0.5 text-[10px] uppercase text-muted-foreground">{sequence.status}</span></div>
                                    <p className="mt-1 text-xs text-muted-foreground">{sequence.steps.length} steps · {sequence.active_enrollments_count} active · {sequence.enrollments_count} total</p>
                                </div>
                                <div className="flex gap-1">
                                    {sequence.status !== 'active' && <Button size="sm" variant="outline" onClick={() => setStatus(sequence, 'active')}><Play /> Activate</Button>}
                                    {sequence.status === 'active' && <Button size="sm" variant="outline" onClick={() => setStatus(sequence, 'paused')}><Pause /> Pause</Button>}
                                    {!['cancelled'].includes(sequence.status) && <Button size="icon" variant="ghost" title="Cancel sequence" onClick={() => setStatus(sequence, 'cancelled')}><Trash2 /></Button>}
                                </div>
                            </div>
                            <ol className="mt-4 space-y-2 border-l pl-4">
                                {sequence.steps.map((step) => <li key={step.id} className="text-sm"><span className="font-medium">Day +{step.delay_days}</span> · {step.template.name}<div className="text-xs text-muted-foreground">{step.template.subject}</div></li>)}
                            </ol>
                        </Card>
                    ))}
                </div>

                <Card className="h-fit p-5">
                    <div className="mb-4 flex items-center gap-2"><CalendarClock className="size-4 text-muted-foreground" /><h2 className="font-semibold">New sequence</h2></div>
                    <form onSubmit={submit} className="space-y-4">
                        <Field label="Name" error={form.errors.name}><Input value={form.data.name} onChange={(event) => form.setData('name', event.target.value)} placeholder="Polite follow-up" /></Field>
                        <div className="grid grid-cols-2 gap-3">
                            <Field label="Send from"><Input type="time" value={form.data.window_start} onChange={(event) => form.setData('window_start', event.target.value)} /></Field>
                            <Field label="Send until"><Input type="time" value={form.data.window_end} onChange={(event) => form.setData('window_end', event.target.value)} /></Field>
                        </div>
                        <label className="flex items-center justify-between rounded-md border p-3"><span className="text-sm">Business days only</span><Switch checked={form.data.business_days_only} onCheckedChange={(value) => form.setData('business_days_only', value)} /></label>
                        <div className="space-y-3">
                            {form.data.steps.map((step, index) => (
                                <div key={index} className="rounded-md border p-3">
                                    <div className="mb-2 flex items-center justify-between text-xs font-medium"><span>Step {index + 1}</span>{form.data.steps.length > 1 && <Button type="button" size="icon" variant="ghost" onClick={() => removeStep(index)}><Trash2 /></Button>}</div>
                                    <div className="space-y-2">
                                        <Select value={String(step.template_id)} onValueChange={(value) => updateStep(index, { template_id: Number(value) })}><SelectTrigger><SelectValue placeholder="Choose template" /></SelectTrigger><SelectContent>{templates.map((template) => <SelectItem key={template.id} value={String(template.id)}>{template.name}</SelectItem>)}</SelectContent></Select>
                                        <Field label="Delay after previous email (days)"><Input type="number" min={1} max={365} value={step.delay_days} onChange={(event) => updateStep(index, { delay_days: Number(event.target.value) })} /></Field>
                                    </div>
                                </div>
                            ))}
                        </div>
                        <Button type="button" variant="outline" className="w-full" onClick={addStep} disabled={form.data.steps.length >= 10 || templates.length === 0}><Plus /> Add step</Button>
                        <Button type="submit" className="w-full" disabled={form.processing || templates.length === 0}>Create draft sequence</Button>
                    </form>
                </Card>
            </div>
        </AppLayout>
    );
}
