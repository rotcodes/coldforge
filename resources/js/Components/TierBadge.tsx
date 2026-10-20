import { AlertCircle, Mail, Sparkles, Stars, Target } from 'lucide-react';
import { cn } from '@/lib/utils';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/Components/ui/tooltip';

/**
 * Visual indicator of a contact's personalization readiness.
 * Tier 0 = unsendable (no email), 1 = email only, 2 = +business,
 * 3 = +industry (full industry-tailored template works),
 * 4 = +rich fields (pain evidence / offer / opener available for advanced templates).
 */
const TIERS = {
    0: { label: 'T0', text: 'No email', icon: AlertCircle, cn: 'bg-red-500/10 text-red-600 dark:text-red-400 ring-red-500/20' },
    1: { label: 'T1', text: 'Email only — no personalization', icon: Mail, cn: 'bg-neutral-500/10 text-neutral-500 ring-neutral-500/20' },
    2: { label: 'T2', text: 'Email + business — basic greeting', icon: Target, cn: 'bg-amber-500/10 text-amber-600 dark:text-amber-400 ring-amber-500/20' },
    3: { label: 'T3', text: 'Industry mapped — tailored template works', icon: Sparkles, cn: 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 ring-emerald-500/20' },
    4: { label: 'T4', text: 'Rich data — advanced personalization available', icon: Stars, cn: 'bg-brand/10 text-primary ring-primary/20' },
} as const;

export default function TierBadge({ tier, compact = false }: { tier: number; compact?: boolean }) {
    const t = TIERS[(tier as keyof typeof TIERS) ?? 1];
    const Icon = t.icon;
    const chip = (
        <span className={cn('inline-flex items-center gap-1 rounded-md px-1.5 py-0.5 text-[11px] font-medium ring-1', t.cn)}>
            <Icon className="size-3" />
            {!compact && t.label}
        </span>
    );
    return (
        <Tooltip>
            <TooltipTrigger asChild>{chip}</TooltipTrigger>
            <TooltipContent>{t.text}</TooltipContent>
        </Tooltip>
    );
}

export function TierLegend() {
    return (
        <div className="flex flex-wrap gap-x-3 gap-y-1 text-xs text-muted-foreground">
            {Object.entries(TIERS).map(([n, t]) => (
                <span key={n} className="inline-flex items-center gap-1">
                    <TierBadge tier={Number(n)} compact />
                    <span>{t.text}</span>
                </span>
            ))}
        </div>
    );
}
