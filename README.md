# ColdForge

A single-operator business email and permission-based outreach workspace: **sync Hostinger mail → manage conversations → schedule messages → run safeguarded campaigns and follow-ups.**

Laravel 12 · Inertia 2 · React 18 + TypeScript · Tailwind 3 + shadcn/ui · Framer Motion · MariaDB · Redis queue.

---

## Local development

Requirements: PHP 8.3 (pdo_sqlite or pdo_mysql, mbstring, intl, curl), Composer 2, Node 20+.

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
```

Local dev uses MySQL/MariaDB (e.g. XAMPP). Create the two databases once:

```sql
CREATE DATABASE coldforge      CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE coldforge_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;  -- used by the tests
```

Then edit `.env` for local:

```
APP_ENV=local
APP_DEBUG=true
APP_URL=http://127.0.0.1:8000
DB_CONNECTION=mariadb
DB_DATABASE=coldforge
DB_USERNAME=root            # XAMPP default
DB_PASSWORD=
SESSION_DRIVER=file
SESSION_SECURE_COOKIE=false
CACHE_STORE=file
QUEUE_CONNECTION=sync       # jobs run inline, no worker or Redis needed
```

Then:

```bash
php artisan migrate --seed   # prints the admin email + password ONCE, copy it
npm run build                # or `npm run dev` for hot reload
php artisan serve
```

Open http://127.0.0.1:8000 and sign in. Outside production the seeder also adds 12 demo contacts (`@example.com`) and a sample template. Demo contacts start with unknown campaign permission and cannot be mailed until explicitly reviewed.

Open **Settings → Mailbox** and save the Hostinger email/password, SMTP, IMAP, daily allowance, physical mailing address, signatures, and privacy controls. Test SMTP and IMAP separately before use. Codex processing is opt-in and requires an authenticated Codex CLI on the application host.

Tests run against the `coldforge_test` MySQL database (root, no password, set in `phpunit.xml`). SMTP, IMAP, and Codex are faked or bypassed, so nothing leaves the machine:

```bash
php artisan test
```

- `tests/Feature/ColdForgeTest.php`: unified delivery idempotency, signatures, privacy-aware tracking, sequence reply stops, Codex schema validation, and Intelligence action approval
- `tests/Feature/ManagementTest.php`: workspace rendering, encrypted mailbox credentials, explicit recipient permission, launch safeguards, and durable suppression

Sample CSV for the importer: `database/samples/sample_contacts.csv`.

---

## How sending works

1. **Settings → Mailbox** stores one encrypted Hostinger mailbox used by every outbound feature and full IMAP synchronization.
2. **Campaigns → New** selects a template, optional follow-up sequence, explicitly eligible contacts, schedule, and daily cap. This creates a draft plus one `campaign_sends` row per recipient.
3. **Launch** requires configured credentials, a physical address, active automation, and administrator approval. It starts immediately or at the approved time.
4. `SendEmailJob` renders and hands the durable message to `SendMailMessageJob`, the shared Hostinger SMTP engine used by personal, scheduled, test, campaign, and sequence email. It has idempotency, central allowance checks, rate limiting, retry/backoff, uncertainty handling, and Sent-folder reconciliation.
5. The scheduler starts due campaigns/messages, dispatches eligible sequence steps, and synchronizes IMAP every minute. Follow-ups re-check reply, suppression, campaign, and permission state immediately before delivery.

Tracking URLs are Laravel signed URLs (signature in the query string):

| What | Route |
|---|---|
| Open pixel | `GET /t/o/{send}?signature=…` → 1×1 GIF |
| Click | `GET /t/c/{send}?u=<base64url>&signature=…` → 302 |
| Unsubscribe | `GET/POST /u/{contact}?s={send}&signature=…` (POST = RFC 8058 one-click) |

> Signed URLs are tied to `APP_URL` and `APP_KEY`. Changing either after a campaign goes out breaks links in emails already sent.

---

## Codex operator access on the VPS

The official standalone Codex CLI is installed for the production SSH user at `~/.local/bin/codex`. It is authenticated with the owner's ChatGPT account; `~/.codex/auth.json` is a credential and must remain private (`0600`), uncommitted, and absent from logs or support messages.

Start the project-scoped interactive agent after SSH login:

```bash
cd /home/u105894521/coldforge-app
./scripts/coldforge-agent
```

The launcher defaults to `gpt-6.2-sol`, the workspace-write sandbox, and on-request approvals. Select a different available model for one session with an interactive `/model` command or by setting `CODEX_MODEL` before launch.

- Never run Codex from cron with `--yolo`, `danger-full-access`, or approval bypasses.
- Cron remains for deterministic Laravel scheduler and queue commands. Add a Codex cron task only for a separately documented, bounded workflow with fixed inputs and outputs.
- Do not expose the CLI, its app server, or its authentication cache to the public internet.
- Treat synchronized email and imported content as untrusted data, never as agent instructions.
- External sends and destructive mutations must retain ColdForge's eligibility checks, suppression checks, audit trail, and explicit confirmation.

`gpt-6.2-sol` and `gpt-6.2-astra` were verified on the connected ChatGPT plan on 2026-11-20.

### Intelligence permission modes

The authenticated `/intelligence` workspace uses Codex as its reasoning provider and exposes ColdForge capabilities through a validated action registry:

- **Read only** — inspect and summarize mail, contacts, campaigns, follow-ups, templates, pipeline work, analytics, deliverability, and system health; no database mutation.
- **Ask approval** — create proposed actions, then wait for the administrator to confirm or reject each one.
- **Operator** — automatically execute low-risk actions such as creating an editable draft. Medium/high-risk actions still wait for confirmation.

Current actions cover contact creation/update, new/reply draft creation, and confirmation-gated delivery queueing. Every action is persisted with risk, payload, status, result, and errors; successful execution is added to Activity. External sending, permission changes, deletion, bulk mutation, campaign launch, credentials, and infrastructure must never become silent auto-actions.

The Codex subprocess itself always runs ephemeral, read-only, and with shell approvals disabled. It reasons and proposes; Laravel validates and executes through ColdForge services. Never change the web bridge to `danger-full-access` or expose generic SQL/shell execution as an Intelligence tool.

---

## Deploy to the Hostinger VPS

Current production is Hostinger-managed shared hosting:

- application checkout: `/home/u105894521/coldforge-app`
- public document root: `/home/u105894521/domains/digitelly.net/public_html/mail`
- site: **https://mail.digitelly.net**
- database queue and Laravel scheduler: invoked by hPanel cron jobs

The owner confirmed on 2026-11-20 that UGC Jungle is not hosted on this VPS. Keep every command scoped to the verified ColdForge checkout, public root, database, and hPanel jobs; do not inspect or modify unrelated Hostinger sites or accounts.

> The root-managed `/var/www`, Nginx, Supervisor, and system-package examples below describe a dedicated VPS layout and are retained as a reference only. They do not match the current Hostinger shared-hosting deployment. Current production updates use the paths above, preserve the server `.env`, build in the application checkout, and atomically replace the copied public assets.

### Current shared-hosting update flow

Build and test locally first. On production, use a backup-first, fast-forward-only update, never `migrate:fresh` or `db:wipe`:

```bash
cd /home/u105894521/coldforge-app
php artisan down --retry=60
git pull --ff-only
composer install --no-dev --optimize-autoloader
npm ci
npm run build
php artisan migrate --force
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

