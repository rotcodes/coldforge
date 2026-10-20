import { useEffect, useRef, useState } from 'react';
import axios from 'axios';
import { router } from '@inertiajs/react';
import { toast } from 'sonner';
import { CheckCircle2, FileUp, Loader2, Sparkles, Upload, Wand2 } from 'lucide-react';
import TagInput from '@/Components/TagInput';
import { Button } from '@/Components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { Label } from '@/Components/ui/label';
import { Progress } from '@/Components/ui/progress';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/table';
import { cn, fmt } from '@/lib/utils';

type Preview = {
    id: number;
    headers: string[];
    rows: string[][];
    total_rows: number;
    suggested: Record<string, string>;
    detected_format: string | null;
    empty_email_rows: number;
};
type ImportState = { status: string; total_rows: number; imported_count: number; failed_count: number };

const slug = (s: string) => s.toLowerCase().trim().replace(/[^a-z0-9]+/g, '_').replace(/^_|_$/g, '') || 'field';

/** All possible mapping targets, grouped for the Select. The backend accepts anything in Contact::FIELDS plus "tags" + "custom:*". */
const TARGETS: { group: string; options: { value: string; label: string }[] }[] = [
    { group: 'Essential', options: [
        { value: 'skip', label: 'Skip' },
        { value: 'email', label: 'Email' },
        { value: 'business', label: 'Business' },
        { value: 'tags', label: 'Tags' },
    ] },
    { group: 'Industry', options: [
        { value: 'industry', label: 'Industry (raw text)' },
        { value: 'industry_slug', label: 'Industry slug (preset)' },
    ] },
    { group: 'Contact', options: [
        { value: 'first_name', label: 'First name' },
        { value: 'last_name', label: 'Last name' },
        { value: 'company', label: 'Company (legacy)' },
        { value: 'country', label: 'Country' },
        { value: 'city_state', label: 'City / state' },
        { value: 'website', label: 'Website' },
        { value: 'decision_maker', label: 'Decision maker' },
        { value: 'dm_role', label: 'Decision maker role' },
        { value: 'direct_phone', label: 'Direct phone' },
        { value: 'main_phone', label: 'Main phone' },
        { value: 'company_linkedin', label: 'Company LinkedIn' },
        { value: 'dm_linkedin', label: 'Decision maker LinkedIn' },
    ] },
    { group: 'Lead intel', options: [
        { value: 'source_system', label: 'Source system (INTEL / CRM)' },
        { value: 'external_id', label: 'External lead ID' },
        { value: 'remote_status', label: 'Remote status' },
        { value: 'remote_reply_status', label: 'Reply status' },
        { value: 'lead_score', label: 'Lead score' },
        { value: 'confidence', label: 'Confidence' },
        { value: 'notion_url', label: 'Notion URL' },
        { value: 'other_emails', label: 'Other emails' },
    ] },
    { group: 'Personalization', options: [
        { value: 'personalized_opener', label: 'Personalized opener' },
        { value: 'pain_evidence', label: 'Pain evidence' },
        { value: 'recommended_offer', label: 'Recommended offer' },
        { value: 'custom_subject', label: 'Email subject (per-lead)' },
        { value: 'why_now_trigger', label: 'Why now trigger' },
    ] },
];

