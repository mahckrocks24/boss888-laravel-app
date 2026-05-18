# BOSS888 — Living State Doc

> Single source of truth for current platform state. Updated end of each session.
> Last update: **2026-05-18 (Waves 1–9 + 13 complete)**

---

## Build state

- **Laravel**: 11.51.0 on AMS3 droplet `134.209.93.41` (web user `www-data`, path `/var/www/levelup-staging`)
- **PHP**: 8.3
- **DB**: MySQL `levelup_staging` (user `levelup`)
- **Runtime**: v2.25.3 on Railway (operational)
- **Active git branch**: `master` (uncommitted: scoring fixes + Waves 1–9 + Wave 13)
- **Cache buster (seo.js)**: `5.9.2-save-meta-fix`
- **Cache buster (blog.js)**: `1.0.1-wp-publish`

---

## What shipped this session (2026-05-17 + 2026-05-18)

### SEO scoring system (F1-F12) — verified end-to-end
Weight rebalance to sum 100, F1 articles.seo_score writeback, F2 daily authority cron, F3 weight math, F4 H1 placeholder reject, F5 readability column, F6 UI clampScore, F7 score_version, F8 syncFromArticle full inputs, F9 per-workspace weight wiring, F10 dashboard per-category aggregation, F11 live `/api/seo/knowledge`, F12 4 missing-route aliases.

### Wave 1 — 90-day chat retention + disclaimer modal
- `seo_assistant_messages` table + 90-day purge cron
- Disclaimer column on users + `accept-disclaimer` route + UI modal

### Wave 2 — Driver behavior (rules 1-4)
- R1 audit-freshness gate on article gen (2-step proposal if last audit > 7 days)
- R2 `runPreflightSweep` tool aggregation on every proposal
- R3 auto-propose when LLM recommends alternative + keyword extraction
- R4 conversational tone in narrations + system prompt TONE block

### Wave 3 — Engine ecosystem
- R6 post-article engine chain (next_proposal mechanism in `branchConfirm`)
- R7 `aiInsertLink` actually mutates HTML (preview + apply, position rules)
- R9 RuntimeClient auto-injects workspace KB into every aiRun call

### Wave 4 → 5 — Proactive notifications, then refactored to platform messaging
- Wave 4 built parallel SEO-specific notification infrastructure
- Wave 5 refactored to use existing `agent_messages` + `notifications` + `messages-ui.js` floater
- New `AgentMessageService::postAsAgent($wsId, $slug, $content)` — single entry point any agent can use

### Wave 6 — Sarah on the platform messaging pattern
- All 4 ProactiveStrategyEngine sites now dual-write `notifications` + Sarah's `agent_messages` thread
- Same pattern available for any other agent (one-line per call site)

### Wave 7 — Publish wiring + Wave-4 cleanup
- New `POST /api/write/articles/{id}/publish` orchestrates: status flip → WP push → wp_post_id persist → Priya notification
- `blog.js _blPublish` now two-step (save edits + call publish endpoint)
- Removed 4 unused Wave-4 routes (table preserved for safety)

### Wave 8 — Two-context worker labels (the user-clarified branding rule)
- `_lgseIsEmbed()` + `_lgseAgentLabel(slug)` helpers
- WP iframe → all AI surfaces collapse to "SEO AI Assistant"
- Laravel SaaS → full named team (James, Sarah, Priya, etc.)
- Sarah delegation modal routes through SEO AI Assistant in embed mode (option C: open drawer + auto-send → user gets "Shall I proceed?" proposal)
- 5 FAB/drawer "LevelUp SEO" labels normalized to bare "SEO AI Assistant"

### Wave 9 — Context-aware publish notification routing
- WP plugin caller (X-API-KEY auth) → publish notification posts to James's thread (visible in WP SEO drawer)
- Laravel SaaS caller (JWT auth) → publish notification posts to Priya's thread (Content Manager owns the surface in SaaS UX)

### Wave 13 — 404 route stubs
- 11 routes that the UI calls but had no backend handlers now return structured `{success, feature_status, message}` envelopes
- 404 count in full UI sweep: **13 → 1**
- **Wave 13b**: fixed the last 404 — `/connector/save-meta` (UI was calling `_seoApi('PATCH', '/connector/save-meta')` which mounted as `/api/seo/connector/save-meta`; corrected URL to `/save-meta` which routes to `/api/seo/save-meta` and accepts the same body)
- **Final 404 count: 0**. Every UI endpoint has a backend handler.

---

## Compliance with AI Assistant Operating Rules (all 7)

| # | Rule | Status |
|---|---|---|
| 1 | Audit-freshness preflight before article gen | ✅ Wave 2 |
| 2 | Tool sweep before proposing | ✅ Wave 2 |
| 3 | Proactive driver (auto-propose) | ✅ Wave 2 |
| 4 | Zero-SEO-knowledge user (friendly tone) | ✅ Wave 2 |
| 5 | No auto-publish, one-by-one | ✅ All waves; Wave 7 publish only on explicit user click |
| 6 | 90-day chat retention + disclaimer | ✅ Wave 1 |
| 7 | AI uses engines, never bypasses | ✅ Wave 3 (engines wired) + Wave 5 (platform messaging) |

## Compliance with two-context branding rule
- WP iframe → all AI worker surfaces show "SEO AI Assistant" only (Wave 8)
- Laravel SaaS → all AI worker surfaces show named specialists (unchanged from baseline)

---

## Active deprecations (kept for safety, can remove next sprint)

- `seo_assistant_notifications` table — no new writes since Wave 5, rows still queryable
- `'assistant_proactive'` role in `seo_assistant_messages` — no new writes
- `renderAssistant` dead-code tab handler — kept with leak labels fixed for hygiene

---

## Open known issues

| Area | Issue | Severity |
|---|---|---|
| Auto-optimization | No post-publish hook dispatches `OptimizeWpAttachmentJob` | MED (Wave 10 — needs UX design) |
| Featured image | Manual via `/pages/regenerate-image`. Not auto-chained after article create. Alt text not auto-set | MED (Wave 11 — needs UX design) |
| Calendar | `scheduled_at` plumbing exists but unreachable from chat flow | MED (Wave 12 — needs date-parsing UX) |
| `/connector/save-meta` PATCH | Last hard 404 — wrong route-prefix group, needs separate handling | LOW |
| Sarah extension | Sarah's existing calls now mirror to her chat thread (Wave 6 DONE). Other agents (Priya/Marcus/Elena) have no proactive code today | LOW (N/A until features land) |
| Git commits | All Wave 1-13 changes uncommitted on `master` working tree (safe only in tarballs) | HIGH |

---

## Crons active (relevant)
```
0  23  * * *   seo:track-ranks
0  3   * * *   seo:rank-track
30 3   * * *   seo:serp-refresh
0  0   * * *   seo:insights
0  3   * * *   seo:authority-score          # F2: weekly → daily
0  2,14 * * *  seo:outbound-check
0  4   * * 0   seo:cluster
0  4   * * *   seo:purge-chat-history       # NEW W1: 90-day chat retention
```

---

## Workspace 1 test data
- `articles.id=26` "E2E Smoke: Ergonomic Office Chairs Dubai" — kept for repeated smoke tests
- Disclaimer accepted for admin user (id=1)

INSTALL STATUS: SAFE TO INSTALL — STAGING ONLY (Waves 1-9 + 13 deployed + smoke verified).
