<?php

namespace App\Http\Controllers;

use App\Models\Contact;
use App\Models\Sender;
use App\Models\Template;
use App\Services\EmailRenderer;
use App\Services\Senders\SenderRegistry;
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
        return Inertia::render('Templates/Editor', ['template' => null, 'fields' => $this->fields()]);
    }

    public function edit(Template $template)
    {
        return Inertia::render('Templates/Editor', ['template' => $template, 'fields' => $this->fields()]);
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
    public function test(Request $request, Template $template)
    {
        $data = $request->validate(['email' => ['required', 'email']]);

        $sender = Sender::where('is_active', true)->orderBy('priority')->first();
        if (! $sender) {
            return back()->with('error', 'Add an active sender first (Settings → Senders).');
        }

        $contact = Contact::firstWhere('email', strtolower($data['email'])) ?? new Contact(['email' => $data['email'], 'first_name' => 'Friend']);
        $mail = EmailRenderer::render($template, $contact);

        $result = SenderRegistry::make($sender)->sendTransactional([
            'from_name' => $template->from_name,
            'from_email' => $template->from_email,
            'reply_to' => $template->reply_to,
            'to_email' => $data['email'],
            'subject' => '[Test] '.$mail['subject'],
            'html' => $mail['html'],
            'text' => $mail['text'],
        ]);

        return $result->ok
            ? back()->with('success', "Test sent to {$data['email']} via {$sender->name}.")
            : back()->with('error', $result->error);
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

    /** Variables offered in the "Insert variable" menu: core contact fields + known custom field keys. */
    private function fields(): array
    {
        $custom = Contact::whereNotNull('custom_fields')->latest('id')->limit(500)->pluck('custom_fields')
            ->flatMap(fn ($f) => array_keys((array) $f))->unique()->values()->all();

        return array_values(array_unique([...Contact::FIELDS, ...$custom, 'unsubscribe_url']));
    }
}
