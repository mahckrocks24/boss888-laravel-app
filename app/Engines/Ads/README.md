# ADS888 — Ads Engine

**Phase shipped:** P0a — Inventory Intelligence
**Status:** live on staging, offline enrichment only
**Plan:** `LVL/ADS888-ENTERPRISE-PLAN-2026-07-27.md` §7A
**Owner doc:** `LVL/handoff-2026-07-27/START-HERE-ADS888-P0A.md`

---

## What this engine does today

It answers one question, per website, offline: **"what is this inventory, and may we sell it?"**

It does **not** serve ads. There is no request-path code in this engine. No route, no
middleware, no template change, no renderer change. That is deliberate — P0a was scoped
to be the phase that cannot cause a regression, and the absence of a request path is what
guarantees that.

```
app/Engines/Ads/
├── Support/
│   ├── AdIndustryTaxonomy.php    31 industries · 9 archetypes · IAB 3.0 · free-text keywords
│   └── AdInterestTaxonomy.php    43 contextual interests · industry defaults · keyword map
└── Services/
    ├── LocationParser.php        free text → ISO-3166 country/region/city + confidence
    ├── IndustryClassifier.php    the derivation cascade + the saleability rule
    ├── InterestDeriver.php       contextual interests from site-owned signals
    └── InventoryProfileService.php  orchestrates, scores, persists

app/Console/Commands/
├── AdsProfileInventoryCommand.php   ads:profile-inventory
└── AdsSyncTaxonomyCommand.php       ads:sync-taxonomy

database/migrations/
├── 2026_07_27_190000_create_ad_taxonomy_table.php
└── 2026_07_27_190100_create_ad_inventory_profiles_table.php

tests/Feature/Ads/   40 tests, 210 assertions
```

---

## The rule this engine exists to enforce

> **Inventory that is `source=unknown` or below `confidence 0.75` is never sold to a
> paid targeted campaign. It serves house ads only.**

An advertiser gets the audience they bought, or they get nothing. They are never sold a
guess. Every other design decision here follows from that sentence:

- `source` and `confidence` are columns, not metadata, because the rule has to be
  queryable at decision time.
- `evidence` is stored so a classification can be *explained* to an advertiser months
  later, or disputed by one.
- `LocationParser` returns confidence `0.00` rather than guessing a country, because a
  wrong country silently sells the wrong audience while an unresolved one shows up in the
  admin worklist and gets fixed by a human. **Fail loud, not wrong.**
- `AdIndustryTaxonomy::archetypeForIndustry()` returns `null` for an unknown slug, unlike
  `TemplateArchetypes::archetypeOf()` which defaults to `professional_advisory`. That
  default is right for the builder (it must render *something*) and wrong here (it would
  sell a bakery to a law-firm campaign).

Call `IndustryClassifier::isSellableForTargeting($source, $confidence)` — do not
re-implement the threshold at the call site.

---

## The derivation cascade

First resolution wins; the winner is recorded in `source`.

| # | `source` | Signal | Confidence |
|---|---|---|---|
| 1 | `explicit` | Admin override already on the profile | 1.00 |
| 2 | `template` | `websites.template_industry`, **only if it validates** against the 31 slugs | 0.95 |
| 3 | `classified` | `workspaces.industry` + business name → keyword match | 0.75 |
| 3b| `classified` | archetype only (no slug matched) | 0.50 |
| 4 | `ai` | Classify published site content — **not implemented in P0a** | — |
| 5 | `unknown` | Nothing resolved | 0.00 |

Step 2 matters more than it looks. Website 62 carries `template_industry = 'travel'`,
which is **not** one of the 31 slugs (`travel_agency` is). Validating rather than trusting
is what stops that value poisoning the profile; it falls through to step 3 and resolves
correctly. There is a regression test for exactly this.

---

## Contextual interests — a hard legal boundary

**An interest here describes the SITE, never the VISITOR.**

Every signal `InterestDeriver` reads is a property of the published site: its SEO
keywords, its stated services, its own template copy, its article titles. None of them
observe a person.

