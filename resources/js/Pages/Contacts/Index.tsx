import { useEffect, useRef, useState } from 'react';
import { Link, router } from '@inertiajs/react';
import { Filter, MoreHorizontal, Pencil, Plus, Search, Trash2, Upload, Users } from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import { confirmDialog, visit } from '@/Components/ConfirmDialog';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import TierBadge, { TierLegend } from '@/Components/TierBadge';
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
import type { Contact, Industry, Paginated } from '@/types';

type Filters = { q?: string; status?: string; tag?: string; industry?: string; source?: string; min_score?: string; has_email?: string };

interface Props {
    contacts: Paginated<Contact>;
    filters: Filters;
    tags: string[];
    statuses: string[];
    industries: Pick<Industry, 'slug' | 'label'>[];
}

export default function ContactsIndex({ contacts, filters, tags, statuses, industries }: Props) {
    const [q, setQ] = useState(filters.q ?? '');
    const [importOpen, setImportOpen] = useState(false);
    const [editing, setEditing] = useState<Contact | null>(null);
    const [formOpen, setFormOpen] = useState(false);
    const [filtersOpen, setFiltersOpen] = useState(false);
    const first = useRef(true);

    const apply = (next: Partial<Filters>) =>
        router.get('/contacts', { ...filters, ...next }, { preserveState: true, preserveScroll: true, replace: true });

    useEffect(() => {
        if (first.current) { first.current = false; return; }
        const t = setTimeout(() => apply({ q: q || undefined }), 300);
        return () => clearTimeout(t);
    }, [q]);

    const openForm = (c: Contact | null) => { setEditing(c); setFormOpen(true); };
    const remove = (c: Contact) => {
        confirmDialog({ title: 'Delete contact?', description: `${c.email} and their send history will be permanently removed.`, onConfirm: visit((o) => router.delete(`/contacts/${c.id}`, { preserveScroll: true, ...o })) });
    };

    const hasFilters = !!(filters.q || filters.status || filters.tag || filters.industry || filters.source || filters.min_score || filters.has_email);
    const activeFilterCount = [filters.industry, filters.source, filters.min_score, filters.has_email].filter(Boolean).length;

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
                        <Input value={q} onChange={(e) => setQ(e.target.value)} placeholder="Search email, name, company, business…" className="pl-8" aria-label="Search contacts" />
                    </div>
                    <div className="flex gap-2">
                        <Select value={filters.status ?? 'all'} onValueChange={(v) => apply({ status: v === 'all' ? undefined : v })}>
                            <SelectTrigger className="w-full sm:w-36" aria-label="Status filter">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">All statuses</SelectItem>
                                {statuses.map((s) => (
                                    <SelectItem key={s} value={s} className="capitalize">{s}</SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <Select value={filters.tag ?? 'all'} onValueChange={(v) => apply({ tag: v === 'all' ? undefined : v })}>
                            <SelectTrigger className="w-full sm:w-32" aria-label="Tag filter">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">All tags</SelectItem>
                                {tags.map((t) => (
                                    <SelectItem key={t} value={t}>{t}</SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <Button variant={filtersOpen ? 'secondary' : 'outline'} size="sm" onClick={() => setFiltersOpen((o) => !o)} className="shrink-0">
                            <Filter className="size-4" /> More
                            {activeFilterCount > 0 && <span className="ml-1 rounded-full bg-primary px-1.5 text-[10px] font-semibold text-primary-foreground">{activeFilterCount}</span>}
                        </Button>
                    </div>
                </div>

                {filtersOpen && (
                    <div className="grid gap-2 border-b bg-muted/30 p-3 sm:grid-cols-2 lg:grid-cols-4">
                        <Select value={filters.industry ?? 'all'} onValueChange={(v) => apply({ industry: v === 'all' ? undefined : v })}>
                            <SelectTrigger className="h-9"><SelectValue placeholder="Industry" /></SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">All industries</SelectItem>
                                {industries.map((i) => (
                                    <SelectItem key={i.slug} value={i.slug}>{i.label}</SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <Select value={filters.source ?? 'all'} onValueChange={(v) => apply({ source: v === 'all' ? undefined : v })}>
                            <SelectTrigger className="h-9"><SelectValue placeholder="Source" /></SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">All sources</SelectItem>
                                <SelectItem value="INTEL">Pure Intelligence</SelectItem>
                                <SelectItem value="CRM">US CRM</SelectItem>
                            </SelectContent>
                        </Select>
                        <Select value={filters.min_score ?? 'all'} onValueChange={(v) => apply({ min_score: v === 'all' ? undefined : v })}>
                            <SelectTrigger className="h-9"><SelectValue placeholder="Lead score" /></SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">Any score</SelectItem>
                                <SelectItem value="60">≥ 60</SelectItem>
                                <SelectItem value="70">≥ 70</SelectItem>
                                <SelectItem value="80">≥ 80</SelectItem>
                                <SelectItem value="90">≥ 90</SelectItem>
                            </SelectContent>
                        </Select>
                        <Select value={filters.has_email ?? 'all'} onValueChange={(v) => apply({ has_email: v === 'all' ? undefined : v })}>
                            <SelectTrigger className="h-9"><SelectValue placeholder="Email" /></SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">All contacts</SelectItem>
                                <SelectItem value="1">Only with email</SelectItem>
                            </SelectContent>
                        </Select>
                    </div>
                )}

                {contacts.data.length === 0 ? (
                    <EmptyState
                        icon={Users}
                        title={hasFilters ? 'No matches' : 'No contacts yet'}
                        text={hasFilters ? 'Try a different search or filter.' : 'Import a CSV or add your first lead manually.'}
                        action={!hasFilters && (<Button onClick={() => setImportOpen(true)}><Upload /> Import CSV</Button>)}
                    />
                ) : (
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Contact</TableHead>
                                <TableHead className="hidden md:table-cell">Business</TableHead>
                                <TableHead className="hidden lg:table-cell">Industry</TableHead>
                                <TableHead className="hidden xl:table-cell">Score</TableHead>
                                <TableHead>Readiness</TableHead>
                                <TableHead>Status</TableHead>
                                <TableHead className="w-10" />
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {contacts.data.map((c) => (
                                <TableRow key={c.id}>
                                    <TableCell className="max-w-[220px]">
                                        <Link href={`/contacts/${c.id}`} className="block rounded focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring">
                                            <div className="truncate font-medium hover:text-primary">{c.decision_maker || c.email || '—'}</div>
                                            <div className="truncate text-xs text-muted-foreground">{c.email || <span className="italic text-red-500/70">no email</span>}</div>
                                        </Link>
                                    </TableCell>
                                    <TableCell className="hidden text-muted-foreground md:table-cell">
                                        <div className="truncate">{c.business || c.company || '—'}</div>
                                        {c.city_state && <div className="text-xs text-muted-foreground/70">{c.city_state}</div>}
                                    </TableCell>
                                    <TableCell className="hidden lg:table-cell">
                                        {c.industry_slug ? (
                                            <span className="rounded-md bg-primary/10 px-1.5 py-0.5 text-xs text-primary">{c.industry_slug.replace(/_/g, ' ')}</span>
                                        ) : c.industry ? (
                                            <span className="rounded-md bg-amber-500/10 px-1.5 py-0.5 text-xs text-amber-600 dark:text-amber-400" title="Industry text present but not matched to a preset">{c.industry}</span>
                                        ) : (
                                            <span className="text-xs text-muted-foreground">—</span>
                                        )}
                                    </TableCell>
                                    <TableCell className="hidden font-mono text-xs text-muted-foreground xl:table-cell">{c.lead_score ?? '—'}</TableCell>
                                    <TableCell><TierBadge tier={c.tier ?? 1} /></TableCell>
                                    <TableCell><StatusBadge status={c.status} /></TableCell>
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
                <div className="border-t px-3 py-2">
                    <TierLegend />
                </div>
                <Pagination page={contacts} />
            </Card>

            <ImportDialog open={importOpen} onOpenChange={setImportOpen} tags={tags} />
            <ContactDialog open={formOpen} onOpenChange={setFormOpen} contact={editing} tags={tags} statuses={statuses} industries={industries} />
        </AppLayout>
    );
}