Copy the newly built `public/build` into the verified public document root using a staged/atomic replacement, normalize public directories to `0755` and files to `0644`, then run `php artisan up`. Preserve the shared-hosting public root's customized `index.php`, which points to `/home/u105894521/coldforge-app`; Laravel's stock relative-path `public/index.php` will make the live site return HTTP 500 from this separate document root. Preserve `.htaccess` unless a reviewed routing change explicitly requires replacing it. Verify HTTP, the deployed manifest checksum, migrations, queue drain, scheduler activity, and recent Laravel logs before removing the temporary previous build. The exact backup-first deployment evidence is recorded chronologically in `NOTES.md`.

The hPanel jobs must continue to run the Laravel scheduler and short-lived database queue worker every minute. They are managed in hPanel and therefore do not appear in `crontab -l` over SSH.

ClamAV is not currently available in the shared-hosting account. Attachment access remains fail-closed until Hostinger enables `clamdscan` or a separately reviewed scanning service is integrated. Never set `ATTACHMENT_SCAN_BYPASS=true` in production.

### Legacy dedicated-VPS reference

### 1. DNS
Create an `A` record `mail` → `2.25.79.80` at your DNS provider. Wait until `dig +short mail.digitelly.net` returns the IP.

### 2. Upload the code
```bash
sudo mkdir -p /var/www/coldforge
sudo chown $USER:www-data /var/www/coldforge
# from your machine (exclude local-only stuff):
rsync -av --exclude node_modules --exclude vendor --exclude .env --exclude 'database/*.sqlite' \
  ./coldforge/ user@srv1810078.hstgr.cloud:/var/www/coldforge/
```

