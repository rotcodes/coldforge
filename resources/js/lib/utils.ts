import { clsx, type ClassValue } from 'clsx';
import { twMerge } from 'tailwind-merge';

export function cn(...inputs: ClassValue[]) {
    return twMerge(clsx(inputs));
}

export const fmt = (n: number) => new Intl.NumberFormat().format(n);

export const pct = (part: number, total: number) => (total > 0 ? Math.round((part / total) * 1000) / 10 : 0);

export const dateTime = (s?: string | null) =>
    s ? new Date(s).toLocaleString(undefined, { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' }) : '—';

export const date = (s?: string | null) =>
    s ? new Date(s).toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' }) : '—';

/** Same {{var}} replacement the server does, for live previews. */
export const fillVars = (content: string, vars: Record<string, string>) =>
    content.replace(/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/g, (_, k) => vars[k] ?? `{{${k}}}`);
