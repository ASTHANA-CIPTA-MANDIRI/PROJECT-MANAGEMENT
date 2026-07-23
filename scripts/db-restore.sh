#!/usr/bin/env bash
#
# Restore the database from a gzipped mysqldump. Defaults to the most recent
# pre-deploy backup, so it pairs with the production deploy/rollback workflows.
#
# Usage:  bash scripts/db-restore.sh [path-to.sql.gz]
#
set -euo pipefail

cd "$(dirname "$0")/.."

if [ -f .env ]; then
    set -a
    # shellcheck disable=SC2046
    export $(grep -E '^DB_' .env | sed 's/\r$//' | xargs -d '\n') 2>/dev/null || true
    set +a
fi

: "${DB_DATABASE:?DB_DATABASE is not set}"
: "${DB_USERNAME:?DB_USERNAME is not set}"

FILE="${1:-}"
if [ -z "${FILE}" ]; then
    # Most recent pre-deploy snapshot; fall back to any backup.
    FILE="$(ls -1t storage/backups/*pre-deploy*.sql.gz 2>/dev/null | head -1 || true)"
    [ -z "${FILE}" ] && FILE="$(ls -1t storage/backups/*.sql.gz 2>/dev/null | head -1 || true)"
fi

[ -z "${FILE}" ] && { echo "No backup file found in storage/backups." >&2; exit 1; }

echo "Restoring ${DB_DATABASE} from ${FILE}"
gunzip -c "${FILE}" | mysql \
    -h "${DB_HOST:-127.0.0.1}" -P "${DB_PORT:-3306}" \
    -u "${DB_USERNAME}" -p"${DB_PASSWORD:-}" \
    "${DB_DATABASE}"

echo "Restore complete."
