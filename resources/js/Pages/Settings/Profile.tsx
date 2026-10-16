import { FormEvent } from 'react';
import { useForm, usePage } from '@inertiajs/react';
import { KeyRound, Loader2, User } from 'lucide-react';
import SettingsLayout from '@/Layouts/SettingsLayout';
import Field from '@/Components/Field';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import type { PageProps } from '@/types';

export default function Profile() {
    const user = usePage<PageProps>().props.auth.user!;
    const profile = useForm({ name: user.name, email: user.email });
    const password = useForm({ current_password: '', password: '', password_confirmation: '' });

    const saveProfile = (e: FormEvent) => {
        e.preventDefault();
        profile.put('/settings/profile', { preserveScroll: true });
    };

    const savePassword = (e: FormEvent) => {
        e.preventDefault();
        password.put('/settings/password', { preserveScroll: true, onSuccess: () => password.reset() });
    };

    return (
        <SettingsLayout title="Profile">
            <div className="grid gap-4 lg:grid-cols-2">
                <Card className="p-5">
                    <h2 className="mb-4 flex items-center gap-2 text-sm font-semibold">
                        <User className="size-4 text-primary" /> Account
                    </h2>
                    <form onSubmit={saveProfile} className="space-y-4" noValidate>
                        <Field label="Display name" htmlFor="name" error={profile.errors.name}>
                            <Input id="name" value={profile.data.name} onChange={(e) => profile.setData('name', e.target.value)} />
                        </Field>
                        <Field label="Login email" htmlFor="email" error={profile.errors.email}>
                            <Input id="email" type="email" value={profile.data.email} onChange={(e) => profile.setData('email', e.target.value)} />
                        </Field>
                        <Button type="submit" disabled={profile.processing || !profile.isDirty}>
                            {profile.processing && <Loader2 className="animate-spin" />} Save
                        </Button>
                    </form>
                </Card>

                <Card className="p-5">
                    <h2 className="mb-4 flex items-center gap-2 text-sm font-semibold">
                        <KeyRound className="size-4 text-primary" /> Change password
                    </h2>
                    <form onSubmit={savePassword} className="space-y-4" noValidate>
                        <Field label="Current password" htmlFor="current_password" error={password.errors.current_password}>
                            <Input id="current_password" type="password" autoComplete="current-password" value={password.data.current_password} onChange={(e) => password.setData('current_password', e.target.value)} />
                        </Field>
                        <Field label="New password" htmlFor="password" error={password.errors.password} hint="At least 10 characters.">
                            <Input id="password" type="password" autoComplete="new-password" value={password.data.password} onChange={(e) => password.setData('password', e.target.value)} />
                        </Field>
                        <Field label="Confirm new password" htmlFor="password_confirmation" error={password.errors.password_confirmation}>
                            <Input id="password_confirmation" type="password" autoComplete="new-password" value={password.data.password_confirmation} onChange={(e) => password.setData('password_confirmation', e.target.value)} />
                        </Field>
                        <Button type="submit" disabled={password.processing}>
                            {password.processing && <Loader2 className="animate-spin" />} Update password
                        </Button>
                    </form>
                </Card>
            </div>
        </SettingsLayout>
    );
}
