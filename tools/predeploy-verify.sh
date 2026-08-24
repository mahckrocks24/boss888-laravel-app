#!/usr/bin/env bash
# MISSION-018 WS-8 (2026-08-24) — the pre-deploy fast lane.
#
# The Genome found 2,795 tests and 17 smoke scripts with NO CI, so the controls
# this programme built (the route-registration census, the route-equivalence
# gate) had nothing running them together. This is that gate: one command, fast
# (seconds, not the >90-min full suite), run before any deploy and wireable to
# CI when a runner is available. Exit 0 = safe to deploy; non-zero = do not.
#
# Checks, in cost order:
#   1. PHP lint sweep over app/ + routes/ + bootstrap/  (syntax errors)
#   2. route-registration census                        (shadowing/duplicates)
#   3. route-equivalence gate (3 tests)                 (ungoverned route change)
#   4. targeted secret scan of tracked files            (live keys committed)
#   5. app boots                                        (container assembles)
#
# Usage:  bash tools/predeploy-verify.sh
set -uo pipefail
cd "$(dirname "$0")/.." || exit 2
FAIL=0
say(){ printf '%s\n' "$*"; }
step(){ printf '\n== %s ==\n' "$*"; }

step "1/5 PHP lint sweep (app, routes, bootstrap)"
LINT_ERR=$(find app routes bootstrap -name '*.php' -print0 \
  | xargs -0 -n1 -P4 php -l 2>&1 | grep -v '^No syntax errors' || true)
if [ -n "$LINT_ERR" ]; then say "LINT FAILURES:"; say "$LINT_ERR"; FAIL=1; else say "OK — no syntax errors"; fi

step "2/5 route-registration census (RISK-0005)"
if [ -f tools/route-registration-census.php ]; then
  if php tools/route-registration-census.php; then say "OK — no duplicate registrations"; else say "CENSUS FAIL — duplicate route registration"; FAIL=1; fi
else say "SKIP — census tool absent"; fi

step "3/5 route-equivalence gate (RISK-0015)"
EQ=$(php artisan test tests/Feature/Routes/RouteEquivalenceTest.php \
  --filter 'route_count_matches_the_baseline|ordered_route_signature_is_unchanged|no_route_lost_its_middleware' 2>&1)
if printf '%s' "$EQ" | grep -q 'FAIL\|Error\|Skipped'; then say "EQUIVALENCE FAIL/SKIP:"; printf '%s\n' "$EQ" | tail -8; FAIL=1;
else printf '%s\n' "$EQ" | grep -E 'Tests:|passed' | tail -1; say "OK — route baseline matches"; fi

step "3b/6 tenancy regression suite (RISK-0022/0043/0037 + canvas P0)"
TEN=$(php artisan test tests/Feature/Infrastructure/TenancyRegressionTest.php 2>&1)
if printf '%s' "$TEN" | grep -q 'FAIL\|Error\|Skipped'; then say "TENANCY FAIL/SKIP:"; printf '%s\n' "$TEN" | tail -8; FAIL=1;
else printf '%s\n' "$TEN" | grep -E 'Tests:|passed' | tail -1; say "OK — cross-workspace isolation holds"; fi

step "4/5 targeted secret scan (tracked files under app/ routes/ config/)"
GIT=$(command -v git || echo /usr/bin/git)
SECRETS=$("$GIT" --no-optional-locks grep -nIE \
  'sk_live_[0-9A-Za-z]{16,}|AKIA[0-9A-Z]{16}|-----BEGIN (RSA |EC )?PRIVATE KEY-----|xox[baprs]-[0-9A-Za-z-]{10,}' \
  -- app routes config 2>/dev/null || true)
if [ -n "$SECRETS" ]; then say "POSSIBLE COMMITTED SECRETS:"; say "$SECRETS"; FAIL=1; else say "OK — no live-key patterns in tracked code"; fi

step "5/5 app boots"
if php artisan --version >/dev/null 2>&1; then say "OK — $(php artisan --version)"; else say "BOOT FAIL"; FAIL=1; fi

printf '\n========================================\n'
if [ "$FAIL" -eq 0 ]; then say "PRE-DEPLOY VERIFY: PASS — safe to deploy"; else say "PRE-DEPLOY VERIFY: FAIL — do NOT deploy"; fi
exit "$FAIL"
