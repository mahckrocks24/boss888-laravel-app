#!/bin/bash
# ============================================================================
# LevelUp Runtime v2.37.3 — POST-DEPLOYMENT PROBES (Railway)
# ============================================================================
# Run AFTER deploying levelup-runtime2-main-v2.37.3-launch-scope.zip.
#
# SAFETY: every probe is read-only or a refusal check.
#   - sends NO email
#   - publishes NO social content
#   - mutates NO customer data
#   - creates no tasks, no campaigns, no posts
#   - the only LLM call is one short retained-path chat (a few hundred tokens);
#     skip it with --no-llm if you want zero provider spend.
#
# Usage:
#   RUNTIME_URL=https://<runtime-host> RUNTIME_SECRET=<X-LevelUp-Secret> \
#     bash runtime-probe-2.37.3.sh [--no-llm]
#
# Do NOT paste the secret into a shared channel. Export it in your shell.
# ============================================================================

set -o pipefail
NO_LLM=0
[ "$1" = "--no-llm" ] && NO_LLM=1

: "${RUNTIME_URL:?set RUNTIME_URL to the deployed runtime base URL}"
: "${RUNTIME_SECRET:?set RUNTIME_SECRET to the X-LevelUp-Secret value}"

H="X-LevelUp-Secret: ${RUNTIME_SECRET}"
PASS=0; FAIL=0
ok()   { PASS=$((PASS+1)); echo "  PASS  $1"; }
bad()  { FAIL=$((FAIL+1)); echo "  FAIL  $1 -- $2"; }

REMOVED_AGENTS='marcus|jordan|tyler|zara|zoe|maya|vera|kai|chris|leo'
REMOVED_TOOLS='create_post|schedule_post|publish_post|list_posts|update_post|get_queue|record_social_analytics|ai_generate_social_post|generate_hashtags|social_image_gen|social_platform_adapt|create_campaign|update_campaign|list_campaigns|schedule_campaign|send_campaign|create_automation|create_template|list_templates|record_metric|test_send_email|ai_generate_email|ai_rewrite_block|ai_suggest_subjects|ai_spam_check|enroll_sequence|list_sequences'

echo "=============================================================="
echo "RUNTIME PROBES v2.37.3  ->  ${RUNTIME_URL}"
echo "=============================================================="

# ---- 1. /health reachable ---------------------------------------------------
echo ""; echo "-- 1. health --"
HEALTH=$(curl -s -m 15 "${RUNTIME_URL}/health")
if [ -z "$HEALTH" ]; then bad "/health reachable" "empty response"; else ok "/health reachable"; fi
echo "$HEALTH" | grep -q '"status":"ok"' && ok "status ok" || bad "status ok" "not ok"

# ---- 2. deployed version ----------------------------------------------------
echo ""; echo "-- 2. deployed version --"
VER=$(echo "$HEALTH" | grep -o '"version":"[^"]*"' | head -1)
echo "     reported: $VER"
echo "$VER" | grep -q '2\.37\.3' && ok "version is 2.37.3" || bad "version is 2.37.3" "got $VER"

# ---- 3. authoritative agent roster ------------------------------------------
echo ""; echo "-- 3. agent roster --"
for a in dmm james alex diana ryan sofia priya nora elena max; do
  echo "$HEALTH" | grep -q "\"$a\"" && ok "retained agent present: $a" || bad "retained agent present: $a" "absent"
done
if echo "$HEALTH" | grep -qiE "\"($REMOVED_AGENTS)\""; then
  bad "no removed agent in roster" "$(echo "$HEALTH" | grep -oiE "\"($REMOVED_AGENTS)\"" | sort -u | tr '\n' ' ')"
else
  ok "no removed agent in roster"
fi

# ---- 4. removed tools not advertised ----------------------------------------
echo ""; echo "-- 4. removed-tool rejection --"
if echo "$HEALTH" | grep -qE "\"($REMOVED_TOOLS)\""; then
  bad "no removed tool advertised" "$(echo "$HEALTH" | grep -oE "\"($REMOVED_TOOLS)\"" | sort -u | tr '\n' ' ')"
else
  ok "no removed tool advertised"
fi
if echo "$HEALTH" | grep -qE '"(email_generation|social_post|email_template_generate_v2|email_block_rewrite|email_subject_suggest|email_spam_critique)"'; then
  bad "no removed generation mode advertised" "ai_run_tasks still lists an out-of-scope mode"
else
  ok "no removed generation mode advertised"
fi

# ---- 5. retained capability still advertised --------------------------------
echo ""; echo "-- 5. retained capability --"
for t in serp_analysis deep_audit write_article improve_draft create_lead \
         generate_page_layout publish_builder_page scan_site_url create_event; do
  echo "$HEALTH" | grep -q "\"$t\"" && ok "retained tool: $t" || bad "retained tool: $t" "absent"
done
echo ""; echo "   Studio direct image/video generation:"
for t in generate_design pick_design_template list_design_templates; do
  echo "$HEALTH" | grep -q "\"$t\"" && ok "studio tool: $t" || bad "studio tool: $t" "absent"
