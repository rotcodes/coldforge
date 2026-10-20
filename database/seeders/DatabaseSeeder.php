<?php

namespace Database\Seeders;

use App\Models\Contact;
use App\Models\Industry;
use App\Models\Sender;
use App\Models\Template;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedUser();
        $this->seedIndustries();

        if (! Sender::exists()) {
            Sender::create([
                'name' => 'Brevo',
                'provider' => 'brevo',
                'config' => ['method' => 'api', 'api_key' => ''], // blank key = falls back to BREVO_API_KEY in .env
                'daily_quota' => 300,
                'priority' => 1,
                'is_active' => true,
                'reset_at' => today(),
            ]);
        }

        if (! Template::exists()) {
            Template::create([
                'name' => 'Intro: quick question',
                'subject' => 'Quick question, {{first_name}}',
                'from_name' => 'Sam from Digitelly',
                'from_email' => 'hello@digitelly.net',
                'reply_to' => 'hello@digitelly.net',
                'html_body' => file_get_contents(database_path('samples/sample_template.html')),
                'text_body' => "Hi {{first_name}},\n\nI noticed {{company}} is growing fast. Worth a 15-minute chat this week?\n\nSam",
            ]);
        }

        // Demo contacts only outside production; they use example.com so they can never be mailed for real.
        if (! app()->isProduction() && ! Contact::exists()) {
            $rows = array_map('str_getcsv', file(database_path('samples/sample_contacts.csv'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
            array_shift($rows);
            foreach ($rows as [$email, $first, $last, $company, $country, $tags, $industry]) {
                Contact::create([
                    'email' => $email, 'first_name' => $first, 'last_name' => $last, 'company' => $company, 'country' => $country,
                    'tags' => explode(';', $tags), 'custom_fields' => ['industry' => $industry], 'source' => 'seed',
                ]);
            }
        }
    }

    private function seedIndustries(): void
    {
        foreach (config('coldforge.default_industries', []) as $i) {
            Industry::updateOrCreate(
                ['slug' => $i['slug']],
                ['label' => $i['label'], 'workflows' => $i['workflows'], 'tracking' => $i['tracking'], 'aliases' => $i['aliases'], 'is_active' => true],
            );
        }
    }

    private function seedUser(): void
    {
        if (User::exists()) {
            return;
        }

        $email = env('ADMIN_EMAIL', 'admin@digitelly.net');
        $password = Str::password(16, symbols: false);
        User::create(['name' => 'Admin', 'email' => $email, 'password' => $password]);

        $this->command?->newLine();
        $this->command?->warn('  First login. Copy this now; it is shown only once:');
        $this->command?->line("  Email:    {$email}");
        $this->command?->line("  Password: {$password}");
        $this->command?->newLine();
    }
}
