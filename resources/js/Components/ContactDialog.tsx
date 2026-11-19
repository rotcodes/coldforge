import { FormEvent, useEffect } from 'react';
import { useForm } from '@inertiajs/react';
import { Loader2 } from 'lucide-react';
import Field from '@/Components/Field';
import TagInput from '@/Components/TagInput';
import { Button } from '@/Components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { Input } from '@/Components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/Components/ui/tabs';
import { Textarea } from '@/Components/ui/textarea';
import type { Contact, ContactStatus, Industry, PermissionStatus, PipelineStatus } from '@/types';

const blank = {
    email: '', first_name: '', last_name: '', company: '', country: '',
    business: '', industry: '', industry_slug: '',
    city_state: '', website: '',
    decision_maker: '', dm_role: '', direct_phone: '', main_phone: '',
    personalized_opener: '', pain_evidence: '', recommended_offer: '',
    status: 'active' as ContactStatus, pipeline_status: 'new' as PipelineStatus, permission_status: 'unknown' as PermissionStatus,
    consent_source: '', consent_evidence: '', notes: '', tags: [] as string[],
};

export default function ContactDialog({
    open, onOpenChange, contact, tags, statuses, industries,
}: {
    open: boolean;
    onOpenChange: (o: boolean) => void;
    contact: Contact | null;
    tags: string[];
    statuses: string[];
    industries: Pick<Industry, 'slug' | 'label'>[];
}) {
    const form = useForm(blank);

    useEffect(() => {
        if (!open) return;
        form.clearErrors();
        form.setData(contact ? {
            email: contact.email,
            first_name: contact.first_name ?? '',
            last_name: contact.last_name ?? '',
            company: contact.company ?? '',
            country: contact.country ?? '',
            business: contact.business ?? '',
            industry: contact.industry ?? '',
            industry_slug: contact.industry_slug ?? '',
            city_state: contact.city_state ?? '',
            website: contact.website ?? '',
            decision_maker: contact.decision_maker ?? '',
            dm_role: contact.dm_role ?? '',
            direct_phone: contact.direct_phone ?? '',
            main_phone: contact.main_phone ?? '',
            personalized_opener: contact.personalized_opener ?? '',
            pain_evidence: contact.pain_evidence ?? '',
            recommended_offer: contact.recommended_offer ?? '',
            status: contact.status,
            pipeline_status: contact.pipeline_status,
            permission_status: contact.permission_status,
            consent_source: contact.consent_source ?? '',
            consent_evidence: contact.consent_evidence ?? '',
            notes: contact.notes ?? '',
            tags: contact.tags ?? [],
        } : blank);
    }, [open, contact]);

    const submit = (e: FormEvent) => {
        e.preventDefault();
        const opts = { preserveScroll: true, onSuccess: () => onOpenChange(false) };
        contact ? form.put(`/contacts/${contact.id}`, opts) : form.post('/contacts', opts);
    };

    const text = (key: keyof typeof blank, label: string, type = 'text') => (
        <Field label={label} htmlFor={`c-${key}`} error={form.errors[key]}>
            <Input id={`c-${key}`} type={type} value={form.data[key] as string} onChange={(e) => form.setData(key, e.target.value)} />
        </Field>
    );

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-2xl">
                <DialogHeader>
                    <DialogTitle>{contact ? 'Edit contact' : 'Add contact'}</DialogTitle>
                </DialogHeader>
                <form onSubmit={submit} noValidate>
                    <Tabs defaultValue="basic">
                        <TabsList className="w-full">
                            <TabsTrigger value="basic" className="flex-1">Basic</TabsTrigger>
                            <TabsTrigger value="intel" className="flex-1">Lead intel</TabsTrigger>
                            <TabsTrigger value="personalization" className="flex-1">Personalization</TabsTrigger>
                        </TabsList>

                        <TabsContent value="basic" className="space-y-4">
                            {text('email', 'Email', 'email')}
                            <div className="grid gap-4 sm:grid-cols-2">
                                {text('business', 'Business')}
                                <Field label="Industry preset" error={form.errors.industry_slug} hint="Drives the smart template variables.">
                                    <Select value={form.data.industry_slug || '__none__'} onValueChange={(v) => form.setData('industry_slug', v === '__none__' ? '' : v)}>
                                        <SelectTrigger><SelectValue placeholder="None" /></SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="__none__">— None —</SelectItem>
                                            {industries.map((i) => (
                                                <SelectItem key={i.slug} value={i.slug}>{i.label}</SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </Field>
                                {text('city_state', 'City / state')}
                                {text('country', 'Country')}
                                {text('industry', 'Industry (raw text)')}
                                {text('website', 'Website')}
                            </div>
                            <div className="grid gap-4 sm:grid-cols-2">
                            <Field label="Contact status" error={form.errors.status}>
                                <Select value={form.data.status} onValueChange={(v) => form.setData('status', v as ContactStatus)}>
                                    <SelectTrigger><SelectValue /></SelectTrigger>
                                    <SelectContent>
                                        {statuses.map((s) => (
                                            <SelectItem key={s} value={s} className="capitalize">{s}</SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </Field>
                            <Field label="Pipeline stage" error={form.errors.pipeline_status}>
                                <Select value={form.data.pipeline_status} onValueChange={(v) => form.setData('pipeline_status', v as PipelineStatus)}>
                                    <SelectTrigger><SelectValue /></SelectTrigger>
                                    <SelectContent>{['new','contacted','replied','interested','demo_requested','demo_scheduled','proposal_sent','negotiation','won','lost','not_interested'].map((s) => <SelectItem key={s} value={s}>{s.replaceAll('_', ' ')}</SelectItem>)}</SelectContent>
                                </Select>
                            </Field>
                            </div>
                            <Field label="Campaign permission" error={form.errors.permission_status} hint="Unknown contacts are excluded from campaigns until explicitly reviewed.">
                                <Select value={form.data.permission_status} onValueChange={(v) => form.setData('permission_status', v as PermissionStatus)}>
                                    <SelectTrigger><SelectValue /></SelectTrigger>
                                    <SelectContent><SelectItem value="unknown">Unknown · not eligible</SelectItem><SelectItem value="consented">Consented</SelectItem><SelectItem value="legitimate_interest">Legitimate interest</SelectItem><SelectItem value="suppressed">Suppressed</SelectItem></SelectContent>
                                </Select>
                            </Field>
                            {form.data.permission_status !== 'unknown' && <div className="grid gap-4 sm:grid-cols-2">{text('consent_source', 'Permission source')}<Field label="Permission evidence" htmlFor="c-consent" error={form.errors.consent_evidence}><Textarea id="c-consent" rows={2} value={form.data.consent_evidence} onChange={(e) => form.setData('consent_evidence', e.target.value)} /></Field></div>}
                            <Field label="Notes" htmlFor="c-notes" error={form.errors.notes}><Textarea id="c-notes" rows={3} value={form.data.notes} onChange={(e) => form.setData('notes', e.target.value)} /></Field>
                            <Field label="Tags" error={form.errors.tags}>
                                <TagInput value={form.data.tags} onChange={(t) => form.setData('tags', t)} suggestions={tags} />
                            </Field>
                        </TabsContent>

                        <TabsContent value="intel" className="space-y-4">
                            <div className="grid gap-4 sm:grid-cols-2">
                                {text('decision_maker', 'Decision maker')}
                                {text('dm_role', 'Role / title')}
                                {text('direct_phone', 'Direct phone')}
                                {text('main_phone', 'Main phone')}
                                {text('first_name', 'First name')}
                                {text('last_name', 'Last name')}
                                {text('company', 'Legacy company (alias of Business)')}
                            </div>
                        </TabsContent>

                        <TabsContent value="personalization" className="space-y-4">
                            <Field label="Personalized opener" htmlFor="c-opener" error={form.errors.personalized_opener} hint="A one-liner about this lead (available as {{personalized_opener}}).">
                                <Textarea id="c-opener" rows={2} value={form.data.personalized_opener} onChange={(e) => form.setData('personalized_opener', e.target.value)} />
                            </Field>
                            <Field label="Pain evidence" htmlFor="c-pain" error={form.errors.pain_evidence} hint="Short description of this lead's pain (available as {{pain_evidence}}).">
                                <Textarea id="c-pain" rows={2} value={form.data.pain_evidence} onChange={(e) => form.setData('pain_evidence', e.target.value)} />
                            </Field>
                            <Field label="Recommended offer" htmlFor="c-offer" error={form.errors.recommended_offer} hint="What you'd pitch them (available as {{recommended_offer}}).">
                                <Textarea id="c-offer" rows={2} value={form.data.recommended_offer} onChange={(e) => form.setData('recommended_offer', e.target.value)} />
                            </Field>
                        </TabsContent>
                    </Tabs>

                    <DialogFooter className="mt-4">
                        <Button type="button" variant="ghost" onClick={() => onOpenChange(false)}>Cancel</Button>
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
