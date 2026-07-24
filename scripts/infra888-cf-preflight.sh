#!/bin/bash
# INFRA888 Phase 2B-CERT Workstream 6 — Cloudflare READ-ONLY preflight.
#
# Every call below is a GET. There is no POST, PUT, PATCH or DELETE in this file.
# Denied access is EVIDENCE OF MISSING SCOPE and is recorded as such — never as a
# capability failure and never as a pass.

cd /var/www/levelup-staging || exit 1
TOKEN=$(grep -E "^CLOUDFLARE_API_TOKEN|^CF_API_TOKEN" .env | head -1 | cut -d= -f2- | tr -d '"')
ZONE=$(grep -E "^CLOUDFLARE_ZONE_ID|^CF_ZONE_ID" .env | head -1 | cut -d= -f2- | tr -d '"')
API="https://api.cloudflare.com/client/v4"

probe () {
  local label="$1" url="$2"
  local body code
  body=$(curl -s -w "\n__HTTP__%{http_code}" -H "Authorization: Bearer $TOKEN" "$url")
  code=$(printf '%s' "$body" | sed -n 's/.*__HTTP__//p')
  body=$(printf '%s' "$body" | sed 's/__HTTP__.*//')
  printf '### %s\nHTTP %s\n%s\n\n' "$label" "$code" \
    "$(printf '%s' "$body" | python3 -c '
import sys, json
try:
    d = json.load(sys.stdin)
except Exception:
    print("  (unparseable response)"); raise SystemExit
if d.get("success"):
    print("  RESULT: SUCCESS")
    r = d.get("result")
    print("  " + json.dumps(r, default=str)[:900])
else:
    errs = d.get("errors", [])
    print("  RESULT: DENIED/ERROR")
    for e in errs:
        print("    code=%s message=%s" % (e.get("code"), e.get("message")))
' 2>/dev/null)"
}

echo "==================== CLOUDFLARE READ-ONLY PREFLIGHT ===================="
echo "Zone ID: ${ZONE:0:8}...  (token not displayed)"
echo

probe "TOKEN VERIFY (identity of the credential itself)" "$API/user/tokens/verify"
probe "ZONE IDENTITY"                    "$API/zones/$ZONE"
probe "ZONE SETTINGS (all)"              "$API/zones/$ZONE/settings"
probe "ZONE SETTING: ssl"                "$API/zones/$ZONE/settings/ssl"
probe "ZONE SETTING: always_use_https"   "$API/zones/$ZONE/settings/always_use_https"
probe "ZONE SETTING: min_tls_version"    "$API/zones/$ZONE/settings/min_tls_version"
probe "DNSSEC"                           "$API/zones/$ZONE/dnssec"
probe "CUSTOM HOSTNAMES"                 "$API/zones/$ZONE/custom_hostnames"
probe "FALLBACK ORIGIN"                  "$API/zones/$ZONE/custom_hostnames/fallback_origin"
probe "CERTIFICATE PACKS"                "$API/zones/$ZONE/ssl/certificate_packs"
probe "ORIGIN CERTIFICATES"              "$API/certificates"
probe "WAF / FIREWALL RULES"             "$API/zones/$ZONE/firewall/rules"
probe "PAGE RULES"                       "$API/zones/$ZONE/pagerules"
probe "RULESETS"                         "$API/zones/$ZONE/rulesets"
probe "WORKERS ROUTES"                   "$API/zones/$ZONE/workers/routes"
probe "ACCOUNT LIST (account-level scope?)" "$API/accounts"
probe "ZONE ANALYTICS"                   "$API/zones/$ZONE/analytics/dashboard"

echo "==================== RATE LIMIT HEADERS ===================="
curl -s -D - -o /dev/null -H "Authorization: Bearer $TOKEN" "$API/zones/$ZONE" \
  | grep -iE "x-ratelimit|cf-ray|retry-after" | sed 's/^/  /'
echo
echo "==================== END PREFLIGHT ===================="
