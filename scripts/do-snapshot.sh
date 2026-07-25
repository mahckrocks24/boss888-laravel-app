#!/bin/bash
#
# do-snapshot.sh — DigitalOcean Droplet snapshot with deterministic retention
#
# v2.0.0  2026-07-21  PATCHED. v1 (2026-05-08) created snapshots with NO retention,
#                     accumulating 75 snapshots in 74 days. This version keeps the
#                     creation behaviour identical and adds bounded, fail-closed
#                     retention.
#
# Cron:  0 2 * * * DO_API_TOKEN=$(cat /root/.do-token 2>/dev/null) /root/do-snapshot.sh
# Modes: (none)        create one snapshot, wait for completion, then prune
#        --dry-run     list + plan only. MUTATES NOTHING.
#        --prune-only  apply retention without creating a new snapshot
# Logs:  /var/log/do-snapshot.log      (the API token is never written to the log)
# Exit:  0 success, non-zero on any failure
#
# SAFETY INVARIANTS
#   * Only snapshots whose name begins with boss888-auto- are ever considered.
#   * Snapshot listing uses /v2/droplets/{id}/snapshots, which is scoped to THIS
#     droplet — snapshots belonging to any other droplet are structurally
#     unreachable by this script.
#   * Deletion NEVER runs until the newly requested snapshot is confirmed
#     'completed' AND present in the droplet's snapshot list.
#   * Any ambiguous or unparseable API response aborts without deleting.
#   * Snapshots with missing/zero size or missing timestamps are ignored, never deleted.
#   * Concurrency is prevented with flock.
#
set -uo pipefail

VERSION="2.0.0"
LOG="${DO_SNAPSHOT_LOG:-/var/log/do-snapshot.log}"
LOCKFILE="/var/lock/do-snapshot.lock"
API="https://api.digitalocean.com/v2"
PREFIX="boss888-auto-"
RETAIN=5
GB_MONTH_USD="0.06"          # DigitalOcean snapshot storage, USD per GB per month

CURL_TIMEOUT=30
CURL_RETRIES=3
POLL_INTERVAL=10
POLL_MAX=90                  # 90 × 10s = 15 minutes

MODE="run"
case "${1:-}" in
  "")           MODE="run" ;;
  --dry-run)    MODE="dry-run" ;;
  --prune-only) MODE="prune-only" ;;
  *) echo "usage: $0 [--dry-run|--prune-only]" >&2; exit 2 ;;
esac

API_BODY=""
API_CODE=""

log() {
  local line="[$(date -u '+%a %b %e %T UTC %Y')] $*"
  printf '%s\n' "$line" >> "$LOG" 2>/dev/null
  if [ "$MODE" != "run" ] || [ -t 1 ]; then printf '%s\n' "$line"; fi
}

die() { log "FATAL: $*"; exit 1; }

# ---------------------------------------------------------------- API helper
# Bounded retries with backoff on 429/5xx. The token is passed in a header and
# is never echoed, logged, or included in any error message.
api() {
  local method="$1" path="$2" data="${3:-}" attempt=1 out code
  while [ "$attempt" -le "$CURL_RETRIES" ]; do
    if [ -n "$data" ]; then
      out=$(curl -sS --max-time "$CURL_TIMEOUT" -w $'\n%{http_code}' -X "$method" \
            -H "Authorization: Bearer ${DO_API_TOKEN}" \
            -H "Content-Type: application/json" \
            -d "$data" "${API}${path}" 2>/dev/null)
    else
      out=$(curl -sS --max-time "$CURL_TIMEOUT" -w $'\n%{http_code}' -X "$method" \
            -H "Authorization: Bearer ${DO_API_TOKEN}" \
            "${API}${path}" 2>/dev/null)
    fi
    code=$(printf '%s' "$out" | tail -n1)
    API_BODY=$(printf '%s' "$out" | sed '$d')
    API_CODE="$code"
    case "$code" in
      2*)      return 0 ;;
      429|5*)  log "  transient HTTP ${code} on ${method} ${path} (attempt ${attempt}/${CURL_RETRIES})"
               sleep $(( attempt * 5 )) ;;
      *)       return 1 ;;
    esac
    attempt=$(( attempt + 1 ))
  done
  return 1
}

# ---------------------------------------------------------------- preflight
exec 9>"$LOCKFILE" || die "cannot open lock file ${LOCKFILE}"
if ! flock -n 9; then
  log "another do-snapshot run holds the lock — exiting without action"
  exit 1
fi

log "=== do-snapshot v${VERSION} start (mode=${MODE}, retain=${RETAIN}) ==="

[ -n "${DO_API_TOKEN:-}" ] || die "DO_API_TOKEN not set (expected via cron env from /root/.do-token)"

