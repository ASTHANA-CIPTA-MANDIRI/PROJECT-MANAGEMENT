# Docker

The application ships a `Dockerfile` that produces a self-contained image: nginx
and PHP-FPM serving the app on port 8000, plus a queue worker and the task
scheduler, all supervised by `supervisord` and running as the unprivileged
`www-data` user.

There is **no published image**. The image has to be built from this
repository. The Docker Hub image belonging to the upstream project
(`eloufirhatim/helper`) is built from a different codebase and is not kept in
step with this one — do not use it.

## Build and run the image

Everything is wired up in `docker-compose.yml`, which builds the image and
starts a MySQL container alongside it:

```bash
docker compose up -d --build
```

The application is then on <http://localhost:8000>.

To build the image on its own:

```bash
docker build -t helper:local .
```

## Configuration

Configuration reaches the container as environment variables, which take
precedence over the `.env` shipped inside the image. `docker-compose.yml` sets
the database and mail variables; adjust them there.

**`APP_KEY` is required and has no default.** The image deliberately does not
contain one: a key baked in at build time would be shared by every installation
built from that image, and anyone holding it could forge session cookies and
signed URLs. The container refuses to start without it.

Generate a key once, and keep it — changing it invalidates every session and
every encrypted value in the database:

```bash
docker run --rm helper:local php artisan key:generate --show
```

Put the result in the `.env` file next to `docker-compose.yml`:

```dotenv
APP_KEY=base64:...
```

Compose reads that file, so `docker compose up` picks it up automatically. It
will refuse to start with an explicit error if the variable is missing.

**`DB_PASSWORD` / `DB_ROOT_PASSWORD` default to `helper`**, same as before,
but can now be overridden the same way as `APP_KEY` — add them to the `.env`
file next to `docker-compose.yml`:

```dotenv
DB_PASSWORD=a-stronger-password
DB_ROOT_PASSWORD=a-different-stronger-password
```

The MySQL container is not published on a host port (only reachable over the
Compose network), so the shipped default is low-risk out of the box; change
it before exposing the database beyond this Compose network.

## Seeding a fresh installation

The container runs `php artisan migrate` on every start, so schema changes are
applied automatically. It does **not** seed: running the seeders on every start
would re-insert the default administrator and the lookup tables over an existing
database.

Seed once, by hand, right after the first `docker compose up`:

```bash
docker compose exec helper php artisan db:seed
```

## What runs inside the container

| Process | Role |
| --- | --- |
| `nginx` | Serves `public/` on port 8000 and passes PHP to FPM over a unix socket |
| `php-fpm` | Runs the application |
| `php artisan queue:work` | Processes queued jobs — notifications, mail |
| `php artisan schedule:work` | Runs `app/Console/Kernel.php`'s scheduled commands (`reports:daily`, `tickets:due-date-reminders`, `cleanup:old-activities`) — there is no separate cron |

`supervisord` starts all four and restarts any that exit. Their output goes to
the container's stdout/stderr, so `docker compose logs -f helper` shows
everything.

Front-end assets are compiled during the build (`npm run build`), so the running
container needs neither Node nor a build step at start-up.

## Verification

`.github/workflows/docker-build.yml` builds the image on every change to the
`Dockerfile`, `run.sh`, `docker/`, or the dependency lock files, then checks the
nginx and PHP-FPM configuration, boots Laravel inside the image, and starts it
against a real MySQL container to confirm it answers on port 8000.
