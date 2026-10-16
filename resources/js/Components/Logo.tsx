import { Zap } from 'lucide-react';

export default function Logo({ collapsed = false }: { collapsed?: boolean }) {
    return (
        <div className="flex items-center gap-2.5">
            <div className="grid size-8 shrink-0 place-items-center rounded-lg bg-brand text-white shadow-glow">
                <Zap className="size-4" fill="currentColor" />
            </div>
            {!collapsed && <span className="text-[15px] font-semibold tracking-tight">ColdForge</span>}
        </div>
    );
}
