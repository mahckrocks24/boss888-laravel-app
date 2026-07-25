# INFRA888 — Sprint 2: Mobile A11y / Touch + Sprint-1 Closure (Session Log)

**Date:** 2026-07-24
**Mode:** Implementation + verification, staging only (droplet IS production — serves levelupgrowth.io + live customer domains).
**Build shipped:** `public/app/js/infrastructure.js?v=1.6.1-error-state` (was `1.5.1-enterprise-align`; through `1.6.0-a11y-touch` → `1.6.1-error-state`).
**Files changed (frontend only):** `public/app/js/infrastructure.js`, `public/app/index.html`. **No backend, no schema, no DB writes, PTAA untouched.**
**Backups (pre-change live files):** `/root/hosting-sprint2-20260724-052357/` (P2 deploy) · `/root/hosting-sprint2-20260724-054608/` (error-state deploy).

---

## 1. What shipped — the 3 open P2s, fixed + verified

All three were **empirically reproduced first** (baseline puppeteer measurement), fixed, then **re-measured** post-deploy. Desktop density deliberately preserved (mobile-only bumps), per the enterprise-align session's intent.

| P2 | Defect (baseline, measured) | Fix | Post-fix (measured) |
|----|------------------------------|-----|----------------------|
| **P2-1** Header actions clip @390px | Primary CTA "Add existing website" right edge at **454px** in a 390px viewport → sliced off (`clip:true`). Cause: `.head-actions` was `flex:none` → max-content width 454px, clipped by ancestor overflow (page overflow read 0 — worse than a scroll: content just vanished). | New class `infra-head-actions` + injected `@media(max-width:767px)` rule: container `flex:1 1 100%` so it's viewport-constrained and buttons wrap; buttons `flex:1 1 auto` to fill. | All header buttons ≤ **358px**, `clip:false`. "Operations"+"Create" share row 1, primary "Add existing" full-width row 2. |
| **P2-2** Touch targets < 44px | Header buttons **38px**, card buttons **36px** — under the `--touch-min:44px` token. | Classes `infra-hbtn` (headerBtn) + `infra-btn` (all btnStyle tap targets); `@media(max-width:767px){ .infra-hbtn,.infra-btn{ min-height:var(--touch-min) } }`. Desktop stays 36/38px. | All buttons **44px** @390; `sample_minHeight:"44px"`. Desktop unchanged. |
| **P2-3** Row not keyboard-operable | `.infra-site-row` was a mouse-only clickable `<div>` (`role:null, tabindex:null`, no keydown). Inner Manage button was reachable, so nothing was strictly *unreachable*, but the row had no keyboard/AT entry point. | Website **name is now the primary, focusable open control** — `<button class="infra-site-open">` (title-as-link pattern) with `aria-label` + `:focus-visible` ring. Avoids the nested-interactive anti-pattern (no `role=button` on a div containing buttons). Whole-row mouse click kept as enhancement. | Name button focusable (`tag:BUTTON`, aria-label present); **Tab→Enter opens the detail (5 tabs)** — keyboard parity confirmed. |

**Mechanism:** inline styles can't express media queries / `:focus-visible` / pseudo-styling, so the module now injects one scoped stylesheet once on mount — `ensureInfraStyles()` → `<style id="infra-responsive">`. Everything in it targets only the module's own classes.

