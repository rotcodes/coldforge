import { FormEvent } from 'react';
import { useForm } from '@inertiajs/react';
import { Loader2, RotateCcw, Save, Sparkles } from 'lucide-react';
import SettingsLayout from '@/Layouts/SettingsLayout';
import Field from '@/Components/Field';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';

interface Props {
    fallbacks: Record<string, string>;
    defaults: Record<string, string>;
}

const SMART_VARS = ['industry_label', 'workflow_examples', 'tracking_examples', 'business', 'decision_maker'];
const ADVANCED_VARS = ['personalized_opener', 'pain_evidence', 'recommended_offer'];

const DESCRIPTIONS: Record<string, string> = {
    industry_label: "Used when the contact has no mapped industry. Appears in subject lines like 'Quick question about your {{industry_label}}'.",
    workflow_examples: 'Used in sentences like "how do you currently manage {{workflow_examples}}".',
    tracking_examples: 'Used in sentences like "You can track {{tracking_examples}}".',
    business: 'Used when the contact has no business/company name.',
    decision_maker: 'Used when no decision maker name is known. Leave blank to render nothing.',
    personalized_opener: 'Fallback opener text when a contact has no researched opener. Leave blank unless you have a generic one.',
    pain_evidence: 'Fallback pain description. Leave blank to render nothing.',
    recommended_offer: 'Fallback offer description. Leave blank to render nothing.',
};

export default function FallbacksPage({ fallbacks, defaults }: Props) {
    const form = useForm({ fallbacks });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.put('/settings/fallbacks', { preserveScroll: true });
    };

    const reset = (key: string) => {
        form.setData('fallbacks', { ...form.data.fallbacks, [key]: defaults[key] ?? '' });
    };

    const row = (key: string) => (
        <Field
            key={key}
            label={<span className="font-mono text-xs text-primary">{`{{${key}}}`}</span>}
            htmlFor={`fb-${key}`}
            hint={DESCRIPTIONS[key]}
        >
            <div className="flex gap-2">
                <Input
                    id={`fb-${key}`}
                    value={form.data.fallbacks[key] ?? ''}
                    onChange={(e) => form.setData('fallbacks', { ...form.data.fallbacks, [key]: e.target.value })}
                    placeholder={defaults[key] || '— (leave blank)'}
                />
                {(form.data.fallbacks[key] ?? '') !== (defaults[key] ?? '') && (
                    <Button type="button" variant="ghost" size="icon" onClick={() => reset(key)} aria-label="Reset to default" title="Reset to default">
                        <RotateCcw className="size-4" />
                    </Button>
                )}
            </div>
        </Field>
    );

    return (
        <SettingsLayout title="Fallbacks">
            <form onSubmit={submit} className="space-y-6">
                <div className="rounded-xl border border-primary/20 bg-primary/5 p-4">
                    <div className="flex gap-3">
                        <Sparkles className="mt-0.5 size-4 shrink-0 text-primary" />
                        <div className="text-sm">
                            <div className="font-medium">What fallbacks are for</div>
                            <p className="mt-1 text-muted-foreground">
                                When a contact is missing a value for a template variable, these texts are used instead so no email ever renders with an empty placeholder.
                                Combined with the "Require industry" toggle in the campaign wizard, you get full control over who gets personalized sends vs generic sends.
                            </p>
                        </div>
                    </div>
                </div>

                <Card className="space-y-5 p-5">
                    <h2 className="text-sm font-semibold uppercase tracking-wider text-muted-foreground">Smart variables</h2>
                    {SMART_VARS.map(row)}
                </Card>

                <Card className="space-y-5 p-5">
                    <h2 className="text-sm font-semibold uppercase tracking-wider text-muted-foreground">Advanced (lead-level)</h2>
                    {ADVANCED_VARS.map(row)}
                </Card>

                <div className="flex justify-end">
                    <Button type="submit" disabled={form.processing}>
                        {form.processing ? <Loader2 className="animate-spin" /> : <Save />} Save fallbacks
                    </Button>
                </div>
            </form>
        </SettingsLayout>
    );
}
