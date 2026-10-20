import { FormEvent, useEffect, useState } from 'react';
import { router, useForm } from '@inertiajs/react';
import { Building2, HelpCircle, Loader2, MoreHorizontal, Pencil, Plus, Sparkles, Trash2, Wand2, X } from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import PageHeader from '@/Components/PageHeader';
import EmptyState from '@/Components/EmptyState';
import Field from '@/Components/Field';
import { confirmDialog, visit } from '@/Components/ConfirmDialog';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/Components/ui/dropdown-menu';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { Textarea } from '@/Components/ui/textarea';
import { Switch } from '@/Components/ui/switch';
import { cn, fmt } from '@/lib/utils';
import type { Industry } from '@/types';

type IndustryWithCount = Industry & { contact_count: number };

interface Props {
    industries: IndustryWithCount[];
    unmappedCount: number;
    unmappedSamples: { industry: string; count: number }[];
}

const blank = { slug: '', label: '', workflows: '', tracking: '', aliases: [] as string[], is_active: true };

export default function IndustriesIndex({ industries, unmappedCount, unmappedSamples }: Props) {
    const [editing, setEditing] = useState<Industry | null>(null);
    const [open, setOpen] = useState(false);
    const [unmappedOpen, setUnmappedOpen] = useState(false);

    const openForm = (i: Industry | null) => {
        setEditing(i);
        setOpen(true);
    };

    const remove = (i: Industry) => {
        confirmDialog({
            title: `Delete "${i.label}"?`,
            description: 'The slug will be unmapped from any contacts using it. This cannot be undone.',
            onConfirm: visit((o) => router.delete(`/industries/${i.id}`, { preserveScroll: true, ...o })),
        });
    };

    const rematch = () => {
        router.post('/industries/rematch', {}, { preserveScroll: true });
    };

    return (
        <AppLayout title="Industries">
            <PageHeader
                title="Industries"
                description="Industry presets drive the smart variables in your templates. Edit the phrasing and aliases here."
                actions={
                    <>
                        <Button variant="outline" onClick={rematch}>
                            <Wand2 /> Re-match unmapped
                        </Button>
                        <Button onClick={() => openForm(null)}>
                            <Plus /> New industry
                        </Button>
                    </>
                }
            />

            {unmappedCount > 0 && (
                <button
                    type="button"
                    onClick={() => setUnmappedOpen(true)}
                    className="mb-4 flex w-full items-center gap-3 rounded-xl border border-amber-500/30 bg-amber-500/10 px-4 py-3 text-left text-sm transition-colors hover:bg-amber-500/15 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                >
                    <HelpCircle className="size-5 shrink-0 text-amber-600 dark:text-amber-400" />
                    <div className="flex-1">
                        <div className="font-medium text-amber-700 dark:text-amber-300">
                            {fmt(unmappedCount)} contacts have an industry text that didn't match any preset
                        </div>
                        <div className="text-xs text-amber-700/80 dark:text-amber-300/80">Click to review and bulk-assign them.</div>
                    </div>
                </button>
            )}

            {industries.length === 0 ? (
                <EmptyState icon={Building2} title="No industries yet" text="Seed the defaults with `php artisan db:seed`, or add one manually." action={<Button onClick={() => openForm(null)}><Plus /> New industry</Button>} />
            ) : (
                <div className="grid gap-3 lg:grid-cols-2">
                    {industries.map((i) => (
                        <Card key={i.id} className={cn('relative overflow-hidden p-4 transition-opacity', !i.is_active && 'opacity-60')}>
                            <div className="flex items-start gap-3">
                                <span className="grid size-9 shrink-0 place-items-center rounded-lg bg-primary/10 text-primary">
                                    <Sparkles className="size-4" />
                                </span>
                                <div className="min-w-0 flex-1">
                                    <div className="flex items-center gap-2">
                                        <code className="rounded bg-muted px-1.5 py-0.5 font-mono text-[11px] text-muted-foreground">{i.slug}</code>
                                        <span className={cn('rounded-md px-1.5 py-0.5 text-[10px] font-medium', i.is_active ? 'bg-emerald-500/10 text-emerald-600' : 'bg-neutral-500/10 text-neutral-500')}>
                                            {i.is_active ? 'Active' : 'Draft'}
                                        </span>
                                        <span className="ml-auto font-mono text-xs text-muted-foreground">{fmt(i.contact_count)} contacts</span>
                                    </div>
                                    <h3 className="mt-1 font-medium">{i.label}</h3>
                                    <dl className="mt-2 space-y-1.5 text-sm">
                                        <div>
                                            <dt className="text-[10px] uppercase tracking-wider text-muted-foreground">Workflows</dt>
                                            <dd className="text-muted-foreground">{i.workflows}</dd>
                                        </div>
                                        <div>
                                            <dt className="text-[10px] uppercase tracking-wider text-muted-foreground">Tracking</dt>
                                            <dd className="text-muted-foreground">{i.tracking}</dd>
                                        </div>
                                    </dl>
                                    <div className="mt-2 flex flex-wrap gap-1">
                                        {(i.aliases ?? []).map((a) => (
                                            <span key={a} className="rounded-md bg-muted px-1.5 py-0.5 text-[11px] text-muted-foreground">{a}</span>
                                        ))}
                                    </div>
                                </div>
                                <DropdownMenu>
                                    <DropdownMenuTrigger asChild>
                                        <Button variant="ghost" size="icon" className="size-8" aria-label="Actions">
                                            <MoreHorizontal />
                                        </Button>
                                    </DropdownMenuTrigger>
                                    <DropdownMenuContent align="end">
                                        <DropdownMenuItem onSelect={() => openForm(i)}>
                                            <Pencil /> Edit
                                        </DropdownMenuItem>
                                        <DropdownMenuItem onSelect={() => remove(i)} className="text-red-500 focus:text-red-500">
                                            <Trash2 /> Delete
                                        </DropdownMenuItem>
                                    </DropdownMenuContent>
                                </DropdownMenu>
                            </div>
                        </Card>
                    ))}
                </div>
            )}

            <IndustryDialog open={open} onOpenChange={setOpen} industry={editing} />
            <UnmappedDialog open={unmappedOpen} onOpenChange={setUnmappedOpen} samples={unmappedSamples} industries={industries} />
        </AppLayout>
    );
}

