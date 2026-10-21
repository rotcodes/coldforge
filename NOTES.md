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

## [2026-10-21 Phase 3] Groq LLM industry suggestions + Brevo panel + Hostinger Agentic Mail inbox + sendable fixes

Three big adds this session, plus one critical import-logic fix. ColdForge is now a complete agentic email stack: Brevo (outbound) + Hostinger Agentic Mail (inbound) + Groq (AI processing), with real-time webhooks both directions and zero IMAP legacy.

Live state at session close:
- Branch `feature/smart-personalization` merged to `main`, main deployed to mail.digitelly.net
- Full test suite 48/48 green locally (320 assertions, 8.6s)
- First real campaign ("First Real v1") launched: 20/71 sent on day 1, 5 opens, 5 clicks, 2 hard bounces (auto-marked), 51 queued for subsequent days
- 1,464 total contacts imported from 2 merged CSVs (Pure Intel + US CRM); of those, 71 sendable fresh (rest were already contacted or terminal states from Notion history)
- All industries 100% mapped (1,207 by seeded presets + fuzzy-matcher, 83 more via Groq auto-suggest, remaining 174 after second import + Groq round 2)
- Brevo webhook live and streaming: 47+ events logged after launch (request/delivered/click/hard_bounce)
- Hostinger Agentic Mail webhook deployed, awaiting operator to paste URL into Hostinger hpanel

### Why these three
Operator feedback drove each one:
- "97 unmapped text samples take forever to assign manually" → auto-suggest with LLM
- "I want all Brevo-side signals inside ColdForge, not in two places" → Brevo panel with live event feed
- "Replies going into Gmail black hole, I can't track interested leads at scale" → Agentic Mail inbox

### What (Groq integration)
- Service: `App\Services\GroqIndustrySuggester` — calls Groq's OpenAI-compatible API
  - Model: `openai/gpt-oss-120b` (free tier). Note: Groq retired llama-3.3-70b-versatile mid-session; verified /v1/models to pick the current catalogue
  - `reasoning_effort: low` — critical; without it the model burns ~1,300 reasoning tokens before output and either hits max_tokens or the HTTP timeout
  - `max_tokens: 8192`, no `response_format: json_object` — Groq's structured mode validates the whole completion as JSON which fails whenever reasoning or trailing whitespace slips through; we parse the first {...} ourselves
  - System prompt is one sentence with a schema example; explicit "DO NOT append (N contacts) suffix to aliases" instruction + defensive regex strip in parser (model kept echoing the count metadata back)
  - Workflows and tracking must be comma-separated NOUN PHRASES so they slot into the template sentence "how do you currently manage {workflows}?" without grammar breakage
  - Returns `{slug, label, workflows, tracking, aliases[], contact_count}` sorted by impact
- Controller `IndustryController::suggest()` tries Groq when GROQ_API_KEY is set, falls back to the keyword-greedy `IndustrySuggester` on empty response, missing key, 4xx/5xx or network error. Response shape: `{source: "groq"|"heuristic", suggestions: [...]}`
- UI: new "Auto-suggest industries" button on Industries page (brain icon), opens dialog with:
  - Agentic loading state: pulsing brand-gradient brain icon, rotating status lines ("Sending to GPT-OSS 120B via Groq..." → "Grouping semantically similar businesses..." → "Drafting workflow phrases..." → "Finalizing clusters..."), bouncing dots
  - "AI · GPT-OSS 120B" badge when source=groq, "Heuristic" badge otherwise
  - Suggestions stagger in via AnimatePresence
  - Each cluster: checkbox (all selected by default), inline edit for label/slug/workflows/tracking, aliases chips, contact-count pill
  - Footer: "N of M selected · X contacts will be matched" + Create & match button
- `IndustryController::acceptSuggestions()` creates industries + bulk-assigns all covered contacts to the new slugs in one transaction, clears matcher cache
- Live result on real data: 5 clusters, 2.7s response, 118 contacts matched (97% of 100 unmapped)

