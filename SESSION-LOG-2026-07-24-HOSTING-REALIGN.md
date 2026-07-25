# INFRA888 — Hosting Product Realignment (Session Log)

**Date:** 2026-07-24
**Mode:** Implementation, staging only (the droplet IS production — serves levelupgrowth.io + live customer domains).
**Driver:** the 2026-07-23 forensic audit — the Builder already delivers hosting, so provisioning-first was wrong.
**Strategy (LOCKED):** two website types, one product.
- **Type 1 Native** (built by the Builder, already live on `*.levelupgrowth.io`) → begins at **Manage hosting**, NEVER "Provision".
- **Type 2 External** (WordPress/Laravel/PHP/static) → begins at **Add existing website → Provision/Migrate**, then becomes managed.

Engine/back-end untouched; codename never rendered. Cache-bust `infrastructure.js?v=1.4.0-hosting-realign`.

---

## Phase 1 — Website classification (recommendation + implementation)

**Recommendation: reuse the existing, currently-unused `websites.platform` column. Introduce NO new field.**
Evidence: all 28 websites have `platform = NULL`, `connector_status = 'none'`, `external_url` empty — every current site is Native. The classifier is therefore:

```
origin = platform ∈ {wordpress,laravel,php,static,external,custom,other} ? 'external' : 'native'
```

Native is the safe default (empty platform, which is what the Builder writes). The future "Add existing website" flow is the only thing that will set `platform` to an external value. Zero migration, zero risk to the 28 live/draft rows, and classification is derived client-side from data `GET /builder/websites` already returns. Implemented as `siteOrigin()` in the SPA.

## Phase 2 — Hosting landing = "Your websites"

The Hosting tab no longer lists hosting *accounts*; it lists the customer's **websites** (from `GET /builder/websites`, the real inventory — no second source). Each card shows: name, origin badge (Built with LevelUp / WordPress…), status (Live/Draft/Not set up yet), primary address (custom domain if verified, else subdomain), SSL, custom-domain status, and hosting state. Header carries **Add existing website** (the Type 2 entry).

Actions by type (Phase 2 spec, met):
- **Native:** Manage hosting · Launch website. **Never** Provision.
- **External live:** Manage hosting · Launch website.
- **External not-set-up:** Finish setup.

## Phase 3 — Commercial alignment

The landing states the truth for **every** plan: *"Every website you build with LevelUp is hosted for you — live, secured and included in your plan."* No "not included" wall on the hosting surface. What upgrades unlock is surfaced on the Manage view (custom domain, Managed Hosting) rather than gating the base experience. No duplicate purchase: a live site is shown as already hosted, never re-sold.

## Phase 4 — Managed Hosting (premium)

Introduced as the upgrade on the Manage view: *"A dedicated, faster runtime with backups, advanced monitoring and priority deployment."* CTA **Upgrade to Managed Hosting** shows for Pro/Agency (`hosting.can_provision === true`); other plans see *"Available on Pro & Agency."* This is where provisioning belongs — not on already-hosted native sites. (CTA is currently an inert placeholder — no fake purchase; wiring it to the existing provisioning pipeline is follow-up.)

## Phase 5 — WordPress / External onboarding (designed)

**Add existing website** wizard: Type → Address → Check → LevelUp address → Review → **Start migration** → a clear *"We've got it from here"* hand-off. Migration is NOT implemented (per strategy); the flow validates input, reserves a LevelUp address (live availability via the same `builder/check-subdomain` the Builder uses), and hands off. Wiring the final step to the existing provisioning pipeline (to create the receiving shell) is a one-line change (`submitExternal`).

## Phase 6 — Upgrade experience

Manage view = an "included" checklist (✓ Hosted, ✓ SSL, ✓ address) + an **Upgrade this website** panel: Custom domain (Connect domain), Managed Hosting, Business email (Coming soon), Backups (Coming soon). Natural, non-nagging, commercially clear.

## Phase 7 — Plain language

No "Provision hosting" on native sites (verified in-browser: the Manage view contains zero occurrences of "provision"). Removed/avoided: infrastructure, provider resource, operation, lifecycle, hosted site — replaced with website / hosting / included / address / manage.

## Phase 8 — Future readiness

Classification (`EXTERNAL_PLATFORMS`, `PLATFORM_LABEL`), the card action-map, and the external wizard's platform list (`EXT_PLATFORMS`) are all data-driven — adding Laravel/static/new providers is a data edit, not a UI redesign.

---

## Verified in-browser (real SPA, real auth, ws#1 Pro)

- Landing "Your websites" renders the native live site with **Manage hosting** + **Launch website**; **Add existing website** present.
- Native **Manage** view: included checklist + upgrade panel; **contains no "provision" text**.
- External wizard: WordPress → URL → check → LevelUp address (live availability green) → review → "We've got it from here."
- **0 page errors.** (3 pre-existing 401s on `/exec/mode`, `/design-tokens`, `/policy` — unrelated, appear regardless.)

## Success criterion — met
A customer immediately sees the difference: a native site says *"already hosted by LevelUp, included"* (Manage), while *"bring my existing website in"* is a distinct, clearly-labelled path (Add existing → migrate) — one unified Hosting product, two journeys, aligned to the plan model (free shared hosting everywhere; custom domain Starter+; Managed Hosting Pro/Agency).

## Remaining implementation work
1. **Real hosting connector** — until it ships, both "Managed Hosting" and external migration are prepared, not live.
2. **Wire "Upgrade to Managed Hosting"** to the existing provisioning pipeline (managed context).
3. **Wire "Connect domain"** to `CustomDomainService` (connect/verify/disconnect already exist).
4. **External migration** — validation probe + content move (designed only here).
5. **Persist `platform`** on the Add-existing flow when the receiving shell is created, so imported sites classify as external.
6. Decide Business Email/Backups (build as priced add-ons or keep "coming soon").

## Files changed (staging)
- `public/app/js/infrastructure.js` — new Your-Websites landing, website cards, Manage view, Add-existing wizard, classifier; hosting-tab loader rewired to `loadYourWebsites()`. Old `renderHostingList`/managed-wizard retained as dead-but-valid pipeline code.
- `public/app/index.html` — version bump to 1.4.0.
- Backup: `/root/hosting-realign-20260723-200633/` (infrastructure.js + index.html, pre-change).
