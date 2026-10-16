# AGENTS.md: guide for AI agents / new developers

Read this first. It covers how to install, run and test ColdForge, how the code is organised, and the rules you must not break.
Deeper history and design decisions: `NOTES.md` (chronological). Deploy steps: `README.md`. Open work: `TODO.md`.

## What this is
Single-user cold-outreach dashboard: **upload leads → write template → schedule send → watch stats**.
Laravel 12 · Inertia 2 · React 18 + TypeScript · Tailwind 3 + shadcn/ui (new-york) · Framer Motion · Recharts · Monaco · MySQL/MariaDB · Redis queue (prod).

Intentionally lean. **Do not add:** multi-user/roles, drag-drop builder, A/B tests, drip sequences, forms, billing, public API (see Non-goals in `TODO.md`).

---

## Install (local, Windows + XAMPP or any MySQL/MariaDB)

Requirements: PHP 8.3 (`pdo_mysql mbstring intl curl xml zip bcmath fileinfo`), Composer 2, Node 20+, MySQL/MariaDB running on 127.0.0.1:3306.

```bash
# 1. databases (once). Only creates coldforge* DBs, touches nothing else
mysql -u root -h 127.0.0.1 -e "CREATE DATABASE IF NOT EXISTS coldforge CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; CREATE DATABASE IF NOT EXISTS coldforge_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

# 2. deps + env
composer install
npm install
cp .env.example .env
php artisan key:generate
```

Edit `.env` for **local** (`.env.example` is production-oriented):

```
APP_ENV=local
APP_DEBUG=true
APP_URL=http://localhost:8010      # must match the port you serve on (tracking links are signed against it)
DB_CONNECTION=mariadb
DB_DATABASE=coldforge
DB_USERNAME=root
DB_PASSWORD=
SESSION_DRIVER=file
SESSION_SECURE_COOKIE=false
SESSION_ENCRYPT=false
CACHE_STORE=file
QUEUE_CONNECTION=sync               # jobs run inline; no worker/Redis needed locally
```

```bash
# 3. schema + first user (prints admin email + random password ONCE, so copy it)
php artisan migrate --seed

# 4. build + run
npm run build
php artisan serve --port=8010       # open http://localhost:8010
```

Lost the password? `php artisan tinker --execute="App\Models\User::first()->update(['password' => 'new-pass-here'])"`

Hot reload while editing React: run `npm run dev` alongside `php artisan serve`.

---

## Verify before you hand off (all must pass)

```bash
php artisan test        # 17 feature tests on MySQL DB `coldforge_test` (see phpunit.xml). Brevo is HTTP-faked
npx tsc --noEmit        # TypeScript
npm run build           # production assets
```

Tests use `RefreshDatabase` on **`coldforge_test` only**; they never touch `coldforge`. MySQL must be running.
Never send real email from tests: always `Http::fake()` Brevo.

---

## Map of the code

```
app/
  Http/Controllers/
    Auth/LoginController.php   login (throttled 5/min), logout
    DashboardController.php    7-day stats, sparklines, 30-day chart
    ContactController.php      CRUD + allTags()
    CsvImportController.php    JSON: upload+preview → start → poll
    TemplateController.php     CRUD + quick "test" send
    CampaignController.php     wizard store (creates draft + campaign_sends), launch/pause/resume, live count
    SenderController.php       CRUD, toggle, test connection; secrets masked as ********
    SettingsController.php     profile, password, deliverability DNS checks
    TrackingController.php     public signed: open pixel, click proxy, unsubscribe
  Jobs/
    ImportContactsJob.php      upsert CSV in batches of 500, merges tags/custom fields
    DispatchDueCampaignsJob.php  every minute (routes/console.php): scheduled→running, tops up running
    ProcessCampaignJob.php     unique per campaign; moves ≤(daily_cap − sent today) queued → sending, reserves sender quota
    SendEmailJob.php           render + send; RateLimited('sender'); 429/5xx retry ×3 (10/60/300s); 4xx fail
  Services/
    EmailRenderer.php          {{vars}} → click-proxy links → unsubscribe footer + pixel
    Senders/SenderInterface.php, SenderResult.php
    Senders/SenderRegistry.php method(): 'api' (Brevo only) | 'smtp' (everything else / Brevo SMTP); make(); pick(strategy)
    Senders/BrevoSender.php    POST https://api.brevo.com/v3/smtp/email, GET /account for test
    Senders/SmtpSender.php     Symfony Mailer ESMTP
  Models/                      Contact, CsvImport, Template, Sender (config = encrypted:array), Campaign, CampaignSend, Event, Setting
config/coldforge.php           BREVO_API_KEY fallback, default cap, sender TPS, footer address
database/migrations/2026_10_16_000001_create_coldforge_tables.php   all app tables
database/seeders/DatabaseSeeder.php  admin user (random pw printed), Brevo sender, sample template, demo contacts (non-prod only)
database/samples/              sample_contacts.csv, sample_template.html
routes/web.php                 all routes (plain URLs, no Ziggy)
routes/console.php             scheduler
resources/js/
  app.tsx                      Inertia bootstrap
  Layouts/AppLayout.tsx        sidebar + sticky profile card, mobile hamburger drawer, header, toasts, <ConfirmHost/>
  Layouts/SettingsLayout.tsx   settings tabs
  Pages/                       Auth/Login, Dashboard, Contacts/{Index,Show}, Templates/{Index,Editor}, Campaigns/{Index,Create,Show}, Settings/{Senders,Profile,Deliverability}
  Components/ui/               shadcn primitives (added with `npx shadcn@2.3.0 add …`, Tailwind-3 compatible)
  Components/                  TiltCard, StatusBadge, Field, PageHeader, EmptyState, TagInput, Logo, MenuToggle,
                               ConfirmDialog (confirmDialog()), ImportDialog, ContactDialog, Pagination
  lib/utils.ts, lib/theme.ts   cn(), formatters, fillVars(); dark/light store
  types/index.d.ts             shared TS types
tests/Feature/ColdForgeTest.php    end-to-end pipeline
tests/Feature/ManagementTest.php   CRUD, settings, senders API/SMTP, security checks
```

