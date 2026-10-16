import { useState } from 'react';
import { X } from 'lucide-react';
import { cn } from '@/lib/utils';

/** Simple chip input: type + Enter/comma to add, click x to remove, optional suggestions. */
export default function TagInput({
    value,
    onChange,
    suggestions = [],
    placeholder = 'Add tag…',
    id,
}: {
    value: string[];
    onChange: (tags: string[]) => void;
    suggestions?: string[];
    placeholder?: string;
    id?: string;
}) {
    const [text, setText] = useState('');
    const add = (t: string) => {
        const tag = t.trim();
        if (tag && !value.includes(tag)) onChange([...value, tag]);
        setText('');
    };
    const unused = suggestions.filter((s) => !value.includes(s));

    return (
        <div className="space-y-2">
            <div className="flex min-h-9 flex-wrap items-center gap-1.5 rounded-md border border-input px-2 py-1.5 shadow-sm focus-within:ring-2 focus-within:ring-ring">
                {value.map((t) => (
                    <span key={t} className="inline-flex items-center gap-1 rounded-md bg-primary/15 px-2 py-0.5 text-xs font-medium text-primary">
                        {t}
                        <button type="button" aria-label={`Remove ${t}`} onClick={() => onChange(value.filter((v) => v !== t))} className="rounded hover:text-foreground focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring">
                            <X className="size-3" />
                        </button>
                    </span>
                ))}
                <input
                    id={id}
                    value={text}
                    onChange={(e) => setText(e.target.value)}
                    onKeyDown={(e) => {
                        if (e.key === 'Enter' || e.key === ',') {
                            e.preventDefault();
                            add(text);
                        } else if (e.key === 'Backspace' && !text && value.length) {
                            onChange(value.slice(0, -1));
                        }
                    }}
                    onBlur={() => text && add(text)}
                    placeholder={value.length ? '' : placeholder}
                    className="min-w-[80px] flex-1 bg-transparent text-sm outline-none placeholder:text-muted-foreground"
                />
            </div>
            {unused.length > 0 && (
                <div className="flex flex-wrap gap-1.5">
                    {unused.slice(0, 20).map((s) => (
                        <button
                            key={s}
                            type="button"
                            onClick={() => add(s)}
                            className={cn('rounded-md border border-dashed px-2 py-0.5 text-xs text-muted-foreground transition-colors hover:border-primary hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring')}
                        >
                            + {s}
                        </button>
                    ))}
                </div>
            )}
        </div>
    );
}
