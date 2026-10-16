import type { LucideIcon } from 'lucide-react';

export default function EmptyState({ icon: Icon, title, text, action }: { icon: LucideIcon; title: string; text: string; action?: React.ReactNode }) {
    return (
        <div className="flex flex-col items-center justify-center px-6 py-16 text-center">
            <div className="mb-4 grid size-12 place-items-center rounded-xl bg-brand text-white shadow-glow">
                <Icon className="size-5" />
            </div>
            <h3 className="font-medium">{title}</h3>
            <p className="mt-1 max-w-sm text-sm text-muted-foreground">{text}</p>
            {action && <div className="mt-5">{action}</div>}
        </div>
    );
}