DROPLET_ID=$(curl -s -m 5 http://169.254.169.254/metadata/v1/id 2>/dev/null)
[[ "$DROPLET_ID" =~ ^[0-9]+$ ]] || die "could not read a numeric droplet ID from the metadata service"
log "droplet=${DROPLET_ID}"

# ---------------------------------------------------------------- create
NEW_NAME=""
if [ "$MODE" = "run" ]; then
  NEW_NAME="${PREFIX}$(date +%Y%m%d-%H%M)"
  log "requesting snapshot: ${NEW_NAME}"

  api POST "/droplets/${DROPLET_ID}/actions" "{\"type\":\"snapshot\",\"name\":\"${NEW_NAME}\"}" \
    || die "snapshot create failed (HTTP ${API_CODE}) — no retention applied"

  ACTION_ID=$(printf '%s' "$API_BODY" | python3 -c \
    'import sys,json;d=json.load(sys.stdin);print(d["action"]["id"])' 2>/dev/null) \
    || die "ambiguous create response — cannot read action id — failing closed"
  [[ "$ACTION_ID" =~ ^[0-9]+$ ]] || die "ambiguous action id — failing closed"
  log "snapshot action ${ACTION_ID} accepted; waiting for completion"

  i=0
  while :; do
    [ "$i" -lt "$POLL_MAX" ] || die "timed out after $((POLL_MAX*POLL_INTERVAL))s waiting for action ${ACTION_ID} — no retention applied"
    api GET "/actions/${ACTION_ID}" || die "action poll failed (HTTP ${API_CODE}) — failing closed"
    ST=$(printf '%s' "$API_BODY" | python3 -c \
      'import sys,json;d=json.load(sys.stdin);print(d["action"]["status"])' 2>/dev/null) \
      || die "ambiguous action response — failing closed"
    case "$ST" in
      completed)   log "snapshot ${NEW_NAME} COMPLETED"; break ;;
      errored)     die "snapshot action ${ACTION_ID} errored — no retention applied" ;;
      in-progress) sleep "$POLL_INTERVAL" ;;
      *)           die "unknown action status '${ST}' — failing closed" ;;
    esac
    i=$(( i + 1 ))
  done
fi

# ---------------------------------------------------------------- list
api GET "/droplets/${DROPLET_ID}/snapshots?per_page=200" \
  || die "could not list droplet snapshots (HTTP ${API_CODE}) — failing closed"

# NOTE: the planner is passed via `python3 -c` rather than a heredoc, because a
# heredoc would rebind stdin and starve the JSON pipe.
read -r -d '' PY_PLAN <<'PY' || true
import sys, json, os
prefix = os.environ["PREFIX"]
retain = int(os.environ["RETAIN"])
rate   = float(os.environ["GB_MONTH_USD"])

try:
    d = json.load(sys.stdin)
except Exception:
    print("ERROR\tunparseable snapshot list"); sys.exit(0)

if not isinstance(d.get("snapshots"), list):
    print("ERROR\tsnapshot list missing 'snapshots' array"); sys.exit(0)

rows, skipped, foreign = [], 0, 0
for s in d["snapshots"]:
    name = s.get("name") or ""
    if not name.startswith(prefix):
        foreign += 1
        continue
    sid  = s.get("id")
    ca   = s.get("created_at")
    size = s.get("size_gigabytes")
    # Fail-safe: anything incomplete, unsized or untimed is IGNORED, never deleted.
    if not sid or not ca or not isinstance(size, (int, float)) or size <= 0:
        skipped += 1
        continue
    rows.append((str(ca), str(sid), name, float(size)))

rows.sort(key=lambda r: (r[0], r[1]), reverse=True)   # newest first
keep, drop = rows[:retain], rows[retain:]

print("META\ttotal_matching=%d\tkeep=%d\tdelete=%d\tignored_incomplete=%d\tignored_nonmatching=%d"
      % (len(rows), len(keep), len(drop), skipped, foreign))
for ca, sid, name, size in keep:
    print("KEEP\t%s\t%s\t%s\t%.2f" % (sid, name, ca, size))
for ca, sid, name, size in drop:
    print("DELETE\t%s\t%s\t%s\t%.2f" % (sid, name, ca, size))
print("COST\tkeep_gb=%.2f\tkeep_usd=%.2f\tdrop_gb=%.2f\tdrop_usd=%.2f\ttotal_usd=%.2f"
      % (sum(r[3] for r in keep), sum(r[3] for r in keep) * rate,
         sum(r[3] for r in drop), sum(r[3] for r in drop) * rate,
         sum(r[3] for r in rows) * rate))
PY

PLAN=$(printf '%s' "$API_BODY" \
       | PREFIX="$PREFIX" RETAIN="$RETAIN" GB_MONTH_USD="$GB_MONTH_USD" python3 -c "$PY_PLAN")

printf '%s' "$PLAN" | grep -q '^ERROR' && die "$(printf '%s' "$PLAN" | grep '^ERROR' | cut -f2) — failing closed"