export default function ImportDialog({ open, onOpenChange, tags }: { open: boolean; onOpenChange: (o: boolean) => void; tags: string[] }) {
    const [step, setStep] = useState<'upload' | 'map' | 'progress'>('upload');
    const [preview, setPreview] = useState<Preview | null>(null);
    const [mapping, setMapping] = useState<Record<string, string>>({});
    const [importTags, setImportTags] = useState<string[]>([]);
    const [error, setError] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);
    const [drag, setDrag] = useState(false);
    const [state, setState] = useState<ImportState | null>(null);
    const inputRef = useRef<HTMLInputElement>(null);

    const reset = () => {
        setStep('upload');
        setPreview(null);
        setMapping({});
        setImportTags([]);
        setError(null);
        setState(null);
    };

    const upload = async (file?: File) => {
        if (!file) return;
        setBusy(true);
        setError(null);
        try {
            const body = new FormData();
            body.append('file', file);
            const { data } = await axios.post<Preview>('/csv-imports', body);
            setPreview(data);
            setMapping(data.suggested);
            setStep('map');
        } catch (e: any) {
            setError(e.response?.data?.errors?.file?.[0] ?? e.response?.data?.message ?? 'Upload failed.');
        } finally {
            setBusy(false);
        }
    };

    const start = async () => {
        if (!preview) return;
        setBusy(true);
        setError(null);
        try {
            const clean = Object.fromEntries(Object.entries(mapping).map(([h, f]) => [h, f === 'skip' ? '' : f]));
            const { data } = await axios.post<ImportState>(`/csv-imports/${preview.id}/start`, { mapping: clean, tags: importTags });
            setState(data);
            setStep('progress');
        } catch (e: any) {
            setError(e.response?.data?.message ?? 'Could not start import.');
        } finally {
            setBusy(false);
        }
    };

    useEffect(() => {
        if (step !== 'progress' || !preview) return;
        const t = setInterval(async () => {
            const { data } = await axios.get<ImportState>(`/api/csv-imports/${preview.id}`);
            setState(data);
            if (data.status === 'done' || data.status === 'failed') {
                clearInterval(t);
                if (data.status === 'done') toast.success(`Imported ${fmt(data.imported_count)} contacts.`);
                else toast.error('Import failed. Check the queue worker logs.');
                router.reload({ only: ['contacts', 'tags', 'industries'] });
            }
        }, 1000);
        return () => clearInterval(t);
    }, [step, preview]);

    const hasEmail = Object.values(mapping).includes('email');
    const done = state?.status === 'done' || state?.status === 'failed';
    const processed = (state?.imported_count ?? 0) + (state?.failed_count ?? 0);
    const willImport = preview ? preview.total_rows - preview.empty_email_rows : 0;

    return (
        <Dialog open={open} onOpenChange={(o) => { onOpenChange(o); if (!o) setTimeout(reset, 200); }}>
            <DialogContent className={cn('flex max-h-[90vh] w-[calc(100vw-2rem)] flex-col gap-0 overflow-hidden p-0', step === 'map' ? 'sm:max-w-3xl' : 'sm:max-w-lg')}>
                <DialogHeader className="shrink-0 border-b px-6 py-5">
                    <DialogTitle>Import contacts</DialogTitle>
                    <DialogDescription>
                        {step === 'upload' && 'Upload a CSV with a header row. Existing emails are updated, not duplicated.'}
                        {step === 'map' && `${fmt(preview!.total_rows)} rows found. Review the auto-mapping before importing.`}
                        {step === 'progress' && 'Importing in the background. You can close this dialog.'}
                    </DialogDescription>
                </DialogHeader>

                <div className="min-h-0 flex-1 space-y-5 overflow-y-auto px-6 py-5">

                {step === 'upload' && (
                    <button
                        type="button"
                        onClick={() => inputRef.current?.click()}
                        onDragOver={(e) => { e.preventDefault(); setDrag(true); }}
                        onDragLeave={() => setDrag(false)}
                        onDrop={(e) => { e.preventDefault(); setDrag(false); upload(e.dataTransfer.files[0]); }}
                        className={cn(
                            'flex w-full flex-col items-center justify-center gap-3 rounded-xl border-2 border-dashed px-6 py-12 text-center transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
                            drag ? 'border-primary bg-primary/5' : 'border-border hover:border-primary/50',
                        )}
                    >
                        {busy ? <Loader2 className="size-8 animate-spin text-primary" /> : <FileUp className="size-8 text-muted-foreground" />}
                        <div>
                            <div className="text-sm font-medium">Drop a CSV here or click to browse</div>
                            <div className="mt-1 text-xs text-muted-foreground">Max 20 MB. Must include an email column.</div>
                        </div>
                        <input ref={inputRef} type="file" accept=".csv,text/csv" className="hidden" onChange={(e) => upload(e.target.files?.[0])} />
                    </button>
                )}

                {step === 'map' && preview && (
                    <div className="space-y-5">
                        {preview.detected_format && (
                            <div className="flex items-center gap-2 rounded-lg border border-emerald-500/30 bg-emerald-500/10 px-3 py-2 text-sm text-emerald-700 dark:text-emerald-300">
                                <Wand2 className="size-4 shrink-0" />
                                <span>
                                    <strong>Detected format: {preview.detected_format}.</strong> Auto-mapped — review and adjust below.
                                </span>
                            </div>
                        )}
                        {preview.empty_email_rows > 0 && (
                            <div className="flex items-center gap-2 rounded-lg border border-amber-500/30 bg-amber-500/10 px-3 py-2 text-sm text-amber-700 dark:text-amber-300">
                                <Sparkles className="size-4 shrink-0" />
                                <span>
                                    {fmt(preview.empty_email_rows)} of {fmt(preview.total_rows)} rows have an empty email and will be skipped. {fmt(willImport)} will be imported.
                                </span>
                            </div>
                        )}

                        <div className="grid gap-2 sm:grid-cols-2">
                            {preview.headers.map((h) => {
                                const target = mapping[h];
                                const isCustom = target?.startsWith('custom:');
                                const customValue = isCustom ? target : `custom:${slug(h)}`;
                                const isMapped = target && target !== 'skip';
                                return (
                                    <div key={h} className={cn('flex flex-col gap-1.5 rounded-lg border p-2.5 transition-colors sm:flex-row sm:items-center sm:gap-3', isMapped ? 'border-primary/30 bg-primary/5' : 'border-border')}>
                                        <div className="min-w-0 flex-1 truncate font-mono text-xs" title={h}>{h}</div>
                                        <Select value={target ?? 'skip'} onValueChange={(v) => setMapping((m) => ({ ...m, [h]: v }))}>
                                            <SelectTrigger className="h-8 w-full sm:w-44">
                                                <SelectValue />
                                            </SelectTrigger>
                                            <SelectContent className="max-h-80">
                                                {TARGETS.map((g) => (
                                                    <div key={g.group}>
                                                        <div className="px-2 py-1 text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">{g.group}</div>
                                                        {g.options.map((o) => (
                                                            <SelectItem key={o.value} value={o.value}>{o.label}</SelectItem>
                                                        ))}
                                                    </div>
                                                ))}
                                                <div className="px-2 py-1 text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">Custom</div>
                                                <SelectItem value={customValue}>Custom: {slug(h)}</SelectItem>
                                            </SelectContent>
                                        </Select>
                                    </div>
                                );
                            })}
                        </div>

                        <div className="space-y-1.5">
                            <Label>Tag all imported contacts</Label>
                            <TagInput value={importTags} onChange={setImportTags} suggestions={tags} placeholder="e.g. notion-import, batch-01" />
                        </div>

                        <div className="w-full max-w-full overflow-x-auto rounded-lg border">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        {preview.headers.map((h) => (
                                            <TableHead key={h} className="whitespace-nowrap text-xs">{h}</TableHead>
                                        ))}
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {preview.rows.map((r, i) => (
                                        <TableRow key={i}>
                                            {preview.headers.map((_, j) => (
                                                <TableCell key={j} className="max-w-[180px] truncate whitespace-nowrap py-1.5 text-xs">{r[j]}</TableCell>
                                            ))}
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </div>
                        {!hasEmail && <p className="text-xs font-medium text-red-500">Map one column to Email.</p>}
                    </div>
                )}

                {step === 'progress' && state && (
                    <div className="space-y-4 py-2">
                        <div className="flex items-center gap-3">
                            {done ? <CheckCircle2 className="size-5 text-emerald-500" /> : <Loader2 className="size-5 animate-spin text-primary" />}
                            <span className="text-sm font-medium capitalize">{state.status}</span>
                            <span className="ml-auto font-mono text-xs text-muted-foreground">
                                {fmt(processed)} / {fmt(state.total_rows)}
                            </span>
                        </div>
                        <Progress value={state.total_rows ? (processed / state.total_rows) * 100 : 0} />
                        <div className="flex gap-6 font-mono text-xs text-muted-foreground">
                            <span><span className="text-emerald-500">{fmt(state.imported_count)}</span> imported</span>
                            <span><span className="text-red-500">{fmt(state.failed_count)}</span> skipped</span>
                        </div>
                    </div>
                )}

                {error && <p className="text-sm font-medium text-red-500">{error}</p>}

                </div>

                {step === 'map' && (
                    <DialogFooter className="shrink-0 border-t bg-background px-6 py-4">
                        <Button variant="ghost" onClick={reset}>Back</Button>
                        <Button onClick={start} disabled={!hasEmail || busy}>
                            {busy ? <Loader2 className="animate-spin" /> : <Upload />} Import {fmt(willImport)} rows
                        </Button>
                    </DialogFooter>
                )}
                {step === 'progress' && done && (
                    <DialogFooter className="shrink-0 border-t bg-background px-6 py-4">
                        <Button onClick={() => onOpenChange(false)}>Done</Button>
                    </DialogFooter>
                )}
            </DialogContent>
        </Dialog>
    );
}
