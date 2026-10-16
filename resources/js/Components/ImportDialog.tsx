import { useEffect, useRef, useState } from 'react';
import axios from 'axios';
import { router } from '@inertiajs/react';
import { toast } from 'sonner';
import { CheckCircle2, FileUp, Loader2, Upload } from 'lucide-react';
import TagInput from '@/Components/TagInput';
import { Button } from '@/Components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { Label } from '@/Components/ui/label';
import { Progress } from '@/Components/ui/progress';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/table';
import { cn, fmt } from '@/lib/utils';

type Preview = { id: number; headers: string[]; rows: string[][]; total_rows: number };
type ImportState = { status: string; total_rows: number; imported_count: number; failed_count: number };

const slug = (s: string) => s.toLowerCase().trim().replace(/[^a-z0-9]+/g, '_').replace(/^_|_$/g, '') || 'field';

const FIELDS = [
    { value: 'skip', label: 'Skip' },
    { value: 'email', label: 'Email' },
    { value: 'first_name', label: 'First name' },
    { value: 'last_name', label: 'Last name' },
    { value: 'company', label: 'Company' },
    { value: 'country', label: 'Country' },
    { value: 'tags', label: 'Tags' },
];

function guess(header: string): string {
    const h = slug(header);
    if (/^e_?mail/.test(h)) return 'email';
    if (/^(first|first_?name|given_name|fname)$/.test(h)) return 'first_name';
    if (/^(last|last_?name|surname|family_name|lname)$/.test(h)) return 'last_name';
    if (/^(company|organization|organisation|company_name)$/.test(h)) return 'company';
    if (/^country/.test(h)) return 'country';
    if (/^tags?$/.test(h)) return 'tags';
    return `custom:${h}`;
}

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
            setMapping(Object.fromEntries(data.headers.map((h) => [h, guess(h)])));
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

    // Poll progress every second until done.
    useEffect(() => {
        if (step !== 'progress' || !preview) return;
        const t = setInterval(async () => {
            const { data } = await axios.get<ImportState>(`/api/csv-imports/${preview.id}`);
            setState(data);
            if (data.status === 'done' || data.status === 'failed') {
                clearInterval(t);
                if (data.status === 'done') toast.success(`Imported ${fmt(data.imported_count)} contacts.`);
                else toast.error('Import failed. Check the queue worker logs.');
                router.reload({ only: ['contacts', 'tags'] });
            }
        }, 1000);
        return () => clearInterval(t);
    }, [step, preview]);

    const hasEmail = Object.values(mapping).includes('email');
    const done = state?.status === 'done' || state?.status === 'failed';
    const processed = (state?.imported_count ?? 0) + (state?.failed_count ?? 0);

    return (
        <Dialog
            open={open}
            onOpenChange={(o) => {
                onOpenChange(o);
                if (!o) setTimeout(reset, 200);
            }}
        >
            <DialogContent className={cn('max-h-[90vh] overflow-y-auto', step === 'map' ? 'sm:max-w-3xl' : 'sm:max-w-lg')}>
                <DialogHeader>
                    <DialogTitle>Import contacts</DialogTitle>
                    <DialogDescription>
                        {step === 'upload' && 'Upload a CSV with a header row. Existing emails are updated, not duplicated.'}
                        {step === 'map' && `${fmt(preview!.total_rows)} rows found. Map your columns to contact fields.`}
                        {step === 'progress' && 'Importing in the background. You can close this dialog.'}
                    </DialogDescription>
                </DialogHeader>

                {step === 'upload' && (
                    <button
                        type="button"
                        onClick={() => inputRef.current?.click()}
                        onDragOver={(e) => {
                            e.preventDefault();
                            setDrag(true);
                        }}
                        onDragLeave={() => setDrag(false)}
                        onDrop={(e) => {
                            e.preventDefault();
                            setDrag(false);
                            upload(e.dataTransfer.files[0]);
                        }}
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
                        <div className="grid gap-3 sm:grid-cols-2">
                            {preview.headers.map((h) => {
                                const custom = `custom:${slug(h)}`;
                                return (
                                    <div key={h} className="flex items-center gap-3 rounded-lg border p-2.5">
                                        <div className="min-w-0 flex-1 truncate font-mono text-xs" title={h}>
                                            {h}
                                        </div>
                                        <Select value={mapping[h]} onValueChange={(v) => setMapping((m) => ({ ...m, [h]: v }))}>
                                            <SelectTrigger className="h-8 w-40">
                                                <SelectValue />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {FIELDS.map((f) => (
                                                    <SelectItem key={f.value} value={f.value}>
                                                        {f.label}
                                                    </SelectItem>
                                                ))}
                                                <SelectItem value={custom}>Custom: {slug(h)}</SelectItem>
                                            </SelectContent>
                                        </Select>
                                    </div>
                                );
                            })}
                        </div>

                        <div className="space-y-1.5">
                            <Label>Tag all imported contacts</Label>
                            <TagInput value={importTags} onChange={setImportTags} suggestions={tags} placeholder="e.g. saas-founders" />
                        </div>

                        <div className="overflow-x-auto rounded-lg border">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        {preview.headers.map((h) => (
                                            <TableHead key={h} className="whitespace-nowrap text-xs">
                                                {h}
                                            </TableHead>
                                        ))}
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {preview.rows.map((r, i) => (
                                        <TableRow key={i}>
                                            {preview.headers.map((_, j) => (
                                                <TableCell key={j} className="max-w-[180px] truncate whitespace-nowrap py-1.5 text-xs">
                                                    {r[j]}
                                                </TableCell>
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
                            <span>
                                <span className="text-emerald-500">{fmt(state.imported_count)}</span> imported
                            </span>
                            <span>
                                <span className="text-red-500">{fmt(state.failed_count)}</span> invalid
                            </span>
                        </div>
                    </div>
                )}

                {error && <p className="text-sm font-medium text-red-500">{error}</p>}

                {step === 'map' && (
                    <DialogFooter>
                        <Button variant="ghost" onClick={reset}>
                            Back
                        </Button>
                        <Button onClick={start} disabled={!hasEmail || busy}>
                            {busy ? <Loader2 className="animate-spin" /> : <Upload />} Import {fmt(preview!.total_rows)} rows
                        </Button>
                    </DialogFooter>
                )}
                {step === 'progress' && done && (
                    <DialogFooter>
                        <Button onClick={() => onOpenChange(false)}>Done</Button>
                    </DialogFooter>
                )}
            </DialogContent>
        </Dialog>
    );
}
