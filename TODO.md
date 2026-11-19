# ColdForge 2.0 status

## Implemented

- [x] One encrypted Hostinger SMTP/IMAP mailbox with separate connection tests
- [x] Full-folder historical and incremental IMAP synchronization with UID checkpoints
- [x] Three-panel mail workspace with search, threads, read/star/archive/trash actions
- [x] Personal compose, reply, forward, attachments, drafts, scheduled mail, retry/cancel
- [x] Shared outbound engine for personal, test, campaign, and sequence mail
- [x] Central idempotency, daily allowance, rate limiting, retry, uncertainty, and pause controls
- [x] Explicit contact permission state, physical-address requirement, suppression list, one-click unsubscribe
- [x] Campaign privacy switches for open/click tracking
- [x] Reusable business-day follow-up sequences that stop on replies or ineligibility
- [x] Central Groq client and opt-in, human-review-only mail summary/classification/reply drafts
- [x] Neutral dark visual foundation and operational overview metrics
- [x] Brevo, multi-sender, webhook, and legacy read-only inbox code removed
- [x] Secure on-demand attachment downloads with size limits and forced download headers
- [x] Edit/reschedule/cancel outgoing drafts and scheduled messages
- [x] Campaign recipient/follow-up detail, campaign cancellation, and per-contact sequence controls
- [x] Deliverability center includes MX, mailbox health, suppression records, and sending errors
- [x] Feature tests with all external delivery/AI calls faked
- [x] Agentic ColdForge Intelligence with bounded read tools, request history, prompt-injection resistance, and explicit editable draft creation
- [x] Smart mail actions for summaries, classification confidence/reasoning, action items, next actions, and tone/length rewrites

## Remaining refinements

- [x] Add fail-closed ClamAV scanning and safe previews before downloaded attachments can be opened
- [x] Add multi-select bulk actions to the mail list
- [x] Add contact, campaign-result, and suppression CSV exports
- [x] Add analytics and activity-log pages to the primary navigation
- [x] Parse delivery-status notification emails and explicit reply opt-outs into bounce/suppression events
- [x] Add configurable data-retention pruning for synchronized bodies and activity logs
- [x] Bundle Monaco locally instead of loading it from jsDelivr

Live-environment acceptance remains operator-owned: connect the real Hostinger mailbox, run both connection tests, configure ClamAV, and send a deliberately small consented test after reviewing recipients.