### 1b. Deep-QA pass → one more defect fixed (v1.6.1)
A second browser cycle exercised surfaces the P2 pass didn't: Activity tab, the Add-existing wizard, an accessibility scan, and (via puppeteer request interception) the loading/empty/**error** states.
- ✅ **A11y:** landing has 7 controls, **0 without an accessible name**; detail tabs are `role="tab"` + keyboard-focusable; Activity tab translates ops to customer language with **no internal-term leak** (`provider_id`/`payload`/`awaiting_approval` raw state etc. absent); wizard steps render; genuine empty list → correct "No websites yet".
- 🔴→✅ **Error-state defect (pre-existing, now fixed).** When `GET builder/websites` returned a **500**, the landing showed **"No websites yet"** with no retry — a server error disguised as an empty account. Root cause: `req()` resolves (never rejects) for every HTTP status, so a 5xx reached the `.then`, `list` fell back to `[]`, and the `.catch` error path never ran. **Fix:** `loadYourWebsites()` now checks `if (!r.ok)` in the `.then` and renders `errorState(...)` + a working **Try again** retry (401 gets a session-expired message). Verified: 500 → "Something went wrong / We couldn't load your websites just now. / Try again", retry re-requests and re-shows the error; a real empty list still shows "No websites yet". Screenshot `screenshots\DEEP-05-error.png`.

