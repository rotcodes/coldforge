import { Link } from '@inertiajs/react';
import { Plus, Send } from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import EmptyState from '@/Components/EmptyState';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Progress } from '@/Components/ui/progress';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/table';
import { date, fmt, pct } from '@/lib/utils';
import type { Campaign } from '@/types';

export default function CampaignsIndex({ campaigns }: { campaigns: Campaign[] }) {
    const newButton = (
        <Button asChild>
            <Link href="/campaigns/create">
                <Plus /> New campaign
            </Link>
        </Button>
    );

    return (
        <AppLayout title="Campaigns">
            <PageHeader title="Campaigns" description="Every send, with live open and click numbers." actions={newButton} />
            <Card className="overflow-hidden">
                {campaigns.length === 0 ? (
                    <EmptyState icon={Send} title="No campaigns yet" text="Pick a template, choose who gets it, and schedule. That's the whole flow." action={newButton} />
                ) : (
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Campaign</TableHead>
                                <TableHead>Status</TableHead>
                                <TableHead className="hidden w-48 md:table-cell">Progress</TableHead>
                                <TableHead className="hidden text-right sm:table-cell">Opens</TableHead>
                                <TableHead className="hidden text-right sm:table-cell">Clicks</TableHead>
                                <TableHead className="hidden text-right lg:table-cell">Created</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {campaigns.map((c) => (
                                <TableRow key={c.id}>
                                    <TableCell className="max-w-[240px]">
                                        <Link href={`/campaigns/${c.id}`} className="block rounded focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring">
                                            <div className="truncate font-medium hover:text-primary">{c.name}</div>
                                            <div className="truncate text-xs text-muted-foreground">{c.template?.name}</div>
                                        </Link>
                                    </TableCell>
                                    <TableCell>
                                        <StatusBadge status={c.status} />
                                    </TableCell>
                                    <TableCell className="hidden md:table-cell">
                                        <div className="mb-1 font-mono text-xs text-muted-foreground">
                                            {fmt(c.sent_count)} / {fmt(c.total_recipients)}
                                        </div>
                                        <Progress value={pct(c.sent_count, c.total_recipients)} className="h-1.5" />
                                    </TableCell>
                                    <TableCell className="hidden text-right font-mono text-sm sm:table-cell">{pct(c.opened_count, c.sent_count)}%</TableCell>
                                    <TableCell className="hidden text-right font-mono text-sm sm:table-cell">{pct(c.clicked_count, c.sent_count)}%</TableCell>
                                    <TableCell className="hidden text-right text-xs text-muted-foreground lg:table-cell">{date(c.created_at)}</TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                )}
            </Card>
        </AppLayout>
    );
}
