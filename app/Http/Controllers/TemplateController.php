<?php

namespace App\Http\Controllers;

use App\Jobs\SendMailMessageJob;
use App\Models\Contact;
use App\Models\Template;
use App\Services\EmailRenderer;
use App\Services\Mailbox\OutboundMailService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class TemplateController extends Controller
{
    public function index()
    {
        return Inertia::render('Templates/Index', [
            'templates' => Template::withCount('campaigns')->latest('updated_at')->get(),
        ]);
    }

    public function create()
    {
        return Inertia::render('Templates/TemplateEditor', ['template' => null, 'fields' => $this->fields()]);
    }

    public function edit(Template $template)
    {
        return Inertia::render('Templates/TemplateEditor', ['template' => $template, 'fields' => $this->fields()]);
    }

    public function store(Request $request)
    {
        $template = Template::create($this->validated($request));

        return redirect("/templates/{$template->id}/edit")->with('success', 'Template created.');
    }

    public function update(Request $request, Template $template)
    {
        $template->update($this->validated($request));

        return back()->with('success', 'Template saved.');
    }

    public function destroy(Template $template)
    {
        if ($template->campaigns()->exists()) {
            return back()->with('error', 'Template is used by a campaign and cannot be deleted.');
        }
        $template->delete();

        return redirect('/templates')->with('success', 'Template deleted.');
    }

    /** Quick send: render a template for one address and send it right away (no tracking). */
    public function test(Request $request, Template $template, OutboundMailService $outbound)
    {
        $data = $request->validate(['email' => ['required', 'email']]);

        $contact = Contact::firstWhere('email', strtolower($data['email'])) ?? new Contact(['email' => $data['email'], 'first_name' => 'Friend', 'business' => 'your business']);
        $mail = EmailRenderer::render($template, $contact);
        try {
            $message = $outbound->create([
                'subject' => '[Test] '.$mail['subject'],
                'html_body' => $mail['html'],
                'plain_body' => $mail['text'],
                'source_type' => 'test',
            ], [['type' => 'to', 'email' => $data['email']]], 'send');
            SendMailMessageJob::dispatch($message);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Test queued for {$data['email']} through the connected mailbox.");
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'subject' => ['required', 'string', 'max:255'],
            'from_name' => ['nullable', 'string', 'max:100'],
            'from_email' => ['required', 'email'],
            'reply_to' => ['nullable', 'email'],
            'html_body' => ['required', 'string'],
            'text_body' => ['nullable', 'string'],
        ]);
    }

    /** Variables offered in the "Insert variable" menu: smart + core fields + known custom field keys. */
    private function fields(): array
    {
        $custom = Contact::whereNotNull('custom_fields')->latest('id')->limit(500)->pluck('custom_fields')
            ->flatMap(fn ($f) => array_keys((array) $f))->unique()->values()->all();

        $smart = ['industry_label', 'workflow_examples', 'tracking_examples'];

        return array_values(array_unique([...$smart, ...Contact::FIELDS, ...$custom, 'unsubscribe_url']));
    }
}
