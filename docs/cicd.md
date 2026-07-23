# CI/CD Pipeline

All pipelines live in `.github/workflows`. Continuous integration runs on every
push/PR; deployment, backup and rollback are separate workflows you enable by
configuring GitHub **Environments** and **secrets**.

## Continuous Integration — `tests.yml`

Runs on push/PR to `main`, `master`, `dev`. Two jobs:

| Job | What it does |
|-----|--------------|
| **Code style (Pint)** | `vendor/bin/pint --test app tests` — fails on style violations |
| **Tests** | Full PHPUnit suite (SQLite in‑memory) + a **70% line coverage gate** |

> Enforce these as required status checks in *Settings → Branches* so nothing
> merges or deploys without passing.

Code style is applied with `pint.json` (Laravel preset). Fix locally with:

```bash
vendor/bin/pint app tests
```

## Deployment

Two workflows deploy over SSH. Both use a GitHub **Environment** so secrets are
scoped and production can require manual approval.

| Workflow | Trigger | Environment |
|----------|---------|-------------|
| `deploy-staging.yml` | push to `main` (or manual) | `staging` |
| `deploy-production.yml` | push a `v*` tag (or manual with a ref) | `production` |

Each run, on the server, does: `artisan down` → pull the ref →
`composer install --no-dev` → `npm ci && npm run build` → `migrate --force` →
cache config/routes/views → `queue:restart` → `artisan up`. The production
deploy also records the current release (`storage/app/PREVIOUS_RELEASE`) and
takes a **pre-deploy DB snapshot** for rollback.

### Required secrets (per environment)

| Secret | Meaning |
|--------|---------|
| `SSH_HOST` | Server hostname/IP |
| `SSH_USER` | SSH user (e.g. `deploy`) |
| `SSH_KEY` | Private key with access to the server |
| `SSH_PORT` | SSH port (optional, defaults to 22) |
| `DEPLOY_PATH` | App root on the server (e.g. `/var/www/rencanakan`) |

Set the environment variable `APP_URL` (Environment → Variables) to show the
deployed URL on the run.

### Setting up

1. *Settings → Environments* → create `staging` and `production`.
2. On `production`, add **Required reviewers** for manual approval.
3. Add the secrets above to each environment.
4. Ensure the server has git, PHP, Composer, Node and a running queue worker,
   and that the deploy user may run the commands.

## Database backup — `db-backup.yml`

Runs nightly (02:00 UTC) and on demand. SSHes in and runs
[`scripts/db-backup.sh`](../scripts/db-backup.sh):

- `mysqldump --single-transaction` → gzip into `storage/backups/`
- optional off‑site upload to S3 (set `BACKUP_S3_BUCKET` in `.env` + awscli)
- **retention**: keeps the 14 most recent local backups

Run manually on the server any time:

```bash
bash scripts/db-backup.sh manual
```

## Rollback — `rollback.yml`

Manual workflow (*Actions → Rollback (production) → Run workflow*) with inputs:

- **ref** — the release to roll back to; blank uses the recorded
  `PREVIOUS_RELEASE`.
- **restore_db** — if checked, restores the most recent **pre-deploy** snapshot
  via [`scripts/db-restore.sh`](../scripts/db-restore.sh).

It re-checks out the ref, reinstalls dependencies, rebuilds assets, optionally
restores the DB, re-caches and brings the app back up.

### Manual rollback (from the server)

```bash
cd /var/www/rencanakan
php artisan down
git checkout <previous-tag-or-sha>
composer install --no-dev --optimize-autoloader
npm ci && npm run build
bash scripts/db-restore.sh          # only if the release ran migrations
php artisan config:cache && php artisan route:cache && php artisan view:cache
php artisan queue:restart
php artisan up
```

> **Migrations & rollback.** Prefer restoring the pre-deploy DB snapshot over
> `migrate:rollback`, which is only safe if every migration has a correct
> `down()`. Write reversible migrations and avoid destructive changes in the
> same release as code that depends on them.

## Recommended flow

```
PR ──► CI (lint + tests + coverage) ──► merge to main
                                          │
                                          ▼
                                   Deploy (staging)
                                          │
                             tag vX.Y.Z ──┤
                                          ▼
                          Deploy (production)  ──(if broken)──►  Rollback
                                   (pre-deploy DB snapshot)
```
