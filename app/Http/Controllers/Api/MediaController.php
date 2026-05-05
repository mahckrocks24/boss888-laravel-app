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
     * storage/app/public/uploads/ and returns url/size/mime. Now ALSO
     * registers a row in the `media` table so the picker's "My Uploads"
     * tab sees it immediately.
     */
    public function upload(Request $request): JsonResponse
    {
        $request->validate([
            'file' => 'required|file|max:102400', // 100 MB
        ]);

        $file = $request->file('file');
        $name = Str::random(16) . '.' . $file->getClientOriginalExtension();
        $path = $file->storeAs('uploads', $name, 'public');

        $wsId = (int) ($request->attributes->get('workspace_id') ?? 0) ?: null;

        try {
            $abs = storage_path('app/public/' . $path);
            $mime = is_file($abs) ? (mime_content_type($abs) ?: $file->getClientMimeType()) : $file->getClientMimeType();
            $size = is_file($abs) ? filesize($abs) : $file->getSize();
            $assetType = 'document';
            if (str_starts_with($mime, 'image/'))      $assetType = 'image';
            elseif (str_starts_with($mime, 'video/'))  $assetType = 'video';
            elseif (str_starts_with($mime, 'audio/'))  $assetType = 'audio';

            $width = null; $height = null;
            if ($assetType === 'image' && is_file($abs)) {
                $info = @getimagesize($abs);
                if ($info) { $width = $info[0]; $height = $info[1]; }
            }

            DB::table('media')->insert([
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
                'source'            => 'upload',
                'is_platform_asset' => 0,
                'is_public'         => 0,
                'use_count'         => 0,
                'created_at'        => now(),
                'updated_at'        => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('[MediaController] upload register failed: ' . $e->getMessage());
        }

        return response()->json([
            'success'   => true,
            'url'       => Storage::url($path),
            'name'      => $file->getClientOriginalName(),
            'size'      => $file->getSize(),
            'mime_type' => $file->getMimeType(),
        ], 201);
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
        $perPage  = max(1, min(60, (int) $request->input('per_page', 24)));

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
            $q->where('workspace_id', $wsId)->where(function ($i) {
                $i->whereNull('is_platform_asset')->orWhere('is_platform_asset', 0);
            });
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
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhereJsonContains('tags', $search);
            });
        }

        $total = (clone $q)->count();
        $files = $q->orderByDesc('created_at')
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->select(
                'id', 'filename', 'url', 'file_url', 'thumbnail_url',
                'mime_type', 'asset_type', 'size_bytes', 'width', 'height',
                'duration_seconds', 'category', 'industry', 'description',
                'tags', 'use_count', 'is_platform_asset', 'created_at'
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

        $row = DB::table('media')->where('id', $mediaId)->first(['id', 'used_in', 'use_count']);
        if (!$row) return response()->json(['success' => false, 'error' => 'Not found'], 404);

        $used = [];
        if (!empty($row->used_in)) {
            $decoded = json_decode($row->used_in, true);
            if (is_array($decoded)) $used = $decoded;
        }
        if ($context !== '' && !in_array($context, $used, true)) {
            $used[] = $context;
        }

        DB::table('media')->where('id', $mediaId)->update([
            'used_in'    => !empty($used) ? json_encode($used) : null,
            'use_count'  => ($row->use_count ?? 0) + 1,
            'updated_at' => now(),
        ]);

        return response()->json([
            'success'   => true,
            'used_in'   => $used,
            'use_count' => ($row->use_count ?? 0) + 1,
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
