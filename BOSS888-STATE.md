# BOSS888-STATE.md

**Last updated:** 2026-05-07
**Updated by:** Claude Code (orientation session)
**Rule:** Refresh this file at every session end. Never let it go stale.

---

## Stack

- Laravel: 11.51.0
- PHP: 8.3.30 (NTS, OPcache 8.3.30, built 2026-05-05)
- Runtime: 2.28.0 (Railway, phase 2)
- Agents: 21 (dmm + 20 specialists)
- Tools: 58 runtime tools
- ai_run task types: 11 (`chat_json` LIVE — Phase 0.17 shipped)
- Internal routes: 3 (`/internal/health`, `/internal/image/generate`, `/internal/vision/analyze`)
- DB: `levelup_staging` @ 127.0.0.1 (MySQL 8.0.45)
- Redis: 6 (PECL-built ext, see project_redis_apt_untracked_files.md)
- Droplet: 134.209.93.41 AMS3 (`Level-Up-Growth`, Ubuntu 22.04)
- SSH key: `~/.ssh/do_levelup`

---

## Last Commit

```
722ace8 chore: add BOSS888-STATE.md — canonical living state doc
3ea4934 feat: CHATBOT888 completion — Block 2 (T2.1-T2.6)
d9d82e0 CHATBOT888: fix guardrails to respect business_context_text as grounding source
8d5a767 CHATBOT888: add chatbot-widget.js to version control
87eb11c CHATBOT888: Chef Red industry pack, guardrails turn_count gate, business context, widget cache-bust
```

---

## Workspaces & Sites

| Metric | Count |
|---|---|
| workspaces | 2 |
| websites | 2 |
| plans | 6 |
| users | 2 |

Plan slugs: `free`, `starter`, `ai-lite`, `growth`, `pro`, `agency`.

---

## Live Client Sites

**Static, deployed alongside Laravel:**
- `/var/www/chef-red/` — full site: `index.html`, `blog/`, `images/`, `sitemap.xml`, `404.html`, `_slug-map.json`. Chef Red is the first live client. CHATBOT888 widget injected (per recent commits).
- `/var/www/clutter-angels/` — `index.html` only (placeholder, full site deploy pending).

**Nginx sites-enabled (wildcard 2026-05-07):**
- `levelup-staging` → `134.209.93.41`, `levelupgrowth.io`, `*.levelupgrowth.io`, `chefredraymundo.com`, `www.chefredraymundo.com`
- `clutter-angels` → `clutter-angels.levelupgrowth.io` (likely shadowed by the wildcard now; can be retired later)
- Pre-wildcard backup: `/etc/nginx/sites-available/levelup-staging.bak-wildcard-20260507-1514`
- Effect: any new `*.levelupgrowth.io` subdomain auto-routes to Laravel; no manual nginx edits needed for new tenants.

