# ADS888 — Ad Server Core (P0.1 + P0.2)

**Shipped:** 2026-07-27 · staging
**State:** `ads_master_enabled = false`. **No advertisement can render anywhere.**
**Plan:** `LVL/ADS888-ENTERPRISE-PLAN-2026-07-27.md`

---

## What exists now

The complete demand side and decision engine. Everything needed to decide *what
advertisement should be shown* — with none of the code that actually shows it.

```
                    ┌──────────────────────────────────────┐
   NOT BUILT ─────► │  DELIVERY PLANE (P0.3)               │
   ToS-gated        │  /ads.js · /api/ads/decide           │
                    │  PublishedSiteMiddleware injection   │
                    └───────────────┬──────────────────────┘
                                    │ calls
                    ┌───────────────▼──────────────────────┐
   BUILT ─────────► │  AdDecisionService  (the waterfall)  │
                    ├──────────────────────────────────────┤
                    │  AdGateService       may we serve?   │
                    │  AdTargetingMatcher  does it match?  │
                    │  AdBudgetService     is it paid for? │
                    │  InventoryProfileService  what is it?│
                    │  AdSettingsService   the knobs       │
                    │  AdAuditService      who changed it  │
                    └──────────────────────────────────────┘
```

`ads:simulate` calls `AdDecisionService` directly, so the entire engine is
exercised against real production data **before** anything is injected into a
customer's page. When the delivery plane is finally wired, it calls the same
service this command has already validated.

---

## The waterfall

| # | Stage | Class | Fails to |
|---|---|---|---|
| 1 | Master switch, plan eligibility, exemptions, site overrides, new-site hold | `AdGateService` | no fill |
| 2 | Slot active + eligible for this template industry | `AdDecisionService` | no fill |
| 3 | Inventory profile — is this sellable to a paid campaign? | `InventoryProfileService` | house only |
| 4 | Approved creatives, live campaigns, in flight | `AdDecisionService` | house only |
| 5 | Targeting match | `AdTargetingMatcher` | house only |
| 6 | Budget + pacing | `AdBudgetService` | house only |
| 7 | Frequency cap | `AdDecisionService` | house only |
| 8 | Rank: tier ASC → eCPM DESC → weighted random | `AdDecisionService` | — |

House campaigns sit at tier 5 with no budget cap, so **fill rate is 100% and a
slot is never blank**. A sold impression (tier 1) always displaces a house ad
rather than competing with it.

---

## Safety properties (each has a test)

1. **The platform ships OFF.** `ads_master_enabled` defaults to `false` in code,
   not just in the database. A fresh environment cannot serve an ad by accident.
2. **`AdGateService` fails closed on every branch.** Any exception, missing
   record or unresolvable plan returns "no ads". A false positive here puts an
   advertisement on a paying customer's website — that is the failure this class
   exists to prevent.
3. **A missing `subscriptions` row means FREE**, resolved via
   `FeatureGateService`. A hand-written SQL join would silently *exempt* exactly
   those workspaces. Never join `subscriptions` here.
4. **An unresolvable plan is NOT treated as free.** It returns a sentinel that
   matches no eligible list, so a billing outage cannot start showing ads to
   paying customers.
5. **Nothing serves unapproved** — including our own house creative, which is
   seeded `review_state = 'pending'`.
6. **Unsellable inventory never carries a paid ad.** `source = unknown` or
   `confidence < 0.75` → house only.
7. **New sites are house-only** for `new_site_house_only_days` (default 7), so a
   brand-new AI-generated site never carries a paying advertiser's brand before
   a human has looked at it.
8. **Budget spend reads the same table the advertiser is billed from**
   (`ad_stats_daily`). If the budget check and the advertiser's report could
   disagree, one of them would be lying.
9. **`AdBudgetService::spentMicros()` fails closed on error** — it reports the
   budget as fully consumed rather than risk overdelivery that cannot be billed.

---

## A bug worth remembering

`Carbon::diffInDays()` returns a **signed** value in Carbon 3 (Laravel 11).
`now()->diffInDays($pastDate)` is therefore *negative*, and the original
new-site-hold check `... < $days` was true for every site ever created — which
silently meant **no paid advertisement could ever serve, on any site, forever**.

It produced no error and no warning. It was caught only because
`test_paid_campaign_outranks_house` asserted on the *outcome* rather than on the
absence of an exception. The fix uses an explicit instant comparison
(`greaterThan(now()->subDays($days))`) that has no sign ambiguity.

---

## Money

Integer micros everywhere. `$1.25` CPM is `1_250_000`. No floats in the billing
path at any point. CPM bills per 1000 **viewable** impressions (IAB/MRC
standard: ≥50% of pixels for ≥1 continuous second); CPC bills per click; flat
bills nothing per event and is invoiced as a lump sum.

A CPC campaign's eCPM is estimated at a deliberately conservative 0.1% CTR, so a
CPC campaign never outranks a known-value CPM campaign on a guess.

---

## Commands

```bash
php artisan ads:seed-platform [--approve-house] [--dry-run]
    Idempotent. Seeds 3 slots (only footer_sticky active), the news_channel
    eligibility exclusions from the structural audit, the house advertiser and
    campaign, and materialises settings from code defaults.

php artisan ads:simulate --all [--explain]
php artisan ads:simulate --website=3 --country=AE --device=mobile --explain
    Runs the full decision waterfall and prints what WOULD serve.
    Serves nothing. Touches no page.
```

---

## Current production state

All three published websites are on **paid** plans, so the gate correctly denies
all three (`workspace_exempt` for the platform's own site, `plan_not_eligible`
for the other two). **Free-plan published inventory is currently zero.**

---

## Not built (P0.3 — the delivery plane)

Everything that touches a live customer page:

- `GET /ads.js?w={websiteId}` — the tag
- `POST /api/ads/decide` — HTTP wrapper around `AdDecisionService`
- `POST /api/ads/event` — the measurement beacon
- `GET /api/ads/click/{token}` — signed click redirect
- `PublishedSiteMiddleware::injectAdSlots()` — slot markup at 3 return sites
- `AdTokenService` (HMAC impression tokens) and `AdEventRecorder`
- Wiring `TrafficDefenseService` to the public ad endpoints for GIVT filtering

**Blocking prerequisite: the Free-plan advertising terms must be in force.**
Until then this phase stays exactly as it is — complete, tested, and switched off.