Clause 9 of the published Free-plan advertising terms states that we do not use cookies,
cross-site identifiers or behavioural profiling to select advertisements. The moment an
interest is derived from observing a visitor rather than reading a page, that clause
becomes false, consent management becomes mandatory across every tenant site, and the
cookieless position — a genuine commercial differentiator — is gone.

**Adding a visitor-derived signal to this engine requires a legal review, not a code
review.**

---

## Reused rather than rebuilt

| Asset | Where | Why it mattered |
|---|---|---|
| `TemplateArchetypes` | `app/Engines/Builder/Support/` | Already a 31→9 taxonomy *and* a battle-tested free-text classifier. Duplicating it would guarantee the builder and the ad server eventually disagree about what a business is. |
| `seo_keywords.cpc` | existing table | What advertisers already pay for a topic elsewhere — the best available proxy for what this inventory is worth. Surfaced in `evidence.top_commercial_keywords`. |
| `workspace_memory` | existing table | Owner-stated `services`, `target_audience`, `differentiators`. |

Still to be reused in P0 (not this phase): `TrafficDefenseService` already performs bot
detection and quality scoring into `traffic_logs`, but `TrafficDefenseMiddleware` skips
public routes. The ad invalid-traffic gate needs it **wired**, not written.

---

## Commands

```bash
php artisan ads:sync-taxonomy [--dry-run]
    Project the code-defined vocabularies into ad_taxonomy.
    Upsert-only: codes are retired (is_active=false), never deleted, because a
    live campaign may already target one.

php artisan ads:profile-inventory [options]
    --dry-run          run the real cascade, write nothing, print the table
    --website=62       one site
    --stale            only unprofiled sites, or profiles past stale_after
    --force            re-derive even where an admin override exists
    --report           print the unclassified worklist (the ad-ops queue)
    --prune            reap profiles whose website is gone or soft-deleted
    --limit=N          cap the batch
```

**Not scheduled yet, deliberately.** A nightly `--stale` run is the intended steady state,
but auto-reprofiling before a human has validated the classifier would bury bad
classifications under a daily rewrite. Schedule it after the first admin review.

---

## Invariants — do not break these

1. **`source='explicit'` is never overwritten by automation** unless `--force`. An admin
   override outranks every derived signal, permanently.
2. **Profiling is idempotent.** Re-running produces the same row.
3. **Unresolved stays unresolved.** No guessing to make the numbers look better.
4. **No FK from `ad_inventory_profiles` to `websites`.** `websites` soft-deletes and the
   sites directory holds ~250 orphans; a cascading FK would either block deletes or
   destroy profile history. Orphans are reaped explicitly by `--prune`.
5. **Only ever store interest codes that exist in the vocabulary.** A code outside
   `AdInterestTaxonomy::INTERESTS` is not targetable and must not be persisted.
6. **This engine writes exactly one table:** `ad_inventory_profiles` (plus `ad_taxonomy`
   via the sync command). It reads `websites`, `workspaces`, `pages`, `seo_keywords`,
   `workspace_memory`, `articles`.

---

## Known gaps (carried into P0)

- **AI classification (cascade step 4) is not implemented.** It costs credits per site and
  needs a spend decision. Sites that reach it are left `unknown` and appear in `--report`.
- **No admin HTTP surface.** P0a adds no routes — `routes/api.php` is under concurrent
  edit by another workstream, and the console command delivers the capability without
  touching it. The admin queue UI lands with the rest of the console in P0.
- **No onboarding capture.** Controlled-vocabulary industry and structured location should
  be captured in the build wizard so new sites arrive pre-classified. That requires
  changes to `ArthurService` (4,452 lines, the build brain) and was deliberately excluded
  from a phase whose contract was "no regression, no template risk".
  **Without it, the free-text gap regenerates with every new site.**
- **Location lexicon is finite.** It covers the countries and cities this platform serves.
  It is not a geocoder and will not resolve an arbitrary village — by design, see the
  fail-loud-not-wrong rule above.
