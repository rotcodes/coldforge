import { useEffect, useRef, useState } from 'react';
import { Link, router } from '@inertiajs/react';
import { MoreHorizontal, Pencil, Plus, Search, Trash2, Upload, Users } from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import { confirmDialog, visit } from '@/Components/ConfirmDialog';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import EmptyState from '@/Components/EmptyState';
import Pagination from '@/Components/Pagination';
import ImportDialog from '@/Components/ImportDialog';
import ContactDialog from '@/Components/ContactDialog';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/Components/ui/dropdown-menu';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/table';
import type { Contact, Paginated } from '@/types';

interface Props {
    contacts: Paginated<Contact>;
    filters: { q?: string; status?: string; tag?: string };
    tags: string[];
    statuses: string[];
}

export default function ContactsIndex({ contacts, filters, tags, statuses }: Props) {
    const [q, setQ] = useState(filters.q ?? '');
    const [importOpen, setImportOpen] = useState(false);
    const [editing, setEditing] = useState<Contact | null>(null);
    const [formOpen, setFormOpen] = useState(false);
    const first = useRef(true);

    const apply = (next: Partial<Props['filters']>) =>
        router.get('/contacts', { ...filters, ...next }, { preserveState: true, preserveScroll: true, replace: true });

    // Debounced search
    useEffect(() => {
        if (first.current) {
            first.current = false;
            return;
        }
        const t = setTimeout(() => apply({ q: q || undefined }), 300);
        return () => clearTimeout(t);
    }, [q]);

    const openForm = (c: Contact | null) => {
        setEditing(c);
        setFormOpen(true);
    };

    const remove = (c: Contact) => {
        confirmDialog({ title: 'Delete contact?', description: `${c.email} and their send history will be permanently removed.`, onConfirm: visit((o) => router.delete(`/contacts/${c.id}`, { preserveScroll: true, ...o })) });
    };

    const hasFilters = !!(filters.q || filters.status || filters.tag);

    return (
        <AppLayout title="Contacts">
            <PageHeader
                title="Contacts"
                description={`${contacts.total.toLocaleString()} contacts`}
                actions={
                    <>
                        <Button variant="outline" onClick={() => setImportOpen(true)}>
                            <Upload /> Import CSV
                        </Button>
                        <Button onClick={() => openForm(null)}>
                            <Plus /> Add contact
                        </Button>
                    </>
                }
            />

            <Card className="overflow-hidden">
                <div className="flex flex-col gap-2 border-b p-3 sm:flex-row">
                    <div className="relative flex-1">
                        <Search className="absolute left-2.5 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                        <Input value={q} onChange={(e) => setQ(e.target.value)} placeholder="Search email, name, company…" className="pl-8" aria-label="Search contacts" />
                    </div>
                    <div className="flex gap-2">
                        <Select value={filters.status ?? 'all'} onValueChange={(v) => apply({ status: v === 'all' ? undefined : v })}>
                            <SelectTrigger className="w-full sm:w-36" aria-label="Status filter">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">All statuses</SelectItem>
                                {statuses.map((s) => (
                                    <SelectItem key={s} value={s} className="capitalize">
                                        {s}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <Select value={filters.tag ?? 'all'} onValueChange={(v) => apply({ tag: v === 'all' ? undefined : v })}>
                            <SelectTrigger className="w-full sm:w-36" aria-label="Tag filter">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">All tags</SelectItem>
                                {tags.map((t) => (
                                    <SelectItem key={t} value={t}>
                                        {t}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>
                </div>

                {contacts.data.length === 0 ? (
                    <EmptyState
                        icon={Users}
                        title={hasFilters ? 'No matches' : 'No contacts yet'}
                        text={hasFilters ? 'Try a different search or filter.' : 'Import a CSV or add your first lead manually.'}
                        action={
                            !hasFilters && (
                                <Button onClick={() => setImportOpen(true)}>
                                    <Upload /> Import CSV
                                </Button>
                            )
                        }
                    />
                ) : (
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Contact</TableHead>
                                <TableHead className="hidden md:table-cell">Company</TableHead>
                                <TableHead className="hidden lg:table-cell">Tags</TableHead>
                                <TableHead>Status</TableHead>
                                <TableHead className="w-10" />
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {contacts.data.map((c) => (
                                <TableRow key={c.id}>
                                    <TableCell className="max-w-[220px]">
                                        <Link href={`/contacts/${c.id}`} className="block rounded focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring">
                                            <div className="truncate font-medium hover:text-primary">{[c.first_name, c.last_name].filter(Boolean).join(' ') || c.email}</div>
                                            <div className="truncate text-xs text-muted-foreground">{c.email}</div>
                                        </Link>
                                    </TableCell>
                                    <TableCell className="hidden text-muted-foreground md:table-cell">{c.company ?? '—'}</TableCell>
                                    <TableCell className="hidden lg:table-cell">
                                        <div className="flex flex-wrap gap-1">
                                            {(c.tags ?? []).slice(0, 3).map((t) => (
                                                <span key={t} className="rounded-md bg-muted px-1.5 py-0.5 text-xs text-muted-foreground">
                                                    {t}
                                                </span>
                                            ))}
                                        </div>
                                    </TableCell>
                                    <TableCell>
                                        <StatusBadge status={c.status} />
                                    </TableCell>
                                    <TableCell>
                                        <DropdownMenu>
                                            <DropdownMenuTrigger asChild>
                                                <Button variant="ghost" size="icon" className="size-8" aria-label="Actions">
                                                    <MoreHorizontal />
                                                </Button>
                                            </DropdownMenuTrigger>
                                            <DropdownMenuContent align="end">
                                                <DropdownMenuItem onSelect={() => openForm(c)}>
                                                    <Pencil /> Edit
                                                </DropdownMenuItem>
                                                <DropdownMenuItem onSelect={() => remove(c)} className="text-red-500 focus:text-red-500">
                                                    <Trash2 /> Delete
                                                </DropdownMenuItem>
                                            </DropdownMenuContent>
                                        </DropdownMenu>
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                )}
                <Pagination page={contacts} />
            </Card>

            <ImportDialog open={importOpen} onOpenChange={setImportOpen} tags={tags} />
            <ContactDialog open={formOpen} onOpenChange={setFormOpen} contact={editing} tags={tags} statuses={statuses} />
        </AppLayout>
    );
}
