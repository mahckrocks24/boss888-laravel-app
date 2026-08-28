<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use App\Services\MediaAccessService;

/**
 * User-facing media endpoints (distinct from AdminMediaController).
 *
 * Existing: POST /api/media/upload  (workspace-scoped single upload)
 * Added 2026-04-19 for the unified media picker:
 *   GET  /api/media/library
 *   GET  /api/media/access
 *   POST /api/media/use
 */
class MediaController
{
    /**
     * POST /api/media/upload — workspace-scoped single file. Writes to
     * storage/app/public/uploads/ and returns url/size/mime. Also
     * registers a row in the `media` table so the picker's "My Uploads"
     * tab sees it immediately.
     *
     * v1.4.4 — accepts video, validates per-kind size caps:
     *   images / audio:  100 MB
     *   video:           200 MB
     *   documents:        50 MB
     */
    public function upload(Request $request): JsonResponse
    {
        // ── v1.4.4 — per-kind size cap. Validation runs in two passes:
        // first an upper bound (200 MB — the largest cap), then the
        // mime-aware cap once we know the type.
        $request->validate([
            'file' => 'required|file|max:204800', // 200 MB upper bound (video cap)
            'kind' => 'nullable|string|in:image,video,document,audio',
        ]);

        $file       = $request->file('file');
        $mimeUpload = $file->getClientMimeType();
        $realMime   = (string) $file->getMimeType();   // RISK-0119 — magic bytes, not spoofable

        // Resolve effective kind, preferring an explicit hint from the
        // mobile companion (which knows what the user picked).
        $kindHint   = $request->input('kind');
        $effective  = $kindHint ?: $this->kindFromMime($mimeUpload);

        // Per-kind size enforcement (kilobytes for the validator).
        $perKindCap = match ($effective) {
            'image', 'audio' => 102400,  // 100 MB
            'video'          => 204800,  // 200 MB
            'document'       =>  51200,  //  50 MB
            default          => 102400,
        };
        $request->validate([
            'file' => 'file|max:' . $perKindCap,
        ]);

        // ── v1.4.4 — explicit allow-list for chat attachments. Anything
        // not on the list is rejected up front so an oversized PDF or a
        // disallowed type can't sneak in through extension confusion.
        $allowedMimes = [
            // images
            'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/heic', 'image/heif',
            // video
            'video/mp4', 'video/quicktime', 'video/webm', 'video/x-matroska', 'video/3gpp',
            // audio
            'audio/mpeg', 'audio/mp4', 'audio/aac', 'audio/wav', 'audio/webm',
            // docs
            'application/pdf',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/vnd.ms-powerpoint',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'text/csv', 'text/plain', 'application/zip', 'application/json',
        ];
        if (! in_array($mimeUpload, $allowedMimes, true)) {
            return response()->json([
                'success' => false,
                'error'   => "Sorry — we don't support {$mimeUpload} attachments yet.",
            ], 422);
        }

        // RISK-0119 (P0) — the 'public' disk is web-served and EXECUTES PHP, so an arbitrary
        // extension/content is RCE/stored-XSS. Block actual script/markup content by magic bytes
        // (defeats a spoofed image/png Content-Type on a PHP/HTML/SVG payload)...
        $dangerMimes = [
            'text/html', 'application/xhtml+xml', 'image/svg+xml',
            'text/x-php', 'application/x-httpd-php', 'application/x-php',
            'application/javascript', 'text/javascript',
            'application/x-sh', 'text/x-shellscript', 'text/x-python', 'application/x-perl',
        ];
        if (in_array($realMime, $dangerMimes, true)) {
            return response()->json([
                'success' => false,
                'error'   => 'That file was rejected for security reasons.',
            ], 422);
        }

        // ...and NEVER store the client-supplied extension: derive a safe one from the validated
        // type. Every value here is an inert extension, so no executable/markup file can be created.
        $extForMime = [
            'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp',
            'image/heic' => 'heic', 'image/heif' => 'heif',
            'video/mp4' => 'mp4', 'video/quicktime' => 'mov', 'video/webm' => 'webm',
            'video/x-matroska' => 'mkv', 'video/3gpp' => '3gp',
            'audio/mpeg' => 'mp3', 'audio/mp4' => 'm4a', 'audio/aac' => 'aac', 'audio/wav' => 'wav', 'audio/webm' => 'weba',
            'application/pdf' => 'pdf', 'application/msword' => 'doc',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            'application/vnd.ms-excel' => 'xls',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
            'application/vnd.ms-powerpoint' => 'ppt',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
            'text/csv' => 'csv', 'text/plain' => 'txt', 'application/zip' => 'zip', 'application/json' => 'json',
        ];
        $safeExt = $extForMime[$mimeUpload] ?? ($extForMime[$realMime] ?? 'bin');
        $name = Str::random(16) . '.' . $safeExt;
        $path = $file->storeAs('uploads', $name, 'public');

        $wsId    = (int) ($request->attributes->get('workspace_id') ?? 0) ?: null;
        $mediaId = null;

        try {
            $abs  = storage_path('app/public/' . $path);
            $mime = is_file($abs) ? (mime_content_type($abs) ?: $mimeUpload) : $mimeUpload;
            $size = is_file($abs) ? filesize($abs) : $file->getSize();

            $assetType = $this->kindFromMime($mime);
            if ($kindHint && in_array($kindHint, ['image', 'video', 'document', 'audio'], true)) {
                $assetType = $kindHint; // mobile companion's user-chosen kind wins
            }

            $width = null; $height = null; $durationSeconds = null;
            if ($assetType === 'image' && is_file($abs)) {
                $info = @getimagesize($abs);
                if ($info) { $width = $info[0]; $height = $info[1]; }
            }

            $mediaId = (int) DB::table('media')->insertGetId([
                'workspace_id'      => $wsId,
                'filename'          => $file->getClientOriginalName() ?: $name,
                'path'              => '/uploads/' . $name,
                'url'               => Storage::url($path),
                'file_url'          => Storage::url($path),
                'mime_type'         => $mime,
                'asset_type'        => $assetType,
                'size_bytes'        => $size,
                'width'             => $width,
                'height'            => $height,
                'duration_seconds'  => $durationSeconds,
                'source'            => 'upload',
                'is_platform_asset' => 0,
                'is_public'         => 0,
                'created_at'        => now(),
                'updated_at'        => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('[MediaController] upload register failed: ' . $e->getMessage());
        }

        // ── v1.4.4 — shape the response so the mobile companion's
        // UploadedMedia DTO can consume it without parsing twice.
        $url = Storage::url($path);
        return response()->json([
            'success' => true,
            'media'   => [
                'id'           => $mediaId,
                'kind'         => $this->kindFromMime($mimeUpload),
                'name'         => $file->getClientOriginalName(),
                'size'         => $file->getSize(),
                'mime'         => $mimeUpload,
                'preview_url'  => $url,        // for images this IS the preview
                'original_url' => $url,
            ],
            // Backwards-compatible fields for any caller still on the v1.3 shape:
            'url'       => $url,
            'name'      => $file->getClientOriginalName(),
            'size'      => $file->getSize(),
            'mime_type' => $mimeUpload,
        ], 201);
    }

    /**
     * v1.4.4 — classify an uploaded file into the four `asset_type` enum
     * values used by the `media` table.
     */
    private function kindFromMime(string $mime): string
    {
        if (str_starts_with($mime, 'image/')) return 'image';
        if (str_starts_with($mime, 'video/')) return 'video';
        if (str_starts_with($mime, 'audio/')) return 'audio';
        return 'document';
    }

    /**
     * GET /api/media/library
     * Query: type (image|video|document|any), platform (0|1), search, page, per_page.
     * platform=1 is plan-gated via MediaAccessService; returns
     * {locked:true, upgrade_required:true} if the workspace has no access.
     */
    public function library(Request $request): JsonResponse
    {
        $wsId = (int) ($request->attributes->get('workspace_id') ?? 0);
        if (!$wsId) return response()->json(['success' => false, 'error' => 'Workspace context missing'], 400);

        $platform = (int) $request->input('platform', 0) === 1;
        $type     = strtolower((string) $request->input('type', 'image'));
        $search   = trim((string) $request->input('search', ''));
        $page     = max(1, (int) $request->input('page', 1));
        $perPage  = max(1, min(500, (int) $request->input('per_page', 24)));

        $access = new MediaAccessService();
        if ($platform) {
            $snap = $access->snapshot($wsId);
            if (!$snap['can_access']) {
                return response()->json([
                    'success'          => true,
                    'locked'           => true,
                    'upgrade_required' => true,
                    'access'           => $snap,
                    'files'            => [],
                    'total'            => 0,
                    'page'             => 1,
                    'per_page'         => $perPage,
                ]);
            }
        }

        $q = DB::table('media');
        if ($platform) {
            $q->where('is_platform_asset', 1);
        } else {
            // SHARED MEDIA LIBRARY (per owner directive 2026-05-15): default
            // mode shows AI-generated + platform assets across all workspaces.
            // Workspace isolation is intentionally relaxed here — these source
            // types are treated as shared resources (templates, AI gens, asset
            // library). User uploads (source upload/uploaded) are EXCLUDED.
            // Owner's intent: "Display Dalle, platform, dall-e-3, and dall-e,
            // creative, and seo featured image in the shared media library on
            // laravel. Skip Upload and uploaded."
            $q->whereIn('source', [
                'dalle',
                'dall-e',
                'dall-e-3',
                'creative_engine',
                'seo_featured_image',
                'platform',
            ]);
        }

        if ($type === 'image') {
            $q->where(function ($i) {
                $i->where('asset_type', 'image')->orWhere('mime_type', 'like', 'image/%');
            });
        } elseif ($type === 'video') {
            $q->where(function ($i) {
                $i->where('asset_type', 'video')->orWhere('mime_type', 'like', 'video/%');
            });
        } elseif ($type === 'document') {
            $q->where('asset_type', 'document');
        }

        if ($search !== '') {
            $q->where(function ($inner) use ($search) {
                $inner->where('filename', 'like', "%{$search}%")
                    ->orWhereJsonContains('tags', $search);
            });
        }

        $total = (clone $q)->count();
        $files = $q->orderByDesc('created_at')
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->select(
                'id', 'workspace_id', 'filename', 'url', 'file_url', 'thumbnail_url',
                'mime_type', 'asset_type', 'size_bytes', 'width', 'height',
                'source', 'is_platform_asset', 'is_public',
                'category', 'tags', 'prompt', 'model', 'created_at'
            )
            ->get();

        return response()->json([
            'success'  => true,
            'locked'   => false,
            'files'    => $files,
            'total'    => $total,
            'page'     => $page,
            'per_page' => $perPage,
            'access'   => $access->snapshot($wsId),
        ]);
    }

    /**
     * GET /api/media/access — current workspace's plan gate state.
     * Drives the picker's usage bar, upgrade CTA, and locked overlay.
     */
    public function access(Request $request): JsonResponse
    {
        $wsId = (int) ($request->attributes->get('workspace_id') ?? 0);
        if (!$wsId) return response()->json(['success' => false, 'error' => 'Workspace context missing'], 400);

        $access = new MediaAccessService();
        $snap = $access->snapshot($wsId);

        $upgradeMap = [
            'free'    => 'AI Lite',
            'starter' => 'AI Lite',
            'ai-lite' => 'Growth',
            'growth'  => 'Pro',
        ];
        $snap['upgrade_to'] = $upgradeMap[$snap['plan_slug'] ?? ''] ?? null;
        $snap['plan_name']  = $this->planName($snap['plan_slug'] ?? null);

        return response()->json(['success' => true] + $snap);
    }

    /**
     * POST /api/media/use — body: {media_id, context}
     * Appends context to used_in JSON if not already present, bumps use_count.
     */
    public function use_(Request $request): JsonResponse
    {
        $mediaId = (int) $request->input('media_id', 0);
        $context = trim((string) $request->input('context', ''));
        if (!$mediaId) return response()->json(['success' => false, 'error' => 'media_id required'], 400);

        // T3.1 — `use_count` column does not exist in schema; tracking via
        // used_in JSON only. If a use_count column is later added,
        // re-introduce the increment here.
        $wsId = (int) ($request->attributes->get('workspace_id') ?? 0);
        if (!$wsId) return response()->json(['success' => false, 'error' => 'Workspace context missing'], 400);

        // RISK-0121 — tenancy: a workspace may only track its OWN media or SHARED assets (the same
        // AI-generated/platform source-types the shared library exposes, per the 2026-05-15 owner
        // directive). This blocks cross-workspace read/write of another workspace's PRIVATE uploads
        // via media_id enumeration.
        $entitled = function ($q) use ($wsId) {
            $q->where('workspace_id', $wsId)
              ->orWhere('is_platform_asset', 1)
              ->orWhereIn('source', ['dalle', 'dall-e', 'dall-e-3', 'creative_engine', 'seo_featured_image', 'platform']);
        };

        $row = DB::table('media')->where('id', $mediaId)->where($entitled)->first(['id', 'used_in']);
        if (!$row) return response()->json(['success' => false, 'error' => 'Not found'], 404);

        $used = [];
        if (!empty($row->used_in)) {
            $decoded = json_decode($row->used_in, true);
            if (is_array($decoded)) $used = $decoded;
        }
        if ($context !== '' && !in_array($context, $used, true)) {
            $used[] = $context;
        }

        DB::table('media')->where('id', $mediaId)->where($entitled)->update([
            'used_in'    => !empty($used) ? json_encode($used) : null,
            'updated_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'used_in' => $used,
        ]);
    }

    /**
     * DELETE /api/media/{id} — workspace-scoped delete.
     * Only allows the caller's workspace to delete its own media.
     * Removes the original file + the thumbnail (if generated) from disk,
     * then deletes the DB row.
     */
    public function delete(Request $request, int $id): JsonResponse
    {
        $wsId = (int) ($request->attributes->get('workspace_id') ?? 0);
        if ($wsId <= 0) {
            return response()->json(['success' => false, 'error' => 'Workspace context missing'], 400);
        }

        $media = DB::table('media')
            ->where('id', $id)
            ->where('workspace_id', $wsId)
            ->first();

        if (! $media) {
            return response()->json(['success' => false, 'error' => 'Not found or unauthorized'], 404);
        }

        $publicRoot = storage_path('app/public');
        $filesRemoved = 0;

        // Original file
        if (! empty($media->path)) {
            $abs = $publicRoot . '/' . ltrim((string) $media->path, '/');
            if (is_file($abs) && @unlink($abs)) $filesRemoved++;
        }

        // Thumbnail file (derive from URL)
        if (! empty($media->thumbnail_url)) {
            $thumbRel = ltrim(str_replace('/storage/', '', (string) $media->thumbnail_url), '/');
            if ($thumbRel) {
                $thumbAbs = $publicRoot . '/' . $thumbRel;
                if (is_file($thumbAbs) && @unlink($thumbAbs)) $filesRemoved++;
            }
        }

        DB::table('media')->where('id', $id)->delete();

        return response()->json([
            'success'       => true,
            'deleted_id'    => $id,
            'files_removed' => $filesRemoved,
        ]);
    }

    private function planName(?string $slug): ?string
    {
        $map = [
            'free' => 'Free', 'starter' => 'Starter', 'ai-lite' => 'AI Lite',
            'growth' => 'Growth', 'pro' => 'Pro', 'agency' => 'Agency',
        ];
        return $slug ? ($map[$slug] ?? ucfirst($slug)) : null;
    }
}
