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
        $q = DB::table('media')
            ->where('category', $category)
            ->where('industry', $industry)
            ->whereNotNull('url')
            ->where('url', '!=', '');

        if ($mood) $q->where('mood', $mood);

        $existing = $q->orderByDesc('created_at')->first();

        if ($existing) {
            // Increment use count
            DB::table('media')->where('id', $existing->id)->increment('use_count');
            return [
                'id' => $existing->id,
                'url' => $existing->url,
                'path' => $existing->path,
                'reused' => true,
            ];
        }

        return null; // caller should generate
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

        // Auto-tags from industry + category + mood
        if (empty($tags)) {
            $tags = array_filter([$industry, $category, $mood, $source]);
        }

        return DB::table('media')->insertGetId([
            'workspace_id' => $workspaceId,
            'filename' => basename($path ?: 'generated.png'),
            'path' => $path,
            'url' => $url,
            'mime_type' => $mime,
            'size_bytes' => $size,
            'width' => $width,
            'height' => $height,
            'source' => $source,
            'category' => $category,
            'prompt' => $prompt ? mb_substr($prompt, 0, 2048) : null,
            'model' => $model,
            'description' => $description ? mb_substr($description, 0, 500) : null,
            'tags' => json_encode($tags),
            'industry' => $industry,
            'mood' => $mood ?? 'luxury',
            'orientation' => $orientation,
            'is_platform_asset' => $workspaceId === null,
            'use_count' => 1,
            'alt_text' => $altText ? mb_substr($altText, 0, 500) : null,
            'metadata_json' => null,
            'created_at' => now(),
            'updated_at' => now(),
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
