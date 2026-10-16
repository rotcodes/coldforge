import { Label } from '@/Components/ui/label';
import { cn } from '@/lib/utils';

/** Label + control + inline error. */
export default function Field({
    label,
    error,
    hint,
    htmlFor,
    className,
    children,
}: {
    label: string;
    error?: string;
    hint?: string;
    htmlFor?: string;
    className?: string;
    children: React.ReactNode;
}) {
    return (
        <div className={cn('space-y-1.5', className)}>
            <Label htmlFor={htmlFor}>{label}</Label>
            {children}
            {error ? <p className="text-xs font-medium text-red-500">{error}</p> : hint ? <p className="text-xs text-muted-foreground">{hint}</p> : null}
        </div>
    );
}
