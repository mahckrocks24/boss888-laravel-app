#!/bin/bash
# DigitalOcean Droplet Snapshot Script
# Requires: DO_API_TOKEN env var (read+write scope)
# Cron: 0 2 * * * DO_API_TOKEN=xxx /root/do-snapshot.sh
# Logs to /var/log/do-snapshot.log

LOG=/var/log/do-snapshot.log

DROPLET_ID=$(curl -s -m 5 http://169.254.169.254/metadata/v1/id 2>/dev/null)
SNAPSHOT_NAME="boss888-auto-$(date +%Y%m%d-%H%M)"

if [ -z "${DROPLET_ID}" ]; then
  echo "[$(date)] ERROR: Could not read droplet ID from metadata service" >> "${LOG}"
  exit 1
fi

if [ -z "${DO_API_TOKEN}" ]; then
  echo "[$(date)] ERROR: DO_API_TOKEN not set (set via cron env or /root/.do-token)" >> "${LOG}"
  exit 1
fi

RESP=$(curl -s -X POST \
  -H "Authorization: Bearer ${DO_API_TOKEN}" \
  -H "Content-Type: application/json" \
  -d "{\"type\":\"snapshot\",\"name\":\"${SNAPSHOT_NAME}\"}" \
  "https://api.digitalocean.com/v2/droplets/${DROPLET_ID}/actions")

if echo "${RESP}" | grep -q '"status":"in-progress"'; then
  echo "[$(date)] Snapshot requested: ${SNAPSHOT_NAME} (droplet=${DROPLET_ID})" >> "${LOG}"
  exit 0
else
  echo "[$(date)] ERROR: API response: ${RESP}" >> "${LOG}"
  exit 1
fi
