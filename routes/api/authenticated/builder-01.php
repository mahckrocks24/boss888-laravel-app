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
        Route::get('/websites/{id}', fn(\Illuminate\Http\Request $r, $id) => response()->json(app($s)->getWebsite($r->attributes->get('workspace_id'), $id)));
        Route::get('/websites/{id}/pages', fn(\Illuminate\Http\Request $r, $id) => response()->json(app($s)->listPages((int) $id, (int) $r->attributes->get('workspace_id'))));
        Route::get('/pages/{id}', function (\Illuminate\Http\Request $r, $id) use ($s) {
            $page = app($s)->getPage((int) $id, (int) $r->attributes->get('workspace_id'));
            return $page ? response()->json($page) : response()->json(['error' => 'Page not found'], 404);
        });
        // Writes through pipeline
        Route::post('/websites', fn(\Illuminate\Http\Request $r) => response()->json(app($exec)->execute($r->attributes->get('workspace_id'), 'builder', 'create_website', $r->all(), ['user_id' => $r->user()?->id, 'source' => 'manual']), 201));
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
            try {
                app($s)->deletePage((int) $id, (int) $r->attributes->get("workspace_id"));
            } catch (\RuntimeException $e) {
                return response()->json(["error" => "Page not found"], 404);
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
            try {
                $html = app(\App\Engines\Builder\Services\BuilderRenderer::class)
                    ->renderPage((array) $website, (array) $page, []);
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning("[Builder] preview render failed", ["page_id" => (int) $id, "error" => $e->getMessage()]);
                return response()->json(["error" => "Preview render failed"], 500);
            }
            return response()->json(["preview_html" => $html, "page_id" => (int) $id]);
        });
        Route::get("/library", fn() => response()->json(["blocks" => [], "templates" => []]));
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
            $result = $arthur->chat($wsId, $msg, $history);
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
