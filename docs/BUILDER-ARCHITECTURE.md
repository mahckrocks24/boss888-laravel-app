# BOSS888 Builder Architecture

**Status:** Canonical (enforced May 2026, Patch 8)
**Source of truth:** this file. If any code disagrees with this document, the code is wrong.

---

## The Law

`pages.sections_json` is the **only** canonical source of truth for builder content.

This means:

- **Arthur mutations** must read and write `sections_json`. Never the static HTML file.
- **Manual edits** must read and write `sections_json` (already true via `BuilderService::updatePage`).
- **Published HTML** is rendered by `BuilderRenderer` from `sections_json` on every request (cached).
- **Snapshots** store `sections_json` copies in `canvas_states` (page_id + sections_json columns added in Patch 8).
- **AI optimisation** reads `sections_json`, mutates it, writes back, snapshots.
- **Static HTML files** are FORBIDDEN as a source of truth.

### Allowed uses of static HTML

- Render output (`BuilderRenderer` produces HTML from `sections_json` on demand).
- Export artefact (the customer downloads their site as HTML/zip).
- Cache layer at `storage/app/public/sites/{id}/index.html` — invalidated whenever `sections_json` changes.

---

## Data flow

```
┌──────────────────────────────────────┐
│   pages.sections_json   (CANONICAL)  │
└──────────────┬───────────────────────┘
               │
       ┌───────▼────────┐
       │ BuilderRenderer │
       └───────┬────────┘
               │ HTML (output only)
               ▼
   PublishedSiteMiddleware (serves + caches)
               │
               ▼
        Visitor browser
```

---

## Edit flow

```
User / Arthur edit
        │
        ▼
BuilderSnapshotService::snapshot(pageId, 'before_edit')
        │
        ▼
BuilderService::updatePage()  →  pages.sections_json = newJson
        │
        ▼
BuilderSnapshotService::snapshot(pageId, 'after_edit')
        │
        ▼
Cache::forget('published_site:...')
        │
        ▼
BuilderRenderer re-renders on next visitor request
```

Both snapshots are recorded so a restore can move you forward OR backward through history. `pre_restore` is a third reason value, written automatically before `restore()` overwrites the page — so a restore is itself undoable.

---

## Known temporary exceptions

- **Chef Red** (`website_id=3`): static HTML only at `storage/app/public/sites/3/index.html`, `sections_json` is empty. Migration tracked as **T3.4 on `boss888-audit/PLAN.md`**. Until that migration runs, do NOT create new sites in this pattern.
- **Arthur edit closure** at `routes/api.php:6962` (`/api/builder/websites/{id}/arthur-edit`): 750 lines of `preg_replace` / `str_replace` / `file_put_contents` on the static HTML. Marked as `LEGACY: T3.4` in source. Will be retired in Patch 8.5/8.6 once Chef Red migrates and Arthur's edit path is rewritten on top of `sections_json`.
- **`TemplateService::deploy()`**: writes static HTML for backwards compat. Marked `LEGACY: T3.4`. Will be deleted in Tier 3.

---

## Section types (BuilderRenderer supported)

Currently dispatched by `BuilderRenderer::renderSection()`:

| Type | Renderer | Notes |
|---|---|---|
| `header` | `renderHeader` | Logo + nav links |
| `hero` | `renderHero` | Heading + body + CTA |
| `features` | `renderFeatures` | Heading + body + items grid |
| `cta` | `renderCta` | Heading + body + button |
| `contact_form` | `renderContact` | Working form with backend POST |
| `blog_list` | `renderBlogList` | Reads `articles` table |
| `footer` | `renderFooter` | Branding + links |
| _(other)_ | `renderGeneric` | Fallback — renders `heading` + `body` |

Planned (Patch 8.5+): gallery, services, team, testimonials, faq, pricing, stats, process, cards.

---

## API endpoints

| Method | Path | Purpose |
|---|---|---|
| `GET` | `/api/builder/pages/{pageId}/history` | Last 20 snapshots |
| `POST` | `/api/builder/pages/{pageId}/restore/{stateId}` | Restore page to that snapshot |

Both auth.jwt-gated and workspace-scoped via the `pages → websites.workspace_id` join.

---

## Snapshot retention

- `canvas_states` rows with `page_id IS NOT NULL` are pruned weekly via `bootstrap/app.php` scheduler.
- Default retention: **30 days**.
- ManualEdit canvas states (`page_id IS NULL`) are NOT touched by this prune — they have their own lifecycle.

---

## Patch 8 file map