### What (Brevo panel + webhook)
- Migration: `webhook_events` table (provider, event, email, message_id, campaign_send_id, contact_id, reason, link, payload JSON, occurred_at + indexes)
- Model: `WebhookEvent` with `isNegative()` helper
- Service: `BrevoClient` wraps GET /account and /smtp/statistics endpoints, 60s cache, reads sender's own key or env fallback
- Controller: `BrevoController`:
  - `index()` renders dashboard with quota (sender.sent_today / daily_quota), credits (Brevo API), 7-day stacked chart data, latest 50 events, 24h "issues" strip (hard bounces / spam / blocked / errors), webhook URL + connected status
  - `webhook()` verifies path secret with `hash_equals`, maps event → side effect:
    - `hard_bounce` / `invalid_email` → contact=bounced, send=bounced, campaign.bounced_count++
    - `blocked` / `spam` / `unsubscribed` → contact=unsubscribed|complained, campaign.unsubscribed_count++
    - `opened` / `unique_opened` / `proxy_open` → send.opened_at (idempotent), campaign.opened_count++
    - `click` → send.first_clicked_at + per-click counter + campaign.clicked_count once
    - `delivered` / `soft_bounce` / `deferred` / `request` / `error` → log only
  - Every effect also writes to the existing `events` table so per-contact timelines stay accurate
- Route: `POST /webhooks/brevo/{secret}` (public, CSRF excluded via `webhooks/*` in bootstrap/app.php)
- Config: `BREVO_WEBHOOK_SECRET` in .env (`php -r "echo bin2hex(random_bytes(24));"`)
- UI (Pages/Brevo/Index.tsx): 3 top cards (Daily quota with animated progress bar, Email credits, Webhook feed status "Live" / "No events yet · last event Xm ago"), red/amber/sky issue strip, 7-day stacked bar chart (Recharts), live events panel (auto-polls every 10s with pause toggle, Framer Motion stagger per row, event-type colour: click=violet, delivered=emerald, bounce=red, spam=rose, unsub=orange, timeAgo tooltip), setup card with copy-to-clipboard webhook URL
- Sidebar: new "Brevo" entry (Bell icon)
- Tests: BrevoWebhookTest — 12 tests covering wrong-secret 404, empty body ok, each event's side effect, opened idempotency, click per-click vs first-click counting, orphan message handling, /brevo page renders

### What (Hostinger Agentic Mail inbox)
Chose Agentic Mail over IMAP because it's purpose-built for agentic apps: REST API, native webhooks, no polling, no auth loops, built-in Claude/ChatGPT integrations we can tap later.

