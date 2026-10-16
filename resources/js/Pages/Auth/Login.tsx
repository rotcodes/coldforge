import { FormEvent } from 'react';
import { Head, useForm } from '@inertiajs/react';
import { motion } from 'framer-motion';
import { ArrowRight, Loader2, Moon, Sun } from 'lucide-react';
import Logo from '@/Components/Logo';
import Field from '@/Components/Field';
import TiltCard from '@/Components/TiltCard';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { useTheme } from '@/lib/theme';

export default function Login() {
    const { theme, toggle } = useTheme();
    const form = useForm({ email: '', password: '', remember: true });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post('/login', { onFinish: () => form.reset('password') });
    };

    return (
        <div className="app-bg relative grid min-h-screen place-items-center overflow-hidden px-4">
            <Head title="Sign in" />
            <button
                onClick={toggle}
                aria-label="Toggle theme"
                className="absolute right-4 top-4 grid size-9 place-items-center rounded-lg text-muted-foreground hover:bg-accent hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
            >
                {theme === 'dark' ? <Sun className="size-4" /> : <Moon className="size-4" />}
            </button>

            {/* floating depth orbs */}
            <motion.div
                aria-hidden
                className="pointer-events-none absolute -left-24 top-1/4 size-72 rounded-full bg-indigo-500/20 blur-3xl"
                animate={{ y: [0, -20, 0] }}
                transition={{ duration: 8, repeat: Infinity, ease: 'easeInOut' }}
            />
            <motion.div
                aria-hidden
                className="pointer-events-none absolute -right-24 bottom-1/4 size-72 rounded-full bg-violet-500/20 blur-3xl"
                animate={{ y: [0, 20, 0] }}
                transition={{ duration: 9, repeat: Infinity, ease: 'easeInOut' }}
            />

            <motion.div initial={{ opacity: 0, y: 12 }} animate={{ opacity: 1, y: 0 }} transition={{ duration: 0.3, ease: 'easeOut' }} className="w-full max-w-sm">
                <div className="mb-8 flex justify-center">
                    <Logo />
                </div>
                <TiltCard hero className="p-7">
                    <h1 className="text-xl font-semibold tracking-tight">Welcome back</h1>
                    <p className="mt-1 text-sm text-muted-foreground">Sign in to your outreach workspace.</p>

                    <form onSubmit={submit} className="mt-6 space-y-4" noValidate>
                        <Field label="Email" htmlFor="email" error={form.errors.email}>
                            <Input id="email" type="email" autoComplete="username" autoFocus value={form.data.email} onChange={(e) => form.setData('email', e.target.value)} aria-invalid={!!form.errors.email} />
                        </Field>
                        <Field label="Password" htmlFor="password" error={form.errors.password}>
                            <Input id="password" type="password" autoComplete="current-password" value={form.data.password} onChange={(e) => form.setData('password', e.target.value)} />
                        </Field>
                        <label className="flex items-center gap-2 text-sm text-muted-foreground">
                            <input type="checkbox" checked={form.data.remember} onChange={(e) => form.setData('remember', e.target.checked)} className="size-4 rounded border-input accent-indigo-500" />
                            Keep me signed in
                        </label>
                        <Button type="submit" className="w-full" disabled={form.processing}>
                            {form.processing ? <Loader2 className="animate-spin" /> : <ArrowRight />}
                            Sign in
                        </Button>
                    </form>
                </TiltCard>
                <p className="mt-6 text-center text-xs text-muted-foreground">Single-user workspace. No sign-ups.</p>
            </motion.div>
        </div>
    );
}
