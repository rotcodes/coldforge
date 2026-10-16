import { Link, router } from '@inertiajs/react';
import { FileText, Plus, Trash2 } from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import { confirmDialog, visit } from '@/Components/ConfirmDialog';
import PageHeader from '@/Components/PageHeader';
import TiltCard from '@/Components/TiltCard';
import EmptyState from '@/Components/EmptyState';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { date } from '@/lib/utils';
import type { Template } from '@/types';

export default function TemplatesIndex({ templates }: { templates: Template[] }) {
    const newButton = (
        <Button asChild>
            <Link href="/templates/create">
                <Plus /> New template
            </Link>
        </Button>
    );

    return (
        <AppLayout title="Templates">
            <PageHeader title="Templates" description="HTML emails with {{variables}} filled per contact." actions={newButton} />

            {templates.length === 0 ? (
                <Card>
                    <EmptyState icon={FileText} title="No templates yet" text="Write your first cold email. Keep it short, plain and personal." action={newButton} />
                </Card>
            ) : (
                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                    {templates.map((t) => (
                        <TiltCard key={t.id} className="group relative flex h-full flex-col p-5">
                            <Link href={`/templates/${t.id}/edit`} className="absolute inset-0 rounded-xl focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring" aria-label={`Edit ${t.name}`} />
                            <div className="mb-3 flex items-start justify-between gap-2">
                                <div className="grid size-9 place-items-center rounded-lg bg-primary/10 text-primary">
                                    <FileText className="size-4" />
                                </div>
                                <button
                                    onClick={() => confirmDialog({ title: 'Delete template?', description: `"${t.name}" will be permanently removed.`, onConfirm: visit((o) => router.delete(`/templates/${t.id}`, o)) })}
                                    aria-label="Delete template"
                                    className="relative z-10 rounded-md p-1.5 text-muted-foreground transition-opacity hover:text-red-500 focus-visible:opacity-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring sm:opacity-0 sm:group-hover:opacity-100"
                                >
                                    <Trash2 className="size-4" />
                                </button>
                            </div>
                            <h3 className="font-medium">{t.name}</h3>
                            <p className="mt-1 line-clamp-2 text-sm text-muted-foreground">{t.subject}</p>
                            <div className="mt-4 flex flex-wrap gap-1">
                                {(t.variables ?? []).slice(0, 4).map((v) => (
                                    <span key={v} className="rounded bg-muted px-1.5 py-0.5 font-mono text-[11px] text-muted-foreground">
                                        {v}
                                    </span>
                                ))}
                            </div>
                            <div className="mt-auto flex justify-between pt-4 text-xs text-muted-foreground">
                                <span>{t.from_email}</span>
                                <span>
                                    {t.campaigns_count} campaigns · {date(t.updated_at)}
                                </span>
                            </div>
                        </TiltCard>
                    ))}
                </div>
            )}
        </AppLayout>
    );
}
