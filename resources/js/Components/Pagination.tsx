import { Link } from '@inertiajs/react';
import { ChevronLeft, ChevronRight } from 'lucide-react';
import { Button } from '@/Components/ui/button';
import { fmt } from '@/lib/utils';
import type { Paginated } from '@/types';

export default function Pagination({ page }: { page: Paginated<unknown> }) {
    if (page.total === 0) return null;
    return (
        <div className="flex items-center justify-between gap-4 border-t px-4 py-3 text-xs text-muted-foreground">
            <span className="font-mono">
                {fmt(page.from ?? 0)}–{fmt(page.to ?? 0)} of {fmt(page.total)}
            </span>
            <div className="flex gap-2">
                <Button variant="outline" size="sm" disabled={!page.prev_page_url} asChild={!!page.prev_page_url}>
                    {page.prev_page_url ? (
                        <Link href={page.prev_page_url} preserveScroll>
                            <ChevronLeft /> Prev
                        </Link>
                    ) : (
                        <span>
                            <ChevronLeft /> Prev
                        </span>
                    )}
                </Button>
                <Button variant="outline" size="sm" disabled={!page.next_page_url} asChild={!!page.next_page_url}>
                    {page.next_page_url ? (
                        <Link href={page.next_page_url} preserveScroll>
                            Next <ChevronRight />
                        </Link>
                    ) : (
                        <span>
                            Next <ChevronRight />
                        </span>
                    )}
                </Button>
            </div>
        </div>
    );
}
