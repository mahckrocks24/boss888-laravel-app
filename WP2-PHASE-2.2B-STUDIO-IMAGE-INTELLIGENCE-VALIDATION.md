# WP2 PHASE 2.2B — STUDIO IMAGE INTELLIGENCE VALIDATION

**Date:** 2026-07-31 · **Status:** ⏸️ **BLOCKED before validation — Studio flag returned to false as instructed**
**Functional validation:** not run · **Visual-quality validation:** BLOCKED (runtime force-low)
**Images generated: 0** · **No brand kit was written** · **No provider call made**

---

## 1. Why the original flag was unsafe

`config('studio.image_intelligence_enabled')` was read at **two** call sites:

| Call site | Surface |
|---|---|
| `StudioAiService::generateImage()` | interactive Studio — the intended target |
| `CreativeService::getBlueprint()`, `$type === 'image'` | the Creative engine |

`CreativeService` is reached by `BackfillFeaturedImagesCommand` (**blog featured images**), `BuilderService`, `BellaController` (2 sites) and the SEO `generate_image_mini` action. One flag therefore could not express "Studio only": turning it on would have moved blog featured-image generation in the same instant.

## 2. Scoped-key implementation

A second key, read **only** by Studio:

```php
// config/studio.php
'image_intelligence_enabled'        => env('STUDIO_IMAGE_INTELLIGENCE_ENABLED', false),        // CreativeService
'image_intelligence_studio_enabled' => env('STUDIO_IMAGE_INTELLIGENCE_STUDIO_ENABLED', false), // StudioAiService only
```

```php
// StudioAiService::generateImage()
if (config('studio.image_intelligence_enabled', false)
    || config('studio.image_intelligence_studio_enabled', false)) {
```

Two lines. `CreativeService` still reads only the original key. Verified by execution: `CreativeService::getBlueprint(…, 'image', …)` returns the 9-key legacy enrichment (`brand_context`, `visual_style`, `stored_blueprint_ids`, `influence_applied`, …) and **not** `gated_canonical`.

### Concurrency on `config/studio.php`

The file is root-owned and was last edited by the other session at 04:41 on 2026-07-30. My change is **purely additive** — one new key appended after the existing one — and touches no line they wrote. Their `image_intelligence_enabled` entry is byte-identical to before.

## 3. Route exposure — and why the flag is now false

```
POST api/studio/ai/generate-image
middleware: api, JwtAuthMiddleware, TrafficDefenseMiddleware,
            ConnectorBrandFilter, ThrottleRequests:20,1
```

**No workspace scoping of any kind.** Any authenticated user in any customer workspace reaches it.

Existing scoping mechanisms searched:

| Candidate | Verdict |
|---|---|
| `infra_entitlement_definitions` / `infra_plan_entitlements` | plan entitlements, not a feature allow-list — wiring them is new architecture |
| `config/platform_events.producer_workspace_allowlist` | a different subsystem's list; reusing the *pattern* means a new key plus a new check in Studio — new architecture |
| anything workspace-scoped in `config/studio.php` | none exists |

Per the instruction — *"If it is broadly available and no existing safe workspace allow-list exists: set `STUDIO_IMAGE_INTELLIGENCE_STUDIO_ENABLED=false` until validation is complete"* — **the flag has been set to false.**

```
studio.image_intelligence_enabled        = false
studio.image_intelligence_studio_enabled = false
```

Every workspace, including Studio, is on legacy behaviour.

## 4. Canonical Chef Red workspace — **workspace 2**

| | ws 2 | ws 5 | ws 6 |
|---|---|---|---|
| Name | **Chef Red Raymundo** | Chef Red's Workspace | Chef Red's Workspace |
| Slug | `chef-red-raymundo-58ov` | `chef-red-mdbp` | `chef-red-wmsx` |
| Owner | **red@levelupgrowth.io** | chefredraymundo@gmail.com | red.raymundo223@gmail.com |
| Created | 2026-05-06 07:57 | 2026-05-11 19:01 | 2026-05-11 19:08 |
| Assets | **327** | 0 | 0 |
| Studio designs | **31** | 0 | 0 |
| Creative jobs | **2** | 0 | 0 |
| Websites | **1** ("Chef Red Raymundo") | 0 | 0 |
| Industry | **private chef** | null | null |

