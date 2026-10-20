# ColdForge build notes

Append-only log. Newest at the bottom.

> **New agent / developer?** Start with [AGENTS.md](AGENTS.md) (install, run, test, code map, conventions, production safety rules). Deploy steps: [README.md](README.md). Open work: [TODO.md](TODO.md).
>
> **Current state (latest):** feature-complete MVP. 17 tests pass on MariaDB. Runs locally at http://localhost:8010 (XAMPP MySQL, sync queue). Not yet verified: a real Brevo send (API or SMTP) and the Redis queue in production. Not yet deployed.

## [2026-10-16 05:45] Step 0 — Project kickoff
- Goal: lean single-user cold outreach app (leads → template → schedule → stats), replacing Mautic.
- Stack: Laravel + Inertia + React 18/TS + Tailwind 3 + shadcn/ui (new-york, neutral) + Framer Motion + Recharts + Monaco, MariaDB, Redis queue.
- Brief from the user: keep it simple and efficient, no over-engineering.
- Next: scaffold.

## [2026-10-16 05:55] Step 1 — Scaffold (no Breeze)
- `composer create-project laravel/laravel:^12`, then Inertia (`inertiajs/inertia-laravel:^2`) + `predis/predis`, wired by hand.
- Decisions:
  - **Laravel 12 instead of 11.** Laravel 11 is EOL and every 11.x release is blocked by Composer for unpatched advisories, two of which hit this app directly: signed-URL path confusion (tracking links) and CRLF injection in the email validation rule. 12 has the same app structure.
  - **No Breeze** (per user). Login, logout, middleware and layout are custom and minimal.
  - **Inertia v2, not v3.** v3 requires React 19, and the brief locks React 18.
  - **Tailwind 3** (the skeleton ships with v4): shadcn new-york + `tailwindcss-animate`, config in `tailwind.config.js`.
  - **predis** so the VPS doesn't need the phpredis extension.
- Next: design system + layout.

## [2026-10-16 06:00] Step 2–3 — Design system, layout, login
- Files: `tailwind.config.js`, `resources/css/app.css`, `components.json`, `Components/ui/*` (shadcn CLI 2.3.0, the last Tailwind-3 compatible version), `Layouts/AppLayout.tsx`, `Pages/Auth/Login.tsx`, `Components/{TiltCard,StatusBadge,Field,PageHeader,EmptyState,TagInput,Logo}.tsx`, `lib/theme.ts`.
- Decisions:
  - Palette: neutral plus one indigo→violet gradient (`bg-brand`). Status colors only for status.
  - `.glass` utility (translucent + 16px blur) over a radial-glow `.app-bg`.
  - `TiltCard`: ≤4° rotateX/Y following the cursor, using `transformPerspective` (no wrapper div, so grid classes still apply).
  - Primary button: gradient + glow shadow that lifts 1px on hover. Focus rings are 2px with offset.
  - Page transition: fade + 8px slide, 180ms ease-out, keyed on URL.
  - Theme: tiny `useSyncExternalStore` hook + inline pre-paint script in `app.blade.php` instead of `next-themes`. Dark by default.
  - Sidebar collapse state lives in localStorage. Below `md` the sidebar becomes a bottom nav.
  - Fonts self-hosted via `@fontsource-variable` (Inter, JetBrains Mono), so no Google Fonts request.

