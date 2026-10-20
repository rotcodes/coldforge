<?php

namespace Tests\Feature;

use App\Jobs\ImportContactsJob;
use App\Models\Contact;
use App\Models\CsvImport;
use App\Models\Industry;
use App\Models\Sender;
use App\Models\Template;
use App\Models\User;
use App\Services\CsvHeaderMapper;
use App\Services\EmailRenderer;
use App\Services\IndustryMatcher;
use App\Services\TemplateFallbacks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PersonalizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_industries_seed_contains_expected_presets(): void
    {
        $this->assertDatabaseHas('industries', ['slug' => 'equipment_dealer']);
        $this->assertDatabaseHas('industries', ['slug' => 'warehouse']);
        $this->assertGreaterThanOrEqual(10, Industry::count());
    }

    public function test_industry_matcher_resolves_exact_alias_and_substrings(): void
    {
        $matcher = new IndustryMatcher;

        $this->assertSame('equipment_dealer', $matcher->match('Construction equipment'));
        $this->assertSame('steel_fabrication', $matcher->match('Metal fabrication'));
        $this->assertSame('wholesale_distribution', $matcher->match('wholesale'));
        $this->assertSame('machining', $matcher->match('CNC machine shop'));
        $this->assertNull($matcher->match(''));
        $this->assertNull($matcher->match('completely unrelated text'));
    }

    public function test_csv_header_mapper_recognises_pure_intel_format(): void
    {
        $headers = ['Source', 'Business', 'Lead ID', 'Email', 'Industry', 'City / State', 'Personalized Opener', 'Pain Evidence', 'Recommended Offer'];
        $this->assertNotNull(CsvHeaderMapper::detectFormat(['Source', 'Business', 'Lead ID', 'Email', 'Personalized Opener', 'Pain Evidence', 'Recommended Offer']));

        $this->assertSame('email', CsvHeaderMapper::guess('Email'));
        $this->assertSame('business', CsvHeaderMapper::guess('Business'));
        $this->assertSame('industry', CsvHeaderMapper::guess('Industry'));
        $this->assertSame('city_state', CsvHeaderMapper::guess('City / State'));
        $this->assertSame('source_system', CsvHeaderMapper::guess('Source'));
        $this->assertSame('external_id', CsvHeaderMapper::guess('Lead ID'));
        $this->assertSame('personalized_opener', CsvHeaderMapper::guess('Personalized Opener'));
        $this->assertSame('custom:employees', CsvHeaderMapper::guess('Employees'));
    }

    public function test_contact_template_vars_use_industry_and_fallbacks(): void
    {
        $c = Contact::create([
            'email' => 't@example.com',
            'business' => 'Acme Equipment',
            'industry' => 'Construction equipment',
            'industry_slug' => 'equipment_dealer',
        ]);

        $vars = $c->templateVars();

        $this->assertSame('equipment dealership', $vars['industry_label']);
        $this->assertStringContainsString('equipment inquiries', $vars['workflow_examples']);
        $this->assertStringContainsString('customer inquiries', $vars['tracking_examples']);
        $this->assertSame('Acme Equipment', $vars['business']);
    }

    public function test_contact_without_industry_falls_back_to_saved_fallback_text(): void
    {
        TemplateFallbacks::save(['workflow_examples' => 'OUR_FALLBACK_WORKFLOW', 'industry_label' => 'OUR_FALLBACK_INDUSTRY']);

        $c = Contact::create(['email' => 't@example.com']);
        $vars = $c->templateVars();

        $this->assertSame('OUR_FALLBACK_WORKFLOW', $vars['workflow_examples']);
        $this->assertSame('OUR_FALLBACK_INDUSTRY', $vars['industry_label']);
    }

    public function test_readiness_tier_calculation(): void
    {
        $t0 = new Contact(['email' => null]);
        $t1 = new Contact(['email' => 'a@b.co']);
        $t2 = new Contact(['email' => 'a@b.co', 'business' => 'Acme']);
        $t3 = new Contact(['email' => 'a@b.co', 'business' => 'Acme', 'industry_slug' => 'equipment_dealer']);
        $t4 = new Contact(['email' => 'a@b.co', 'business' => 'Acme', 'industry_slug' => 'equipment_dealer', 'personalized_opener' => 'opener']);

        $this->assertSame(0, $t0->readinessTier());
        $this->assertSame(1, $t1->readinessTier());
        $this->assertSame(2, $t2->readinessTier());
        $this->assertSame(3, $t3->readinessTier());
        $this->assertSame(4, $t4->readinessTier());
    }

    public function test_matching_filter_honours_industry_source_and_score(): void
    {
        Contact::create(['email' => 'a@b.co', 'industry_slug' => 'equipment_dealer', 'source_system' => 'INTEL', 'lead_score' => 90]);
        Contact::create(['email' => 'c@d.co', 'industry_slug' => 'warehouse', 'source_system' => 'CRM', 'lead_score' => 60]);
        Contact::create(['email' => 'e@f.co']);

        $this->assertSame(1, Contact::matchingFilter(['industry_slugs' => ['equipment_dealer']])->count());
        $this->assertSame(1, Contact::matchingFilter(['source_system' => 'CRM'])->count());
        $this->assertSame(1, Contact::matchingFilter(['lead_score_min' => 80])->count());
        $this->assertSame(2, Contact::matchingFilter(['require_industry' => true])->count());
        $this->assertSame(3, Contact::matchingFilter([])->count());
    }

    public function test_import_auto_maps_pure_intel_csv_and_matches_industry(): void
    {
        Storage::fake();
        $user = User::create(['name' => 'Admin', 'email' => 'admin@test.dev', 'password' => 'secret-password']);
        $this->actingAs($user);

        $csv = "\"Source\",\"Business\",\"Lead ID\",\"Status\",\"Reply Status\",\"Email\",\"Industry\",\"City / State\",\"Website\",\"Decision Maker\",\"Lead Score\",\"Personalized Opener\",\"Pain Evidence\",\"Recommended Offer\",\"Notion URL\"\n"
            ."\"CRM\",\"Rudd Equipment\",\"US-001\",\"RESEARCHING\",\"\",\"sales@rudd.co\",\"Construction equipment\",\"Fort Wayne, IN\",\"rudd.co\",\"Richard Smith\",\"82\",\"Rudd is a dealer\",\"Pipeline manual\",\"Dealer CRM\",\"https://notion.so/x\"\n"
            ."\"INTEL\",\"Steel Shop\",\"IN-001\",\"NEEDS VERIFICATION\",\"\",\"\",\"Stainless fabrication\",\"\",\"\",\"\",\"75\",\"\",\"\",\"\",\"\"\n"
            ."\"CRM\",\"Already Replied\",\"US-002\",\"SENT\",\"REPLIED\",\"replied@test.co\",\"warehouse\",\"\",\"\",\"\",\"70\",\"\",\"\",\"\",\"\"\n";

        $file = UploadedFile::fake()->createWithContent('intel.csv', $csv);
        $preview = $this->post('/csv-imports', ['file' => $file])->assertOk()->json();

        $this->assertSame('email', $preview['suggested']['Email']);
        $this->assertSame('business', $preview['suggested']['Business']);
        $this->assertSame('industry', $preview['suggested']['Industry']);
        $this->assertSame('personalized_opener', $preview['suggested']['Personalized Opener']);
        $this->assertNotNull($preview['detected_format']);
        $this->assertSame(1, $preview['empty_email_rows']);

        $this->postJson("/csv-imports/{$preview['id']}/start", ['mapping' => $preview['suggested'], 'tags' => ['batch-test']])->assertOk();

        $status = $this->getJson("/api/csv-imports/{$preview['id']}")->json();
        $this->assertSame('done', $status['status']);
        $this->assertSame(2, $status['imported_count']); // 2 with email imported; 1 without email skipped
        $this->assertSame(1, $status['failed_count']);

        $rudd = Contact::firstWhere('email', 'sales@rudd.co');
        $this->assertNotNull($rudd);
        $this->assertSame('Rudd Equipment', $rudd->business);
        $this->assertSame('equipment_dealer', $rudd->industry_slug);
        $this->assertSame(82, $rudd->lead_score);
        $this->assertSame('CRM', $rudd->source_system);
        $this->assertSame('US-001', $rudd->external_id);
        $this->assertSame('active', $rudd->status);

        $replied = Contact::firstWhere('email', 'replied@test.co');
        $this->assertSame('unsubscribed', $replied->status, 'Contacts with a Reply Status must auto-unsubscribe.');
    }

    public function test_rendering_with_null_industry_does_not_leave_empty_placeholders(): void
    {
        $template = Template::create([
            'name' => 'T', 'subject' => 'Quick question about your {{industry_label}}', 'from_email' => 'me@x.co',
            'html_body' => '<p>How do you currently manage {{workflow_examples}}?</p>',
        ]);
        $contact = Contact::create(['email' => 'x@y.co']); // no industry

        $rendered = EmailRenderer::render($template, $contact);

        $this->assertStringContainsString('business', $rendered['subject']); // default fallback for industry_label
        $this->assertStringContainsString('daily operations', $rendered['html']); // default fallback for workflow_examples
        $this->assertStringNotContainsString('{{', $rendered['html']);
    }

    public function test_industries_crud_endpoints(): void
    {
        $user = User::create(['name' => 'Admin', 'email' => 'admin@test.dev', 'password' => 'secret-password']);
        $this->actingAs($user);

        $this->get('/industries')->assertOk()->assertInertia(fn ($p) => $p->component('Industries/Index'));

        $this->post('/industries', [
            'slug' => 'custom_x',
            'label' => 'X business',
            'workflows' => 'xw',
            'tracking' => 'xt',
            'aliases' => ['xx', 'yy'],
            'is_active' => true,
        ])->assertRedirect();

        $i = Industry::where('slug', 'custom_x')->firstOrFail();
        $this->assertSame(['xx', 'yy'], $i->aliases);

        $this->put("/industries/{$i->id}", [
            'label' => 'X business updated', 'workflows' => 'xw2', 'tracking' => 'xt2', 'aliases' => ['zz'], 'is_active' => true,
        ])->assertRedirect();
        $this->assertSame('X business updated', $i->fresh()->label);

        $this->delete("/industries/{$i->id}")->assertRedirect();
        $this->assertDatabaseMissing('industries', ['id' => $i->id]);
    }

    public function test_fallbacks_save_and_round_trip(): void
    {
        $user = User::create(['name' => 'Admin', 'email' => 'admin@test.dev', 'password' => 'secret-password']);
        $this->actingAs($user);

        $this->get('/settings/fallbacks')->assertOk()->assertInertia(fn ($p) => $p->component('Settings/Fallbacks'));

        $this->put('/settings/fallbacks', ['fallbacks' => [
            'workflow_examples' => 'CUSTOM WORKFLOW',
            'industry_label' => 'CUSTOM LABEL',
        ]])->assertRedirect();

        $all = TemplateFallbacks::all();
        $this->assertSame('CUSTOM WORKFLOW', $all['workflow_examples']);
        $this->assertSame('CUSTOM LABEL', $all['industry_label']);
    }
}
