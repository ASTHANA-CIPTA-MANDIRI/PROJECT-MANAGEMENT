#!/bin/bash
#
# Container entrypoint. Prepares the schema, then hands PID 1 to supervisord,
# which runs nginx, PHP-FPM and the queue worker.

set -euo pipefail

cd /app

# APP_KEY is not baked into the image on purpose. A key generated at build
# time is a key that every pull of the image shares, and anyone holding it can
# forge session cookies and signed URLs against any installation that never
# replaced it. It has to be supplied per installation instead.
if [ -z "${APP_KEY:-}" ] && ! grep -qE '^APP_KEY=.+' .env 2>/dev/null; then
    cat >&2 <<'EOF'
FATAL: APP_KEY is not set.

Generate one key per installation and keep it stable — changing it invalidates
every session and every encrypted column:

    docker run --rm <image> php artisan key:generate --show

Then pass it to the container, e.g. `-e APP_KEY=base64:...` or by mounting an
.env that contains it.
EOF
    exit 1
fi

# The database container may still be coming up on a cold `docker compose up`.
for attempt in $(seq 1 30); do
    if php artisan migrate --force --no-interaction; then
        break
    fi

    if [ "$attempt" -eq 30 ]; then
        echo "FATAL: database still unreachable after 30 attempts." >&2
        exit 1
    fi

    echo "Database not ready yet (attempt ${attempt}/30); retrying in 2s..." >&2
    sleep 2
done

# Schema migrations run on every start; seeding does not. `db:seed` here would
# re-run the default-admin and lookup seeders against an existing database on
# every restart. Seed a fresh installation once, by hand:
#
#     docker compose exec <service> php artisan db:seed

php artisan optimize:clear

exec /usr/bin/supervisord -c /etc/supervisor/supervisord.conf