## [2026-10-16 06:00] Step 4 — Migrations + models
- `database/migrations/2026_10_16_000001_create_coldforge_tables.php`, models `Contact, CsvImport, Template, Sender, Campaign, CampaignSend, Event, Setting`.
- Decisions:
  - Status/type columns are short strings, not DB enums: portable across SQLite/MariaDB, and adding a status needs no migration.
  - Extra columns beyond the spec: `csv_imports.path/status/tags` (needed for background import + progress).
  - `events` also has type `failed` (the spec's SendEmailJob logs it).
  - Tag filtering uses `LIKE '%"tag"%'` on the JSON text. Works on MariaDB and SQLite and is fine at single-user scale.
  - `senders.config` uses the `encrypted:array` cast (APP_KEY).
  - `Template::variables` is auto-detected on save.

## [2026-10-16 06:05] Steps 5–8 — Controllers + pages
- Controllers: `Auth/LoginController, Dashboard, Contact, CsvImport, Template, Campaign, Sender, Settings, Tracking`.
- Pages: all 12 from the brief + `Layouts/SettingsLayout.tsx`, `Components/{ImportDialog,ContactDialog,Pagination}.tsx`.
- Decisions:
  - Plain URLs (no Ziggy).
  - CSV upload is parsed server-side (`fgetcsv`) for the preview, so quoting/BOM edge cases match the real import. Column mapping is auto-guessed from headers; unknown columns default to `custom:<slug>`.
  - Template preview renders client-side with sample values in a sandboxed iframe. Monaco loads from jsDelivr (default of `@monaco-editor/react`), which keeps the bundle small.
  - Wizard submit creates a **draft** with all `campaign_sends` rows. "Launch" on the Show page starts it.
  - Campaign Show polls with `router.reload({only})` every 5s while running/scheduled.
  - Sender secrets are never sent to the browser (masked as `********`; leaving it unchanged keeps the stored value).
  - "Quick send" on the dashboard = send a template to one address via the first active sender (no tracking, `[Test]` subject prefix).

## [2026-10-16 06:05] Steps 7, 9 — Senders + jobs
- `Services/Senders/{SenderInterface,SenderResult,SenderRegistry,BrevoSender,SmtpSender,TodoSender}.php`, `Jobs/{ImportContactsJob,ProcessCampaignJob,SendEmailJob,DispatchDueCampaignsJob}.php`, `routes/console.php`.
- Decisions:
  - Generic SMTP driver is real (Symfony Mailer), so MailerSend/Elastic/Mailjet already work over SMTP. Their native API drivers are one `TodoSender` stub that fails with a clear message.
  - Quota is **reserved** when a send is assigned to a sender (`sent_today++`) and released on failure or pause, so one batch can't overshoot a sender's daily quota.
  - `sent_today` resets lazily on first use each day (no extra cron).
  - Per-sender TPS: Laravel `RateLimited('sender')` job middleware (`Limit::perSecond`). Retries: `maxExceptions=3`, backoff 10/60/300s, `retryUntil` 6h so rate-limit releases don't burn attempts. 4xx = fail immediately.
  - `ProcessCampaignJob` is `ShouldBeUnique` per campaign. The scheduler tops up running campaigns every minute, which makes the daily cap roll over naturally.
  - If a campaign is paused while sends sit in the queue, `SendEmailJob` puts them back to `queued`.

## [2026-10-16 06:05] Step 10 — Tracking + post-processor
- `Services/EmailRenderer.php`, `TrackingController`, `views/unsubscribed.blade.php`.
- Decisions:
  - All three links are `URL::signedRoute` (signature in the query) and use the `signed` middleware. The click target is base64url in `u`, covered by the signature, so the click proxy isn't an open redirect.
  - Renderer: fill `{{vars}}` (HTML-escaped) → rewrite every `http(s)` `href` to the click proxy → append unsubscribe footer + 1×1 pixel before `</body>`. If the template already uses `{{unsubscribe_url}}`, no footer is added.
  - `List-Unsubscribe` + `List-Unsubscribe-Post: One-Click` headers. `POST /u/*` is CSRF-exempt for RFC 8058.
  - A click also marks the send opened (image-blocking clients).

## [2026-10-16 06:10] Steps 11–12 — Dashboard + deliverability
- Dashboard: Sent / Delivered / Open rate / Click rate for 7 days, each with a sparkline. 30-day area chart. Last 5 campaigns. Quick send. All from `campaign_sends` timestamps, grouped by `DATE()`.
- Deliverability: `dns_get_record` for SPF (checks it includes Brevo), DKIM (CNAME/TXT on brevo1, brevo2, google, default, mail, k1, mailjet, selector1/2, s1, mlsend), DMARC (warn on `p=none`). Each card has a fix hint. Last domain is stored in `settings`.

## [2026-10-16 06:15] Step 13 — Seeders + samples + tests
- `DatabaseSeeder`: admin user (`ADMIN_EMAIL`, random 16-char password **printed once in the console**, not written to any file), Brevo sender (blank key → falls back to `BREVO_API_KEY`), sample template. Demo contacts only when `APP_ENV != production`; they use `@example.com`.
- Samples: `database/samples/sample_contacts.csv`, `sample_template.html`.
- `tests/Feature/ColdForgeTest.php` (6 tests, 62 assertions, Brevo faked with `Http::fake`): login, every page renders, CSV import (12 ok / 1 invalid, tags + custom fields), draft → launch → sent with message_id → open pixel GIF → click 302 → unsubscribe → tampered signature 403, daily cap + pause, 4xx failure releases quota.
- Decision: the seeded password is printed instead of written to NOTES.md, so a credential never sits in a file that might get committed or copied around.

## [2026-10-16 06:20] Steps 14–15 — README, verification
- `README.md`: local setup, pipeline overview, full VPS deploy (DNS, MariaDB, nginx vhost snippet, certbot, Supervisor, cron). Every step only creates `coldforge`-named resources. ugcjungle stays untouched.
- `.env.example` is production-ready: MariaDB, Redis DB 2 + `coldforge_` prefix, secure + encrypted sessions.
- Verified locally: `migrate:fresh --seed` ✓, `php artisan test` 6/6 ✓, `tsc --noEmit` ✓, `npm run build` ✓, `config:cache`/`route:cache` ✓, `schedule:list` shows the job ✓. Clicked through in a browser: login, dashboard, contacts, template editor (Monaco + preview), wizard, senders. 375px mobile + light mode: no horizontal scroll, no console errors.
- Not verified: a real Brevo send (needs a real key) and running against MariaDB/Redis (only SQLite + sync queue locally).
- Next: deploy per README. Open items are in TODO.md.

## [2026-10-16 06:45] Step 16 — Switch local dev + tests to MySQL
- Local `.env` now uses XAMPP's MariaDB 10.4 (`coldforge` DB, root, no password). `phpunit.xml` uses a separate `coldforge_test` DB. The old SQLite file was removed.
- Added `tests/Feature/ManagementTest.php` (10 tests): contact/template/sender CRUD + validation, sender secret encrypted at rest + masked in HTML + kept on a blank update, quota daily reset + priority pick, quick send, profile/password, schedule-later → scheduler flips to running, login throttle, click proxy rejects `javascript:` URLs, deliverability domain validation.
- Verified on MariaDB: `migrate:fresh --seed` ✓, `php artisan test` **16 passed / 136 assertions** ✓. Browser: signed in, ran the wizard (live count 3 for tag `founders`), and the draft was created with 3 queued `campaign_sends` rows in MySQL ✓.
- Decision: tests run on the same engine as production (MariaDB) rather than SQLite, so JSON/LIKE/DATE() behavior matches. Trade-off: XAMPP MySQL must be running to run tests.

## [2026-10-16 07:30] Step 17 — UI polish round + SMTP for every provider
- Cards: removed the hover lift and shadow from `TiltCard` (the subtle tilt stays). The "Sends per day" chart is now a static `Card`, and Recharts' click-focus outline is disabled (`accessibilityLayer={false}` + CSS guard in `app.css`).
- Deletes: new `Components/ConfirmDialog.tsx`. `confirmDialog({ title, description, onConfirm })` opens one shared modal, rendered once by `AppLayout` via `<ConfirmHost/>`. It shows a spinner until the Inertia request finishes. It replaced all 5 `window.confirm()` calls (contacts ×2, templates, campaigns, senders).
- Sidebar: sticky profile card at the bottom (avatar, name, email). It opens a menu: My profile / Settings / Log out. When collapsed, only the avatar shows. Removed the avatar from the header (one place for account actions).
- Mobile: dropped the bottom nav. New `Components/MenuToggle.tsx` is a two-bar (long + short) hamburger that springs into an X. It opens a left drawer (shadcn Sheet, blurred overlay) with the same nav + profile card. The drawer closes on navigation.
- Senders: `config.method` = `api` | `smtp`. Brevo can use either (SMTP preset `smtp-relay.brevo.com:587`). MailerSend / Elastic / Mailjet always use SMTP, with prefilled hosts (`smtp.mailersend.net`, `smtp.elasticemail.com:2525`, `in-v3.mailjet.com`). Only the keys for the chosen method are stored. SMTP needs host/port/login/password (the password is kept on edit if left masked). `SenderRegistry::method()` decides the method; `TodoSender` was removed as dead code. Each card shows an API/SMTP badge and host:port.
- Tests: +1 (`test_brevo_can_connect_over_smtp_or_api`) → **17 passed / 151 assertions** on MariaDB. `tsc` ✓, `npm run build` ✓. Browser-checked: profile menu, mobile drawer, delete modal, SMTP form.

## [2026-10-16 16:06] Step 18 — Handoff docs + GitHub
- Added `AGENTS.md` (single source for any agent/dev: exact install for XAMPP/MySQL, local `.env` overrides, verify commands, code map, conventions, gotchas, production safety rules) and `CLAUDE.md` (imports AGENTS.md for Claude Code).
- Added a "start here" pointer + current-state summary at the top of this file.
- Put the project under git and pushed it to a **private** GitHub repo `rotcodes/coldforge` (branch `main`). `.env`, `vendor/`, `node_modules/`, `public/build/` and uploaded CSVs are ignored; scanned for keys/passwords before the first commit.
- Decision: private repo, because it holds production hostnames, the deploy layout and the email pipeline. No secrets are committed either way.
- Next: deploy per README → add a real Brevo key/SMTP → Settings → Senders → Test → Quick send to own inbox → first small campaign.

## [2026-10-20 Phase 2] Smart personalization — industry-aware templates & 29-column CSV ingestion

Big feature add: templates now resolve 3 "smart" variables per contact (industry_label / workflow_examples / tracking_examples) against an editable Industries table, with operator-controlled fallbacks for when data is missing. Imports auto-detect the Pure Intelligence / US CRM 29-column format, store all the lead-level intel columns in dedicated fields, fuzzy-match raw industry text to a preset slug, and skip rows without an email or already-replied-to.

Branch: `feature/smart-personalization` → PR to `main` after local verification.

### Why
Operator wanted one global template whose first line adapts to each lead's industry (equipment dealer / wholesale / warehouse / machining ...) so sends feel personal at scale. Their existing CSVs from Notion already carry 29 columns of per-lead research (industry, pain evidence, recommended offer, Notion URL, ...) that was being thrown away. New system captures all of it, surfaces readiness tiers so the operator can see at a glance how personalized any send will be, and degrades gracefully for future CSVs that have less data.

### What (data model)
- `industries` table: `slug / label / workflows / tracking / aliases(json) / is_active`. Seeded with 10 presets from `config/coldforge.php` (equipment_dealer, steel_fabrication, wholesale_distribution, warehouse, pos_retail, engineering_services, machining, construction, auto_parts, hvac).
- `contacts` extended with 24 new nullable columns:
  - **Rich**: `business, industry, industry_slug, city_state, website, decision_maker, dm_role, direct_phone, main_phone, company_linkedin, dm_linkedin`
  - **Source tracking**: `source_system, external_id, remote_status, remote_reply_status, lead_score, confidence, notion_url, other_emails` + `UNIQUE(source_system, external_id)` for re-import dedup
  - **Lead-level personalization**: `personalized_opener, pain_evidence, recommended_offer, custom_subject, why_now_trigger`
- `settings` key `variable_fallbacks` (JSON) stores operator-editable fallback strings per smart variable.

### What (services)
- `App\Services\IndustryMatcher` — resolves free-text industry to slug via alias exact match + longest-substring match. Request-cached.
- `App\Services\CsvHeaderMapper` — guesses the target field for ~45 known CSV headers (plus a `detectFormat()` that recognises the Pure Intel / US CRM signature).
- `App\Services\TemplateFallbacks` — thin reader/writer for the `variable_fallbacks` setting with sane defaults.
- `Contact::templateVars()` now returns 20+ keys, including the 3 smart industry vars (resolved against `Industry` row + fallback chain) and the lead-level vars.
- `Contact::matchingFilter()` extended to accept `industry_slugs[], source_system, lead_score_min, require_industry` on top of `tags[]`.
- `Contact::readinessTier()` returns 0..4 ladder used by UI badges and the campaign readiness check.

### What (controllers + routes)
- New: `IndustryController` (index + CRUD + `rematch` + `assignUnmapped`), `FallbacksController` (index + update), `CampaignController::preview` (per-contact live render for the editor + wizard).
- `CsvImportController::store` now returns `{suggested, detected_format, empty_email_rows}`.
- `ImportContactsJob` handles the new columns, runs fuzzy-matcher at import time, and auto-marks contacts whose remote status is SENT/CONTACTED/REPLIED (or has any Reply Status value) as `status=unsubscribed`. Re-imports preserve a user's local status (never unsubs to active). Rows without an email count as failed.
- `DashboardController` reports a readiness tier breakdown + unmapped-industry count.

### What (UI)
- New top-level sidebar entry: **Industries**. Full CRUD page with per-industry contact counts, an "unmapped text" dialog for bulk-assigning raw strings, and a one-click "Re-match unmapped" button.
- New Settings tab: **Fallbacks**. One row per smart variable with inline reset-to-default.
- Contacts/Index: tier badge column, legend, and a "More filters" bar (industry / source / lead score / has-email).
- Contact/Show: Tabs — Basic / Lead intel / Pitch — with LinkedIn/Notion links and tier badge next to status.
- ContactDialog: 3-tab form (Basic / Lead intel / Personalization) with the industry preset dropdown.
- ImportDialog: shows a green "detected format" chip, auto-fills the mapping, warns about empty-email rows, and groups the target field dropdown by purpose (Essential / Industry / Contact / Lead intel / Personalization).
- Templates/Editor: right-side **Variables** panel grouped by purpose (click-to-insert), plus a "Preview with real contact" button that renders the template against a random live contact and reports which variables used a fallback.
- Campaigns/Create: new **Readiness** step (step 3 of 4) that shows the tier breakdown as horizontal bars + a live preview iframe + the "Require mapped industry" toggle.
- Dashboard: new **Audience readiness** TiltCard tile with tier bars and an amber "unmapped contacts" link to Industries when any exist.
- `Components/TierBadge.tsx` + `TierLegend` export for consistent tier chips everywhere.

### What (tests)
`tests/Feature/PersonalizationTest.php` — 10 tests covering:
- Industries seed, IndustryMatcher (exact + substring), CsvHeaderMapper (recognition + field guess).
- `Contact::templateVars()` resolves industry preset AND falls back to saved fallback text.
- `readinessTier()` ladder (0..4).
- `matchingFilter()` honours each new filter dimension.
- End-to-end import of a Pure Intel-shaped CSV: auto-mapping suggestions, auto-match of `Construction equipment` to `equipment_dealer`, auto-unsub when Reply Status is filled, empty-email skip counted correctly.
- Rendering with null industry contains fallback text, no raw `{{` placeholders in the output.
- Industries CRUD endpoints work and clear the matcher cache.
- Fallbacks round-trip through the settings API.

### Operator must do (first run after `git pull`)
1. `php artisan migrate` — adds the 24 contacts columns + `industries` table.
2. `php artisan db:seed` — seeds the 10 default industries (idempotent — safe on existing DB).
3. `npm install && npm run build` (nothing new in deps, but `public/build/` needs refresh).
4. Open **Industries** in the sidebar → tweak phrases if desired. Open **Settings → Fallbacks** → set the default `workflow_examples` / `tracking_examples` for leads without industry (defaults are generic enough).
5. Re-import a Pure Intel CSV via **Contacts → Import CSV** — mapping is now auto-filled; just click Import. Industry text matches a slug automatically; whatever doesn't match shows up in the amber "unmapped" banner on the Industries page.
6. Edit your main template to use `{{industry_label}}`, `{{workflow_examples}}`, `{{tracking_examples}}` instead of hard-coded phrases. Preview with a real contact via the "Preview with real contact" button in the editor.
7. Create a campaign — the new Readiness step shows how many contacts fall into each tier before you launch. Toggle "Require mapped industry" to force strict personalization or allow fallbacks.

### Decisions worth remembering
- **Industries table over config file.** The operator will tweak phrasing often; stashing it in DB (with a UI) beats editing `config/coldforge.php` and clearing caches each time. Seeder is idempotent so defaults can be re-applied.
- **Industry fuzzy-match is additive.** Raw text is always preserved in the `industry` column; `industry_slug` is set only on an unambiguous match. Operator can bulk-assign the rest from the Industries page.
- **"Require industry" is per-campaign, defaults ON.** This is the strict/fallback knob. ON = skip unmapped contacts (full personalization enforced). OFF = fallback text used from Settings → Fallbacks. The dashboard tile + wizard preview make this reversible without surprise.
- **Re-imports preserve a contact's local status.** If someone was unsubscribed locally, a fresh CSV won't quietly reactivate them. Remote status (SENT / CONTACTED / REPLIED / any Reply Status) still auto-unsubs on first import.
- **Backwards compatible.** Existing templates with `{{first_name}}` keep working. Existing contacts without the new fields keep working (fallbacks everywhere). Nothing was renamed or dropped.
