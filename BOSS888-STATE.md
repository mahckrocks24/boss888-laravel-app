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
d9d82e0 CHATBOT888: fix guardrails to respect business_context_text as grounding source
8d5a767 CHATBOT888: add chatbot-widget.js to version control
87eb11c CHATBOT888: Chef Red industry pack, guardrails turn_count gate, business context, widget cache-bust
6f94e55 CHATBOT888: 8 tables migrated, Chef Red enabled, widget token minted, script injected
5750178 Chef Red blog: fix breadcrumb z-index and background to prevent title overlap
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

**Nginx sites-enabled:**
- `levelup-staging` → `134.209.93.41`, `staging.levelupgrowth.io`, `app.levelupgrowth.io`, `levelupgrowth.io`, `chef-red.levelupgrowth.io`, `chefredraymundo.com`, `www.chefredraymundo.com`
- `clutter-angels` → `clutter-angels.levelupgrowth.io`

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

## Known Code Gaps (orientation 2026-05-07)

1. **`BuilderRenderer::injectChatbotWidget` does NOT exist** — file is 576 lines, 18 methods, zero references to `chatbot` or `widget`. The 2026-05-02 progress entry's claim that this method was added is not in the recovered code. Builder-published sites currently emit no chatbot widget. (Static client sites like Chef Red have the widget injected directly into their HTML, bypassing the renderer.)
2. **PlanSeeder is missing 4 chatbot keys** (`chatbot_included`, `chatbot_addon_eligible`, `chatbot_kb_max_docs`, `chatbot_messages_per_month`). `FeatureGateService::canAccessChatbot` Path 1 is dead; Path 2 (price ≥ $199) is the only working gate.
3. **Blog completely ungated** — `routes/web.php:128-129` (static HTML) and the `blog` API prefix at `routes/api.php:5125` have no auth, no middleware, no plan check. Free-tier reads everything. Open-by-design or open-by-omission — owner decision pending.
4. **No `STRIPE_MODE` env var** — `STRIPE_SECRET_KEY` is set but mode is implicit. Should be made explicit before production gate.
5. **No `daily-progress/` or `storage/audits/` directories on the server** — both are local-only on Windows. Server-side log discipline not yet established.

---

## Open Tasks (from masterplan BOSS888-MASTERPLAN-2026-05-07.md)

| ID | Task | Status |
|---|---|---|
| T1.1 | STATE.md created | ✅ |
| T1.2 | Backfill daily-progress May 3-7 | ⬜ |
| T1.3 | Update PLAN.md | ⬜ |
| T2.1 | PlanSeeder chatbot keys | ⬜ |
| T2.2 | BuilderRenderer injectChatbotWidget | ⬜ |
| T2.3 | Cache invalidation on chatbot toggle | ⬜ |
| T2.4 | Stripe TEST add-on verification | ⬜ |
| T3.1 | Template hero zoom/crop fix | ⬜ |
| T3.2 | Contact form audit | ⬜ |
| T3.3 | Clutter Angels workspace setup | ⬜ |
| T4.1 | KB retrieval OR logic fix | ⬜ |
| T4.2 | LevelUpGrowth homepage content | ⬜ |
| T5.1 | Blog gating — awaiting owner decision | ⏳ |
| T5.2 | Pricing changes — awaiting owner input | ⏳ |
| T6.1 | Phase 9 APP888 patches | ⬜ |
| T6.2 | Phase 10 production deployment | ⬜ |