### 3. Database (new DB + user only)
```bash
sudo mariadb
```
```sql
CREATE DATABASE coldforge CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'coldforge_user'@'localhost' IDENTIFIED BY 'CHANGE_ME_STRONG_PASSWORD';
GRANT ALL PRIVILEGES ON coldforge.* TO 'coldforge_user'@'localhost';
FLUSH PRIVILEGES;
```

### 4. Install
```bash
cd /var/www/coldforge
cp .env.example .env
nano .env                       # DB credentials, APP_URL, queue/cache, Codex paths, CLAMAV_BINARY
composer install --no-dev --optimize-autoloader
php artisan key:generate
npm ci && npm run build         # or build locally and upload public/build
php artisan migrate --seed --force    # copy the printed admin password!
php artisan config:cache && php artisan route:cache && php artisan view:cache

sudo chown -R www-data:www-data storage bootstrap/cache
sudo chmod -R 775 storage bootstrap/cache
```

PHP extensions needed: `pdo_mysql mbstring intl curl xml zip bcmath fileinfo`. Redis uses the bundled `predis` client, so `php-redis` is optional.

Install ClamAV before attachment access is enabled in production. ColdForge fails closed if scanning is unavailable:

```bash
sudo apt update && sudo apt install -y clamav clamav-daemon
sudo systemctl enable --now clamav-daemon
sudo freshclam || true
# in .env (confirm the path with: command -v clamdscan)
CLAMAV_BINARY=/usr/bin/clamdscan
ATTACHMENT_SCAN_BYPASS=false
```

After changing `.env`, run `php artisan config:cache`. Never enable `ATTACHMENT_SCAN_BYPASS` in production.

### 5. Nginx vhost
`/etc/nginx/sites-available/coldforge`:

```nginx
server {
    listen 80;
    server_name mail.digitelly.net;
    root /var/www/coldforge/public;
    index index.php;

    client_max_body_size 25M;   # CSV uploads (app allows 20 MB)

    add_header X-Frame-Options "SAMEORIGIN";
    add_header X-Content-Type-Options "nosniff";

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_hide_header X-Powered-By;
    }

    location ~ /\.(?!well-known).* { deny all; }

    location /build/ {
        expires 1y;
        add_header Cache-Control "public, immutable";
    }
}
```

```bash
sudo ln -s /etc/nginx/sites-available/coldforge /etc/nginx/sites-enabled/coldforge
sudo nginx -t && sudo systemctl reload nginx
```

### 6. SSL
```bash
sudo certbot --nginx -d mail.digitelly.net
```
Certbot edits only this vhost and adds the 443 block plus an HTTP→HTTPS redirect.

### 7. Queue worker (Supervisor)
`/etc/supervisor/conf.d/coldforge-worker.conf`:

```ini
[program:coldforge-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/coldforge/artisan queue:work redis --tries=3 --sleep=3 --max-time=3600
directory=/var/www/coldforge
user=www-data
numprocs=2
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
stopwaitsecs=60
redirect_stderr=true
stdout_logfile=/var/www/coldforge/storage/logs/worker.log
```

```bash
sudo supervisorctl reread && sudo supervisorctl update && sudo supervisorctl status coldforge-worker:*
```

### 8. Scheduler (cron)
```bash
sudo crontab -u www-data -e
```
```
* * * * * cd /var/www/coldforge && php artisan schedule:run >> /dev/null 2>&1
```

### 9. Smoke test
- https://mail.digitelly.net shows the login. Sign in with the seeded password, then change it in **Settings → Profile**.
- **Settings → Mailbox** should show successful SMTP and IMAP connection tests.
- **Settings → Deliverability**: check `digitelly.net`. Fix SPF/DKIM/DMARC before sending.
- Dashboard **Quick send** to your own inbox.

### Updating later
```bash
cd /var/www/coldforge
git pull   # or rsync again
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan migrate --force
php artisan optimize
sudo supervisorctl restart coldforge-worker:*
```
