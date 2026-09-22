<?php

/**
 * CR-22B — extracted route module: builder-01
 *
 * Source: routes/api.php lines 10437-10875 of the authoritative pre-extraction
 * file (sha256 9aa519a1445f8e26…), copied VERBATIM — not reformatted, reordered
 * or edited in any way.
 *
 * Included from INSIDE the authenticated group closure
 *   Route::middleware(['auth.jwt','traffic.defense','connector.brand'])->group(...)
 * at the exact position the code previously occupied, so middleware stack,
 * prefix nesting and registration order are unchanged. PHP `require` executes in
 * the including scope, so parent-closure variables remain visible.
 *
 * `use` aliases, however, do NOT cross a require boundary — they are resolved
 * per file at compile time. A missing import does not fatal: `TaskController::class`
 * silently becomes the string "TaskController" and the route registers against a
 * wrong action. The FULL parent import set is therefore re-declared below,
 * unconditionally, in every module. Unused imports trigger no autoload and cost
 * nothing; a missing one is a silent production defect.
 *
 * Owner: Website Builder   ·   Routes: 34   ·   Statements: 1
 *
 * CR-22 scope forbids improving anything in this file. Move it, do not edit it.
 */

use App\Http\Controllers\Api\AgentController;
use App\Http\Controllers\Api\ApprovalController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DesignTokenController;
use App\Http\Controllers\Api\EngineController;
use App\Http\Controllers\Api\ManualExecutionController;
use App\Http\Controllers\Api\MeetingController;
use App\Http\Controllers\Api\SystemController;
use App\Http\Controllers\Api\TaskController;
use App\Http\Controllers\Api\WorkspaceController;
use Illuminate\Support\Facades\Route;

