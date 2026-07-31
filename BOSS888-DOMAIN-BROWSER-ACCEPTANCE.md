# BOSS888 — DOMAIN PORTAL BROWSER ACCEPTANCE

**Date:** 2026-07-29 · **Status:** CHECKLIST — not executed
**Surface:** LevelUp Growth portal → Infrastructure → Domains
**Executed by:** Mark (a human session is required; headless token injection is not honoured by this SPA)

---

## Pre-conditions

| Item | Value |
|---|---|
| URL | `https://levelupgrowth.io/app` (`https://staging.levelupgrowth.io/app` also serves it) |
| Account | **Mark's own credentials.** Do not use `red@levelupgrowth.io` — its password was changed and restored on 2026-07-29 and it must not be disturbed again |
| Workspace | Any workspace whose plan grants `infrastructure` (Free does not; Starter and above do) |
| Expected domain, workspace 2 | `chefred-fcf3e2.com` — active, expires 2027-07-29, 2 name servers, auto-renew off |
| Expected domain, workspace 1 | `lvl-shop-eb6db479.com` |
| Environment | **sandbox** — no real money can be spent |

**Step 0 — hard refresh.** `Ctrl+Shift+R` (or `Cmd+Shift+R`). Cloudflare caches assets for 4 hours and both modules are new (`domains-commerce.js?v=1.0.0-domains`, `infrastructure.js?v=1.8.0-domains-20260729`). **Without this the whole checklist may test yesterday's JavaScript.**

---

## A. Navigation and discoverability

| # | Action | Expected |
|---|---|---|
| A1 | Sidebar shows **Domains** beneath Websites | present, globe icon |
| A2 | Click Domains | Domains surface, breadcrumb `Hosting › Domains` |
| A3 | Page title and description | "Domains" / "Search for a new domain, or manage the domains you already own." |
| A4 | Devtools console | **no errors**; `window.luDomains` defined |
| A5 | Network tab | `GET /api/domains` → 200 |

## B. Dashboard

| # | Action | Expected |
|---|---|---|
| B1 | Metric strip | Domains / Active / Expiring in 30 days — counts match the table |
| B2 | Table | `chefred-fcf3e2.com`, status **Active**, registered 29 Jul 2026, expires 29 Jul 2027, auto-renew **Off** |
| B3 | Registrar column/detail | **"LevelUp Growth"** — never a supplier name |
| B4 | Filter box | typing filters rows live |
| B5 | Status filter | All / Active / Expiring soon / Expired |
| B6 | Sort by Domain, Status, Expires | arrow flips on repeat click |
| B7 | Pagination | absent with 1 domain (appears above 10) |
| B8 | Responsive | narrow the window — table scrolls horizontally, **page body does not** |

## C. Search

| # | Action | Expected |
|---|---|---|
| C1 | `not a domain` → Search | inline "That does not look like a domain…"; **no network request** |
| C2 | `google.com` | already registered; suggestions shown, labelled *"We have not checked these yet"* |
| C3 | A random unregistered `.com` | **Available**, retail price, **renewal price**, "Registered until <date>" |
| C4 | Renewal price is shown | present and distinct from first-term price |
| C5 | Devtools → response body for `/api/domains/search` | **contains no** `cost_minor`, `markup`, `registrar_cost`, `namecheap`, `INFRA888` |
| C6 | Registration period selector | 1–10 years |

## D. Cart

| # | Action | Expected |
|---|---|---|
| D1 | Add to cart | toast; button becomes "In cart"; header shows **Cart (1)** |
| D2 | Open cart | line item, period selector, Remove |
| D3 | Change years to 3 | line updates |
| D4 | Totals | Subtotal, **Tax = "Calculated at checkout"**, Total due today |
| D5 | Add a second domain | both listed; subtotal sums |
| D6 | Remove one | removed; totals update |
| D7 | Reload the page mid-cart | **cart persists** (localStorage, workspace-scoped) |

## E. Checkout boundary — **STOP HERE unless authorised**

| # | Action | Expected |
|---|---|---|
| E1 | Click Checkout | redirect to `checkout.stripe.com` |
| E2 | Stripe page | line item reads **"Domain registration - <domain>"**, description mentions **LevelUp Growth**; no supplier named |
| E3 | Amount | matches the cart total |
| E4 | **Do not pay.** Press browser Back | returns to portal, **cart preserved** |

> **A completed purchase requires Mark's explicit authorisation.** It spends sandbox credit and creates a real registrar registration. If authorised, use Stripe test card `4242 4242 4242 4242`, any future expiry, any CVC — then continue to section F.

## F. Post-purchase (only if a purchase was authorised)

| # | Action | Expected |
|---|---|---|
| F1 | After payment | returns to Domains; toast *"Payment received. We are registering your domain now…"*; cart cleared |
| F2 | Wait ≤ 60s, refresh | new domain appears, status Active |
| F3 | Timeline | six steps, all done |

## G. Domain detail

| # | Action | Expected |
|---|---|---|
| G1 | Click **Manage** | detail view, breadcrumb ends with the domain |
| G2 | Overview grid | Status, Registered, Expires, Renews on, Registrar = **LevelUp Growth**, DNS managed by, Transfer lock, WHOIS privacy |
| G3 | Name servers | 2 listed, monospace |
| G4 | **Copy** | toast "Name servers copied"; paste elsewhere to confirm |
| G5 | Activity timeline | Order placed → Payment received → Registration queued → Registering domain → Registration complete → DNS configured |
| G6 | Timeline content | **no** provider codes, `refund_due`, retry counts, or supplier names |
| G7 | Refresh | toast; data re-reads within a few seconds |
| G8 | Auto-renew toggle ON | either "Auto-renew is on." **or** *"still being applied"* — and if the latter, **the checkbox returns to off** |
| G9 | All domains | returns to the list |

> **G8 is the honesty test.** The sandbox registrar accepts the change and does not apply it. A UI that shows the toggle as ON has lied. Correct behaviour is the checkbox reverting with the "still being applied" note.

## H. Tenancy

| # | Action | Expected |
|---|---|---|
| H1 | Note the domain id in the URL/network for workspace 2 | e.g. id=2 |
| H2 | Switch workspace (or log in as another workspace's owner) | Domains list shows **only that workspace's** domains |
| H3 | Devtools: `GET /api/domains/<other workspace's id>` | **404** |
| H4 | `GET /api/domains/<other id>/timeline` | **404** |
| H5 | `POST /api/domains/<other id>/auto-renew` | **404**, and no registrar call occurs |

## I. Branding sweep

| # | Check | Expected |
|---|---|---|
| I1 | `Ctrl+F` the rendered page for `INFRA888`, `BOSS888` | **0 hits** |
| I2 | Same for a supplier name | **0 hits** |
| I3 | View-source `domains-commerce.js` | no internal codenames, no supplier name |
| I4 | Detail view support contact | `support@levelupgrowth.io` |

---

## Result recording

| Section | Pass / Fail | Notes |
|---|---|---|
| A Navigation | | |
| B Dashboard | | |
| C Search | | |
| D Cart | | |
| E Checkout boundary | | |
| F Post-purchase | N/A unless authorised | |
| G Detail + timeline | | |
| H Tenancy | | |
| I Branding | | |

**Acceptance = A, B, C, D, E, G, H, I all pass.** F is optional and requires explicit authorisation.

Any failure: capture the console error, the failing network request/response, and the section number. Do not fix in place — report first, so the failure is diagnosed against the trace in the masterplan rather than patched blind.
