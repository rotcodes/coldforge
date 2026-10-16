# ColdForge

A lean cold-outreach dashboard for one operator: **upload leads → write template → schedule send → watch stats.**

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

Open http://127.0.0.1:8000 and sign in. Outside production the seeder also adds 12 demo contacts (`@example.com`), a sample template and a Brevo sender with no key.

To actually send, put a Brevo API key in `BREVO_API_KEY` (or on the sender in Settings → Senders).

Tests run against the `coldforge_test` MySQL database (root, no password, set in `phpunit.xml`). Brevo is faked, so nothing leaves the machine:

```bash
php artisan test
```

- `tests/Feature/ColdForgeTest.php`: login, every page renders, CSV import, campaign → Brevo send → open/click/unsubscribe tracking, daily cap, pause, failures
- `tests/Feature/ManagementTest.php`: contact/template/sender CRUD + validation, secret masking, quota reset, quick send, profile/password, scheduling, login throttle, click-proxy safety

Sample CSV for the importer: `database/samples/sample_contacts.csv`.

---

## How sending works

1. **Campaigns → New**: pick a template, filter contacts by tag, set the daily cap. This creates a **draft** plus one `campaign_sends` row per matching active contact.
2. **Launch** sets the campaign to `running` (or `scheduled` if it has a future start time) and dispatches `ProcessCampaignJob`.
3. `ProcessCampaignJob` moves up to *(daily cap − sent today)* queued sends to `sending`, picks a sender per send (round robin = least used today, priority = lowest number first) and dispatches `SendEmailJob`.
4. `SendEmailJob` renders the template for the contact, rewrites links to the click tracker, adds the open pixel and unsubscribe footer, and calls the sender. Senders connect by API key (Brevo) or SMTP (any provider). It is rate limited per sender (`COLDFORGE_SENDER_TPS`, default 10/s). 429/5xx/network errors retry 3× with backoff (10s, 60s, 5m); other errors fail right away.
5. The scheduler runs `DispatchDueCampaignsJob` every minute: it starts due scheduled campaigns and tops up every running one. That's how the daily cap rolls over to the next day.

Tracking URLs are Laravel signed URLs (signature in the query string):

| What | Route |
|---|---|
| Open pixel | `GET /t/o/{send}?signature=…` → 1×1 GIF |
| Click | `GET /t/c/{send}?u=<base64url>&signature=…` → 302 |
| Unsubscribe | `GET/POST /u/{contact}?s={send}&signature=…` (POST = RFC 8058 one-click) |

> Signed URLs are tied to `APP_URL` and `APP_KEY`. Changing either after a campaign goes out breaks links in emails already sent.

---

## Deploy to the Hostinger VPS

Target: `srv1810078.hstgr.cloud`, subdomain **mail.digitelly.net** → A record `2.25.79.80`.

> ⚠️ Do **not** touch `/var/www/ugcjungle*`, any ugcjungle nginx vhost, or any ugcjungle database. Every step below only creates *new* things named `coldforge`.

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
nano .env                       # DB_PASSWORD, BREVO_API_KEY, COLDFORGE_FOOTER_ADDRESS
composer install --no-dev --optimize-autoloader
php artisan key:generate
npm ci && npm run build         # or build locally and upload public/build
php artisan migrate --seed --force    # copy the printed admin password!
php artisan config:cache && php artisan route:cache && php artisan view:cache

sudo chown -R www-data:www-data storage bootstrap/cache
sudo chmod -R 775 storage bootstrap/cache
```

PHP extensions needed: `pdo_mysql mbstring intl curl xml zip bcmath`. Redis uses the bundled `predis` client, so `php-redis` is optional.

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
- **Settings → Senders → Test** on the Brevo sender should say "connection OK".
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
