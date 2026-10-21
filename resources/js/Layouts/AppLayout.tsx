import { useEffect, useState } from 'react';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { motion } from 'framer-motion';
import { toast } from 'sonner';
import { Bell, Building2, ChevronRight, ChevronsLeft, ChevronsUpDown, FileText, Inbox, LayoutDashboard, LogOut, Moon, Send, Settings, Sun, User as UserIcon, Users } from 'lucide-react';
import Logo from '@/Components/Logo';
import MenuToggle from '@/Components/MenuToggle';
import { ConfirmHost } from '@/Components/ConfirmDialog';
import { Toaster } from '@/Components/ui/sonner';
import { Avatar, AvatarFallback } from '@/Components/ui/avatar';
import { Sheet, SheetContent, SheetDescription, SheetTitle } from '@/Components/ui/sheet';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuLabel, DropdownMenuSeparator, DropdownMenuTrigger } from '@/Components/ui/dropdown-menu';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/Components/ui/tooltip';
import { useTheme } from '@/lib/theme';
import { cn } from '@/lib/utils';
import type { PageProps, User } from '@/types';

const nav = [
    { label: 'Dashboard', href: '/', icon: LayoutDashboard },
    { label: 'Contacts', href: '/contacts', icon: Users },
    { label: 'Templates', href: '/templates', icon: FileText },
    { label: 'Campaigns', href: '/campaigns', icon: Send },
    { label: 'Inbox', href: '/inbox', icon: Inbox, badge: 'unread' as const },
    { label: 'Industries', href: '/industries', icon: Building2 },
    { label: 'Brevo', href: '/brevo', icon: Bell },
    { label: 'Settings', href: '/settings/senders', icon: Settings, match: '/settings' },
];

export type Crumb = { label: string; href?: string };

const initials = (name: string) =>
    name
        .split(/\s+/)
        .map((p) => p[0])
        .join('')
        .slice(0, 2)
        .toUpperCase();