**Selected: workspace 2.** It is the only one with any content, the only one owned by a `@levelupgrowth.io` address, the earliest created, and the only one with a website. Workspaces 5 and 6 are empty self-signup duplicates created five days later, minutes apart. It contains real customer-adjacent data (327 assets), so any write must be minimal and governed.

No duplicate kits will be created across the three.

## 5. Brand-kit recovery — **the kit never existed**

Searched in the prescribed order:

| # | Source | Result |
|---|---|---|
| 1 | Pre-wipe backup `db-20260730-0100.sql.gz` | `studio_brand_kits` contains **exactly two rows: ws1 and ws13**. No ws2. |
| 1b | **Nine daily backups**, 2026-07-24 → 2026-07-29 | ws2 kit: **none, in every one** |
| 1c | `creative_brand_identities` in backup | one row, **workspace 26**. No ws2. |
| 2 | Preserved incident evidence (`/root/p1c-activation-evidence/`) | no brand-kit material |
| 3 | Exported brand-kit files on disk | none found |
| 4 | Application audit history (`audit_logs LIKE '%brand%'`) | **no rows** |
| 5 | Previously generated brand assets on ws2 | **no logo asset**; `workspaces.brand_color` and `logo_url` both null; only `industry = "private chef"` |
| 6 | Manual re-entry from values confirmed by Mark | **the only remaining source** |

### Correction to my Phase 2.2B report

I previously wrote that Chef Red "has no brand kit **post-wipe**", which implied the 13:50 wipe destroyed it. **That was wrong** — I inferred causation from timing. Nine consecutive daily backups predating the wipe show no ws2 row in either brand table. Chef Red has **never** had a brand kit. The wipe is not implicated.

### Why I did not seed one

`studio_brand_kits` requires primary/secondary/accent/background/text colours, heading and body fonts, and optionally logo, dark logo, brand name and tagline. The schema defaults are `#6C5CE7 / #00E5A8 / #F59E0B / Syne / DM Sans` — **the LevelUp Growth house style**. Both existing kits (ws1, ws13) are byte-identical to those defaults, i.e. unconfigured rather than curated.

Seeding those values as Chef Red's brand would misrepresent a real customer's brand **and** invalidate the white-label test, whose entire purpose is to prove the absence of LevelUp branding. Inventing plausible "private chef" colours would be worse: validation output would look authoritative while proving nothing.

**No write was performed.** The dry-run/confirm-token setup command is not built either, because there are no authoritative values for it to write.

## 6. Validation cases run: **none**

Zero images generated. Zero provider calls. Zero cost incurred. The instruction *"Do not validate using the workspaces-table fallback"* is precisely the condition that holds today.

## 7. Quality limitation

`ImageIntelligenceService` documents that the runtime "force-caps gpt-image-1 to `low`". That runtime is the Railway Node service, for which no access exists from here.

- **Part A — functional validation:** not yet run (blocked on the brand kit), but *would* be valid under the cap.
- **Part B — visual-quality validation:** **BLOCKED**, and must stay blocked until the cap is removed and the same controlled cases are rerun at intended quality.

## 8. Regression status

No regressions. The only code change is the additive scoped key; with both flags false the system is byte-for-byte on legacy behaviour. Route count unchanged at 1040; `/api/health` and `/admin/dashboard` return 200. `RuntimeClient` untouched. Full routing-regression suite (items 1–14) is **not yet run** — items 12–14 depend on a restored brand kit.

## 9. Final state

```
studio.image_intelligence_enabled        = false   (CreativeService / blog / Builder / Bella / SEO — legacy)
studio.image_intelligence_studio_enabled = false   (StudioAiService — legacy)
workspace scope                          = none enabled
images generated                         = 0
brand kit written                        = none
```

**Rollback:** nothing to roll back. To re-enable Studio later: `STUDIO_IMAGE_INTELLIGENCE_STUDIO_ENABLED=true`. To remove the scoped key entirely, delete the `config/studio.php` entry and the second clause in `StudioAiService::generateImage()`.

## 10. What is needed to proceed

**From Mark — Chef Red's actual brand values** (source 6, the only one left):

| Field | Required |
|---|---|
| `primary_color`, `secondary_color`, `accent_color` | yes — hex |
| `background_color`, `text_color` | yes — hex |
| `heading_font`, `body_font` | yes |
| `brand_name` | recommended (defaults to "Chef Red Raymundo") |
| `tagline` | optional |
| `logo_url`, `logo_dark_url` | needed for meaningful white-label and override tests; must be reachable |

