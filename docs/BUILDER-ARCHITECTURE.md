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
