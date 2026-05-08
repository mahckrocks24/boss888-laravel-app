<?php

namespace App\Http\Controllers\Api\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use App\Services\MediaService;

/**
 * Unified media library admin surface. Reads the real-world `media` table
 * (populated by MediaService from DALL-E generation, user upload, and
 * platform assets). Also surfaces loose files in storage/arthur-uploads/
 * that never landed in the media table.
 *
 * Separate from AdminContentController::listCreativeAssets (which also
 * now reads media for backward compat). This controller is the primary
 * home for everything under /admin/media/*.
 */
class AdminMediaController
{
    /**
     * GET /admin/media — filtered + paginated list.
     * Supported filters: search, category, industry, type (image|video|all),
     * source (dalle|upload|platform|...), is_platform_asset (0|1).
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $perPage = max(1, min(100, (int) $request->input('per_page', 20)));
            $page    = max(1, (int) $request->input('page', 1));
            $offset  = ($page - 1) * $perPage;

            $q = DB::table('media')
                ->leftJoin('workspaces', 'workspaces.id', '=', 'media.workspace_id');

            $search = trim((string) $request->input('search', ''));
            if ($search !== '') {
                $q->where(function ($inner) use ($search) {
                    $inner->where('media.filename', 'like', "%{$search}%")
                        ->orWhereJsonContains('media.tags', $search);
                });
            }

            $category = trim((string) $request->input('category', ''));
            if ($category !== '' && $category !== 'all') {
                $q->where('media.category', $category);
            }

            // T3.1B — `industry` column does not exist in schema. Filter is
            // accepted in the request for forward-compat but silently no-op'd.

            $type = strtolower(trim((string) $request->input('type', '')));
            if ($type === 'image') {
                $q->where(function ($inner) {
                    $inner->where('media.asset_type', 'image')
                        ->orWhere('media.mime_type', 'like', 'image/%');
                });
            } elseif ($type === 'video') {
                $q->where(function ($inner) {
                    $inner->where('media.asset_type', 'video')
                        ->orWhere('media.mime_type', 'like', 'video/%');
                });
            } elseif ($type === 'document') {
                $q->where('media.asset_type', 'document');
            } elseif ($type === 'audio') {
                $q->where('media.asset_type', 'audio');
            }

            $source = trim((string) $request->input('source', ''));
            if ($source !== '' && $source !== 'all') {
                $q->where('media.source', $source);
            }

            $platformOnly = $request->input('is_platform_asset', null);
            if ($platformOnly === '1' || $platformOnly === 1 || $platformOnly === true || $platformOnly === 'true') {
                $q->where('media.is_platform_asset', 1);
            } elseif ($platformOnly === '0' || $platformOnly === 0 || $platformOnly === false || $platformOnly === 'false') {
                $q->where('media.is_platform_asset', 0);
            }

            $total = (clone $q)->count();

            $items = $q->orderByDesc('media.created_at')
                ->offset($offset)
                ->limit($perPage)
                ->select(
                    'media.id',
                    'media.workspace_id',
                    'media.filename',
                    'media.url',
                    'media.file_url',
                    'media.thumbnail_url',
                    'media.path',
                    'media.mime_type',
                    'media.asset_type',
                    'media.size_bytes',
                    'media.width',
                    'media.height',
                    'media.duration_seconds',
                    'media.source',
                    'media.category',
                    'media.is_platform_asset',
                    'media.is_public',
                    'media.tags',
                    'media.used_in',
                    'media.prompt',
                    'media.model',
                    'media.created_at',
                    'workspaces.name as workspace_name'
                )
                ->get();

            // Distinct filter options so the UI can populate dropdowns without a
            // second round-trip.
            $categories = DB::table('media')->whereNotNull('category')
                ->distinct()->pluck('category')->filter()->values();
            // T3.1B — `industry` column missing in schema; return empty for forward-compat
            $industries = collect();
            $sources    = DB::table('media')->whereNotNull('source')
                ->distinct()->pluck('source')->filter()->values();

            return response()->json([
                'success'     => true,
                'data'        => $items,
                'total'       => $total,
                'page'        => $page,
                'per_page'    => $perPage,
                'total_pages' => $perPage > 0 ? (int) ceil($total / $perPage) : 1,
                'filters'     => [
                    'categories' => $categories,
                    'industries' => $industries,
                    'sources'    => $sources,
                ],
            ]);
        } catch (\Throwable $e) {
            Log::warning('[AdminMedia] index failed: ' . $e->getMessage());
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /admin/media/stats — 4 cards: total / platform / workspace / storage.
     */
    public function stats(Request $request): JsonResponse
    {
        try {
            $total     = (int) DB::table('media')->count();
            $platform  = (int) DB::table('media')->where('is_platform_asset', 1)->count();
            $workspace = (int) DB::table('media')->where('is_platform_asset', 0)->count();
            $bytes     = (int) DB::table('media')->sum('size_bytes');

            return response()->json([
                'success'        => true,
                'total_files'    => $total,
                'platform'       => $platform,
                'workspace'      => $workspace,
                'storage_bytes'  => $bytes,
                'storage_human'  => $this->humanBytes($bytes),
                'by_category'    => DB::table('media')
                    ->selectRaw('category, COUNT(*) as count, COALESCE(SUM(size_bytes),0) as bytes')
                    ->groupBy('category')->get(),
                'by_source'      => DB::table('media')
                    ->selectRaw('source, COUNT(*) as count')
                    ->groupBy('source')->get(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('[AdminMedia] stats failed: ' . $e->getMessage());
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * POST /admin/media/upload — admin uploads a platform asset.
     * Accepts multipart "file" + optional category, industry, description, tags.
     * 100 MB cap — videos supported via MP4/MOV/WEBM. ffmpeg/ffprobe if
     * available for thumbnail + duration; silent fallback if not.
     */
    public function upload(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'file' => 'required|file|max:102400', // 100 MB — matches nginx + PHP limits
            ]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 422);
        }

        $file = $request->file('file');
        $category = $this->sanitize((string) $request->input('category'), 'hero,gallery,website,blog,logo,team,other');
        $industry = $this->sanitizeFree((string) $request->input('industry'), 50);
        $description = trim((string) $request->input('description'));
        $tagsRaw  = (string) $request->input('tags', '');
        $tags = array_values(array_filter(array_map('trim', explode(',', $tagsRaw))));

        $ext = strtolower($file->getClientOriginalExtension());
        if (!in_array($ext, ['jpg','jpeg','png','webp','gif','svg','mp4','webm','mov','m4v','pdf','doc','docx'], true)) {
            return response()->json(['success' => false, 'error' => 'Unsupported file type: ' . $ext], 422);
        }

        $persisted = $this->persistUploadedFile($file, $category, $industry, $description, $tags);
        if (!$persisted['success']) {
            return response()->json(['success' => false, 'error' => $persisted['error']], 500);
        }

        return response()->json([
            'success' => true,
            'id'      => $persisted['id'],
            'url'     => $persisted['url'],
            'path'    => $persisted['path'],
            'size'    => $persisted['size'],
            'mime'    => $persisted['mime'],
            'asset_type'    => $persisted['asset_type'],
            'thumbnail_url' => $persisted['thumbnail_url'],
            'duration_seconds' => $persisted['duration_seconds'],
        ], 201);
    }

    /**
     * Shared persist-to-disk + insert-into-media used by both single and
     * bulk upload paths. Handles image dimensions, video thumbnail (via
     * ffmpeg when present), and duration probe (via ffprobe when present).
     * Returns a uniform shape so callers don't branch on single vs bulk.
     */
    private function persistUploadedFile($file, ?string $category, ?string $industry, string $description, array $tags): array
    {
        try {
            $ext = strtolower($file->getClientOriginalExtension());
            $clientMime = $file->getClientMimeType();
            $isVideo = str_starts_with($clientMime, 'video/') || in_array($ext, ['mp4','webm','mov','m4v'], true);
            $isImage = str_starts_with($clientMime, 'image/') || in_array($ext, ['jpg','jpeg','png','webp','gif','svg'], true);
            $isDoc   = in_array($ext, ['pdf','doc','docx'], true);

            // Videos get their own subdirectory so browsing disk stays sane.
            $relDir = $isVideo ? 'media/videos' : ($isDoc ? 'media/documents' : 'media/platform');
            $name = Str::random(16) . '.' . $ext;
            $absDir = storage_path('app/public/' . $relDir);
            if (!is_dir($absDir)) @mkdir($absDir, 0755, true);

            if (!$file->move($absDir, $name)) {
                return ['success' => false, 'error' => 'Failed to store uploaded file'];
            }

            $absPath = $absDir . '/' . $name;
            $relPath = '/' . $relDir . '/' . $name;
            $url     = '/storage/' . $relDir . '/' . $name;
            $size    = is_file($absPath) ? filesize($absPath) : 0;
            $mime    = is_file($absPath) ? (mime_content_type($absPath) ?: $clientMime) : $clientMime;

            $width = null; $height = null; $orientation = 'landscape';
            $duration = null; $thumbnailUrl = null; $assetType = 'document';

            if ($isImage) {
                $assetType = 'image';
                $info = @getimagesize($absPath);
                if ($info) {
                    $width  = $info[0];
                    $height = $info[1];
                    if ($height > $width) $orientation = 'portrait';
                    elseif ($width === $height) $orientation = 'square';
                }
            } elseif ($isVideo) {
                $assetType = 'video';
                [$width, $height, $duration, $thumbnailUrl] = $this->probeVideoAndThumbnail($absPath, $name, $absDir);
                if ($width && $height) {
                    if ($height > $width) $orientation = 'portrait';
                    elseif ($width === $height) $orientation = 'square';
                }
            }

            // T3.1B — `industry`, `description`, `orientation`, `use_count`
            // columns don't exist in schema. Fold orientation/industry/description
            // into metadata_json so the data isn't lost.
            $metadata = [
                'uploaded_by_admin' => true,
                'original_name'     => $file->getClientOriginalName(),
                'orientation'       => $orientation,
            ];
            if ($industry)    $metadata['industry']    = $industry;
            if ($description) $metadata['description'] = $description;

            $id = DB::table('media')->insertGetId([
                'workspace_id'      => null,
                'filename'          => $file->getClientOriginalName() ?: $name,
                'path'              => $relPath,
                'url'               => $url,
                'file_url'          => $url,
                'thumbnail_url'     => $thumbnailUrl,
                'mime_type'         => $mime,
                'asset_type'        => $assetType,
                'size_bytes'        => $size,
                'width'             => $width,
                'height'            => $height,
                'duration_seconds'  => $duration,
                'source'            => 'platform',
                'category'          => $category ?: null,
                'is_platform_asset' => 1,
                'is_public'         => 1,
                'tags'              => !empty($tags) ? json_encode($tags) : null,
                'metadata_json'     => json_encode($metadata),
                'created_at'        => now(),
                'updated_at'        => now(),
            ]);

            return [
                'success'          => true,
                'id'               => $id,
                'url'              => $url,
                'path'             => $relPath,
                'size'             => $size,
                'mime'             => $mime,
                'asset_type'       => $assetType,
                'thumbnail_url'    => $thumbnailUrl,
                'duration_seconds' => $duration,
            ];
        } catch (\Throwable $e) {
            Log::warning('[AdminMedia] persistUploadedFile failed: ' . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Probe a local video file for width/height/duration and extract a
     * thumbnail at ~1s via ffmpeg. All best-effort — missing binaries or
     * any error return null tuples and the caller falls back gracefully.
     *
     * Returns [width, height, duration_seconds, thumbnail_url].
     */
    private function probeVideoAndThumbnail(string $absPath, string $name, string $absDir): array
    {
        $width = null; $height = null; $duration = null; $thumbUrl = null;

        $ffprobe = trim((string) @shell_exec('command -v ffprobe 2>/dev/null'));
        if ($ffprobe !== '' && is_file($absPath)) {
            $cmd = escapeshellcmd($ffprobe)
                . ' -v error -select_streams v:0 -show_entries stream=width,height:format=duration'
                . ' -of default=noprint_wrappers=1 '
                . escapeshellarg($absPath) . ' 2>/dev/null';
            $out = (string) @shell_exec($cmd);
            if ($out !== '') {
                foreach (explode("\n", $out) as $line) {
                    if (str_starts_with($line, 'width=')) $width = (int) substr($line, 6);
                    elseif (str_starts_with($line, 'height=')) $height = (int) substr($line, 7);
                    elseif (str_starts_with($line, 'duration=')) $duration = (int) round((float) substr($line, 9));
                }
            }
        }

        $ffmpeg = trim((string) @shell_exec('command -v ffmpeg 2>/dev/null'));
        if ($ffmpeg !== '' && is_file($absPath)) {
            $thumbName = pathinfo($name, PATHINFO_FILENAME) . '_thumb.jpg';
            $thumbAbs  = $absDir . '/' . $thumbName;
            $cmd = escapeshellcmd($ffmpeg)
                . ' -y -ss 00:00:01 -i ' . escapeshellarg($absPath)
                . ' -frames:v 1 -vf scale=640:-2 -q:v 5 '
                . escapeshellarg($thumbAbs) . ' 2>/dev/null';
            @shell_exec($cmd);
            if (is_file($thumbAbs) && filesize($thumbAbs) > 0) {
                $relDir = str_replace(storage_path('app/public/'), '', $absDir);
                $thumbUrl = '/storage/' . trim($relDir, '/') . '/' . $thumbName;
            }
        }

        return [$width, $height, $duration, $thumbUrl];
    }

    /**
     * POST /admin/media/bulk-upload — multiple files in one request.
     * Expects multipart with files[] + shared category/industry/description/tags.
     */
    public function bulkUpload(Request $request): JsonResponse
    {
        $files = $request->file('files') ?: [];
        if (empty($files)) {
            return response()->json(['success' => false, 'error' => 'No files provided (use files[])'], 400);
        }
        if (!is_array($files)) $files = [$files];
        if (count($files) > 30) {
            return response()->json(['success' => false, 'error' => 'Too many files — max 30 per batch'], 422);
        }

        $category    = $this->sanitize((string) $request->input('category'), 'hero,gallery,website,blog,logo,team,other');
        $industry    = $this->sanitizeFree((string) $request->input('industry'), 50);
        $description = trim((string) $request->input('description'));
        $tags        = array_values(array_filter(array_map('trim', explode(',', (string) $request->input('tags', '')))));

        $uploaded = [];
        $failed   = [];
        foreach ($files as $file) {
            if (!$file || $file->getSize() > 100 * 1024 * 1024) {
                $failed[] = ['filename' => $file?->getClientOriginalName() ?? 'unknown', 'error' => 'File too large or invalid'];
                continue;
            }
            $ext = strtolower($file->getClientOriginalExtension());
            if (!in_array($ext, ['jpg','jpeg','png','webp','gif','svg','mp4','webm','mov','m4v','pdf','doc','docx'], true)) {
                $failed[] = ['filename' => $file->getClientOriginalName(), 'error' => 'Unsupported type: ' . $ext];
                continue;
            }
            $r = $this->persistUploadedFile($file, $category, $industry, $description, $tags);
            if ($r['success']) {
                $uploaded[] = [
                    'id' => $r['id'], 'url' => $r['url'], 'asset_type' => $r['asset_type'],
                    'thumbnail_url' => $r['thumbnail_url'], 'duration_seconds' => $r['duration_seconds'],
                    'filename' => $file->getClientOriginalName(),
                ];
            } else {
                $failed[] = ['filename' => $file->getClientOriginalName(), 'error' => $r['error'] ?? 'unknown'];
            }
        }

        return response()->json([
            'success'        => true,
            'uploaded_count' => count($uploaded),
            'failed_count'   => count($failed),
            'uploaded'       => $uploaded,
            'failed'         => $failed,
        ], 201);
    }

    /**
     * POST /admin/media/bulk-delete — body: { ids: [1,2,3] }
     */
    public function bulkDestroy(Request $request): JsonResponse
    {
        $ids = $request->input('ids', []);
        if (!is_array($ids) || empty($ids)) {
            return response()->json(['success' => false, 'error' => 'ids[] required'], 400);
        }
        $ids = array_values(array_filter(array_map('intval', $ids), fn($v) => $v > 0));
        if (count($ids) > 200) {
            return response()->json(['success' => false, 'error' => 'Too many ids — max 200 per batch'], 422);
        }

        $rows = DB::table('media')->whereIn('id', $ids)->get(['id','path']);
        $deleted = DB::table('media')->whereIn('id', $ids)->delete();
        $filesRemoved = 0;
        foreach ($rows as $row) {
            if (empty($row->path)) continue;
            $abs = storage_path('app/public/' . ltrim($row->path, '/'));
            if (is_file($abs) && @unlink($abs)) $filesRemoved++;
        }
        return response()->json([
            'success' => true, 'rows_deleted' => $deleted, 'files_removed' => $filesRemoved,
        ]);
    }

    /**
     * PATCH /admin/media/{id}/tags — body: { tags: ["luxury","warm"], description?: "..." }
     * Lightweight editor hook; also accepts optional category/industry updates.
     */
    public function updateTags(Request $request, int $id): JsonResponse
    {
        $row = DB::table('media')->where('id', $id)->first();
        if (!$row) return response()->json(['success' => false, 'error' => 'Not found'], 404);

        $patch = [];
        if ($request->has('tags')) {
            $tags = $request->input('tags');
            if (is_string($tags)) {
                $tags = array_values(array_filter(array_map('trim', explode(',', $tags))));
            }
            $tags = is_array($tags) ? array_values(array_filter(array_map('strval', $tags))) : [];
            $patch['tags'] = !empty($tags) ? json_encode($tags) : null;
        }
        // T3.1B — description and industry columns don't exist in schema;
        // accept inputs in the request for forward-compat but ignore them.
        if ($request->has('category')) {
            $c = $this->sanitize((string) $request->input('category'), 'hero,gallery,website,blog,logo,team,other');
            if ($c !== '') $patch['category'] = $c;
        }
        if (empty($patch)) return response()->json(['success' => false, 'error' => 'Nothing to update'], 400);
        $patch['updated_at'] = now();
        DB::table('media')->where('id', $id)->update($patch);

        $updated = DB::table('media')->where('id', $id)->first(['id','tags','category']);
        return response()->json(['success' => true, 'media' => $updated]);
    }

    /**
     * GET /admin/media/{id}/usage — scan websites + articles for references to the asset URL.
     * Expensive-ish (scans JSON columns with LIKE) so kept off the list endpoint.
     */
    public function usage(Request $request, int $id): JsonResponse
    {
        $row = DB::table('media')->where('id', $id)->first(['id','url','path','filename']);
        if (!$row) return response()->json(['success' => false, 'error' => 'Not found'], 404);

        $needle  = '%' . $row->url . '%';
        $needle2 = '%' . ($row->path ?: $row->url) . '%';

        $sites = DB::table('websites')
            ->whereNull('deleted_at')
            ->where(function ($q) use ($needle, $needle2) {
                $q->where('template_variables', 'like', $needle)
                  ->orWhere('template_variables', 'like', $needle2);
            })
            ->select('id', 'name', 'template_industry', 'status')
            ->limit(50)->get();

        $articles = collect();
        if (\Illuminate\Support\Facades\Schema::hasTable('articles')) {
            try {
                $hasContent = \Illuminate\Support\Facades\Schema::hasColumn('articles', 'content');
                $hasFeatured = \Illuminate\Support\Facades\Schema::hasColumn('articles', 'featured_image');
                $articles = DB::table('articles')
                    ->where(function ($q) use ($needle, $needle2, $hasContent, $hasFeatured) {
                        if ($hasContent)  $q->where('content', 'like', $needle)->orWhere('content', 'like', $needle2);
                        if ($hasFeatured) $q->orWhere('featured_image', 'like', $needle2);
                        if (!$hasContent && !$hasFeatured) $q->whereRaw('0 = 1');
                    })
                    ->select('id', 'title', 'status')
                    ->limit(50)->get();
            } catch (\Throwable $e) {
                Log::warning('[AdminMedia] usage article scan failed: ' . $e->getMessage());
            }
        }

        return response()->json([
            'success'      => true,
            'media_id'     => $id,
            'url'          => $row->url,
            'websites'     => $sites,
            'articles'     => $articles,
            'total_usage'  => $sites->count() + $articles->count(),
        ]);
    }

    /**
     * DELETE /admin/media/{id}
     * Removes the original file + thumbnail (T3.1D) + DB row.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        try {
            $row = DB::table('media')->where('id', $id)->first();
            if (!$row) {
                return response()->json(['success' => false, 'error' => 'Not found'], 404);
            }

            $publicRoot = storage_path('app/public');
            $filesRemoved = 0;

            // Original file.
            if (!empty($row->path)) {
                $abs = $publicRoot . '/' . ltrim($row->path, '/');
                if (is_file($abs) && @unlink($abs)) $filesRemoved++;
            }

            // T3.1D — also remove the generated thumbnail if present.
            if (!empty($row->thumbnail_url)) {
                $thumbRel = ltrim(str_replace('/storage/', '', (string) $row->thumbnail_url), '/');
                if ($thumbRel) {
                    $thumbAbs = $publicRoot . '/' . $thumbRel;
                    if (is_file($thumbAbs) && @unlink($thumbAbs)) $filesRemoved++;
                }
            }

            $deleted = DB::table('media')->where('id', $id)->delete() > 0;

            return response()->json([
                'success'       => true,
                'deleted'       => $deleted,
                'files_removed' => $filesRemoved,
            ]);
        } catch (\Throwable $e) {
            Log::warning('[AdminMedia] destroy failed: ' . $e->getMessage());
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * POST /admin/media/generate — admin-only DALL-E 3 image generation.
     * Body: {prompt, size?, quality?, category?, filename?}
     * After save, immediately generates a thumbnail so the new image
     * appears in the picker grid with art, not a blank card.
     */
    public function generate(Request $request): JsonResponse
    {
        // Defense-in-depth admin check (route also has AdminMiddleware).
        $userId = (int) $request->attributes->get('user_id');
        $user = DB::table('users')->where('id', $userId)->first();
        if (!$user || !$user->is_platform_admin) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'prompt'   => 'required|string|max:1000',
            'size'     => 'sometimes|in:1024x1024,1792x1024,1024x1792',
            'quality'  => 'sometimes|in:standard,hd',
            'category' => 'nullable|string|max:50',
            'filename' => 'nullable|string|max:100',
        ]);

        $size     = $validated['size']     ?? '1792x1024';
        $quality  = $validated['quality']  ?? 'standard';
        $category = $validated['category'] ?? 'generated';

        // PATCH 4 (2026-05-08): route through RuntimeClient (was direct curl
        // to api.openai.com — hands-vs-brain bypass introduced in T3.8).
        $runtime = app(\App\Connectors\RuntimeClient::class);
        if (!$runtime->isConfigured()) {
            return response()->json(['error' => 'Runtime (RUNTIME_URL / RUNTIME_SECRET) not configured'], 500);
        }

        $imgResult = $runtime->imageGenerate($validated['prompt'], [
            'size'    => $size,
            'quality' => $quality,
        ]);

        if (empty($imgResult['success']) || empty($imgResult['url'])) {
            $errorMsg = $imgResult['error'] ?? 'unknown';
            Log::warning('[AdminMedia] generate runtime failed', ['result' => $imgResult]);
            return response()->json(['error' => 'Image generation failed: ' . $errorMsg], 500);
        }

        $imageUrl      = $imgResult['url'];
        $revisedPrompt = $imgResult['revised_prompt'] ?? $validated['prompt'];

        // Download.
        $imageData = @file_get_contents($imageUrl);
        if (! $imageData || strlen($imageData) < 1024) {
            return response()->json(['error' => 'Failed to download generated image'], 500);
        }

        // Filename + path.
        $filename = ! empty($validated['filename'])
            ? preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $validated['filename'])) . '.jpg'
            : 'gen_' . time() . '_' . \Illuminate\Support\Str::random(6) . '.jpg';
        if (! $filename || $filename === '.jpg') $filename = 'gen_' . time() . '.jpg';

        $subdir = 'generated/' . date('Y-m');
        $absDir = storage_path('app/public/' . $subdir);
        if (! is_dir($absDir)) @mkdir($absDir, 0755, true);
        $absPath = $absDir . '/' . $filename;

        if (file_put_contents($absPath, $imageData) === false) {
            return response()->json(['error' => 'Failed to save image to disk'], 500);
        }
        @chmod($absPath, 0644);

        [$width, $height] = @getimagesize($absPath) ?: [0, 0];
        $sizeBytes = filesize($absPath);

        // Inline thumbnail generation (T3.1D Phase 4B requirement).
        $relPath  = $subdir . '/' . $filename;
        $thumbAbs = storage_path('app/public/thumbnails/' . $relPath);
        $thumbnailUrl = null;
        if (\App\Services\ThumbnailService::generate($absPath, $thumbAbs)) {
            $thumbnailUrl = '/storage/thumbnails/' . $relPath;
        } else {
            Log::warning('[AdminMedia] generate: inline thumbnail failed', ['path' => $relPath]);
        }

        $mediaId = DB::table('media')->insertGetId([
            'workspace_id'      => null,
            'filename'          => $filename,
            'path'              => $relPath,
            'url'               => '/storage/' . $relPath,
            'file_url'          => '/storage/' . $relPath,
            'thumbnail_url'     => $thumbnailUrl,
            'mime_type'         => 'image/jpeg',
            'asset_type'        => 'image',
            'size_bytes'        => $sizeBytes,
            'width'             => $width,
            'height'            => $height,
            'source'            => 'dall-e',
            'is_platform_asset' => 1,
            'is_public'         => 1,
            'category'          => $category,
            'prompt'            => $validated['prompt'],
            'model'             => 'dall-e-3',
            'metadata_json'     => json_encode([
                'revised_prompt' => $revisedPrompt,
                'size'           => $size,
                'quality'        => $quality,
                'generated_at'   => now()->toIso8601String(),
                'generated_by'   => $userId,
            ]),
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);

        return response()->json([
            'success'        => true,
            'media_id'       => $mediaId,
            'url'            => '/storage/' . $relPath,
            'thumbnail_url'  => $thumbnailUrl,
            'revised_prompt' => $revisedPrompt,
            'size_bytes'     => $sizeBytes,
            'dimensions'     => $width . 'x' . $height,
        ]);
    }

    /**
     * GET /admin/media/arthur-uploads — list raw files under storage/app/public/arthur-uploads/
     * that never landed in the media table (users uploaded mid-build).
     */
    public function arthurUploads(Request $request): JsonResponse
    {
        try {
            $root = storage_path('app/public/arthur-uploads');
            $rows = [];
            if (is_dir($root)) {
                foreach (scandir($root) as $wsDir) {
                    if ($wsDir === '.' || $wsDir === '..') continue;
                    $absWs = $root . '/' . $wsDir;
                    if (!is_dir($absWs)) continue;
                    $wsId = is_numeric($wsDir) ? (int) $wsDir : null;
                    foreach (scandir($absWs) as $f) {
                        if ($f === '.' || $f === '..') continue;
                        $abs = $absWs . '/' . $f;
                        if (!is_file($abs)) continue;
                        $rows[] = [
                            'filename'     => $f,
                            'workspace_id' => $wsId,
                            'path'         => "/arthur-uploads/{$wsDir}/{$f}",
                            'url'          => "/storage/arthur-uploads/{$wsDir}/{$f}",
                            'size_bytes'   => filesize($abs),
                            'mime_type'    => mime_content_type($abs) ?: 'application/octet-stream',
                            'modified_at'  => date('c', filemtime($abs)),
                        ];
                    }
                }
            }

            // Sort newest first
            usort($rows, fn($a, $b) => strcmp($b['modified_at'], $a['modified_at']));

            return response()->json([
                'success' => true,
                'data'    => $rows,
                'total'   => count($rows),
            ]);
        } catch (\Throwable $e) {
            Log::warning('[AdminMedia] arthurUploads failed: ' . $e->getMessage());
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    // ── Helpers ──────────────────────────────────────────────────────

    private function humanBytes(int $bytes): string
    {
        if ($bytes <= 0) return '0 B';
        $units = ['B','KB','MB','GB','TB'];
        $i = (int) floor(log($bytes, 1024));
        $i = max(0, min($i, count($units) - 1));
        return round($bytes / pow(1024, $i), $i > 1 ? 2 : 0) . ' ' . $units[$i];
    }

    private function sanitize(string $v, string $allowedCsv): string
    {
        $v = trim(strtolower($v));
        $allowed = array_map('trim', explode(',', $allowedCsv));
        return in_array($v, $allowed, true) ? $v : '';
    }

    private function sanitizeFree(string $v, int $max = 50): string
    {
        $v = trim($v);
        if ($v === '' || strlen($v) > $max) return '';
        if (!preg_match('/^[A-Za-z0-9 _\-]+$/', $v)) return '';
        return $v;
    }
}
