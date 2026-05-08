#!/bin/bash
# BOSS888 Daily Backup Script
# Cron: 0 1 * * * /root/daily-backup.sh
# Local: 7-day rotation in /root/backups/
# Offsite: s3://boss888-backups/db-backups/ — 30-day rotation (when /root/.s3cfg present)

BACKUP_DIR="/root/backups"
DATE=$(date +%Y%m%d-%H%M)
DB_BACKUP="${BACKUP_DIR}/db-${DATE}.sql"
KEEP_DAYS_LOCAL=7
KEEP_OFFSITE=30
S3_PREFIX="s3://boss888-backups/db-backups"
LOG=/var/log/daily-backup.log

mkdir -p "${BACKUP_DIR}"

# Source DB credentials from .env so we don't bake them into this script.
ENV_FILE="/var/www/levelup-staging/.env"
DB_USER=$(grep '^DB_USERNAME=' "${ENV_FILE}" 2>/dev/null | cut -d= -f2- | tr -d '"' )
DB_PASS=$(grep '^DB_PASSWORD=' "${ENV_FILE}" 2>/dev/null | cut -d= -f2- | tr -d '"' )
DB_NAME=$(grep '^DB_DATABASE=' "${ENV_FILE}" 2>/dev/null | cut -d= -f2- | tr -d '"' )

if [ -z "${DB_USER}" ] || [ -z "${DB_PASS}" ] || [ -z "${DB_NAME}" ]; then
  echo "[$(date)] ERROR: Could not read DB credentials from ${ENV_FILE}" >> "${LOG}"
  exit 1
fi

mysqldump --no-tablespaces --single-transaction --quick \
  -u "${DB_USER}" -p"${DB_PASS}" \
  "${DB_NAME}" > "${DB_BACKUP}" 2>/dev/null

if [ $? -eq 0 ] && [ -s "${DB_BACKUP}" ]; then
  gzip "${DB_BACKUP}"
  SIZE=$(du -sh "${DB_BACKUP}.gz" | cut -f1)
  echo "[$(date)] DB backup: ${DB_BACKUP}.gz (${SIZE})" >> "${LOG}"
else
  echo "[$(date)] ERROR: DB backup failed for ${DB_NAME}" >> "${LOG}"
  rm -f "${DB_BACKUP}"
  exit 1
fi

# ── Local rotation: keep last N days ──────────────────────────────────────
find "${BACKUP_DIR}" -name 'db-*.sql.gz' -mtime +${KEEP_DAYS_LOCAL} -delete
KEPT=$(ls "${BACKUP_DIR}" 2>/dev/null | wc -l)
echo "[$(date)] Local rotation complete. Files kept: ${KEPT}" >> "${LOG}"

# ── Offsite push: DigitalOcean Spaces (s3cmd) ────────────────────────────
if [ -f /root/.s3cfg ] && command -v s3cmd >/dev/null 2>&1; then
  REMOTE="${S3_PREFIX}/$(basename ${DB_BACKUP}.gz)"
  if s3cmd put "${DB_BACKUP}.gz" "${REMOTE}" --no-progress >> "${LOG}" 2>&1; then
    echo "[$(date)] Offsite upload: ${REMOTE}" >> "${LOG}"
  else
    echo "[$(date)] WARNING: Offsite upload failed (see s3cmd output above)" >> "${LOG}"
  fi

  # ── Offsite rotation: keep last N most-recent objects ────────────────
  # s3cmd ls output format:  YYYY-MM-DD HH:MM <size> <s3-uri>
  REMOVE_LIST=$(s3cmd ls "${S3_PREFIX}/" 2>/dev/null \
                | awk '{print $4}' \
                | sort \
                | head -n "-${KEEP_OFFSITE}")
  if [ -n "${REMOVE_LIST}" ]; then
    echo "${REMOVE_LIST}" | while read -r OLD; do
      [ -n "${OLD}" ] && s3cmd del "${OLD}" >> "${LOG}" 2>&1 \
        && echo "[$(date)] Offsite rotated: ${OLD}" >> "${LOG}"
    done
  fi
else
  echo "[$(date)] Offsite skipped: /root/.s3cfg not configured" >> "${LOG}"
fi
