# CI/CD Pipeline

All pipelines live in `.github/workflows`. Continuous integration runs on every
push/PR; deployment, backup and rollback are separate workflows you enable by
configuring GitHub **Environments** and **secrets**.

## Continuous Integration — `tests.yml`

Runs on every branch push and on PRs to `main`, `master`, `dev`. Two jobs:

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

### Failing safely

All three SSH workflows (staging deploy, production deploy, rollback) wrap their
release steps in a subshell with `trap 'php artisan up' EXIT` around them. Under
a plain `set -e` a failing `composer install`, `npm run build` or
`migrate --force` aborted the script before the final `artisan up`, leaving the
site stuck on the maintenance page until someone SSHed in.

The two deploys also **restore the previous commit** when a step fails
(reinstall dependencies, rebuild assets, re-cache) and then exit non-zero so the
run shows up red. The rollback workflow is itself the fallback, so it only lifts
maintenance mode and reports loudly.

The scripts run under the deploy user's login shell, which may be `dash` — keep
them POSIX, with no `set -o pipefail` or other bashisms. `DeployWorkflowTest`
syntax-checks each one and rejects bashisms.

### Production: CI gate and automatic recovery

A `v*` tag triggers the production workflow directly, and CI does not run on
tags — so `deploy-production.yml` opens with a **`ci-gate` job** that looks up
the `tests.yml` run for the exact commit being deployed and fails unless it
concluded `success`. The deploy job `needs: ci-gate`, so a tag on a commit with
a red or never-run suite never reaches the server. For an emergency, run the
workflow manually with **skip_ci_check** ticked; a pushed tag can never bypass
the gate.

When a production release fails it checks the code back out at
`PREVIOUS_RELEASE` (see *Failing safely* above), but the **database is
deliberately left alone**: restoring it is destructive, so if migrations ran
partially, follow up with the rollback workflow below and `restore_db` ticked.

Staging is **not** gated on CI — finding out that `main` is broken is what
staging is for. It still restores the previous commit on a failed deploy, but it
takes no pre-deploy snapshot, so its database is never rolled back.

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
restores the DB, re-caches and brings the app back up. A blank **ref** with no
recorded `PREVIOUS_RELEASE` fails *before* the site is taken down, so a mistyped
run cannot cause the outage it was meant to fix.

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
                                    ci-gate (CI green for this commit?)
                                          │
                                          ▼
                          Deploy (production)  ──(step fails)──►  auto-restore
                                   (pre-deploy DB snapshot)        previous code
                                          │                        + artisan up
                                          └────(if broken)──►  Rollback (+ DB)
```