With those, the controlled write is one row in `studio_brand_kits` for workspace 2 — dry-run first, before/after values, no overwrite (none exists), a governance audit row naming the authorising operator, asset URLs verified reachable, no provider call.

**And a scoping decision**, since the Studio route is open to all workspaces. Options, cheapest first:

1. **Validate with the flag enabled for a short, supervised window**, with the flag off before and after — no new architecture, but every workspace is briefly on the canonical path.
2. **Add a workspace allow-list to the Studio gate**, mirroring the platform-events pattern (one config key, one check). Small, but it is new architecture and this milestone forbids it without a decision.
3. **Validate in an isolated environment** rather than production.

## 11. Recommendations

**Runtime cap.** Removing the gpt-image-1 force-low cap requires Railway access, which does not exist here. Until then no image path — Studio or otherwise — can be certified production-quality. This should be raised as its own blocker rather than carried silently through phases.

**Next surface.** None. Expansion stays prohibited: `CreativeService` needs its own scoped flag, each of its four callers (blog backfill, Builder, Bella, SEO) needs migrating independently, and final-quality validation must pass first. The global key must not remain the only switch for multiple product surfaces.

---
---

# PHASE 2.2B.1 — WORKSPACE SCOPING: STOPPED ON A PRIOR FINDING

**Date:** 2026-07-31 · **Status:** ⏸️ **STOPPED — the live Studio route bypasses the feature flag entirely**
**Allow-list: NOT built** · **Brand kit: NOT written** · **Images: 0** · **Provider calls: 0**

---

## 1. Corrections recorded, as instructed

- The production wipe **did not** remove a Chef Red brand kit.
- **No Chef Red brand kit existed in any inspected backup** — nine consecutive daily dumps, 2026-07-24 → 2026-07-29, plus the 01:00 pre-wipe dump. `studio_brand_kits` holds only ws1 and ws13 throughout; `creative_brand_identities` only ws26.
- My earlier statement implying post-wipe loss **was incorrect**. I inferred causation from timing.
- **Workspace 2** is the canonical Chef Red workspace (`Chef Red Raymundo`, owner `red@levelupgrowth.io`, created 2026-05-06, 327 assets, 31 studio designs, 2 creative jobs, 1 website, industry `private chef`).
- **Workspaces 5 and 6** are empty duplicate self-signups (created 2026-05-11, minutes apart, zero of everything) and must not receive duplicate kits. Neither was modified.

## 2. THE BLOCKING FINDING — the flag does not control the live route

`POST /api/studio/ai/generate-image` is defined at
**`routes/api/authenticated/studio-02.php:1496`** (owner `www-data`, mtime **2026-07-28 12:24:46**):

```php
Route::post('/ai/generate-image', function (\Illuminate\Http\Request $r) {
    $wsId = (int) $r->attributes->get('workspace_id');
    if ($wsId <= 0) return response()->json(['error' => 'workspace_required'], 400);
    $gate = app(\App\Core\Billing\FeatureGateService::class);
    if (!$gate->canUseAI($wsId)) { /* 403 */ }
    …
    $out = app(\App\Core\ImageIntelligence\ImageIntelligenceService::class)->generate([
        'source' => 'studio', 'workspace_id' => $wsId, …
    ]);
```

**Flag references inside that closure: 0.** It calls `ImageIntelligenceService` **unconditionally** and never touches `StudioAiService::generateImage()`.

### Consequences

1. **ImageIntelligence has been live for every workspace since 2026-07-28**, irrespective of `studio.image_intelligence_enabled` and `studio.image_intelligence_studio_enabled` — both of which are `false` right now.
2. **`StudioAiService::generateImage()` is orphaned for this route.** The only `generateImage` call sites in `routes/` are `CreativeService::generateImage` (`content-01.php:83`, `api.php:5662`, `api.php:6946`) — the legacy Creative path.
3. The scoped key I added in Phase 2.2B is real, correct code sitting on a path this route does not use.

### Two of my earlier statements were therefore wrong

- *"Studio route now on canonical when the flag is enabled"* — the route was **already** canonical, unflagged, before I touched anything.
- *"Flag returned to false → back to legacy for everyone"* — setting it false **changed nothing** for the live HTTP route. Only `StudioAiService` (unused here) and `CreativeService` (already false) respond to those keys.

