import { motion } from 'framer-motion';
import { cn } from '@/lib/utils';

/** Two-bar hamburger (long + short) that morphs into an X. */
export default function MenuToggle({ open, onClick, className }: { open: boolean; onClick: () => void; className?: string }) {
    const t = { type: 'spring' as const, stiffness: 400, damping: 28 };
    return (
        <button
            type="button"
            onClick={onClick}
            aria-label={open ? 'Close menu' : 'Open menu'}
            aria-expanded={open}
            className={cn(
                'group glass relative grid size-9 shrink-0 place-items-center rounded-xl transition-colors hover:border-primary/40 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
                className,
            )}
        >
            <span className="relative flex h-2.5 w-[18px] flex-col justify-between">
                <motion.span
                    className="block h-0.5 origin-center self-start rounded-full bg-foreground transition-colors group-hover:bg-primary"
                    initial={false}
                    animate={open ? { rotate: 45, y: 4, width: 18 } : { rotate: 0, y: 0, width: 18 }}
                    transition={t}
                />
                <motion.span
                    className="block h-0.5 origin-center self-end rounded-full bg-foreground transition-colors group-hover:bg-primary"
                    initial={false}
                    animate={open ? { rotate: -45, y: -4, width: 18 } : { rotate: 0, y: 0, width: 11 }}
                    transition={t}
                />
            </span>
        </button>
    );
}