META=$(printf '%s\n' "$PLAN" | grep '^META' || true)
COST=$(printf '%s\n' "$PLAN" | grep '^COST' || true)
[ -n "$META" ] || die "retention planner produced no summary — failing closed"
log "plan: ${META#META$'\t'}"
log "cost: ${COST#COST$'\t'}  (at \$${GB_MONTH_USD}/GB/month)"

# The new snapshot must be present and completed before anything is deleted.
if [ "$MODE" = "run" ]; then
  printf '%s\n' "$PLAN" | grep -qP "^KEEP\t[0-9]+\t${NEW_NAME}\t" \
    || die "newly created snapshot ${NEW_NAME} is not present in the completed list — refusing to delete anything"
  log "verified ${NEW_NAME} present and completed — retention may proceed"
fi

# In prune-only mode, refuse to prune against a stale series (creation may be broken).
if [ "$MODE" = "prune-only" ]; then
  NEWEST_TS=$(printf '%s\n' "$PLAN" | grep '^KEEP' | head -1 | cut -f4)
  if [ -n "$NEWEST_TS" ]; then
    NEWEST_EPOCH=$(date -u -d "$NEWEST_TS" +%s 2>/dev/null || echo 0)
    NOW_EPOCH=$(date -u +%s)
    AGE_H=$(( (NOW_EPOCH - NEWEST_EPOCH) / 3600 ))
    [ "$NEWEST_EPOCH" -gt 0 ] || die "cannot parse newest snapshot timestamp — failing closed"
    [ "$AGE_H" -le 48 ] || die "newest matching snapshot is ${AGE_H}h old (>48h) — creation may be broken; refusing to prune"
    log "newest matching snapshot is ${AGE_H}h old — within the 48h freshness guard"
  else
    die "no completed matching snapshots found — refusing to prune"
  fi
fi

# ---------------------------------------------------------------- report
log "--- RETAIN (${RETAIN} newest completed ${PREFIX}* snapshots) ---"
printf '%s\n' "$PLAN" | grep '^KEEP' | while IFS=$'\t' read -r _ sid name ca size; do
  log "  KEEP    id=${sid}  ${name}  ${ca}  ${size}GB"
done

DELETE_COUNT=$(printf '%s\n' "$PLAN" | grep -c '^DELETE' || true)
if [ "$DELETE_COUNT" -eq 0 ]; then
  log "nothing to prune — ${PREFIX}* count is at or below the retention limit"
else
  log "--- SURPLUS (${DELETE_COUNT} snapshots beyond retention) ---"
  printf '%s\n' "$PLAN" | grep '^DELETE' | while IFS=$'\t' read -r _ sid name ca size; do
    log "  SURPLUS id=${sid}  ${name}  ${ca}  ${size}GB"
  done
fi

if [ "$MODE" = "dry-run" ]; then
  log "=== DRY RUN — nothing was created, nothing was deleted ==="
  exit 0
fi

# ---------------------------------------------------------------- delete
DELETED=0
FAILED=0
if [ "$DELETE_COUNT" -gt 0 ]; then
  while IFS=$'\t' read -r _ sid name ca size; do
    [ -n "${sid:-}" ] || continue
    # Belt-and-braces: re-assert the prefix immediately before the DELETE call.
    case "$name" in
      "${PREFIX}"*) ;;
      *) log "  REFUSED id=${sid} ${name} — name does not match ${PREFIX}* (should be unreachable)"; FAILED=$((FAILED+1)); continue ;;
    esac
    if api DELETE "/snapshots/${sid}"; then
      log "  DELETED id=${sid}  ${name}  ${ca}  ${size}GB"
      DELETED=$(( DELETED + 1 ))
    else
      log "  ERROR   id=${sid}  ${name} — delete failed (HTTP ${API_CODE})"
      FAILED=$(( FAILED + 1 ))
    fi
  done < <(printf '%s\n' "$PLAN" | grep '^DELETE')
fi

log "retention applied: deleted=${DELETED} failed=${FAILED}"

# ---------------------------------------------------------------- verify
api GET "/droplets/${DROPLET_ID}/snapshots?per_page=200" \
  || die "post-delete verification listing failed (HTTP ${API_CODE})"

REMAIN=$(printf '%s' "$API_BODY" | PREFIX="$PREFIX" python3 -c \
  'import sys,json,os
d=json.load(sys.stdin)
p=os.environ["PREFIX"]
print(sum(1 for s in d.get("snapshots",[]) if (s.get("name") or "").startswith(p)))' 2>/dev/null) \
  || die "post-delete verification could not be parsed"

log "verification: ${REMAIN} ${PREFIX}* snapshots remain (expected ${RETAIN})"

if [ "$FAILED" -gt 0 ]; then
  log "=== COMPLETED WITH ERRORS ==="
  exit 1
fi
if [ "$REMAIN" -ne "$RETAIN" ]; then
  log "=== WARNING: remaining count ${REMAIN} != retention ${RETAIN} ==="
  exit 1
fi

log "=== do-snapshot v${VERSION} OK ==="
exit 0
