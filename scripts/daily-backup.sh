#!/bin/bash
# BOSS888 Daily Backup Script
# Cron: 0 1 * * * /root/daily-backup.sh
# Keeps 7 days of compressed DB dumps in /root/backups/.

BACKUP_DIR="/root/backups"
DATE=$(date +%Y%m%d-%H%M)
DB_BACKUP="${BACKUP_DIR}/db-${DATE}.sql"
KEEP_DAYS=7

mkdir -p "${BACKUP_DIR}"

# Source DB credentials from .env so we don't bake them into this script.
ENV_FILE="/var/www/levelup-staging/.env"
DB_USER=$(grep '^DB_USERNAME=' "${ENV_FILE}" 2>/dev/null | cut -d= -f2- | tr -d '"' )
DB_PASS=$(grep '^DB_PASSWORD=' "${ENV_FILE}" 2>/dev/null | cut -d= -f2- | tr -d '"' )
DB_NAME=$(grep '^DB_DATABASE=' "${ENV_FILE}" 2>/dev/null | cut -d= -f2- | tr -d '"' )

if [ -z "${DB_USER}" ] || [ -z "${DB_PASS}" ] || [ -z "${DB_NAME}" ]; then
  echo "[$(date)] ERROR: Could not read DB credentials from ${ENV_FILE}" >> /var/log/daily-backup.log
  exit 1
fi

mysqldump --no-tablespaces --single-transaction --quick \
  -u "${DB_USER}" -p"${DB_PASS}" \
  "${DB_NAME}" > "${DB_BACKUP}" 2>/dev/null

if [ $? -eq 0 ] && [ -s "${DB_BACKUP}" ]; then
  gzip "${DB_BACKUP}"
  SIZE=$(du -sh "${DB_BACKUP}.gz" | cut -f1)
  echo "[$(date)] DB backup: ${DB_BACKUP}.gz (${SIZE})" >> /var/log/daily-backup.log
else
  echo "[$(date)] ERROR: DB backup failed for ${DB_NAME}" >> /var/log/daily-backup.log
  rm -f "${DB_BACKUP}"
fi

# Rotate: delete dumps older than KEEP_DAYS days.
find "${BACKUP_DIR}" -name 'db-*.sql.gz' -mtime +${KEEP_DAYS} -delete

KEPT=$(ls "${BACKUP_DIR}" 2>/dev/null | wc -l)
echo "[$(date)] Rotation complete. Files kept: ${KEPT}" >> /var/log/daily-backup.log

# Optional: offsite push via s3cmd (only runs if /root/.s3cfg is present).
# Owner must populate /root/.s3cfg with DO Spaces credentials first.
if [ -f /root/.s3cfg ] && command -v s3cmd >/dev/null 2>&1; then
  S3_BUCKET="${S3_BUCKET:-s3://boss888-backups}"
  if s3cmd put "${BACKUP_DIR}/db-${DATE}.sql.gz" "${S3_BUCKET}/db/" >> /var/log/daily-backup.log 2>&1; then
    echo "[$(date)] Offsite OK: ${S3_BUCKET}/db/db-${DATE}.sql.gz" >> /var/log/daily-backup.log
  else
    echo "[$(date)] WARNING: Offsite push failed (see s3cmd output above)" >> /var/log/daily-backup.log
  fi
else
  echo "[$(date)] Offsite skipped: /root/.s3cfg not configured" >> /var/log/daily-backup.log
fi