## 2. Browser QA (real headless clicks, puppeteer on the droplet)
Harness: `/root/qa-sprint2.cjs` (baseline+postfix) and `/root/qa-behavior.cjs` (behavioral). Token: `/root/.infra-ui-token` (user 1 / ws#1 Pro; re-mint with `php /root/infra-mint2.php` — the refresh token is single-use, so re-mint before each run).
- **Responsive overflow: 0** at 1280 / 1024 / 768 / 390.
- **Honesty held:** website detail contains **zero "provision"** (`hasProvision:false`).
- **Managed upgrade continuity (Sprint-1 item): VERIFIED** — button present for eligible Pro user → click routes to `nav('billing')`. No dead button, no fake purchase. Ineligible → "Available on Pro & Agency" text.
- **Console/API:** only the pre-existing, non-Hosting 401s (`design-tokens`, `exec/mode`, `policy`, `workspace/state`, `messages`, `agents/dashboard`, `auth/refresh`). Hosting's own calls (`infrastructure/overview`, `builder/websites`) return **200**. **No new errors.**
- Screenshots: `LVL\hosting-sprint2-2026-07-24\screenshots\` (BEFORE/AFTER-websites-390, AFTER 1440 / detail-hosting / detail-domain / detail-overview / domains / email).

## 3. Sprint-1 closure verifications (the "do not skip" list)

1. **Verify Operations backend authorization (not just UI hiding)** — ⚠️ **FINDING: backend does NOT enforce it.** The intelligence endpoints (`/api/infrastructure/intelligence/{dashboard,assets,assets/{id},blast-radius,incidents,reliability}`, `routes/api.php:19245-19250`) run `auth.jwt` + `DenyApiKeyAuth` **only — no admin/role/Gate check** (`InfrastructureIntelligenceController.php`, zero `admin|authorize|Gate|abort|403|policy` matches). A **non-admin** workspace member calling them directly gets **200 with their own workspace's ops data**, not 403. The "Operations" surface hidden via a localStorage flag is **cosmetic**; Bible Law L2 (customer/control-plane split) is **not enforced server-side**. Data is still tenant-scoped. The earlier `blastRadius` cross-tenant concern was **audited and CLEARED** — `AssetGraphService::blastRadius()` runs entirely inside `WorkspaceContext::run($ws)` and every model uses the `BelongsToWorkspace` global scope, plus the entry asset is looked up with an explicit `where('workspace_id',$ws)` → 404 on a foreign id. So `affected_workspaces` can only ever contain the **caller's own** id; no cross-tenant read, no IDOR. This is therefore an **authorization-tier** question (a non-admin sees *their own* ops intelligence the UI means to reserve for admins), **not a tenant-isolation breach**. → **Needs a product decision** (see §5). Not changed autonomously — it's a production backend authz change with genuine product-intent ambiguity (the controller was originally designed as tenant-scoped-customer-readable; the admin-only decision is newer/frontend).
2. **Classify persistent 401s** — ✅ **QA-harness artifact, not a customer defect.** All are non-Hosting engine endpoints; they 401 because the limited-scope mint token (`via:infra-ui-verify`) is rejected by stricter guards on those unrelated routes. Hosting's own endpoints authenticate fine (200). Same set the enterprise-align session documented. No Hosting surface is affected.
3. **Verify Domain workflow readiness / Connect-domain wiring** — ✅ **Correctly deferred; "coming soon" validated as the right choice.** Backend investigation of `CustomDomainService` (`app/Services/CustomDomainService.php`; routes `api.php:14609-14631`) found it **UNSAFE to wire**: it writes a `domain_verified_at` column **that does not exist** on `websites`, so `connect`/`verify`/`disconnect` all **500** — and `connect()` creates a **real Cloudflare CNAME record BEFORE** the failing DB write, so wiring the button would **orphan real DNS records on production** and then error. (During this session I briefly enabled the button, immediately caught the risk, and reverted to disabled "coming soon".) → backend bug must be fixed before any frontend wiring (see §5).
4. **Validate Managed Hosting upgrade continuity** — ✅ VERIFIED (see §2).

## 4. Files & mechanics (for the next engineer)
- `infrastructure.js`:
  - `ensureInfraStyles()` (new, in the boot section) injects `<style id="infra-responsive">`; called at top of `infraLoad`.
  - `pageShell()` actions container → `class="infra-head-actions"`.
  - `headerBtn()` → `class="infra-hbtn"` + `justify-content:center`.
  - `renderWebsiteCard()`: name span → `<button class="infra-site-open" ...>`; Manage/Open + all `btnStyle` tap targets → `class="infra-btn"`.
  - `bindYourWebsites()`: binds `.infra-site-open` click → `openDetail` (whole-row mouse click kept).
  - Detail-view buttons (`infra-open-builder`, rec CTA, `infra-upgrade-managed`, `infra-connect-domain`, `infra-settings-builder`, Open website anchor, enterpriseEmpty buttons) → `class="infra-btn"`.
- `index.html`: version string `1.5.1-enterprise-align` → `1.6.0-a11y-touch`.
- Validated `node --check` before deploy; deployed via scp→/tmp→cp with `chown www-data:www-data`, `chmod 644`.

## 5. Remaining defects / recommended next sprint (priority order)
1. **[PRODUCT — decide] Operations authz not enforced server-side.** Downgraded from "security" after the blastRadius audit cleared tenant isolation (see §3). Still worth deciding: (a) add a platform-admin gate (middleware/Gate) to the 6 intelligence routes to match Bible Law L2; or (b) confirm customers *are* meant to read their own ops intelligence and keep it, treating the admin-only UI as a curation choice. No cross-tenant leak; the exposure is a non-admin seeing their *own* workspace's ops intelligence.
2. **[BLOCKER for Connect-domain] Fix `CustomDomainService`.** Add the missing `domain_verified_at` column (additive migration) **and** reorder `connect()` so the DB row is written / a transaction guards the Cloudflare create (else orphaned records on any failure). Only after this is the "Connect domain" button safe to wire (enter domain → show CNAME instructions → verify → connected). Enabling the domain feature = a real Cloudflare-mutating launch → needs product sign-off + its own test pass.
3. **Managed Hosting provisioning flow** — its own guided flow reusing the retained wizard pipeline (approval→queue→Null), turning the Billing route into a real validated upgrade once a connector ships. Still Null-connector PREVIEW today.
4. **App-wide shared-component pass** (beyond Hosting) — the unification brief.
5. **Minor:** Pro user sees "Custom domains available from Starter" on the Domain tab — entitlement-copy nuance; revisit when domains ships (depends on `entitlement.domains.available` semantics).

**Do NOT:** write more strategy docs; add a 2nd website inventory / subdomain service; expose provider/operation terms to customers; fake migration/hosting/domain success; change backend authz or run the domain migration without a decision; touch PTAA.

## Backups
`/root/hosting-sprint2-20260724-052357/` (this sprint) · `/root/hosting-align-20260723-212146/` · `/root/hosting-realign-20260723-200633/` · `/root/hosting-productization-20260723-134708/`.
