import { FormEvent, useEffect } from 'react';
import { useForm } from '@inertiajs/react';
import { Loader2 } from 'lucide-react';
import Field from '@/Components/Field';
import TagInput from '@/Components/TagInput';
import { Button } from '@/Components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { Input } from '@/Components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import type { Contact, ContactStatus } from '@/types';

const blank = { email: '', first_name: '', last_name: '', company: '', country: '', status: 'active' as ContactStatus, tags: [] as string[] };

export default function ContactDialog({
    open,
    onOpenChange,
    contact,
    tags,
    statuses,
}: {
    open: boolean;
    onOpenChange: (o: boolean) => void;
    contact: Contact | null;
    tags: string[];
    statuses: string[];
}) {
    const form = useForm(blank);

    useEffect(() => {
        if (!open) return;
        form.clearErrors();
        form.setData(
            contact
                ? {
                      email: contact.email,
                      first_name: contact.first_name ?? '',
                      last_name: contact.last_name ?? '',
                      company: contact.company ?? '',
                      country: contact.country ?? '',
                      status: contact.status,
                      tags: contact.tags ?? [],
                  }
                : blank,
        );
    }, [open, contact]);

    const submit = (e: FormEvent) => {
        e.preventDefault();
        const opts = { preserveScroll: true, onSuccess: () => onOpenChange(false) };
        contact ? form.put(`/contacts/${contact.id}`, opts) : form.post('/contacts', opts);
    };

    const text = (key: 'email' | 'first_name' | 'last_name' | 'company' | 'country', label: string, type = 'text') => (
        <Field label={label} htmlFor={`c-${key}`} error={form.errors[key]}>
            <Input id={`c-${key}`} type={type} value={form.data[key]} onChange={(e) => form.setData(key, e.target.value)} />
        </Field>
    );

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>{contact ? 'Edit contact' : 'Add contact'}</DialogTitle>
                </DialogHeader>
                <form onSubmit={submit} className="space-y-4" noValidate>
                    {text('email', 'Email', 'email')}
                    <div className="grid gap-4 sm:grid-cols-2">
                        {text('first_name', 'First name')}
                        {text('last_name', 'Last name')}
                        {text('company', 'Company')}
                        {text('country', 'Country')}
                    </div>
                    <Field label="Status" error={form.errors.status}>
                        <Select value={form.data.status} onValueChange={(v) => form.setData('status', v as ContactStatus)}>
                            <SelectTrigger>
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {statuses.map((s) => (
                                    <SelectItem key={s} value={s} className="capitalize">
                                        {s}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </Field>
                    <Field label="Tags" error={form.errors.tags}>
                        <TagInput value={form.data.tags} onChange={(t) => form.setData('tags', t)} suggestions={tags} />
                    </Field>
                    <DialogFooter>
                        <Button type="button" variant="ghost" onClick={() => onOpenChange(false)}>
                            Cancel
                        </Button>
                        <Button type="submit" disabled={form.processing}>
                            {form.processing && <Loader2 className="animate-spin" />}
                            {contact ? 'Save' : 'Add contact'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
