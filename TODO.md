# TODO

## MVP (Phase 1)
- [x] Laravel 12 + Inertia 2 + React 18/TS + Tailwind 3 + shadcn/ui scaffold (no Breeze)
- [x] Single-user login (throttled), seeded admin with random password
- [x] Glass/depth design system, dark default + light toggle, sidebar → hamburger drawer on mobile
- [x] Migrations + models for all tables
- [x] Contacts: list, search, filter, add/edit/delete, detail page
- [x] CSV import: drag-drop, preview, column mapping, background job, progress polling
- [x] Templates: Monaco editor, live preview (300ms debounce), variable detection + insert
- [x] Senders: Brevo via API key **or** SMTP, other providers via SMTP (prefilled hosts), encrypted config, test button, quota bar
- [x] Custom confirm modal for all deletes, sidebar profile card, mobile hamburger drawer
- [x] Campaign wizard (template → audience → schedule), draft → launch / pause / resume
- [x] Send pipeline: DispatchDueCampaigns → ProcessCampaign → SendEmail, per-sender rate limit, retry/backoff
- [x] Tracking: signed open pixel, click proxy, unsubscribe (GET + RFC 8058 one-click POST)
- [x] Dashboard: 4 stat cards with sparklines, 30-day chart, recent campaigns, quick send
- [x] Deliverability: SPF / DKIM / DMARC checker with fix hints
- [x] Feature tests with faked Brevo
- [x] README with deploy steps, NOTES.md

## Pending / nice to have
- [ ] Brevo webhook endpoint (bounces, spam complaints, so contacts get `bounced` / `complained` automatically)
- [ ] Native API drivers for MailerSend, Elastic Email, Mailjet (they connect over SMTP with prefilled hosts today)
- [ ] Bulk actions on contacts (tag / delete / export selected)
- [ ] CSV export of contacts and campaign results
- [ ] Spread sends across the day (random delay between sends) instead of sending each day's cap at once
- [ ] Prune old `events` rows (e.g. `model:prune` after 180 days)
- [ ] Bundle Monaco locally instead of loading it from the jsDelivr CDN

## Phase 2 (out of scope for now)
- [ ] Follow-up / drip sequences (stop on reply)
- [ ] Reply detection via IMAP
- [ ] A/B subject tests