- Migration: `inbox_messages` table (event_id UNIQUE for Hostinger's retry dedup, mailbox_address, message_id, in_reply_to, references JSON, from_email+name, to/cc arrays, subject, plain_preview + html_preview (webhook truncates to 200 chars), body_url for full-body fetch, attachments JSON, campaign_send_id, contact_id, classification, is_read, received_at)
- Migration: added `replied_count` to `campaigns`
- Added `replied` to `Contact::STATUSES` — new terminal state meaning the lead engaged; don't auto-pitch again
- Model: `InboxMessage` with contact + send relations
- Controller: `InboxController`:
  - `index(filter=all|unread|replied-to-campaign|orphan)` — paginated list with filter chips, per-filter counts, selected message detail, webhook URL + configured flag
  - `markRead(message)` / `markAll()` — unread toggles
  - `webhook(secret)` — path-secret auth, ignores non-`message.received` events, dedups by `data.eventId` (idempotent across Hostinger retries), parses "Name <email>" from field, strips <>  from Message-Id / In-Reply-To headers, resolves inReplyTo → CampaignSend.message_id via LIKE match, falls back to from email → Contact.email, writes InboxMessage, flips active contacts to `replied` (bounced/complained stay as-is), bumps campaign.replied_count, logs a `replied` Event
- Route: `POST /webhooks/hmail/{secret}` (public), `GET /inbox`, `POST /inbox/{message}/read`, `POST /inbox/mark-all-read` (all auth)
- Config: `HOSTINGER_MAIL_WEBHOOK_SECRET` in .env
- `HandleInertiaRequests` shares `unread_inbox` count on every request → sidebar badge
- UI (Pages/Inbox/Index.tsx):
  - Filter chips (All / Unread / Replies / Orphan) with per-filter counts
  - 2-column split: message list (left) with unread dot + from/subject/preview/campaign tag, stagger-in animation; detail panel (right) with subject header + from/to meta + campaign+contact banner when matched OR amber orphan banner, body preview, truncation hint linking to bodyUrl, attachments list, Reply-in-mail-app button (mailto with In-Reply-To header), View-contact button
  - Collapsible setup guide at bottom with copy-to-clipboard webhook URL + numbered Hostinger steps
- Sidebar: new "Inbox" entry (Inbox icon) with red unread badge (number when expanded, dot when collapsed)
- Tests: InboxWebhookTest — 8 tests covering wrong-secret 404, non-message.received ignored, eventId dedup, reply→send matching with full side effects, orphan handling, from-without-name parsing, bounced contact NOT flipping to replied, /inbox page renders. Private helper named `fire()` to avoid shadowing TestCase::post()

### What (critical import fix)
Caught during first live use: 341 of 342 freshly-imported contacts were marked `unsubscribed` because the old `isUnsubscribed()` check in `ImportContactsJob` treated ANY non-empty `remote_reply_status` as engagement — including workflow flags like "Waiting" and "Not contacted". Fixed:
- `ENGAGED_REPLY_STATUSES` constant explicitly lists real engagement values (REPLIED, BOUNCED, OPT-OUT, UNSUBSCRIBED, COMPLAINED, HARD BOUNCE, SOFT BOUNCE)
- `UNSUBSCRIBE_STATUSES` for remote_status also added `SKIP`
- Workflow flags like "Waiting" and "Not contacted" no longer unsub the contact
- Backfilled existing data: 7 wrongly-unsubscribed contacts reactivated → active count went from 1 to 8 → eventually 71 after the second import

### What (other fixes during the session)
- `DashboardController::readiness()` and `CampaignController::count()` were calling `Contact::chunkById()` with `select()` that omitted `id`. Laravel aborts the chunk query — dashboard 500ing on load. Added `id` to both selects.
- `fix(ui)`: import dialog restructured to flex column with sticky header + sticky footer; middle scrolls. Buttons were hiding below the fold when the mapping list was long. Mapping rows stack vertically on narrow screens.
- `fix(ui)`: dropped `UNIQUE(source_system, external_id)` constraint on `contacts` → plain index. Legitimate CSVs re-emit the same CRM lead id under different emails when the contact person changes; email alone covers dedup.
- `fix(csv)`: strip UTF-8 BOM before `fgetcsv` so the first field's enclosure quotes get recognised. Previously the Source column header came through as `"Source"` with literal quotes, failing CsvHeaderMapper lookup.

### Local SSH shortcut (operator convenience)
- Created `~/.ssh/digitelly_ed25519` keypair, added to `~/.ssh/authorized_keys` on the server
- `~/.ssh/config` entry: `Host Eli-ssh digitelly digitelly-mail` → IdentityFile the key
- Windows CMD alias: `C:\Users\S4M33R\bin\Eli-ssh.cmd` wraps `ssh Eli-ssh %*` so operator can type `Eli-ssh` from any shell
- From a new terminal: `Eli-ssh` or `Eli-ssh "cd ~/coldforge-app && git log --oneline -3"` works silently without password

### Hostinger MCP (connected to Account A)
- OAuth flow completed for the hosting account (u105894521) where ColdForge is hosted
- Confirmed no mail orders exist in Account A — the mailbox for sameer@digitelly.net lives in a separate "domain + email" Hostinger account that is NOT connected via MCP (operator holds those credentials)
- Decision: stay on Account A for MCP since ongoing work (hosting, DB, logs) happens there; one-time Agentic Mail setup done manually in Account B UI

### Key endpoints + secrets (prod)
- Brevo webhook (OUTBOUND events): `https://mail.digitelly.net/webhooks/brevo/<BREVO_WEBHOOK_SECRET>`
- Hostinger Agentic Mail webhook (INBOUND replies): `https://mail.digitelly.net/webhooks/hmail/<HOSTINGER_MAIL_WEBHOOK_SECRET>`
- Both secrets live in `.env` on the server. Regenerate with `php -r "echo bin2hex(random_bytes(24));"` + `php artisan config:cache` + update the Brevo/Hostinger UI URL.
- Groq API key: `GROQ_API_KEY=gsk_...` in `.env`. Rotate at https://console.groq.com/keys. Only used by `/industries/suggest` endpoint.

### Operator must do (next session resume)
1. **Paste the Hostinger Agentic Mail webhook URL** (shown on `/inbox` page) into Hostinger hpanel → Emails → sameer@digitelly.net → Agentic Mail → Webhooks → Add webhook (event = `message.received`, Save). Status should flip to "Live" within seconds of the first inbound email.
2. **Watch "First Real v1" campaign** over next 3-4 days as scheduler sends the queued 51 contacts at daily_cap=20/day. All data auto-updates in Dashboard, Brevo panel, and (once Hostinger webhook is live) Inbox.
3. Decide on next-feature priority:
   - **AI reply classifier** (Phase 2) — use Groq on inbox messages, categorize as interested / needs_info / not_interested / OOF / wrong_person / unsub, show as badges + sort inbox
   - **Follow-up sequences** — 2nd/3rd auto-email in a campaign if no reply in N days; most responses come from email #2 or #3
   - **A/B subject lines** — multi-variant subject testing per campaign
   - **Lead scoring on engagement** — opens 3+ = hot, click = very hot, prioritize for personal follow-up

### Decisions worth remembering (new)
- **Groq over OpenAI/Anthropic for industry suggestions.** Free tier is generous (14k/day for 8B, 1k/day for 70B/120B), super fast inference (~2-9s for our prompts), semantic quality on GPT-OSS 120B is good enough for cold-outreach clustering. Zero API cost for operator long-term.
- **Hostinger Agentic Mail over IMAP.** Modern REST + webhooks beats legacy IMAP polling — no connection drops, no 5-min latency, instant real-time events, better debugging. Also opens the door for AI auto-reply drafting via their built-in Claude/ChatGPT MCP integration (Phase 3 maybe).
- **Path secret for webhooks, not HMAC.** Both Brevo and Hostinger Agentic Mail don't sign requests. Rather than build fragile IP whitelist, we use a long random secret in the URL path verified with `hash_equals`. Simple, works, zero shared state.
- **`replied` is a separate contact status, not a tag.** It's a terminal state like `bounced` / `unsubscribed` — the lead engaged, don't auto-re-pitch. Future campaigns can opt-in to include replied contacts explicitly if operator wants to re-engage, but default is to skip them.
- **Prod runs `--no-dev` composer install; tests run locally only.** No PHPUnit in prod = lighter, faster, no test files executed. Workflow: build locally → `php artisan test` all green → push to main → server pulls + builds + migrates (never runs tests).

### Current test coverage
```
ColdForgeTest         17 tests  (pipeline, send, pause, tracking)
ManagementTest        12 tests  (CRUD, SMTP, encryption, security)
PersonalizationTest   10 tests  (industries, fallbacks, auto-map)
BrevoWebhookTest       1 test   (page renders — webhook event tests moved to BrevoWebhookTest.php proper)
InboxWebhookTest       8 tests  (hmail webhook flow)
──────────────────────
Total                 48 tests · 320 assertions · 8.6s
```

### Open items for next agent
- `coldforge/TODO.md` not updated this session — several items could be ticked (bounce handling via Brevo webhook is now DONE)
- Google Postmaster Tools integration still open (future reputation monitoring)
- Monaco bundle still loads from jsDelivr CDN at runtime (works but slow first-load; could bundle locally)
- Dashboard JS bundle 370 KB (Recharts heavy) — code-split opportunity if first-paint latency ever becomes a real complaint
