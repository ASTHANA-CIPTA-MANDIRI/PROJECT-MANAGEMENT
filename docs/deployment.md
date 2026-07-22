# Deployment & Setup Guide

This guide covers running Rencanakan locally and deploying it to a server.
For Docker specifically, see [docker.md](docker.md); for a quick first install
see [installation.md](installation.md).

## Requirements

| Component | Version |
|-----------|---------|
| PHP | 8.1+ (developed on 8.2) with `iconv`, `mbstring`, `pdo_mysql`, `gd`, `zip`, `bcmath`, `intl`, `fileinfo`, `exif` |
| Composer | 2.x |
| Node.js | 16+ (for building assets with Vite) |
| MySQL | 8+ |
| A cron daemon | for the scheduler (production) |

## Local setup

```bash
# 1. Install dependencies
composer install
npm install

# 2. Environment
cp .env.example .env
php artisan key:generate

# 3. Configure the database in .env (DB_DATABASE, DB_USERNAME, DB_PASSWORD),
#    then run migrations + seeders
php artisan migrate --seed

# 4. Build front-end assets (required — pages reference the Vite manifest)
npm run build      # or: npm run dev   (for hot reload during development)

# 5. Serve
php artisan serve --port=8000
```

Open <http://localhost:8000>. The first seeded user is assigned the default
role; sign in and change the credentials.

> **`APP_URL` must match how you access the app** (e.g. `http://localhost:8000`).
> OAuth callback URLs are derived from it — a mismatch causes
> `redirect_uri_mismatch`.

## Key environment variables

| Variable | Purpose |
|----------|---------|
| `APP_URL` | Base URL; OAuth callbacks are built from it |
| `DB_*` | MySQL connection |
| `QUEUE_CONNECTION` | `database` (prod) or `sync` (simple/dev) |
| `MAIL_*` | SMTP for verification & notification emails |
| `GOOGLE_CLIENT_ID/SECRET`, `GITHUB_ID/SECRET` | Social login (optional) |
| `SENTRY_LARAVEL_DSN` | Error tracking; **empty = Sentry disabled** |
| `SENTRY_TRACES_SAMPLE_RATE` | Performance monitoring sample rate (0–1) |
| `INTERNAL_IP_WHITELIST` | IPs/CIDRs allowed on `internal` routes (fail‑closed) |

## Production deployment

```bash
# On the server, after pulling the code:
composer install --no-dev --optimize-autoloader
npm ci && npm run build

php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

### 1. Queue worker

Queued notifications (ticket events, daily summary) and the Jira import job need
a worker. Run it under a process manager (systemd / Supervisor):

```ini
# /etc/supervisor/conf.d/rencanakan-worker.conf
[program:rencanakan-worker]
command=php /var/www/rencanakan/artisan queue:work --sleep=3 --tries=3 --max-time=3600
autostart=true
autorestart=true
numprocs=1
user=www-data
redirect_stderr=true
stdout_logfile=/var/www/rencanakan/storage/logs/worker.log
```

> With `QUEUE_CONNECTION=sync` no worker is needed, but requests block while
> emails send. Use the database queue + a worker in production.

### 2. Scheduler (cron)

The daily report and activity cleanup are scheduled in `App\Console\Kernel`.
Add **one** cron entry so Laravel's scheduler runs every minute:

```cron
* * * * * cd /var/www/rencanakan && php artisan schedule:run >> /dev/null 2>&1
```

This drives:

- `reports:daily` — every day at 07:00
- `cleanup:old-activities --days=90` — weekly (Mon 02:00)

### 3. Web server

Point the document root at `public/`. Example Nginx:

```nginx
server {
    listen 80;
    server_name rencanakan.example.com;
    root /var/www/rencanakan/public;

    index index.php;
    location / { try_files $uri $uri/ /index.php?$query_string; }
    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }
}
```

## Post‑deploy checklist

- [ ] `php artisan migrate --force` ran cleanly
- [ ] `npm run build` produced `public/build/manifest.json`
- [ ] Queue worker is running (or `QUEUE_CONNECTION=sync`)
- [ ] Cron entry for `schedule:run` is installed
- [ ] `APP_URL` matches the public URL; `APP_DEBUG=false`
- [ ] Mail sends (test with a real registration)
- [ ] `SENTRY_LARAVEL_DSN` set if you want error tracking
- [ ] OAuth redirect URIs registered with Google/GitHub match `APP_URL`

## Running the test suite

Tests run against an **in‑memory SQLite** database (configured in `phpunit.xml`),
so they never touch your MySQL data:

```bash
vendor/bin/phpunit                 # full suite
vendor/bin/phpunit --testsuite Unit
```

CI runs the same suite on every push/PR (`.github/workflows/tests.yml`) and
fails if line coverage drops below 70%.