// ==== CR-22B MODULE BODY BEGINS - verbatim from routes/api.php, do not edit ====
    // ── Builder Engine ───────────────────────────────────────────
    Route::prefix('builder')->group(function () {
        $s = \App\Engines\Builder\Services\BuilderService::class;
        $exec = \App\Core\EngineKernel\EngineExecutionService::class;
        // Reads
        Route::get('/websites', fn(\Illuminate\Http\Request $r) => response()->json(app($s)->listWebsites($r->attributes->get('workspace_id'))));
        // INC-0006: getWebsite() is workspace-scoped and returns null for a website this business does
        // not own — but response()->json(null) renders that as 200 {}, which claims the website exists
        // and is empty. Match the denial the rest of the Builder already uses two lines below and
        // throughout routes/api.php: an undifferentiated 404 for missing, foreign and inaccessible
        // alike, so the response never distinguishes 'not yours' from 'not real'.
        Route::get('/websites/{id}', function (\Illuminate\Http\Request $r, $id) use ($s) {
            $website = app($s)->getWebsite($r->attributes->get('workspace_id'), $id);
            return $website
                ? response()->json($website)
                : response()->json(['error' => 'Website not found'], 404);
        });
        Route::get('/websites/{id}/pages', fn(\Illuminate\Http\Request $r, $id) => response()->json(app($s)->listPages((int) $id, (int) $r->attributes->get('workspace_id'))));
        // DEC-0046 (2026-09-13) — template editor: curated palettes (instant), palette apply (free, verified), undo.
        Route::get('/websites/{id}/palettes', fn(\Illuminate\Http\Request $r, $id) => response()->json(
            app(\App\Engines\Builder\Services\ArthurService::class)->palettesFor((int) $r->attributes->get('workspace_id'), (int) $id)
        ));
        Route::post('/websites/{id}/palette', function (\Illuminate\Http\Request $r, $id) {
            $__ws = (int) $r->attributes->get('workspace_id');
            if (! \App\Engines\Builder\Support\EditorCredits::canAfford($__ws, 'palette')) return response()->json(['success' => false, 'error' => 'insufficient_credits', 'message' => \App\Engines\Builder\Support\EditorCredits::refusal('palette')], 402);
            $res = app(\App\Engines\Builder\Services\ArthurService::class)->applyPalette($__ws, (int) $id, (string) $r->input('theme', ''));
            if (! empty($res['success'])) { $res['credits'] = \App\Engines\Builder\Support\EditorCredits::charge($__ws, 'palette', (int) $id, ['theme' => (string) $r->input('theme', '')]); $res['message'] = rtrim((string) ($res['message'] ?? 'Palette applied.'), ' .') . '.' . \App\Engines\Builder\Support\EditorCredits::suffix((int) $res['credits']); }
            return response()->json($res, ! empty($res['success']) ? 200 : 422);
        });
        Route::post('/websites/{id}/undo', function (\Illuminate\Http\Request $r, $id) {
            $owned = \Illuminate\Support\Facades\DB::table('websites')->where('id', (int) $id)->where('workspace_id', (int) $r->attributes->get('workspace_id'))->whereNull('deleted_at')->exists();
            if (! $owned) { return response()->json(['undone' => false, 'error' => 'not_found'], 404); }
            $res = app(\App\Engines\Builder\Services\TemplateService::class)->undoLatest((int) $id);
            return response()->json($res, ! empty($res['undone']) ? 200 : 422);
        });
        // LAYOUT SWITCHER (2026-09-14) — sibling designs, free preview, apply with undo.
        Route::get('/websites/{id}/layouts', fn(\Illuminate\Http\Request $r, $id) => response()->json(
            app(\App\Engines\Builder\Services\ArthurService::class)->layoutsFor((int) $r->attributes->get('workspace_id'), (int) $id)
        ));
        Route::post('/websites/{id}/layout/preview', function (\Illuminate\Http\Request $r, $id) {
            $res = app(\App\Engines\Builder\Services\ArthurService::class)->previewLayout((int) $r->attributes->get('workspace_id'), (int) $id, (string) $r->input('design', ''));
            return response()->json($res, ! empty($res['success']) ? 200 : 422);
        });
        Route::post('/websites/{id}/layout', function (\Illuminate\Http\Request $r, $id) {
            $__ws = (int) $r->attributes->get('workspace_id');
            if (! \App\Engines\Builder\Support\EditorCredits::canAfford($__ws, 'layout')) return response()->json(['success' => false, 'error' => 'insufficient_credits', 'message' => \App\Engines\Builder\Support\EditorCredits::refusal('layout')], 402);
            $res = app(\App\Engines\Builder\Services\ArthurService::class)->applyLayout($__ws, (int) $id, (string) $r->input('design', ''), (int) ($r->attributes->get('user_id') ?? optional($r->user())->id ?? 0) ?: null);
            if (! empty($res['success'])) { $__base = \App\Engines\Builder\Support\EditorCredits::charge($__ws, 'layout', (int) $id, ['design' => (string) $r->input('design', '')]); $res['credits'] = (int) ($res['credits'] ?? 0) + $__base; $res['message'] = rtrim((string) ($res['message'] ?? 'Layout applied.'), ' .') . '.' . \App\Engines\Builder\Support\EditorCredits::suffix((int) $res['credits']); }
            return response()->json($res, ! empty($res['success']) ? 200 : 422);
        });
        // STORE PAYMENTS (DEC-0051, 2026-09-15) — the workspace's own Stripe account; priced catalogue items get a checkout button.
        $pay = \App\Engines\Builder\Services\StorePaymentsService::class;
        Route::get('/store-payments', fn(\Illuminate\Http\Request $r) => response()->json(app($pay)->status((int) $r->attributes->get('workspace_id'))));
        Route::put('/store-payments', function (\Illuminate\Http\Request $r) use ($pay) {
            $res = app($pay)->connect((int) $r->attributes->get('workspace_id'), (string) $r->input('secret_key', ''), $r->input('publishable_key'), (string) $r->input('currency', 'USD'), (string) config('app.url'));
            if (! empty($res['success'])) { foreach (\Illuminate\Support\Facades\DB::table('websites')->where('workspace_id', (int) $r->attributes->get('workspace_id'))->whereNull('deleted_at')->pluck('id') as $sid) { try { app(\App\Engines\Builder\Services\CatalogueService::class)->sync((int) $sid, null, 'payments_on'); } catch (\Throwable $e) {} } }
            return response()->json($res, ! empty($res['success']) ? 200 : 422);
        });
        Route::delete('/store-payments', function (\Illuminate\Http\Request $r) use ($pay) {
            $res = app($pay)->disconnect((int) $r->attributes->get('workspace_id'));
            foreach (\Illuminate\Support\Facades\DB::table('websites')->where('workspace_id', (int) $r->attributes->get('workspace_id'))->whereNull('deleted_at')->pluck('id') as $sid) { try { app(\App\Engines\Builder\Services\CatalogueService::class)->sync((int) $sid, null, 'payments_off'); } catch (\Throwable $e) {} }
            return response()->json($res);
        });
        Route::get('/store-payments/orders', fn(\Illuminate\Http\Request $r) => response()->json(['orders' => \Illuminate\Support\Facades\DB::table('catalogue_orders')->where('workspace_id', (int) $r->attributes->get('workspace_id'))->orderByDesc('id')->limit(50)->get()]));
        // SITE SETTINGS (DEC-0051, 2026-09-15) — tracking ids into every page, a zip export the customer owns, domain state.
        $siteOwned = function (\Illuminate\Http\Request $r, $id) { $w = \Illuminate\Support\Facades\DB::table('websites')->where('id', (int) $id)->whereNull('deleted_at')->first(); return ($w && (int) $w->workspace_id === (int) $r->attributes->get('workspace_id')) ? $w : null; };
        Route::get('/websites/{id}/site-settings', function (\Illuminate\Http\Request $r, $id) use ($siteOwned) {
            $w = $siteOwned($r, $id); if (! $w) return response()->json(['error' => 'not_found'], 404);
            $s = json_decode((string) ($w->settings_json ?: '{}'), true) ?: [];
            $dom = $w->custom_domain ? ['domain' => $w->custom_domain, 'text' => $w->custom_domain . ($w->domain_verified ? ' — connected' : ' — waiting for DNS / SSL')] : ['domain' => null, 'text' => $w->subdomain ? 'Published at ' . $w->subdomain . '. A custom domain can be connected under Websites → Domain.' : 'No domain yet — set a subdomain to publish, then connect your own domain.'];
            return response()->json(['tracking' => (array) ($s['tracking'] ?? []), 'domain' => $dom, 'subdomain' => $w->subdomain, 'status' => $w->status]);
        });
        // PREVIEW GATE (2026-09-15, RISK-0177): the editor preview is for signed-in members of the site's workspace only.
        // The renderer (inline editing script and all) is app('lu.preview.render'), defined in routes/api.php. A soft-deleted
        // draft still reaches the renderer, which redirects to the workspace's live site (EV-1003) — same workspace, same gate.
        Route::get('/websites/{id}/preview', function (\Illuminate\Http\Request $r, $id) {
            $w = \Illuminate\Support\Facades\DB::table('websites')->where('id', (int) $id)->first(['id', 'workspace_id']);
            if (! $w || (int) $w->workspace_id !== (int) $r->attributes->get('workspace_id')) return response()->json(['error' => 'not_found'], 404);
            return app('lu.preview.render')((int) $id);
        });
        // ELEMENT888 (DEC-0052, 2026-09-15): the toolbox and the drag handle in the preview — one element moves, aligns or resizes. 1 credit each.
        Route::post('/websites/{id}/elements/{op}', function (\Illuminate\Http\Request $r, $id, $op) use ($siteOwned) {
            $w = $siteOwned($r, $id); if (! $w) return response()->json(['success' => false, 'message' => 'Website not found'], 404);
            if (! in_array($op, ['move', 'align', 'size', 'effect'], true)) return response()->json(['success' => false, 'message' => 'Unknown operation'], 422);
            $field = (string) preg_replace('/[^a-z0-9_\-]/i', '', (string) $r->input('field', ''));
            $blockIn = (string) preg_replace('/[^a-z0-9_\-]/i', '', (string) $r->input('block', ''));
            if ($field === '' && ! ($op === 'effect' && $blockIn !== '')) return response()->json(['success' => false, 'message' => 'Which element?'], 422);
            $action = 'element_' . $op; $ws = (int) $r->attributes->get('workspace_id');
            // OWNER RULE 2026-09-15: the toolbox and the drag handle are the customer's own hands — free. Arthur's version of the same change costs 1.
            if ($op === 'move') {
                $dir = strtolower((string) $r->input('dir', '')); $ref = (string) preg_replace('/[^a-z0-9_\-]/i', '', (string) $r->input('ref', '')) ?: null;
                $res = app(\App\Engines\Builder\Services\TemplateService::class)->moveElement((int) $id, $field, $dir, $ref);
            } elseif ($op === 'effect') {
                $v = $r->input('value'); $v = ($v === null || $v === '') ? null : (float) $v;
                $res = app(\App\Engines\Builder\Services\ArthurService::class)->effectElement((int) $id, $field, (string) $r->input('effect', ''), (string) $r->input('dir', 'up'), $v, $r->input('color') !== null ? (string) $r->input('color') : null, $blockIn);
            } elseif ($op === 'align') {
                $res = app(\App\Engines\Builder\Services\ArthurService::class)->alignElement((int) $id, $field, strtolower((string) $r->input('align', '')));
            } else {
                $res = app(\App\Engines\Builder\Services\ArthurService::class)->sizeElement((int) $id, $field, strtolower((string) $r->input('dir', 'bigger')) === 'smaller' ? 'smaller' : 'bigger');
            }
            if (empty($res['success'])) return response()->json(['success' => false, 'message' => (string) ($res['message'] ?? 'That did not work.')], 422);
            $cost = 0;
            return response()->json(['success' => true, 'message' => ucfirst((string) $res['message']) . '.', 'credits' => $cost, 'state' => $res['state'] ?? null, 'url' => '/storage/sites/' . (int) $id . '/index.html']);
        });
        Route::put('/websites/{id}/tracking', function (\Illuminate\Http\Request $r, $id) use ($siteOwned) {
            $w = $siteOwned($r, $id); if (! $w) return response()->json(['success' => false, 'message' => 'Website not found'], 404);
            $in = []; $bad = [];
            foreach (['ga4' => '/^G-[A-Z0-9]{4,20}$/', 'gtm' => '/^GTM-[A-Z0-9]{4,12}$/', 'meta_pixel' => '/^\d{8,20}$/', 'tiktok_pixel' => '/^[A-Z0-9]{10,40}$/i'] as $k => $re) {
                $v = strtoupper(trim((string) $r->input($k, ''))); if ($k === 'tiktok_pixel') $v = trim((string) $r->input($k, ''));
                if ($v === '') continue; if (! preg_match($re, $v)) { $bad[] = $k; continue; } $in[$k] = $v;
            }
            if ($bad) return response()->json(['success' => false, 'message' => 'That does not look like a valid id: ' . implode(', ', $bad) . '. GA4 ids look like G-XXXXXXXX, Tag Manager like GTM-XXXXXXX, a Meta pixel is a 15–16 digit number.'], 422);
            $s = json_decode((string) ($w->settings_json ?: '{}'), true) ?: []; $s['tracking'] = $in;
            \Illuminate\Support\Facades\DB::table('websites')->where('id', (int) $id)->update(['settings_json' => json_encode($s), 'updated_at' => now()]);
            \Illuminate\Support\Facades\Artisan::call('sites:inject-scripts', ['--site' => (int) $id]);
            return response()->json(['success' => true, 'tracking' => $in, 'message' => $in === [] ? 'Tracking removed from every page.' : 'Tracking is on every page of the site: ' . implode(', ', array_keys($in)) . '.']);
        });
        Route::get('/websites/{id}/export', function (\Illuminate\Http\Request $r, $id) use ($siteOwned) {
            $w = $siteOwned($r, $id); if (! $w) return response()->json(['success' => false, 'message' => 'Website not found'], 404);
            $root = storage_path('app/public/sites/' . (int) $id); if (! is_file($root . '/index.html')) return response()->json(['success' => false, 'message' => 'This site has no export yet.'], 422);
            $tmp = tempnam(sys_get_temp_dir(), 'site') . '.zip'; $zip = new \ZipArchive(); $zip->open($tmp, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
            foreach ($it as $f) { $rel = substr($f->getPathname(), strlen($root) + 1); if (str_starts_with($rel, '.history') || preg_match('/\.bak(-|$)/', $rel)) continue; if ($f->isDir()) { $zip->addEmptyDir($rel); } else { $zip->addFile($f->getPathname(), $rel); } }
            $zip->close();
            $bytes = (string) @file_get_contents($tmp); @unlink($tmp);
            if ($bytes === '') return response()->json(['success' => false, 'message' => 'The export could not be built just now — please try again.'], 500);
            return response($bytes, 200, ['Content-Type' => 'application/zip', 'Content-Disposition' => 'attachment; filename="site-' . (int) $id . '.zip"', 'Content-Length' => (string) strlen($bytes), 'Cache-Control' => 'no-store']);
        });
        // CATALOGUE888 (DEC-0049, 2026-09-14) — one catalogue backend inside Laravel; kinds (listing, service, menu …) are declared
        // by the design or derived from its variable families. Every other site gets an empty catalogue list.
        $cat = \App\Engines\Builder\Services\CatalogueService::class;
        $catStatus = fn(array $res) => ! empty($res['success']) ? 200 : ((($res['code'] ?? '') === 'NOT_FOUND') ? 404 : ((($res['code'] ?? '') === 'NO_CREDITS') ? 402 : 422));
        Route::get('/websites/{id}/catalogue', fn(\Illuminate\Http\Request $r, $id) => response()->json(app($cat)->overview((int) $r->attributes->get('workspace_id'), (int) $id)));
        Route::post('/websites/{id}/catalogue/photo', function (\Illuminate\Http\Request $r, $id) use ($cat, $catStatus) {
            $f = $r->file('photo');
            if (! $f || ! $f->isValid()) return response()->json(['success' => false, 'message' => 'Attach a photo (JPG, PNG or WEBP).'], 422);
            $res = app($cat)->storePhoto((int) $r->attributes->get('workspace_id'), (int) $id, $f);
            return response()->json($res, $catStatus($res));
        });
        Route::put('/websites/{id}/catalogue/{kind}/settings', function (\Illuminate\Http\Request $r, $id, $kind) use ($cat, $catStatus) {
            $res = app($cat)->setEnabled((int) $r->attributes->get('workspace_id'), (int) $id, (string) $kind, $r->boolean('enabled'));
            return response()->json($res, $catStatus($res));
        })->where('kind', '[a-z_]+');
        Route::post('/websites/{id}/catalogue/{kind}', function (\Illuminate\Http\Request $r, $id, $kind) use ($cat, $catStatus) {
            $res = app($cat)->create((int) $r->attributes->get('workspace_id'), (int) $id, (string) $kind, (array) $r->all(), (int) ($r->attributes->get('user_id') ?? 0) ?: null, 'editor');
            return response()->json($res, $catStatus($res));
        })->where('kind', '[a-z_]+');
        Route::put('/websites/{id}/catalogue/{kind}/{iid}', function (\Illuminate\Http\Request $r, $id, $kind, $iid) use ($cat, $catStatus) {
            $res = app($cat)->update((int) $r->attributes->get('workspace_id'), (int) $id, (string) $kind, (int) $iid, (array) $r->all());
            return response()->json($res, $catStatus($res));
        })->where('kind', '[a-z_]+')->where('iid', '[0-9]+');
        Route::post('/websites/{id}/catalogue/{kind}/{iid}/status', function (\Illuminate\Http\Request $r, $id, $kind, $iid) use ($cat, $catStatus) {
            $res = app($cat)->setStatus((int) $r->attributes->get('workspace_id'), (int) $id, (string) $kind, (int) $iid, (string) $r->input('status', ''), $r->has('closed_note') ? (string) $r->input('closed_note') : null);
            return response()->json($res, $catStatus($res));
        })->where('kind', '[a-z_]+')->where('iid', '[0-9]+');
        Route::delete('/websites/{id}/catalogue/{kind}/{iid}', function (\Illuminate\Http\Request $r, $id, $kind, $iid) use ($cat, $catStatus) {
            $res = app($cat)->delete((int) $r->attributes->get('workspace_id'), (int) $id, (string) $kind, (int) $iid);
            return response()->json($res, $catStatus($res));
        })->where('kind', '[a-z_]+')->where('iid', '[0-9]+');
        Route::get('/pages/{id}', function (\Illuminate\Http\Request $r, $id) use ($s) {
            $page = app($s)->getPage((int) $id, (int) $r->attributes->get('workspace_id'));
            return $page ? response()->json($page) : response()->json(['error' => 'Page not found'], 404);
        });
        // Writes through pipeline
        Route::post('/websites', function (\Illuminate\Http\Request $r) use ($exec) {
            $in = $r->all();
            // CATALOGUE VERIFICATION (2026-09-20): a house theme (ThemeRegistry: amg-travel, kabayan-news, mrdigital-enterprise) is not a
            // customer catalogue entry. A raw create carrying settings.theme = 'kabayan-news' gave any workspace the house design;
            // only the house account (admin user 1) may name one — everyone else gets the palette default.
            if (is_array($in['settings'] ?? null) && isset($in['settings']['theme'])
                && in_array(strtolower(trim((string) $in['settings']['theme'])), \App\Engines\Builder\Services\ThemeRegistry::keys(), true)
                && (int) ($r->user()?->id ?? 0) !== 1) { unset($in['settings']['theme']); }
            $__created = app($exec)->execute($r->attributes->get('workspace_id'), 'builder', 'create_website', $in, ['user_id' => $r->user()?->id, 'source' => 'manual']);
            // RFC-0011 U5a: the new website belongs to the business named in the request (this workspace's), else the default
            try { $__wid = (int) (is_array($__created) ? ($__created['website_id'] ?? $__created['data']['website_id'] ?? $__created['id'] ?? 0) : 0); if ($__wid > 0) { \App\Models\Business::stampWebsite((int) $r->attributes->get('workspace_id'), $__wid, isset($in['business_id']) ? (int) $in['business_id'] : null); } } catch (\Throwable $__bsErr) { \Illuminate\Support\Facades\Log::warning('[Business] website stamp failed: ' . $__bsErr->getMessage()); }
            return response()->json($__created, 201);
        });
        // PATCH (publish-flow-fix, 2026-05-09) — duplicate publish route
        // removed. The closure version at /builder/websites/{id}/publish
        // (line ~5430) is now the canonical publish endpoint — it gates
        // on websites.subdomain being non-NULL (returns 422 with a
        // user-facing message if missing), preventing "site is published
        // but has no URL to access" states. The engine path here
        // bypassed that check and was the second of two routes
        // resolving to the same path; first-registered wins, so this
        // one was masking the closure entirely.
        // Route::post('/websites/{id}/publish', ...) — removed
        Route::post('/websites/{wid}/pages', fn(\Illuminate\Http\Request $r, $wid) => response()->json(app($exec)->execute($r->attributes->get('workspace_id'), 'builder', 'generate_page', array_merge($r->all(), ['website_id' => $wid]), ['user_id' => $r->user()?->id, 'source' => 'manual']), 201));
        Route::put('/pages/{id}', function (\Illuminate\Http\Request $r, $id) use ($s) {
            // BUILDER888 P0-2 (2026-08-09) — was `response()->json(...) && update(...)`,
            // which evaluated to a bool and threw the JSON response away.
            try {
                app($s)->updatePage((int) $id, $r->all(), (int) $r->attributes->get('workspace_id'));
            } catch (\App\Engines\Builder\Exceptions\BuilderConflictException $e) {
                // RISK-0100 — concurrent overwrite refused truthfully.
                return response()->json(['error' => $e->getMessage(), 'conflict' => true], 409);
            } catch (\RuntimeException $e) {
                return response()->json(['error' => 'Page not found'], 404);
            }
            return response()->json(['updated' => true, 'page_id' => (int) $id]);
        });

        // PATCH 8 (2026-05-08) — Architecture Lock Tier 1: snapshot/restore for sections_json edits.
        Route::get('/pages/{pageId}/history', [\App\Http\Controllers\Api\BuilderSnapshotController::class, 'history'])
            ->where('pageId', '[0-9]+');
        Route::post('/pages/{pageId}/restore/{stateId}', [\App\Http\Controllers\Api\BuilderSnapshotController::class, 'restore'])
            ->where(['pageId' => '[0-9]+', 'stateId' => '[0-9]+']);

        // PATCH 8.5 (2026-05-08) — Arthur structured-JSON edit endpoint.
        // Refuses 422 on legacy static-HTML pages (Chef Red); for those the
        // legacy /api/builder/websites/{id}/arthur-edit closure still applies
        // until T3.4 / Patch 8.6 retires it.
        Route::post('/pages/{pageId}/arthur-edit', [\App\Http\Controllers\Api\ArthurEditController::class, 'edit'])
            ->where('pageId', '[0-9]+');
        // PATCH 3 (2026-05-08): the legacy structured wizard relied on
        // BuilderService::wizardGenerate() which calls 4 helpers that were
        // removed in 2026-04-19. Returning a clean 501 instead of letting
        // the request fatal. Website creation is owned by Arthur now —
        // POST /api/builder/arthur/message handles intent extraction and
        // multi-page generation conversationally.
        Route::post('/wizard', fn(\Illuminate\Http\Request $r) => response()->json([
            'error' => 'Website creation has moved to Arthur. Use POST /api/builder/arthur/message instead.',
            'replacement' => '/api/builder/arthur/message',
            'status' => 'gone',
        ], 501));


        // Builder page operations (from WP class-lubld-rest.php)
        Route::get("/pages", function(\Illuminate\Http\Request $r) use ($s) {
            $wid = $r->input("website_id");
            if (!$wid) return response()->json([]);
            return response()->json(app($s)->listPages((int) $wid, (int) $r->attributes->get("workspace_id")));
        });
        Route::get("/load/{id}", function(\Illuminate\Http\Request $r, $id) use ($s) {
            $page = app($s)->getPage((int) $id, (int) $r->attributes->get("workspace_id"));
            if (!$page) return response()->json(["error" => "Page not found"], 404);
            $page = is_object($page) ? (array)$page : $page;
            // Parse sections_json into sections array for the frontend
            if (isset($page['sections_json'])) {
                $parsed = is_string($page['sections_json']) ? json_decode($page['sections_json'], true) : $page['sections_json'];
                $page['sections'] = $parsed['sections'] ?? [];
                $page['schemaVersion'] = $parsed['schemaVersion'] ?? 1;
                $page['theme'] = $parsed['theme'] ?? [];
            }
            if (isset($page['seo_json'])) {
                $page['seo'] = is_string($page['seo_json']) ? json_decode($page['seo_json'], true) : $page['seo_json'];
            }
            return response()->json($page);
        });
        Route::post("/create", function(\Illuminate\Http\Request $r) use ($s, $exec) {
            $wid = $r->input("website_id");
            if (!$wid) return response()->json(["error" => "website_id required"], 400);
            return response()->json(app($exec)->execute($r->attributes->get("workspace_id"), "builder", "generate_page", array_merge($r->all(), ["website_id" => $wid]), ["user_id" => $r->user()?->id, "source" => "manual"]), 201);
        });
        Route::post("/save", function(\Illuminate\Http\Request $r) use ($s) {
            $pid = $r->input("page_id");
            if (!$pid) return response()->json(["error" => "page_id required"], 400);
            $data = $r->except("page_id");
            if (isset($data["sections_json"])) {
                $data["sections"] = is_string($data["sections_json"]) ? json_decode($data["sections_json"], true) : $data["sections_json"];
            }
            try {
                app($s)->updatePage((int) $pid, $data, (int) $r->attributes->get("workspace_id"));
            } catch (\RuntimeException $e) {
                return response()->json(["error" => "Page not found"], 404);
            }
            return response()->json(["updated" => true, "page_id" => (int)$pid]);
        });
        Route::post("/delete/{id}", function (\Illuminate\Http\Request $r, $id) use ($s) {
            $__pg = \Illuminate\Support\Facades\DB::table('pages')->where('id', (int) $id)->first(['slug', 'website_id']);
            try {
                app($s)->deletePage((int) $id, (int) $r->attributes->get("workspace_id"));
            } catch (\RuntimeException $e) {
                return response()->json(["error" => "Page not found"], 404);
            }
            // STRESS C31 (2026-09-06): on a template site the page's export folder and menu link go with the row
            if ($__pg && !in_array((string) $__pg->slug, ['home', 'blog', 'news', ''], true) && preg_match('/^[a-z0-9\-]+$/', (string) $__pg->slug)) {
                $__dir = storage_path('app/public/sites/' . (int) $__pg->website_id . '/' . $__pg->slug);
                if (is_dir($__dir)) { try { \Illuminate\Support\Facades\File::deleteDirectory($__dir); } catch (\Throwable $e) {} }
                try { (new \App\Engines\Builder\Services\TemplateService())->removeNavLink((int) $__pg->website_id, (string) $__pg->slug); } catch (\Throwable $e) {}
                try { \App\Http\Controllers\PublishedSiteController::invalidateCache((int) $__pg->website_id); } catch (\Throwable $e) {}
            }
            return response()->json(["deleted" => true, "page_id" => (int) $id]);
        });
        // BUILDER888 no-fake-success: URL-based website cloning is not implemented.
        // Return a truthful 501 instead of {cloned:true} (which falsely signalled success).
        // (Real URL cloning would also need SSRF protection — deferred, out of hardening scope.)
        Route::post("/clone", fn(\Illuminate\Http\Request $r) => response()->json(["cloned" => false, "error" => "URL-based website cloning is not implemented."], 501));
        Route::post("/ai", fn() => response()->json(["reply" => "The AI builder assistant has been retired. Use the Strategy Room instead.", "status" => "deprecated"]));
        Route::delete("/websites/{id}", function (\Illuminate\Http\Request $r, $id) use ($s) {
            try {
                app($s)->deleteWebsite((int)$id, (int)$r->attributes->get("workspace_id"));
            } catch (\RuntimeException $e) {
                return response()->json(["error" => "Website not found"], 404);
            }
            return response()->json(["deleted" => true, "id" => (int)$id]);
        });
        Route::get("/stats", function(\Illuminate\Http\Request $r) {
            $wsId = $r->attributes->get("workspace_id");
            return response()->json([
                "websites" => \Illuminate\Support\Facades\DB::table("websites")->where("workspace_id", $wsId)->whereNull("deleted_at")->count(),
                "pages" => \Illuminate\Support\Facades\DB::table("pages")->whereIn("website_id", \Illuminate\Support\Facades\DB::table("websites")->where("workspace_id", $wsId)->pluck("id"))->count(),
            ]);
        });
        Route::get("/preview/{id}", function (\Illuminate\Http\Request $r, $id) use ($s) {
            // BUILDER888: real preview — render the CURRENT page (draft or published)
            // via BuilderRenderer so the create->edit->preview->publish walk works.
            // Tenancy-checked: getPage returns null when the page is not in the caller ws.
            $wsId = (int) $r->attributes->get("workspace_id");
            $page = app($s)->getPage((int) $id, $wsId);
            if (! $page) { return response()->json(["error" => "Page not found"], 404); }
            $website = \Illuminate\Support\Facades\DB::table("websites")->where("id", $page->website_id)->first();
            if (! $website) { return response()->json(["error" => "Website not found"], 404); }
            // SINGLE TRUTH (2026-09-06): a template site's page IS its static export. Preview that, never the generic
            // sections stack that generateWebsite also wrote (REPORT-0044 P0: editor showed a page nobody had seen).
            $slug = strtolower((string) ($page->slug ?? 'home'));
            $staticPath = storage_path('app/public/sites/' . (int) $website->id . '/' . ($slug === 'home' || $slug === '' ? 'index.html' : $slug . '/index.html'));
            if (is_file($staticPath)) {
                $staticHtml = (string) file_get_contents($staticPath);
                return response()->json(['preview_html' => $staticHtml, 'page_id' => (int) $id, 'source' => 'static_export']);
            }
            try {
                $html = app(\App\Engines\Builder\Services\BuilderRenderer::class)
                    ->renderPage((array) $website, (array) $page, []);
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning("[Builder] preview render failed", ["page_id" => (int) $id, "error" => $e->getMessage()]);
                return response()->json(["error" => "Preview render failed"], 500);
            }
            return response()->json(["preview_html" => $html, "page_id" => (int) $id]);
        });
        // LIBRARY (2026-09-06): the ONE capability manifest for the editor — pages (with preview URLs), sections, prices, limits.
        // ?website_id= scopes pages/sections to that site's industry. Was a stub returning empty lists.
        Route::get("/library", function (\Illuminate\Http\Request $r) {
            $wsId = (int) $r->attributes->get('workspace_id');
            $industry = null; $existing = [];
            $wid = (int) $r->query('website_id', 0);
            if ($wid > 0) {
                $w = \Illuminate\Support\Facades\DB::table('websites')->where('id', $wid)->where('workspace_id', $wsId)->whereNull('deleted_at')->first();
                if ($w) { $s = json_decode((string) ($w->settings_json ?: '{}'), true) ?: [];
                    // CATALOGUE VERIFICATION (2026-09-20): settings.template is a DESIGN (cafe_arch, courses_studio …) since the
                    // 2026-09-10 variants; the catalogue is keyed by the design's industry family, or a site built on a variant
                    // lost every industry page (menu, events, listings, before/after …) and the industry-only sections in the pickers.
                    $industry = (string) ($s['template'] ?? $s['industry'] ?? $w->template_industry ?? '') ?: null;
                    if ($industry !== null) { $industry = app(\App\Engines\Builder\Services\TemplateService::class)->industryOf($industry) ?: $industry; }
                    $existing = \Illuminate\Support\Facades\DB::table('pages')->where('website_id', $wid)->pluck('slug')->toArray(); }
            }
            $isStatic = $wid > 0 && is_file(storage_path('app/public/sites/' . $wid . '/index.html'));
            $pages = array_values(array_map(function ($p) use ($existing) { $p['preview_url'] = '/page-templates/' . $p['slug'] . '/preview'; $p['exists'] = in_array(str_replace('_', '-', $p['slug']), $existing, true); return $p; },
                array_filter(\App\Engines\Builder\Support\BuilderCapabilities::pages($industry), fn($p) => !($isStatic && in_array($p['slug'], ['cart', 'checkout', 'account'], true)))));
            return response()->json([
                'industry'    => $industry,
                'pages'       => $pages,
                'sections'    => array_values(\App\Engines\Builder\Support\BuilderCapabilities::sections($industry)),
                'pricing'     => \App\Engines\Builder\Support\BuilderCapabilities::pricing(),
                'limitations' => \App\Engines\Builder\Support\BuilderCapabilities::limitations(),
                'blocks'      => [], 'templates' => [], // legacy keys kept for older callers
            ]);
        });
        Route::get("/sections/{id}", fn($r, $id) => response()->json(["section" => null]));
        Route::get("/components/{id}", fn($r, $id) => response()->json(["component" => null]));
        Route::get("/containers/{id}", fn($r, $id) => response()->json(["container" => null]));
        Route::get("/websites/{id}/blueprint", function(\Illuminate\Http\Request $r, $id) {
            try { return response()->json(app(\App\Engines\Creative\Services\BlueprintService::class)->getPageBlueprint($r->attributes->get("workspace_id"), ["website_id" => $id])); }
            catch (\Throwable $e) { return response()->json(["blueprint" => null]); }
        });
        Route::post("/websites/{id}/deploy-prep", fn($r, $id) => response()->json(["ready" => true]));
        Route::post('/arthur/message', function (\Illuminate\Http\Request $r) {
            $wsId    = $r->attributes->get('workspace_id');
            $msg     = (string) $r->input('message', '');
            $history = $r->input('history', []);
            if (!is_array($history)) $history = [];

            $arthur = new \App\Engines\Builder\Services\ArthurService();

            // PATCH (Arthur confirm flow, 2026-05-09) — two modes on this route:
            //
            //   (a) Conversation turn: client posts {message, history}.
            //       chat() returns type='question' (still gathering info) or
            //       type='confirm' (LLM has enough — send back the summary so
            //       the frontend can render Upload Logo + Build buttons). On
            //       'confirm' we DO NOT build yet; we cache build_data for
            //       300s so the follow-up confirm POST can retrieve it.
            //
            //   (b) Confirm action: client posts {confirm:true, logo_url:'…',
            //       build_data:{…optional echo…}}. We pull build_data from
            //       cache (or trust the client echo as fallback), call
            //       buildFromChat($wsId, $buildData, $logoUrl), and return
            //       type='complete' with website_id + website_url.
            $isConfirm = (bool) $r->input('confirm', false);
            // CHAT METER (2026-09-15): the build conversation is chat too — 1 credit per 10 messages; the build itself is priced separately.
            $__meter = ['debited' => false];
            if (! $isConfirm) {
                $__meter = app(\App\Core\Billing\CreditService::class)->meterChat((int) $wsId, 'arthur_message');
                if (empty($__meter['sufficient'])) return response()->json(['type' => 'error', 'reply' => 'Not enough credits to chat — 1 credit covers 10 messages. Add credits under Billing to continue.', 'build_error' => 'insufficient_credits'], 402);
            }

            if ($isConfirm) {
                // PATCH (Arthur build timeout, 2026-05-09) — website builds
                // commonly take 50-70s (LLM content generation across 5+ JSON
                // calls + image generation). Default PHP-FPM max_execution_time
                // is 30s and nginx fastcgi_read_timeout was 60s — both fixed.
                // Override the per-request limits so a slow LLM round-trip
                // doesn't kill the script while it's still doing real work.
                @set_time_limit(180);
                @ini_set('max_execution_time', '180');
                $logoUrl  = (string) $r->input('logo_url', '');
                $clientBd = $r->input('build_data', []);
                $cached   = \Illuminate\Support\Facades\Cache::get('arthur_build_data_' . $wsId, []);
                $buildData = is_array($cached) && !empty($cached['business_name'])
                    ? $cached
                    : (is_array($clientBd) ? $clientBd : []);

                // PATCH (full confirm panel, 2026-05-09) — accept images[]
                // and primary_color / secondary_color from the new UI.
                $imagesIn = $r->input('images', []);
                $images   = [];
                if (is_array($imagesIn)) {
                    foreach ($imagesIn as $u) {
                        if (is_string($u) && $u !== '') $images[] = $u;
                    }
                }
                $images = array_slice($images, 0, 10); // hard cap 10

                $primary   = (string) $r->input('primary_color', '');
                $secondary = (string) $r->input('secondary_color', '');
                $hex = '/^#[0-9a-fA-F]{6}$/';
                $colors = [];
                if ($primary   !== '' && preg_match($hex, $primary))   $colors['primary']   = $primary;
                if ($secondary !== '' && preg_match($hex, $secondary)) $colors['secondary'] = $secondary;
                // COLOUR THEMES (2026-09-05): a chosen theme arrives as `palette` {id,primary,secondary,accent,bg,text} or
                // just its id; generateWebsite promotes it into the brand colours (then harmonises them).
                $paletteIn = $r->input('palette');
                if (is_string($paletteIn) && $paletteIn !== '') $paletteIn = \App\Engines\Builder\Support\ColorTheme::find($paletteIn);
                if (is_array($paletteIn) && !empty($paletteIn['primary'])) $buildData['palette'] = $paletteIn;

                if (empty($buildData['business_name'])) {
                    return response()->json([
                        'type'        => 'error',
                        'build_error' => 'Build data missing or expired. Please describe your business again.',
                    ], 400);
                }

                try {
                    $built = $arthur->buildFromChat(
                        $wsId,
                        $buildData,
                        $logoUrl !== '' ? $logoUrl : null,
                        $images,
                        $colors,
                        $r->user()?->id,
                    );
                    \Illuminate\Support\Facades\Cache::forget('arthur_build_data_' . $wsId);
                    // BUILDER888 P1-8B (2026-08-10) — this used to return
                    // 'complete' unconditionally, so a failed build was labelled
                    // complete alongside build_outcome=error. A failed generation
                    // is never complete. 'error' is the vocabulary this contract
                    // already uses and the SPA already handles.
                    $failed = (($built['type'] ?? '') === 'error');

                    return response()->json([
                        'type'           => $failed ? 'error' : 'complete',
                        'website_id'     => $failed ? null : ($built['website_id'] ?? null),
                        'workspace_id'   => $built['workspace_id'] ?? null, // P2a — the (possibly new) workspace the site landed in; FE switches to it
                        'website_url'    => $failed ? null : ($built['website_url'] ?? null),
                        'build_outcome'  => $failed ? 'error' : 'website_created',
                        'build_error'    => $failed ? ($built['message'] ?? null) : null,
                        'error_category' => $failed ? ($built['error_category'] ?? null) : null,
                        'correlation_id' => $built['correlation_id'] ?? null,
                        'retryable'      => $failed ? (bool) ($built['retryable'] ?? false) : null,
                        'build_data'     => $buildData,
                    ], $failed ? 422 : 200);
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::error('[Arthur:buildFromChat] failed', [
                        'workspace_id' => $wsId,
                        'error'        => $e->getMessage(),
                    ]);
                    // BUILDER888 P1-8B — was 'Build failed: ' . $e->getMessage(),
                    // i.e. a raw engine exception handed to the customer.
                    return response()->json(
                        \App\Engines\Builder\Support\BuilderErrorContract::fromThrowable(
                            $e,
                            \App\Engines\Builder\Support\BuilderErrorContract::GENERATION_FAILED,
                            ['workspace_id' => $wsId, 'stage' => 'build_from_chat']
                        ) + ['website_id' => null],
                        500
                    );
                }
            }

            // Conversation turn — pure chat() dialogue, no build trigger.
            // RECREATE FROM A URL (DEC-0051, 2026-09-15): an address in the first messages is read and turned into the same
            // build_data a conversation produces; the confirm panel follows as usual.
            if (count($history) <= 4 && preg_match('~(?:https?://|www\.)[^\s<>"]+~i', $msg, $um) && ! preg_match('~levelupgrowth\.io~i', $um[0])) {
                $result = app(\App\Engines\Builder\Services\SiteImportService::class)->importForChat((int) $wsId, $um[0]);
            } else {
                $result = $arthur->chat($wsId, $msg, $history);
            }
            // COLOUR THEMES (2026-09-05): the confirm panel shows curated themes for THIS business instead of a bare picker.
            if (is_array($result) && !empty($result['build_data']['business_name'])) {
                $result['themes'] = $arthur->themesFor((array) $result['build_data'], 4);
                $result['themes_all'] = array_map(fn($t) => array_diff_key($t, ['moods' => 1, 'industries' => 1]), \App\Engines\Builder\Support\ColorTheme::all());
            }
            if (! empty($__meter['debited']) && is_array($result) && ! empty($result['reply'])) { $result['chat_meter'] = 1; $result['reply'] = rtrim((string) $result['reply']) . ' (1 credit — every 10th chat message)'; }
            return response()->json($result);
        });

        // Arthur user photo upload — multipart, ONE file per request.
        // Client uploads files one at a time (keeps each request under
        // PHP post_max_size = 8M), accumulates returned URLs, then passes
        // them as state.uploaded_images on the next /arthur/message call.
        Route::post('/arthur/upload', function (\Illuminate\Http\Request $r) {
            $wsId = $r->attributes->get('workspace_id');
            if (!$wsId) return response()->json(['error' => 'Auth required'], 401);

            $file = $r->file('image');
            if (!$file instanceof \Illuminate\Http\UploadedFile) {
                return response()->json(['error' => 'No file received. Field name must be "image".'], 400);
            }
            // Laravel's isValid() checks the uploaded file is present + not errored.
            // Must check this BEFORE getSize()/getMimeType() which stat() the tmp file.
            if (!$file->isValid()) {
                return response()->json(['error' => 'Upload invalid: ' . $file->getErrorMessage()], 400);
            }

            // Read size/mime once, defensively (stat failures here come from
            // tmp-file loss between requests — rare but fatal otherwise).
            try {
                $size = $file->getSize();
                $mime = $file->getMimeType() ?: $file->getClientMimeType();
            } catch (\Throwable $e) {
                return response()->json(['error' => 'Could not read uploaded file: ' . $e->getMessage()], 400);
            }

            // 2 MB hard cap per file (staging PHP upload_max_filesize = 2M)
            if ($size > 2 * 1024 * 1024) {
                return response()->json(['error' => 'File too large (max 2 MB).'], 413);
            }
            $allowed = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
            if (!in_array($mime, $allowed, true)) {
                return response()->json(['error' => 'Unsupported file type. Use JPG, PNG, WebP, or GIF.'], 415);
            }

            $dir = storage_path("app/public/arthur-uploads/{$wsId}");
            if (!is_dir($dir)) @mkdir($dir, 0775, true);

            $extByMime = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
            $ext  = $extByMime[$mime];
            $name = 'up_' . bin2hex(random_bytes(8)) . '.' . $ext;
            try {
                $file->move($dir, $name);
                \App\Engines\Builder\Support\ImagePolicy::normaliseInPlace($dir . '/' . $name, 'logo'); // IMAGE POLICY 2026-09-06 (chat logo)
                if (!str_ends_with(strtolower($name), '.svg')) { $__fit = \App\Engines\Builder\Support\ImageCrop::autoSafe($dir . '/' . $name, 'logo', $dir . '/' . $name); if ($__fit && $__fit['path'] !== $dir . '/' . $name) { @unlink($dir . '/' . $name); $name = basename($__fit['path']); } } // CROP TOOL: fixed 800×260 canvas
            } catch (\Throwable $e) {
                return response()->json(['error' => 'Upload failed: ' . $e->getMessage()], 500);
            }
            @chmod($dir . '/' . $name, 0644);

            return response()->json([
                'success' => true,
                'url'     => "/storage/arthur-uploads/{$wsId}/{$name}",
                'size'    => $size,
                'mime'    => $mime,
            ]);
        });

        // ── Arthur wizard logo upload (T1 2026-04-20) ──────────────
        // Session-scoped temp upload. Files live in storage/app/public/tmp/logos/
        // and are auto-cleaned. ArthurService::generateWebsite() copies the
        // chosen logo into the permanent per-website directory.
        // Route path = /api/builder/logo-upload-temp (group prefix `builder` already applied).
        Route::post('/logo-upload-temp', function (\Illuminate\Http\Request $r) {
            $file = $r->file('logo');
            if (!$file instanceof \Illuminate\Http\UploadedFile || !$file->isValid()) {
                return response()->json(['error' => 'No valid logo file received (field: logo).'], 400);
            }
            $size = $file->getSize();
            $mime = $file->getMimeType() ?: $file->getClientMimeType();
            if ($size > 2 * 1024 * 1024) {
                return response()->json(['error' => 'Logo too large (max 2 MB).'], 413);
            }
            $allowed = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/svg+xml' => 'svg', 'image/webp' => 'webp'];
            if (!isset($allowed[$mime])) {
                return response()->json(['error' => 'Unsupported logo type. Use PNG, JPG, SVG, or WEBP.'], 415);
            }
            $dir = storage_path('app/public/tmp/logos');
            if (!is_dir($dir)) @mkdir($dir, 0775, true);
            $name = 'logo_' . bin2hex(random_bytes(8)) . '.' . $allowed[$mime];
            try {
                $file->move($dir, $name);
            } catch (\Throwable $e) {
                return response()->json(['error' => 'Logo upload failed: ' . $e->getMessage()], 500);
            }
            @chmod($dir . '/' . $name, 0644);
            $tempPath = $dir . '/' . $name;
            $tempUrl  = '/storage/tmp/logos/' . $name;

            // BUILDER888 P0-4 (2026-08-10) — an uploaded SVG is served from this
            // origin as image/svg+xml with no Content-Disposition, so navigating
            // to it executes its script where the SPA keeps its bearer token.
            // Proven executable during the audit. Strip active content; refuse
            // the upload outright if what is left is not a usable SVG.
            if ($allowed[$mime] === 'svg') {
                $clean = \App\Engines\Builder\Support\SvgSanitizer::sanitize(
                    (string) @file_get_contents($dir . '/' . $name)
                );
                if ($clean === null) {
                    @unlink($dir . '/' . $name);
                    return response()->json(['error' => 'That SVG could not be processed safely. Please upload a PNG, JPG or WEBP.'], 422);
                }
                file_put_contents($dir . '/' . $name, $clean);
            }

            // Extract colors + generate palettes for immediate palette_choice.
            $palettes = [];
            try {
                if (class_exists(\App\Services\ColorExtractorService::class)) {
                    $extractor = new \App\Services\ColorExtractorService();
                    $dominant  = $extractor->extractFromFile($tempPath);
                    $palettes  = $extractor->generatePalettes($dominant);
                }
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('[Arthur] color extract failed: ' . $e->getMessage());
            }

            return response()->json([
                'success'   => true,
                'temp_url'  => $tempUrl,
                'temp_path' => $tempPath, // server-side only; used later by generateWebsite
                'size'      => $size,
                'mime'      => $mime,
                'palettes'  => $palettes,
            ]);
        });

        // ── Arthur image-upload-temp (full confirm panel, 2026-05-09) ────
        // Uploads ONE website image at a time. Auto-optimizes via GD:
        // resize to max 1920px wide, re-encode JPEG at 85% quality.
        // Caller accumulates returned temp_url's into the `images[]` array
        // sent on the follow-up /arthur/message {confirm:true} POST.
        // Field name: "image". 5 MB cap. PNG/JPG/JPEG/WEBP only.
        Route::post('/image-upload-temp', function (\Illuminate\Http\Request $r) {
            $file = $r->file('image');
            if (!$file instanceof \Illuminate\Http\UploadedFile || !$file->isValid()) {
                return response()->json(['error' => 'No valid image file received (field: image).'], 400);
            }
            $origSize = $file->getSize();
            $mime     = $file->getMimeType() ?: $file->getClientMimeType();
            if ($origSize > 5 * 1024 * 1024) {
                return response()->json(['error' => 'Image too large (max 5 MB).'], 413);
            }
            $allowed = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'];
            if (!isset($allowed[$mime])) {
                return response()->json(['error' => 'Unsupported image type. Use PNG, JPG, or WEBP.'], 415);
            }

            $dir = storage_path('app/public/tmp/images');
            if (!is_dir($dir)) @mkdir($dir, 0775, true);

            // Auto-optimize via GD: load → resize if >1920px wide → re-encode
            // as JPEG quality 85 (unless source is PNG with alpha, then keep PNG).
            $srcRaw = @file_get_contents($file->getRealPath());
            if ($srcRaw === false || $srcRaw === '') {
                return response()->json(['error' => 'Could not read uploaded file.'], 400);
            }
            $src = @imagecreatefromstring($srcRaw);
            if (!$src) {
                return response()->json(['error' => 'Image decode failed (corrupt file?).'], 422);
            }
            $w = imagesx($src);
            $h = imagesy($src);
            $maxW = 1920;
            if ($w > $maxW) {
                $newW = $maxW;
                $newH = (int) round($h * ($maxW / $w));
                $resized = imagecreatetruecolor($newW, $newH);
                if ($mime === 'image/png') {
                    imagealphablending($resized, false);
                    imagesavealpha($resized, true);
                    $transparent = imagecolorallocatealpha($resized, 0, 0, 0, 127);
                    imagefilledrectangle($resized, 0, 0, $newW, $newH, $transparent);
                }
                imagecopyresampled($resized, $src, 0, 0, 0, 0, $newW, $newH, $w, $h);
                imagedestroy($src);
                $src = $resized;
                $w = $newW; $h = $newH;
            }

            // Detect PNG alpha: if PNG and has alpha, keep PNG; else JPEG quality 85.
            $hasAlpha = false;
            if ($mime === 'image/png') {
                // imageistruecolor + check a few pixels for non-255 alpha
                imagealphablending($src, false);
                for ($y = 0; $y < $h && !$hasAlpha; $y += max(1, intdiv($h, 20))) {
                    for ($x = 0; $x < $w && !$hasAlpha; $x += max(1, intdiv($w, 20))) {
                        $c = imagecolorat($src, $x, $y);
                        $a = ($c >> 24) & 0x7F;
                        if ($a > 0) $hasAlpha = true;
                    }
                }
            }

            $ext = ($mime === 'image/png' && $hasAlpha) ? 'png' : 'jpg';
            $name = 'img_' . bin2hex(random_bytes(8)) . '.' . $ext;
            $outPath = $dir . '/' . $name;

            if ($ext === 'png') {
                imagealphablending($src, false);
                imagesavealpha($src, true);
                $ok = @imagepng($src, $outPath, 6);
            } else {
                // Flatten alpha onto white for JPEG output
                if ($mime === 'image/png') {
                    $flat = imagecreatetruecolor($w, $h);
                    $white = imagecolorallocate($flat, 255, 255, 255);
                    imagefilledrectangle($flat, 0, 0, $w, $h, $white);
                    imagecopy($flat, $src, 0, 0, 0, 0, $w, $h);
                    imagedestroy($src);
                    $src = $flat;
                }
                $ok = @imagejpeg($src, $outPath, 85);
            }
            imagedestroy($src);

            if (!$ok || !is_file($outPath)) {
                return response()->json(['error' => 'Optimization failed.'], 500);
            }
            @chmod($outPath, 0644);
            $optSize = filesize($outPath) ?: 0;

            return response()->json([
                'success'        => true,
                'temp_url'       => '/storage/tmp/images/' . $name,
                'temp_path'      => $outPath,
                'original_size'  => (int) $origSize,
                'optimized_size' => (int) $optSize,
                'width'          => $w,
                'height'         => $h,
                'format'         => $ext,
            ]);
        });

        // ── Template System ──────────────────────────────────────
        Route::get('/templates', function () {
            $ts = new \App\Engines\Builder\Services\TemplateService();
            return response()->json(['templates' => $ts->listTemplates()]);
        });

        Route::get('/templates/{industry}', function ($industry) {
            $ts = new \App\Engines\Builder\Services\TemplateService();
            $manifest = $ts->getManifest($industry);
            return $manifest
                ? response()->json($manifest)
                : response()->json(['error' => 'Not found'], 404);
        });
    });