**Nginx sites-available (NOT enabled):**
- `chef-red` (file exists but no symlink — chef-red.levelupgrowth.io and chefredraymundo.com are served via the levelup-staging vhost's multi server_name list)
- `default` (catch-all `_`)
- `levelup-staging.bak.20260506-2002` (rollback artifact)

---

## Chatbot Tables

8 tables (created 2026-05-06 via `2026_05_06_200000_create_chatbot_tables`, batch [7]):

```
chatbot_escalations
chatbot_knowledge_chunks
chatbot_knowledge_sources
chatbot_messages
chatbot_sessions
chatbot_settings
chatbot_usage_logs
chatbot_widget_tokens
```

---

## Templates

17 industry templates in `storage/templates/`:
architecture, automotive, cafe, childcare, cleaning, construction, consulting, education, finance, hospitality, logistics, marketing_agency, pet_services, photography, real_estate_broker, technology, wellness.

Each is a directory containing a `manifest.json`.

---

## Migration Ledger Summary

91 migrations, all status **Ran**. Batch breakdown:
- [1] 27 — auth/workspace/plans/credits/CRM foundation (2026-04-04)
- [2] ~50 — intelligence, creative, SEO, blog cols, studio, bookings (through 2026-04-18)
- [3] 11 — media library, studio, sequences, email builder, approvals, onboarding (through 2026-04-25)
- [4] `recovery_lost_columns` (2026-05-05 droplet redeploy patch)
- [5] `recovery_builder_default_assets` (2026-05-06)
- [6] `recovery_approvals_schema` (2026-05-06)
- [7] `create_chatbot_tables` (2026-05-06 — chatbot rebuilt post-recovery)

---

## Backups (latest on `/root/`)

- `backup-20260507-0646.sql` (604K) — today's morning backup
- `backup-20260506-2200.sql` (599K)
- `backup-20260506-1300.sql` (401K)

Daily backup is purely manual (no cron yet — open infra task).

---

## Known Code Gaps (refreshed end-of-day 2026-05-07)

**Resolved during Block 2** (commit 3ea4934):
- ~~`BuilderRenderer::injectChatbotWidget` missing~~ → added in T2.2; exercised end-to-end through nginx (with T2.5 `enabled=false` suppression)
- ~~PlanSeeder missing 4 chatbot keys~~ → added in T2.1; all 6 plans now have full entitlement matrix
- ~~No `STRIPE_MODE` env var~~ → set to `test` in T2.4 stage 1 (.env additions tracked via .env.example placeholders, not committed)
- ~~CHATBOT888 settings change doesn't bust render cache~~ → cache invalidation added in T2.3 (`updateSettings` / `mintWidgetToken` / `revokeWidgetToken`)
- ~~`Subscription` model fillable missing chatbot_addon fields~~ → fixed in T2.6 (latent pre-existing bug surfaced during verify)

**Still open**:
1. **Blog completely ungated** — `routes/web.php:128-129` + `routes/api.php:5125` have no auth, no plan check. Owner decision pending (T5.1).
2. **No `daily-progress/` or `storage/audits/` directories on the server** — both are local-only on Windows. Server-side log discipline not yet established.
3. **`subscriptions.chatbot_addon_active=1` for ws=2 with NULL `chatbot_addon_item_id`** — data drift from Chef Red May-6 setup. Functionally fine (Path 1 entitlement masks it). Cleanup deferred to T6.2.
4. **CHATBOT_ADDON_PRICE_ID not in `config/billing.php`** — only env-resolved. Works fine via `config(...) ?? env(...)` fallback in StripeService, but for symmetry with other Stripe keys should be added to the config file. Minor.
5. **Stripe live add-on flow only PARTIALLY verified** (T2.4) — synchronous `subscriptionItems->create` requires a real `stripe_subscription_id` on the workspace, which requires real checkout flow + webhook reachability. Deferred to T6.2.
6. **`storage/templates/` + `storage/creative-templates/` untracked in git** — 17 industry packs are on disk but not version-controlled. Should be tracked or explicitly gitignored.
7. **No `default_server` directive in nginx port 80 vhosts** — Host-header fallthrough relies on alphabetical sites-enabled load order. Surfaced during T2.2 when `levelupgrowth.levelupgrowth.io` was missing from server_name. Adding explicit `default_server` would harden against future drift.

---

## Open Tasks (from masterplan BOSS888-MASTERPLAN-2026-05-07.md)

| ID | Task | Status |
|---|---|---|
| T1.1 | STATE.md created | ✅ 2026-05-07 |
| T1.2 | Backfill daily-progress May 3-7 | ✅ 2026-05-07 |
| T1.3 | Update PLAN.md | ✅ 2026-05-07 |
| T2.1 | PlanSeeder chatbot keys | ✅ 2026-05-07 |
| T2.2 | BuilderRenderer injectChatbotWidget | ✅ 2026-05-07 |
| T2.3 | Cache invalidation on chatbot toggle | ✅ 2026-05-07 |
| T2.4 | Stripe TEST add-on verification | ⚠️ PARTIAL 2026-05-07 (live sub create → T6.2) |
| T2.5 | injectChatbotWidget: suppress when enabled=false | ✅ 2026-05-07 |
| T2.6 | StripeService chatbot_addon_active + Subscription fillable fix | ✅ 2026-05-07 |
| T3.1 | Template hero zoom/crop fix | ⬜ |
| T3.2 | Contact form audit | ⬜ (audit done 2026-05-07, fix pending) |
| T3.3 | Clutter Angels workspace setup | ⏸ ON HOLD 2026-05-07 |
| T3.4 | Chef Red: migrate static HTML → BuilderRenderer | ⬜ NEW 2026-05-07 (multi-session) |
| T3.5 | Platform rule: no static-only sites; DB sections_json required | ⬜ NEW 2026-05-07 |
| T4.1 | KB retrieval OR logic fix | ⬜ |
| T4.2 | LevelUpGrowth homepage content | ⬜ |
| T5.1 | Blog gating — awaiting owner decision | ⏳ |
| T5.2 | Pricing changes — awaiting owner input | ⏳ |
| T6.1 | Phase 9 APP888 patches | ⬜ |
| T6.2 | Phase 10 production deployment | ⬜ |