### What changed on 2026-07-28

The preserved backup `studio-02.php.bak-P6-20260728` (2026-07-27 20:18) shows the previous form:

```php
Route::post('/ai/generate-image', fn($r) => $studioAiGate($r, 'generateImage', 8, 'studio_ai_generate_image'))
```

`$studioAiGate` resolved workspace, checked the plan gate, charged credits and called `StudioAiService` — which **would** have honoured the flag. Some time on 2026-07-28 the route was rewritten to call `ImageIntelligenceService` directly, removing the flag from the path.

## 3. Authenticated workspace context — trustworthy

The stop condition about workspace context is **not** triggered. The route reads
`$r->attributes->get('workspace_id')`, set server-side by `JwtAuthMiddleware`
(`$request->attributes->set('workspace_id', …)`, with `workspace_users` role
re-verification and the ambiguous null-claim fallback already removed). It is never
taken from the request payload, and `<= 0` is rejected with `workspace_required`.
Every other handler in `studio-02.php` uses the same source.

So an allow-list keyed on that value would be sound — but there is nothing to key it
to until the route consults a flag at all.

## 4. Why I did not build the allow-list

Adding it to `StudioAiService::generateImage()` would have produced a gate that
provably does nothing for the live route: green tests, zero real effect. That is the
failure mode this programme has repeatedly paid for.

Making it effective requires editing the **live route closure** — which:

- changes behaviour for **every** workspace on a customer-facing feature that has been
  running the canonical pipeline for three days;
- means non-allow-listed workspaces move canonical → legacy, a real behaviour change,
  not a no-op;
- touches `routes/api/authenticated/studio-02.php`, last edited by the other
  engineering session on 2026-07-28.

That is a decision, not an implementation detail, so it is reported rather than taken.

## 5. `config/studio.php` concurrency

| | value |
|---|---|
| owner / mtime before | `www-data` / 2026-07-30 14:15:19 |
| sha256 before | `d5c9dcdb4db0edae37569b45525e28cd1408d15a465d66043d6ff6f105e73b00` |
| change made this phase | **none** |

The Phase 2.2B addition (`image_intelligence_studio_enabled`) remains the only edit,
purely additive, touching no line the other session wrote. No drift was sealed.

## 6. Force-low runtime blocker — evidence

From `app/Core/ImageIntelligence/ImageIntelligenceService.php`:

- line ~113 — `// 5) provider request (quality forwarded — runtime honours what it can)`
  then `$this->runtime->imageGenerate($compiled['provider_prompt'], [...])`
- line ~130 — `// 6) capture ACTUAL provider params (runtime forces low today) + real cost`
- line ~139 — `// (gpt-image-1 spells badly, esp. at forced-low quality)`
- line ~179 — `// The runtime can downgrade quality (today it force-caps gpt-image-1 to 'low'); the customer must only pay for what was …`
- line ~203 — persists `provider_response.quality = $actualQuality`, i.e. the value the
  runtime returned, not the value requested.

The cap lives in the Railway Node runtime, unreachable from this host. Functional
testing remains possible; **production-quality certification remains blocked**, and
expansion to blog, Builder, Bella or any customer-facing surface stays prohibited.

## 7. Final state

```
studio.image_intelligence_enabled                     = false
studio.image_intelligence_studio_enabled              = false
studio.image_intelligence_studio_workspace_allowlist  = NOT CREATED
route POST /api/studio/ai/generate-image              = canonical for ALL workspaces (unflagged)
brand-kit writes                                      = 0
images generated                                      = 0
provider calls                                        = 0
route count                                           = 1040 (unchanged)
```

## 8. Readiness conditions for validation

| # | Condition | Status |
|---|---|---|
| 1 | The Studio route consults a feature flag | ❌ **it does not** — must be decided |
| 2 | A Studio-scoped workspace allow-list exists | ❌ blocked on 1 |
| 3 | Chef Red brand kit approved and written | ❌ awaiting Mark's values |
| 4 | Force-low cap removed | ❌ needs Railway access |
| 5 | Authenticated workspace context trustworthy | ✅ confirmed |
| 6 | Canonical workspace identified | ✅ workspace 2 |
| 7 | Non-Studio surfaces on legacy | ✅ `CreativeService` governed only by the shared key, which is false |

Functional validation may begin once 1–3 hold. Visual-quality validation stays
**BLOCKED** on 4 regardless.