function IndustryDialog({ open, onOpenChange, industry }: { open: boolean; onOpenChange: (o: boolean) => void; industry: Industry | null }) {
    const form = useForm(blank);
    const [aliasInput, setAliasInput] = useState('');

    useEffect(() => {
        if (!open) return;
        form.clearErrors();
        form.setData(industry ? {
            slug: industry.slug, label: industry.label, workflows: industry.workflows, tracking: industry.tracking,
            aliases: industry.aliases ?? [], is_active: industry.is_active,
        } : blank);
        setAliasInput('');
    }, [open, industry]);

    const addAlias = () => {
        const v = aliasInput.trim().toLowerCase();
        if (!v || form.data.aliases.includes(v)) return;
        form.setData('aliases', [...form.data.aliases, v]);
        setAliasInput('');
    };

    const submit = (e: FormEvent) => {
        e.preventDefault();
        const opts = { preserveScroll: true, onSuccess: () => onOpenChange(false) };
        industry ? form.put(`/industries/${industry.id}`, opts) : form.post('/industries', opts);
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-xl">
                <DialogHeader>
                    <DialogTitle>{industry ? 'Edit industry' : 'New industry'}</DialogTitle>
                </DialogHeader>
                <form onSubmit={submit} className="space-y-4" noValidate>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field label="Slug" htmlFor="slug" error={form.errors.slug} hint="Lowercase, underscores. Leave blank to derive from label.">
                            <Input id="slug" value={form.data.slug} onChange={(e) => form.setData('slug', e.target.value)} placeholder="auto" disabled={!!industry} className="font-mono text-sm" />
                        </Field>
                        <Field label="Label" htmlFor="label" error={form.errors.label} hint="Used in {{industry_label}}">
                            <Input id="label" value={form.data.label} onChange={(e) => form.setData('label', e.target.value)} placeholder="equipment dealership" />
                        </Field>
                    </div>
                    <Field label="Workflows" htmlFor="workflows" error={form.errors.workflows} hint="Used in {{workflow_examples}}">
                        <Textarea id="workflows" rows={2} value={form.data.workflows} onChange={(e) => form.setData('workflows', e.target.value)} placeholder="orders, inventory across warehouses, dispatch, and client follow-ups" />
                    </Field>
                    <Field label="Tracking" htmlFor="tracking" error={form.errors.tracking} hint="Used in {{tracking_examples}}">
                        <Textarea id="tracking" rows={2} value={form.data.tracking} onChange={(e) => form.setData('tracking', e.target.value)} placeholder="orders, stock movement, dispatch status, and client accounts" />
                    </Field>
                    <Field label="Aliases" hint="CSV industry text that should match this slug. Case-insensitive.">
                        <div className="flex flex-wrap items-center gap-1.5 rounded-md border bg-background p-2 focus-within:ring-2 focus-within:ring-ring">
                            {form.data.aliases.map((a) => (
                                <span key={a} className="inline-flex items-center gap-1 rounded-md bg-muted px-1.5 py-0.5 text-xs">
                                    {a}
                                    <button type="button" onClick={() => form.setData('aliases', form.data.aliases.filter((x) => x !== a))} className="hover:text-red-500" aria-label={`Remove ${a}`}>
                                        <X className="size-3" />
                                    </button>
                                </span>
                            ))}
                            <input
                                value={aliasInput}
                                onChange={(e) => setAliasInput(e.target.value)}
                                onKeyDown={(e) => {
                                    if (e.key === 'Enter' || e.key === ',') { e.preventDefault(); addAlias(); }
                                }}
                                onBlur={addAlias}
                                placeholder="add alias…"
                                className="min-w-32 flex-1 bg-transparent text-sm outline-none"
                            />
                        </div>
                    </Field>
                    <div className="flex items-center justify-between rounded-md border p-2.5">
                        <div>
                            <Label htmlFor="is_active" className="text-sm">Active</Label>
                            <p className="text-xs text-muted-foreground">Inactive industries are hidden from import matching and template previews.</p>
                        </div>
                        <Switch id="is_active" checked={form.data.is_active} onCheckedChange={(v) => form.setData('is_active', v)} />
                    </div>
                    <DialogFooter>
                        <Button type="button" variant="ghost" onClick={() => onOpenChange(false)}>Cancel</Button>
                        <Button type="submit" disabled={form.processing}>
                            {form.processing && <Loader2 className="animate-spin" />}
                            {industry ? 'Save' : 'Create industry'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function UnmappedDialog({ open, onOpenChange, samples, industries }: { open: boolean; onOpenChange: (o: boolean) => void; samples: Props['unmappedSamples']; industries: Industry[] }) {
    const [picks, setPicks] = useState<Record<string, string>>({});
    const [busy, setBusy] = useState<string | null>(null);

    const assign = (raw: string) => {
        const slug = picks[raw];
        if (!slug) return;
        setBusy(raw);
        router.post('/industries/assign-unmapped', { raw_industry: raw, slug }, {
            preserveScroll: true,
            onFinish: () => setBusy(null),
        });
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-2xl">
                <DialogHeader>
                    <DialogTitle>Unmapped industry text</DialogTitle>
                </DialogHeader>
                <p className="text-sm text-muted-foreground">
                    Top 10 industry values from your imports that didn't match any preset. Assign them in bulk, or add a new industry preset.
                </p>
                <div className="space-y-2">
                    {samples.map((s) => (
                        <div key={s.industry} className="flex items-center gap-3 rounded-lg border p-2.5">
                            <div className="min-w-0 flex-1">
                                <div className="truncate text-sm font-medium" title={s.industry}>{s.industry}</div>
                                <div className="text-xs text-muted-foreground">{s.count} contacts</div>
                            </div>
                            <Select value={picks[s.industry] ?? ''} onValueChange={(v) => setPicks({ ...picks, [s.industry]: v })}>
                                <SelectTrigger className="h-8 w-44">
                                    <SelectValue placeholder="Pick a preset" />
                                </SelectTrigger>
                                <SelectContent>
                                    {industries.map((i) => (
                                        <SelectItem key={i.slug} value={i.slug}>{i.label}</SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <Button size="sm" disabled={!picks[s.industry] || busy === s.industry} onClick={() => assign(s.industry)}>
                                {busy === s.industry ? <Loader2 className="animate-spin" /> : 'Assign'}
                            </Button>
                        </div>
                    ))}
                </div>
            </DialogContent>
        </Dialog>
    );
}
