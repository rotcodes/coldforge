import { cn } from '@/lib/utils';

const styles: Record<string, string> = {
    // green
    active: 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 ring-emerald-500/20',
    sent: 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 ring-emerald-500/20',
    completed: 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 ring-emerald-500/20',
    opened: 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 ring-emerald-500/20',
    pass: 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 ring-emerald-500/20',
    // accent
    running: 'bg-indigo-500/10 text-indigo-600 dark:text-indigo-300 ring-indigo-500/25',
    sending: 'bg-indigo-500/10 text-indigo-600 dark:text-indigo-300 ring-indigo-500/25',
    clicked: 'bg-violet-500/10 text-violet-600 dark:text-violet-300 ring-violet-500/25',
    scheduled: 'bg-violet-500/10 text-violet-600 dark:text-violet-300 ring-violet-500/25',
    // amber
    paused: 'bg-amber-500/10 text-amber-600 dark:text-amber-400 ring-amber-500/20',
    unsubscribed: 'bg-amber-500/10 text-amber-600 dark:text-amber-400 ring-amber-500/20',
    warn: 'bg-amber-500/10 text-amber-600 dark:text-amber-400 ring-amber-500/20',
    // red
    bounced: 'bg-red-500/10 text-red-600 dark:text-red-400 ring-red-500/20',
    failed: 'bg-red-500/10 text-red-600 dark:text-red-400 ring-red-500/20',
    complained: 'bg-red-500/10 text-red-600 dark:text-red-400 ring-red-500/20',
    fail: 'bg-red-500/10 text-red-600 dark:text-red-400 ring-red-500/20',
};

export default function StatusBadge({ status, className }: { status: string; className?: string }) {
    const live = status === 'running' || status === 'sending';
    return (
        <span
            className={cn(
                'inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-xs font-medium capitalize ring-1 ring-inset',
                styles[status] ?? 'bg-muted text-muted-foreground ring-border',
                className,
            )}
        >
            {live && <span className="size-1.5 animate-pulse rounded-full bg-current" />}
            {status.replace('_', ' ')}
        </span>
    );
}