done

# ---- 6. Laravel <-> runtime handshake ---------------------------------------
echo ""; echo "-- 6. Laravel/runtime handshake --"
IH=$(curl -s -m 15 -H "$H" "${RUNTIME_URL}/internal/health")
if [ -n "$IH" ]; then
  echo "$IH" | grep -qi 'unauthor' && bad "secret accepted" "401 - RUNTIME_SECRET mismatch" || ok "secret accepted on /internal/health"
else
  bad "internal health reachable" "empty"
fi
# unauthenticated must be refused
UA=$(curl -s -m 15 -o /dev/null -w '%{http_code}' "${RUNTIME_URL}/internal/health")
[ "$UA" = "401" ] || [ "$UA" = "403" ] && ok "unauthenticated internal route refused ($UA)" \
  || bad "unauthenticated internal route refused" "got HTTP $UA"

# ---- 7. removed generation modes refused ------------------------------------
echo ""; echo "-- 7. out-of-scope generation refused (no content produced) --"
for t in social_post email_generation email_template_generate_v2 social_hashtag_pack \
         email_subject_suggest email_block_rewrite social_platform_adapt; do
  R=$(curl -s -m 20 -X POST "${RUNTIME_URL}/ai/run" -H "Content-Type: application/json" -H "$H" \
        -d "{\"task\":\"$t\",\"prompt\":\"probe - must be refused\"}")
  if echo "$R" | grep -q 'out_of_launch_scope'; then
    ok "refused: $t"
  else
    bad "refused: $t" "$(echo "$R" | head -c 160)"
  fi
done

# ---- 8. Sarah retained behaviour (single small LLM call) --------------------
echo ""; echo "-- 8. Sarah retained path --"
if [ "$NO_LLM" = "1" ]; then
  echo "  SKIP  (--no-llm)"
else
  R=$(curl -s -m 45 -X POST "${RUNTIME_URL}/ai/run" -H "Content-Type: application/json" -H "$H" \
        -d '{"task":"chat_json","system":"Reply with JSON {\"ok\":true} only.","prompt":"respond","max_tokens":32}')
  echo "$R" | grep -qi 'out_of_launch_scope' \
    && bad "retained path not blocked" "chat_json wrongly refused" \
    || ok "retained path reachable (chat_json not blocked by launch scope)"
fi

# ---- 9. Sarah excluded-request behaviour ------------------------------------
echo ""; echo "-- 9. Sarah excluded-request behaviour --"
echo "  MANUAL: in the app (levelupgrowth.io/app) ask Sarah each of these."
echo "          Expected: states it is not part of the product, offers a retained"
echo "          alternative, creates NO task, names NO removed specialist."
echo "            1. \"Create a social media campaign.\""
echo "            2. \"Send an email campaign.\""
echo "            3. \"Build a newsletter sequence.\""
echo "            4. \"Ask Marcus to publish this.\""
echo "            5. \"Get Chris to make TikTok videos.\""
echo "            6. \"Ask Leo to create ads.\""
echo "            7. \"Post every day on Facebook.\""
echo "            8. \"Automatically reply to comments.\""
echo "          Then confirm 0 new rows: tasks / agent_assignments for removed agents."

# ---- 10. Alex technical SEO --------------------------------------------------
echo ""; echo "-- 10. Alex technical SEO --"
for t in deep_audit insert_link scan_site_url export_website; do
  echo "$HEALTH" | grep -q "\"$t\"" && ok "alex-capable tool present: $t" || bad "alex-capable tool present: $t" "absent"
done

# ---- 11. startup / registry errors -------------------------------------------
echo ""; echo "-- 11. startup + registry errors --"
echo "  MANUAL: railway logs --service <runtime-service> | grep -iE 'error|cannot find module|SyntaxError'"
echo "          Expected: no ERROR lines; expect the informational line"
echo "          '[TOOL-REGISTRY] Launch scope: withheld N out-of-scope tools'"
echo "          and '[REGISTRY] Unified: 43 tools from canonical registry'."
echo "  MANUAL: confirm 2 replicas Active (railway.toml minInstances=2)."

# ---- 12. fallback behaviour --------------------------------------------------
echo ""; echo "-- 12. fallback roster --"
echo "  NOTE: the DB-outage fallback path cannot be forced safely against a live"
echo "        Railway service (it would require breaking the Laravel handshake)."
echo "        It was proven off-Railway: with no LARAVEL_BASE_URL/WP_URL set, the"
echo "        runtime serves AGENTS_STATIC, which contains exactly the 10 retained"
echo "        agents and none of the 10 removed. Re-verify only if you ever change"
echo "        AGENTS_STATIC."

echo ""
echo "=============================================================="
echo "RESULT: ${PASS} passed, ${FAIL} failed  (plus manual items 9, 11)"
echo "=============================================================="
[ "$FAIL" -eq 0 ] || exit 1