## Conventions (match these)
- **Statuses are strings**, not DB enums (see model constants). Add a status = no migration.
- **Forms:** Inertia `useForm` + `<Field>` for inline errors under the input. Use toasts only for flash success/error, never for validation.
- **Deletes / destructive actions:** `confirmDialog({ title, description, onConfirm: visit((o) => router.delete(url, o)) })`. Never `window.confirm`.
- **Styling:** Tailwind tokens from `app.css`/`tailwind.config.js`. One accent: `bg-brand` (indigo→violet). Status colors only for status (`StatusBadge`). Glass surfaces: `.glass`. Icons: `lucide-react` only, no emoji.
- **Cards:** `TiltCard` for decorative cards (subtle tilt, no hover shadow); plain `Card` for anything interactive like charts/tables.
- **Secrets:** sender credentials live in `senders.config` (encrypted with APP_KEY) and are never sent to the browser.
- **PHP style:** Laravel defaults, `$request->validate([...])` inline in controllers, no Form Request classes, no repositories/services unless reused.
- **Keep it simple.** The owner explicitly asked for "simple, efficient, no over-engineering".

## Things that will bite you
- **APP_URL + APP_KEY sign every tracking link.** Changing either after sending breaks links in emails already sent. Locally, APP_URL must match the served port.
- **Laravel 11 is EOL with unpatched advisories.** We are on Laravel 12 on purpose. Don't downgrade.
- **Inertia must stay on v2** (`@inertiajs/react@^2`, `inertiajs/inertia-laravel:^2`). v3 requires React 19.
- **Tailwind must stay on v3.** For new shadcn components use `npx shadcn@2.3.0 add <name>` (latest shadcn targets Tailwind 4). After adding `sonner`, remove `next-themes` (we use `lib/theme.ts`).
- **Tag filtering** uses `LIKE '%"tag"%'` on the JSON column (portable, fine at single-user scale).
- **Quota is reserved** when a send is assigned (`sent_today++`) and released on failure or pause.
- Monaco loads from the jsDelivr CDN at runtime (`@monaco-editor/react` default).

## Production safety (VPS srv1810078.hstgr.cloud)
- Deploy only to `/var/www/coldforge`, DB `coldforge` / user `coldforge_user`, Redis DB index **2** with prefix `coldforge_`, nginx vhost `coldforge`, supervisor program `coldforge-worker`.
- **NEVER touch** `/var/www/ugcjungle*`, any ugcjungle nginx vhost, or any ugcjungle database. They share the server.
- Don't run `migrate:fresh` / `db:wipe` in production. Use `php artisan migrate --force` only.
- Full step-by-step: `README.md` → "Deploy to the Hostinger VPS".

## Git
- `main` is the deployable branch. Feature work goes on a branch → PR.
- Never commit `.env`, `vendor/`, `node_modules/`, `public/build/`, uploaded CSVs (`storage/app/private/imports`) or any API key.
- Append a block to `NOTES.md` after each logical change (format: `## [YYYY-MM-DD HH:MM] Step N — title`, what / decisions / next) and tick `TODO.md` items inline.