/** Sticky card at the bottom of the sidebar: who's signed in + profile / logout. */
function ProfileCard({ user, collapsed }: { user: User; collapsed: boolean }) {
    const avatar = (
        <Avatar className="size-8 shrink-0 ring-2 ring-primary/20">
            <AvatarFallback className="bg-brand text-xs font-semibold text-white">{initials(user.name)}</AvatarFallback>
        </Avatar>
    );

    return (
        <DropdownMenu>
            <DropdownMenuTrigger
                className={cn(
                    'glass flex w-full items-center gap-3 rounded-xl text-left transition-colors hover:border-primary/30 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring data-[state=open]:border-primary/40',
                    collapsed ? 'justify-center p-1.5' : 'p-2.5',
                )}
                aria-label="Account menu"
            >
                {avatar}
                {!collapsed && (
                    <>
                        <span className="min-w-0 flex-1">
                            <span className="block truncate text-sm font-medium">{user.name}</span>
                            <span className="block truncate text-xs text-muted-foreground">{user.email}</span>
                        </span>
                        <ChevronsUpDown className="size-4 shrink-0 text-muted-foreground" />
                    </>
                )}
            </DropdownMenuTrigger>
            <DropdownMenuContent side="top" align="start" sideOffset={8} className="w-[--radix-dropdown-menu-trigger-width] min-w-56">
                <DropdownMenuLabel className="flex items-center gap-3 font-normal">
                    {avatar}
                    <span className="min-w-0">
                        <span className="block truncate text-sm font-medium">{user.name}</span>
                        <span className="block truncate text-xs text-muted-foreground">{user.email}</span>
                    </span>
                </DropdownMenuLabel>
                <DropdownMenuSeparator />
                <DropdownMenuItem onSelect={() => router.visit('/settings/profile')}>
                    <UserIcon /> My profile
                </DropdownMenuItem>
                <DropdownMenuItem onSelect={() => router.visit('/settings/senders')}>
                    <Settings /> Settings
                </DropdownMenuItem>
                <DropdownMenuSeparator />
                <DropdownMenuItem onSelect={() => router.post('/logout')} className="text-red-500 focus:bg-red-500/10 focus:text-red-500">
                    <LogOut /> Log out
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

function NavLinks({ collapsed, isActive, onNavigate, badges }: { collapsed: boolean; isActive: (i: (typeof nav)[number]) => boolean; onNavigate?: () => void; badges: Record<string, number> }) {
    return (
        <nav className="space-y-1">
            {nav.map((item) => {
                const active = isActive(item);
                const badgeCount = 'badge' in item && item.badge ? badges[item.badge] : 0;
                const link = (
                    <Link
                        key={item.href}
                        href={item.href}
                        onClick={onNavigate}
                        className={cn(
                            'relative flex h-10 items-center gap-3 rounded-lg px-3 text-sm font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring md:h-9 md:px-2.5',
                            active ? 'bg-primary/10 text-foreground' : 'text-muted-foreground hover:bg-accent hover:text-foreground',
                        )}
                    >
                        {active && <motion.span layoutId={onNavigate ? 'nav-active-m' : 'nav-active'} className="absolute inset-y-1.5 left-0 w-0.5 rounded-full bg-brand" />}
                        <span className="relative shrink-0">
                            <item.icon className={cn('size-4', active && 'text-primary')} />
                            {badgeCount > 0 && collapsed && <span className="absolute -right-1 -top-1 size-2 rounded-full bg-red-500 ring-2 ring-background" />}
                        </span>
                        {!collapsed && <span className="flex-1">{item.label}</span>}
                        {!collapsed && badgeCount > 0 && (
                            <span className="rounded-full bg-red-500 px-1.5 py-0.5 text-[10px] font-semibold text-white">{badgeCount > 99 ? '99+' : badgeCount}</span>
                        )}
                    </Link>
                );
                return collapsed ? (
                    <Tooltip key={item.href}>
                        <TooltipTrigger asChild>{link}</TooltipTrigger>
                        <TooltipContent side="right">{item.label}{badgeCount > 0 && ` · ${badgeCount}`}</TooltipContent>
                    </Tooltip>
                ) : (
                    link
                );
            })}
        </nav>
    );
}

export default function AppLayout({ title, crumbs, children }: { title: string; crumbs?: Crumb[]; children: React.ReactNode }) {
    const { url, props } = usePage<PageProps>();
    const { theme, toggle } = useTheme();
    const [mobileOpen, setMobileOpen] = useState(false);
    const [collapsed, setCollapsed] = useState(() => {
        try {
            return localStorage.getItem('sidebar') === 'collapsed';
        } catch {
            return false;
        }
    });

    useEffect(() => {
        if (props.flash.success) toast.success(props.flash.success);
        if (props.flash.error) toast.error(props.flash.error);
    }, [props.flash]);

    const toggleSidebar = () => {
        setCollapsed((c) => {
            try {
                localStorage.setItem('sidebar', c ? 'open' : 'collapsed');
            } catch {}
            return !c;
        });
    };

    const isActive = (item: (typeof nav)[number]) => {
        const path = url.split('?')[0];
        const base = item.match ?? item.href;
        return base === '/' ? path === '/' : path.startsWith(base);
    };

    const user = props.auth.user!;
    const trail: Crumb[] = crumbs ?? [{ label: title }];
    const badges = { unread: (props as any).unread_inbox ?? 0 };

    return (
        <TooltipProvider delayDuration={200}>
            <Head title={title} />
            <div className="app-bg min-h-screen">
                {/* Sidebar (md+) */}
                <aside
                    className={cn(
                        'glass fixed inset-y-0 left-0 z-30 hidden flex-col border-y-0 border-l-0 transition-[width] duration-200 md:flex',
                        collapsed ? 'w-[68px]' : 'w-60',
                    )}
                >
                    <div className="flex h-14 items-center px-4">
                        <Link href="/" className="rounded-lg focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring">
                            <Logo collapsed={collapsed} />
                        </Link>
                    </div>
                    <div className="flex-1 overflow-y-auto px-3 py-4">
                        <NavLinks collapsed={collapsed} isActive={isActive} badges={badges} />
                    </div>
                    <div className="space-y-2 p-3">
                        <button
                            onClick={toggleSidebar}
                            aria-label={collapsed ? 'Expand sidebar' : 'Collapse sidebar'}
                            className={cn(
                                'flex h-8 w-full items-center gap-3 rounded-lg px-2.5 text-xs text-muted-foreground transition-colors hover:bg-accent hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
                                collapsed && 'justify-center px-0',
                            )}
                        >
                            <ChevronsLeft className={cn('size-4 shrink-0 transition-transform', collapsed && 'rotate-180')} />
                            {!collapsed && 'Collapse'}
                        </button>
                        <ProfileCard user={user} collapsed={collapsed} />
                    </div>
                </aside>

                {/* Drawer (mobile) */}
                <Sheet open={mobileOpen} onOpenChange={setMobileOpen}>
                    <SheetContent side="left" className="glass flex w-[280px] flex-col gap-0 border-y-0 border-l-0 bg-background/85 p-0 sm:max-w-[280px] [&>button:first-child]:hidden">
                        <SheetTitle className="sr-only">Navigation</SheetTitle>
                        <SheetDescription className="sr-only">Main menu</SheetDescription>
                        <div className="flex h-14 items-center justify-between px-4">
                            <Logo />
                            <MenuToggle open onClick={() => setMobileOpen(false)} />
                        </div>
                        <div className="flex-1 overflow-y-auto px-3 py-4">
                            <motion.div initial={{ opacity: 0, x: -8 }} animate={{ opacity: 1, x: 0 }} transition={{ duration: 0.2, delay: 0.05 }}>
                                <NavLinks collapsed={false} isActive={isActive} onNavigate={() => setMobileOpen(false)} badges={badges} />
                            </motion.div>
                        </div>
                        <div className="p-3 pb-[max(0.75rem,env(safe-area-inset-bottom))]">
                            <ProfileCard user={user} collapsed={false} />
                        </div>
                    </SheetContent>
                </Sheet>

                <div className={cn('transition-[padding] duration-200', collapsed ? 'md:pl-[68px]' : 'md:pl-60')}>
                    {/* Header */}
                    <header className="glass sticky top-0 z-20 flex h-14 items-center gap-3 border-x-0 border-t-0 px-4 sm:px-6">
                        <MenuToggle open={mobileOpen} onClick={() => setMobileOpen(true)} className="md:hidden" />
                        <nav aria-label="Breadcrumb" className="flex min-w-0 flex-1 items-center gap-1.5 text-sm">
                            {trail.map((c, i) => (
                                <span key={i} className={cn('flex min-w-0 items-center gap-1.5', i < trail.length - 1 && 'hidden sm:flex')}>
                                    {i > 0 && <ChevronRight className="size-3.5 shrink-0 text-muted-foreground" />}
                                    {c.href ? (
                                        <Link href={c.href} className="truncate text-muted-foreground transition-colors hover:text-foreground">
                                            {c.label}
                                        </Link>
                                    ) : (
                                        <span className="truncate font-medium">{c.label}</span>
                                    )}
                                </span>
                            ))}
                        </nav>
                        <button
                            onClick={toggle}
                            aria-label="Toggle theme"
                            className="grid size-9 place-items-center rounded-lg text-muted-foreground transition-colors hover:bg-accent hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                        >
                            {theme === 'dark' ? <Sun className="size-4" /> : <Moon className="size-4" />}
                        </button>
                    </header>

                    <motion.main
                        key={url.split('?')[0]}
                        initial={{ opacity: 0, y: 8 }}
                        animate={{ opacity: 1, y: 0 }}
                        transition={{ duration: 0.18, ease: 'easeOut' }}
                        className="mx-auto w-full max-w-7xl px-4 pb-12 pt-6 sm:px-6 lg:px-8"
                    >
                        {children}
                    </motion.main>
                </div>
            </div>
            <ConfirmHost />
            <Toaster position="top-right" richColors={false} />
        </TooltipProvider>
    );
}
