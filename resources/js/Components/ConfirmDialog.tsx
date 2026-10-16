import { useState, useSyncExternalStore } from 'react';
import { AlertTriangle, Loader2, Trash2 } from 'lucide-react';
import { Button } from '@/Components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/Components/ui/dialog';

type Options = {
    title: string;
    description?: string;
    confirmLabel?: string;
    /** Runs on confirm; the dialog shows a spinner until it resolves. */
    onConfirm: () => void | Promise<unknown>;
};

let current: Options | null = null;
const listeners = new Set<() => void>();
const set = (o: Options | null) => {
    current = o;
    listeners.forEach((l) => l());
};

/** Open the shared confirm modal from anywhere: confirmDialog({ title, onConfirm }). */
export function confirmDialog(options: Options) {
    set(options);
}

/** Wraps an Inertia visit so the dialog stays open (with spinner) until the request finishes. */
export const visit = (fn: (done: { onFinish: () => void }) => void) => () => new Promise<void>((resolve) => fn({ onFinish: () => resolve() }));

/** Rendered once in AppLayout. */
export function ConfirmHost() {
    const opts = useSyncExternalStore(
        (cb) => {
            listeners.add(cb);
            return () => listeners.delete(cb);
        },
        () => current,
        () => null,
    );
    const [busy, setBusy] = useState(false);

    const confirm = async () => {
        if (!opts) return;
        setBusy(true);
        try {
            await opts.onConfirm();
        } finally {
            setBusy(false);
            set(null);
        }
    };

    return (
        <Dialog open={!!opts} onOpenChange={(o) => !o && !busy && set(null)}>
            <DialogContent className="sm:max-w-md">
                <DialogHeader className="flex-row items-start gap-4 space-y-0 text-left">
                    <div className="grid size-10 shrink-0 place-items-center rounded-xl bg-red-500/10 text-red-500 ring-1 ring-inset ring-red-500/20">
                        <AlertTriangle className="size-5" />
                    </div>
                    <div className="space-y-1.5">
                        <DialogTitle>{opts?.title}</DialogTitle>
                        {opts?.description && <DialogDescription>{opts.description}</DialogDescription>}
                    </div>
                </DialogHeader>
                <DialogFooter className="gap-2 sm:gap-0">
                    <Button variant="ghost" onClick={() => set(null)} disabled={busy}>
                        Cancel
                    </Button>
                    <Button variant="destructive" onClick={confirm} disabled={busy} autoFocus>
                        {busy ? <Loader2 className="animate-spin" /> : <Trash2 />}
                        {opts?.confirmLabel ?? 'Delete'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
