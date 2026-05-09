<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MediaService
{
    /**
     * Register a generated/uploaded image in the global media library.
     */
    public static function register(
        string $path,
        string $url,
        string $source = 'upload',
        ?string $category = null,
        ?int $workspaceId = null,
        ?string $prompt = null,
        ?string $model = null,
        array $metadata = []
    ): int {
        $fullPath = str_starts_with($path, '/')
            ? public_path($path)
            : storage_path('app/public/' . ltrim($path, '/'));

        $size = file_exists($fullPath) ? filesize($fullPath) : 0;
        $mime = file_exists($fullPath) ? (mime_content_type($fullPath) ?: 'image/png') : 'image/png';
        $filename = basename($path);

        // Try to get dimensions
        $width = null;
        $height = null;
        if (file_exists($fullPath) && str_starts_with($mime, 'image/')) {
            $info = @getimagesize($fullPath);
            if ($info) {
                $width = $info[0];
                $height = $info[1];
            }
        }

        return DB::table('media')->insertGetId([
            'workspace_id' => $workspaceId,
            'filename' => $filename,
            'path' => $path,
            'url' => $url,
            'mime_type' => $mime,
            'size_bytes' => $size,
            'width' => $width,
            'height' => $height,
            'source' => $source,
            'category' => $category,
            'prompt' => $prompt,
            'model' => $model,
            'metadata_json' => !empty($metadata) ? json_encode($metadata) : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * List all media with filters.
     */
    public static function list(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        $q = DB::table('media')->orderByDesc('created_at');

        if (!empty($filters['source'])) $q->where('source', $filters['source']);
        if (!empty($filters['category'])) $q->where('category', $filters['category']);
        if (!empty($filters['workspace_id'])) $q->where('workspace_id', $filters['workspace_id']);
        if (!empty($filters['search'])) $q->where('filename', 'like', '%' . $filters['search'] . '%');

        $total = (clone $q)->count();
        $items = $q->offset($offset)->limit($limit)->get()->toArray();

        return ['items' => $items, 'total' => $total];
    }

    /**
     * Get stats for admin dashboard.
     */
    public static function stats(): array
    {
        return [
            'total' => DB::table('media')->count(),
            'total_size' => DB::table('media')->sum('size_bytes'),
            'by_source' => DB::table('media')
                ->selectRaw('source, COUNT(*) as count, SUM(size_bytes) as total_bytes')
                ->groupBy('source')
                ->get()->toArray(),
            'by_category' => DB::table('media')
                ->selectRaw('category, COUNT(*) as count')
                ->groupBy('category')
                ->get()->toArray(),
        ];
    }

    /**
     * Delete a media item.
     */
    public static function delete(int $id): bool
    {
        $media = DB::table('media')->where('id', $id)->first();
        if (!$media) return false;

        // Delete file from disk
        $fullPath = str_starts_with($media->path, '/')
            ? public_path($media->path)
            : storage_path('app/public/' . ltrim($media->path, '/'));

        if (file_exists($fullPath)) {
            @unlink($fullPath);
        }

        DB::table('media')->where('id', $id)->delete();
        return true;
    }

    /**
     * Find an existing image matching criteria, or generate a new one.
     */
    public static function findOrGenerate(
        string $industry,
        string $category,
        ?string $mood = null,
        ?int $workspaceId = null
    ): ?array {
        // PATCH (Media Fix 2026-05-09) — `media` table has no `industry` or
        // `mood` columns. Industry tagging lives in the `tags` JSON array.
        // Mood is dropped entirely — too narrow a filter and not stored
        // anywhere reliably. Caller falls back to DALL-E generation if
        // no platform-asset hero matches the industry tag.
        $q = DB::table('media')
            ->where('category', $category)
            ->whereNotNull('url')
            ->where('url', '!=', '');

        // Prefer platform-asset heroes (workspace_id IS NULL or
        // is_platform_asset=1). Per-workspace assets are also
        // acceptable when scoped — but the canonical hero pool is
        // platform-wide template heroes seeded in Patches 9A-D.
        if ($workspaceId) {
            $q->where(function ($sub) use ($workspaceId) {
                $sub->where('workspace_id', $workspaceId)
                    ->orWhere('is_platform_asset', 1);
            });
        } else {
            $q->where('is_platform_asset', 1);
        }

        // Filter by industry via tags JSON array. Hero rows seeded by
        // Patches 9A-D have tags like ["restaurant","hero","template"].
        if ($industry !== '') {
            $q->whereRaw('JSON_CONTAINS(tags, ?)', [json_encode($industry)]);
        }

        $existing = $q->orderByDesc('created_at')->first();
        if (! $existing) return null;   // caller generates

        return [
            'id'     => $existing->id,
            'url'    => $existing->url,
            'path'   => $existing->path,
            'reused' => true,
        ];
    }

    /**
     * Register with full metadata including auto-tags.
     */
    public static function registerFull(
        string $path,
        string $url,
        string $source,
        string $category,
        string $industry,
        ?int $workspaceId = null,
        ?string $prompt = null,
        ?string $model = null,
        ?string $mood = null,
        ?string $description = null,
        array $tags = []
    ): int {
        $fullPath = str_starts_with($path, '/')
            ? public_path($path)
            : storage_path('app/public/' . ltrim($path, '/'));

        $size = file_exists($fullPath) ? filesize($fullPath) : 0;
        $mime = file_exists($fullPath) ? (mime_content_type($fullPath) ?: 'image/png') : 'image/png';
        $width = null;
        $height = null;
        $orientation = 'landscape';

        if (file_exists($fullPath) && str_starts_with($mime, 'image/')) {
            $info = @getimagesize($fullPath);
            if ($info) {
                $width = $info[0];
                $height = $info[1];
                if ($height > $width) $orientation = 'portrait';
                elseif ($width === $height) $orientation = 'square';
            }
        }

        // Auto-generate alt_text from prompt
        $altText = $description ?: self::generateAltText($prompt, $industry, $category);

        // PATCH (Media Fix 2026-05-09) — fold industry + mood into the tags
        // JSON array (the only first-class taxonomy column on `media`).
        // Auto-tag rule: [industry, category, mood, source] minus empties,
        // then merge any caller-supplied tags. Order matters: industry first
        // so JSON_CONTAINS(tags, '"<industry>"') in findOrGenerate matches.
        $autoTags = array_values(array_filter([$industry, $category, $mood, $source]));
        $tags     = empty($tags) ? $autoTags : array_values(array_unique(array_merge($autoTags, $tags)));

        // PATCH (Media Fix 2026-05-09) — phantom columns removed.
        // The `media` table has no `industry`, `mood`, `orientation`,
        // `use_count`, `alt_text`, or `description` columns. Stash those
        // facts inside metadata_json instead so the data isn't lost.
        $metadata = [
            'industry'    => $industry,
            'mood'        => $mood ?? 'luxury',
            'orientation' => $orientation,
            'alt_text'    => $altText ? mb_substr($altText, 0, 500) : null,
            'description' => $description ? mb_substr($description, 0, 500) : null,
        ];

        return DB::table('media')->insertGetId([
            'workspace_id'      => $workspaceId,
            'filename'          => basename($path ?: 'generated.png'),
            'path'              => $path,
            'url'               => $url,
            'mime_type'         => $mime,
            'size_bytes'        => $size,
            'width'             => $width,
            'height'             => $height,
            'source'            => $source,
            'category'          => $category,
            'prompt'            => $prompt ? mb_substr($prompt, 0, 2048) : null,
            'model'             => $model,
            'tags'              => json_encode($tags),
            'is_platform_asset' => $workspaceId === null ? 1 : 0,
            'metadata_json'     => json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);
    }

    private static function generateAltText(?string $prompt, string $industry, string $category): string
    {
        if (!$prompt) return ucfirst($category) . ' image for ' . $industry . ' business';
        // Extract key descriptors from prompt (first 100 chars, clean up)
        $clean = preg_replace('/no text.*$/i', '', $prompt);
        $clean = preg_replace('/photorealistic.*$/i', '', $clean);
        $clean = trim($clean, ', .');
        return mb_substr($clean, 0, 200);
    }
}
