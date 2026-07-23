#!/usr/bin/env bash
#
# Database backup: mysqldump -> gzip into storage/backups, with optional upload
# to S3 and local retention. Reads DB credentials from the app's .env.
#
# Usage:  bash scripts/db-backup.sh [label]
#   label  optional tag for the file name (e.g. "scheduled", "pre-deploy")
#
set -euo pipefail

LABEL="${1:-scheduled}"

# Move to the application root (this script lives in scripts/).
cd "$(dirname "$0")/.."

# Load DB_* (and optional BACKUP_S3_BUCKET) from .env.
if [ -f .env ]; then
    set -a
    # shellcheck disable=SC2046
    export $(grep -E '^(DB_|BACKUP_S3_BUCKET)' .env | sed 's/\r$//' | xargs -d '\n') 2>/dev/null || true
    set +a
fi

: "${DB_DATABASE:?DB_DATABASE is not set}"
: "${DB_USERNAME:?DB_USERNAME is not set}"

BACKUP_DIR="storage/backups"
mkdir -p "$BACKUP_DIR"

STAMP="$(date +%Y-%m-%d_%H%M%S)"
FILE="${BACKUP_DIR}/${DB_DATABASE}_${LABEL}_${STAMP}.sql.gz"

echo "Backing up ${DB_DATABASE} -> ${FILE}"
mysqldump \
    --single-transaction --quick --no-tablespaces \
    -h "${DB_HOST:-127.0.0.1}" -P "${DB_PORT:-3306}" \
    -u "${DB_USERNAME}" -p"${DB_PASSWORD:-}" \
    "${DB_DATABASE}" | gzip > "${FILE}"

echo "Backup written: ${FILE} ($(du -h "${FILE}" | cut -f1))"

# Off-site copy to S3 when a bucket is configured (needs awscli + credentials).
if [ -n "${BACKUP_S3_BUCKET:-}" ]; then
    aws s3 cp "${FILE}" "s3://${BACKUP_S3_BUCKET}/db-backups/$(basename "${FILE}")"
    echo "Uploaded to s3://${BACKUP_S3_BUCKET}/db-backups/"
fi

# Retention: keep the 14 most recent local backups.
# shellcheck disable=SC2012
ls -1t "${BACKUP_DIR}"/*.sql.gz 2>/dev/null | tail -n +15 | xargs -r rm --
echo "Retention applied (kept 14 most recent)."
