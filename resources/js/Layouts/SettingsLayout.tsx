import { Link, usePage } from '@inertiajs/react';
import { Mail, ShieldCheck, User } from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import { cn } from '@/lib/utils';

const tabs = [
    { label: 'Senders', href: '/settings/senders', icon: Mail },
    { label: 'Deliverability', href: '/settings/deliverability', icon: ShieldCheck },
    { label: 'Profile', href: '/settings/profile', icon: User },
];

export default function SettingsLayout({ title, children }: { title: string; children: React.ReactNode }) {
    const { url } = usePage();
    return (
        <AppLayout title={title} crumbs={[{ label: 'Settings', href: '/settings/senders' }, { label: title }]}>
            <h1 className="mb-4 text-2xl font-semibold tracking-tight">Settings</h1>
            <nav className="mb-6 flex gap-1 overflow-x-auto border-b">
                {tabs.map((t) => {
                    const active = url.startsWith(t.href);
                    return (
                        <Link
                            key={t.href}
                            href={t.href}
                            className={cn(
                                '-mb-px flex items-center gap-2 whitespace-nowrap border-b-2 px-3 py-2.5 text-sm font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
                                active ? 'border-primary text-foreground' : 'border-transparent text-muted-foreground hover:text-foreground',
                            )}
                        >
                            <t.icon className="size-4" /> {t.label}
                        </Link>
                    );
                })}
            </nav>
            {children}
        </AppLayout>
    );
}
