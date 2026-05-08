<?php

namespace App\Http\Controllers;

use App\Services\ThumbnailService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * On-the-fly thumbnail server.
 *
 * Self-healing cache pattern:
 * - 1st request: nginx try_files misses → falls through to PHP → this
 *   controller generates the thumbnail to disk → serves it.
 * - 2nd+ request: nginx serves the on-disk file directly, never hits PHP.
 *
 * After a successful first generation, also persists thumbnail_url on the
 * matching media row so listing endpoints have it without a re-call.
 */
class ThumbnailController
{
    public function serve(Request $request, string $path)
    {
        // Path traversal guard. Allow only alphanum, slash, dash, dot, underscore.
        if (str_contains($path, '..') || ! preg_match('#^[a-z0-9/_\-.]+$#i', $path)) {
            abort(400, 'Invalid path');
        }

        $publicRoot = storage_path('app/public');
        $thumbAbs   = $publicRoot . '/thumbnails/' . $path;
        $srcAbs     = $publicRoot . '/' . $path;

        if (! is_file($thumbAbs)) {
            if (! is_file($srcAbs)) {
                abort(404, 'Source image not found');
            }
            if (! ThumbnailService::generate($srcAbs, $thumbAbs)) {
                abort(500, 'Thumbnail generation failed');
            }

            // Persist thumbnail_url so list endpoints reflect it without
            // waiting for the next backfill.
            $publicUrl = '/storage/thumbnails/' . $path;
            $sourceUrl = '/storage/' . $path;
            DB::table('media')
                ->where(function ($q) use ($sourceUrl, $path) {
                    $q->where('file_url', $sourceUrl)
                      ->orWhere('url', $sourceUrl)
                      ->orWhere('path', $path)
                      ->orWhere('path', '/' . $path);
                })
                ->update([
                    'thumbnail_url' => $publicUrl,
                    'updated_at'    => now(),
                ]);
        }

        return response()->file($thumbAbs, [
            'Content-Type'  => 'image/jpeg',
            'Cache-Control' => 'public, max-age=2592000, immutable',
            'X-Generated'   => 'lu-thumbs',
        ]);
    }
}