| File | Role |
|---|---|
| `app/Engines/Builder/Services/BuilderSnapshotService.php` | snapshot/restore/history/prune |
| `app/Http/Controllers/Api/BuilderSnapshotController.php` | HTTP wiring + workspace check |
| `database/migrations/2026_05_08_193000_add_builder_columns_to_canvas_states.php` | adds page_id + sections_json + reason + index |
| `app/Engines/Builder/Services/BuilderService.php` | `updatePage()` now snapshots before+after |
| `app/Engines/Builder/Services/ArthurService.php` | `generateWebsite()` populates `sections_json` for new pages |
| `routes/api.php` | history + restore routes |
| `bootstrap/app.php` | weekly prune scheduler |
| `docs/BUILDER-ARCHITECTURE.md` | this file |

---

## Arthur Edit Flow (Patch 8.5 Tier 1, 2026-05-08)

**New canonical edit path** for Arthur — operates on `sections_json` only, never on static HTML.

### Endpoint

```
POST /api/builder/pages/{pageId}/arthur-edit
Body: { "message": "Make the hero heading bigger", "section_index": 0 }
```

Workspace-scoped via `pages → websites.workspace_id`. Auth: `auth.jwt`.

### Flow

```
1. Load pages.sections_json
2. Refuse with HTTP 422 + {legacy: true} if sections_json is empty
   (Chef Red-style legacy sites use the old closure until T3.4)
3. BuilderSnapshotService::snapshot(pageId, 'arthur_edit_before')
4. RuntimeClient::chatJson(systemPrompt, userMessage, [], 1500)
   → returns { actions: [...], reply: "..." }
5. For each action: validate op + apply atomically inside DB::transaction
   (per-action try/catch — partial failure is recorded, doesn't abort batch)
6. Run SectionSchema::validate on every resulting section (warnings logged)
7. UPDATE pages.sections_json
8. BuilderSnapshotService::snapshot(pageId, 'arthur_edit_after')
9. Cache::forget('published_site:{subdomain}:*')
10. Return { success, sections, reply, actions_applied, errors }
```

### Section data shape — FLAT

Sections store fields directly on the section object, NOT under a `data` namespace:

```json
{ "type": "hero", "heading": "...", "body": "..." }
```

NOT:

```json
{ "type": "hero", "data": { "heading": "...", "body": "..." } }
```

This matches the canonical sections_json on page id=2 and what `BuilderRenderer::renderHero/renderFeatures/...` reads. If a future schema migration moves to nested `data`, the renderer must change in lockstep.

### Supported ops

| Op | Required fields | Effect |
|---|---|---|
| `update_text` | `section_index`, `field`, `value` | Set a text field on a section |
| `update_field` | `section_index`, `field`, `value` | Same — alias |
| `update_image` | `section_index`, `field` (default `background_image`), `value` (URL) | Set an image field |
| `add_section` | `type`, plus optional flat fields, optional `insert_at` index | Append or insert a new section |
| `remove_section` | `section_index` | Remove a section |
| `reorder_section` | `from_index`, `to_index` | Move a section |

Maximum **5 actions per response** (server enforces; truncates with warning if more).

### Section types (15 known + generic fallback)

`header, hero, features, cta, contact_form, blog_list, footer, gallery, services, team, testimonials, faq, pricing, stats, generic`

Note: BuilderRenderer currently only dispatches the original 7 (header / hero / features / cta / contact_form / blog_list / footer) — the rest fall through to `renderGeneric`. Future patch will expand the renderer's whitelist.

### Field rules per type — `SectionSchema`

`app/Engines/Builder/Schema/SectionSchema.php` declares required + optional fields per type. `SectionSchema::validate(section)` returns `{ok: bool, errors?: string[]}`. Validation runs after every edit; failures log warnings but do not block writes — the edit is the source of truth, the schema enforces gradually as data converges.

### Patch 8.5 file map

| File | Role |
|---|---|
| `app/Engines/Builder/Schema/SectionSchema.php` | 15-type schema + ops registry |
| `app/Engines/Builder/Services/ArthurEditService.php` | edit pipeline |
| `app/Http/Controllers/Api/ArthurEditController.php` | HTTP wrap + workspace check + legacy 422 gate |
| `routes/api.php` | new POST route |

---

## Legacy Path (T3.4 — retire after Chef Red migration)

The closure at `routes/api.php:6973` (`/api/builder/websites/{id}/arthur-edit`) operates on static HTML files via regex. It is **frozen** — do NOT extend.

Migration plan:
1. **Patch 8.6** = T3.4: Chef Red migrates from `storage/app/public/sites/3/index.html` to `pages.sections_json` (sections re-derived from existing HTML, parity verified, static file deleted).
2. **Patch 8.6 follow-up**: delete the legacy closure entirely + delete `TemplateService::deploy()`.

Until then both paths coexist: new sites use the JSON path; Chef Red stays on the regex closure.
