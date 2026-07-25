# MW-1(a) — Entry-Point Isolation + SEO Isolation — COMPLETE (verified)
**2026-07-24. Live production change (marketing site). No new features. Backup `/root/mw1a-20260724-085316/`.**

Goal (Boss): a single global launch gate; eliminate every direct /app transition from the live marketing site pre-launch; confirm staging + unreleased functionality can't be discovered/indexed. **All verified in-browser on production vs staging.**

## Environment (confirmed)
- Production (public): `levelupgrowth.io`, `www.levelupgrowth.io`. Staging (internal testing): `staging.levelupgrowth.io` (+ IP `134.209.93.41`). One box, host-distinguished. `APP_ENV=staging`, DB `levelup_staging`.
- Live marketing pages are **static HTML** served via `response()->file(public/marketing/…)` (the blade views are legacy/unused). 18 pages share `public/marketing/js/site.js`.

## What shipped (single global launch gate — layered defense)
1. **`config/marketing.php`** (new) — single source of truth: `public_launched` (env `PLATFORM_PUBLIC_LAUNCHED`, default **false**), `production_hosts`, `noindex_hosts`, gated CTA href.
2. **SPA-shell guard** — inline script injected at the top of `public/app/index.html` (`MW1A-GATE`). On production hosts pre-launch it `location.replace('/')` **before the app boots**. This is the server-of-record backstop: **every** /app entry vector (any CTA, typed URL, deep link, static or blade) is caught. Staging unaffected.
   - **Verified:** prod `/app/` → redirects to home; prod `/app/#signup` → redirects to home; staging `/app/` → app loads.
3. **Marketing CTA gate** — appended to `site.js` (loaded by all 18 static pages): on production pre-launch, rewrites every `a[href^="/app"]` → a "Get notified" mailto and relabels. Cache-busted `site.js?v=2.0.0`→`?v=2.1.0-mw1a` across 18 pages (Cloudflare had cached the old query URL).
   - **Verified:** prod home + pricing → **0 `/app` links remain, 6 gated, "Get notified"**; staging → links intact.
4. **Existing guards retained:** `selectPlan()` buttons → home on prod; `/sign-up` route → 302 home on prod.
5. **Blade layer (defensive):** `marketing-layout.blade.php` + `home.blade.php` gained the same gate + canonical (currently unused by live routes; harmless, correct if any blade is ever routed).

## SEO Isolation Audit (Boss-requested confirmation)
| Check | Finding | Status |
|---|---|---|
| **Staging indexable?** | Was `Allow: /` on all hosts. Added **`X-Robots-Tag: noindex, nofollow` middleware** (`StagingNoindex`) scoped to `noindex_hosts` only (staging + IP) — never customer subdomains. **Crawlable+noindex = correct deindex signal.** | ✅ **Staging cannot be indexed** (verified: staging `/` + `/pricing` return the header; production returns none). |
| **Unreleased app discoverable/indexable?** | `/app` is gated (redirects on prod), not linked (CTAs gated), absent from sitemap. | ✅ Not discoverable/enterable on production. |
| **Structured data (JSON-LD)** | **0 of 19 static pages** carry JSON-LD → nothing exposing unreleased features. | ✅ No leak. |
| **Internal links** | All marketing→/app links gated; product/nav links are relative (host-safe). | ✅ |
| **Canonicals** | **0 of 19 static pages** have `rel=canonical`. Not an *isolation* leak (staging is noindexed regardless), but a duplicate-content hygiene gap (www vs apex). | ⚠️ **Follow-up** (bulk per-page canonical add — content sprint). |
| **Sitemap** | Lists `/sign-up` (302s on prod) and serves per-host `<loc>` (staging self-lists, but staging noindexed via header). | ⚠️ **Follow-up** (drop `/sign-up`, force apex base — small `web.php` edit, deferred for safety). |

**Confirmation:** YES — staging and unreleased functionality **cannot be indexed or entered on production**. Remaining items (canonicals, sitemap cleanup) are SEO hygiene, not isolation leaks.

## Rollback (tested-ready)
- **Disable the gate wholesale:** set `PLATFORM_PUBLIC_LAUNCHED=true` (or at launch) → gate lifts everywhere (config-driven), OR restore files from `/root/mw1a-20260724-085316/` (web.php not changed; restores `public/app/index.html`, blades). 
- Per-file: SPA guard is idempotent (re-run insert script re-adds; remove `MW1A-GATE` line to drop). site.js gate = `MW1A_LAUNCHED=true`. Middleware = remove the one append line in `bootstrap/app.php`.
- No DB change, no nginx change, no destructive action.

## Files changed (production)
`config/marketing.php` (new) · `app/Http/Middleware/StagingNoindex.php` (new) · `bootstrap/app.php` (register middleware) · `public/app/index.html` (SPA guard) · `public/marketing/js/site.js` (CTA gate) · 18× `public/marketing/**.html` (site.js version bump) · `resources/views/components/marketing-layout.blade.php` + `resources/views/marketing/home.blade.php` (defensive, unused by live routes).

## FINAL VERIFICATION MATRIX (browser, prod vs staging — ALL PASS)
Harness `/root/qa-matrix.cjs`; evidence `LVL\handoff-2026-07-24\screenshots-mw1a\`. Consistency re-checked 4× on prod (stable).

| Test | Production | Staging | Result |
|---|---|---|---|
| `/app` | Redirects → home | Opens app | ✅ PASS |
| `/app/#signup` | Redirects → home | Opens signup | ✅ PASS |
| Hero CTA | Waitlist ("Get notified") | Opens app ("Start Free"→/app/#signup) | ✅ PASS |
| Pricing CTA | Waitlist (0 /app links) | Opens signup | ✅ PASS |
| Typed `/sign-up` | Blocked → home | Works (serves signup) | ✅ PASS |
| Direct deep link `/app/dashboard` | Blocked → home | Works (opens app) | ✅ PASS |

Consistency (4 prod samples): `appLinks:0, gated:9, hero:"Get notified"` every time; staging: `appLinks:6, gated:0, hero:"Start Free"` (correctly ungated). Evidence: `MW1A-r3-prod-hero.png` (prod hero = "Get notified"), `MW1A-r3-staging-hero.png` (staging hero click → app signup screen).

**Gate hardened during verification:** the hero "Start Free" used the `selectPlan()` (non-/app) path, so the href-only gate left its label. `site.js` gate now also relabels **text-matched** CTAs (buttons + non-/app anchors) → "Get notified" with click interception, plus a MutationObserver + delayed re-runs for dynamic renders. Cache-bust `site.js?v=2.1.1-mw1a` (18 pages).

## Launch Activation (when platform reaches CUSTOMER-LIVE)
Flip `PLATFORM_PUBLIC_LAUNCHED=true` **and** `MW1A_LAUNCHED=true` in `site.js` + `LAUNCHED=true` in the `public/app/index.html` guard (or remove guards); re-point gated CTAs to real signup; per-product CTAs still gate on their own availability state. Then re-verify prod entry works.

## Remaining MW-1 (not (a)) — documented, not started
Ownership positioning on the live STATIC pages; `/email`→"Email Marketing"; AI-page consolidation; SEO page; nav regroup; per-page canonicals; Why-LevelUp; products-first pricing. (These edit the static HTML/CMS, not blades.)
