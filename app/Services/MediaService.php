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

        // PATCH (cross-industry-tags, 2026-05-09) — TIERED lookup.
        // Tier 1 — exact industry slug match (e.g. 'dental')
        // Tier 2 — semantic cross-industry tags (e.g. medical, luxury_interior)
        // Tier 3 — universal 'general' floor
        // We try each tier in order and return the first non-empty result.
        // This stops a restaurant build from grabbing a dental photo just
        // because both are tagged 'general' — exact-match always wins.
        if ($industry === '') {
            $existing = $q->orderByDesc('created_at')->first();
            if (! $existing) return null;
            return ['id' => $existing->id, 'url' => $existing->url, 'path' => $existing->path, 'reused' => true];
        }

        $tagSets = [
            [$industry],                                                                          // Tier 1
            array_diff(self::getCompatibleTags($industry), [$industry, 'general']),               // Tier 2
            ['general'],                                                                          // Tier 3
        ];

        foreach ($tagSets as $set) {
            if (empty($set)) continue;
            $tier = clone $q;
            $tier->where(function ($sub) use ($set) {
                foreach ($set as $tag) {
                    $sub->orWhereRaw('JSON_CONTAINS(tags, ?)', [json_encode($tag)]);
                }
            });
            $hit = $tier->orderByDesc('created_at')->first();
            if ($hit) {
                return ['id' => $hit->id, 'url' => $hit->url, 'path' => $hit->path, 'reused' => true];
            }
        }

        return null; // caller generates

        return [
            'id'     => $existing->id,
            'url'    => $existing->url,
            'path'   => $existing->path,
            'reused' => true,
        ];
    }

    /**
     * PATCH (cross-industry-tags, 2026-05-09)
     *
     * Returns the list of tags an image should match against to be considered
     * suitable for the given industry. The first element is always the
     * industry's exact slug (highest specificity); subsequent elements are
     * cross-industry semantic tags (skyline, office, luxury_interior, etc.)
     * that the image might also legitimately serve. The 'general' floor tag
     * is always included so retagged platform assets without a specific
     * sector match still surface for any industry.
     */
    private static function getCompatibleTags(string $industry): array
    {
        $crossTags = [
            // Professional / B2B / corporate
            'consulting'         => ['skyline', 'office', 'dubai', 'team'],
            'marketing_agency'   => ['office', 'technology', 'team', 'dubai'],
            'it_services'        => ['skyline', 'office', 'technology', 'dubai'],
            'real_estate_agency' => ['skyline', 'building', 'dubai', 'luxury_interior'],
            'architecture'       => ['skyline', 'building', 'office'],
            'construction'       => ['skyline', 'building', 'dubai'],
            'home_services'      => ['building'],
            'interior_design'    => ['luxury_interior', 'building', 'office'],
            // Hospitality
            'hotel'              => ['luxury_interior', 'dubai', 'food', 'building'],
            'resort'             => ['nature', 'luxury_interior', 'food'],
            'short_term_rental'  => ['dubai', 'luxury_interior'],
            'travel_agency'      => ['nature', 'dubai'],
            'event_venue'        => ['luxury_interior', 'food'],
            // Food
            'restaurant'         => ['food', 'luxury_interior'],
            'cafe'               => ['food'],
            'catering'           => ['food'],
            // Health / medical
            'dental'             => ['medical'],
            'medical_clinic'     => ['medical'],
            'aesthetic_clinic'   => ['medical', 'luxury_interior'],
            // Wellness / fitness / beauty
            'gym'                => ['fitness'],
            'beauty_salon'       => ['luxury_interior', 'retail'],
            'barbershop'         => ['retail'],
            // Pet / kids
            'pet_services'       => ['nature'],
            'childcare'          => ['education', 'fitness'],
            // Retail / commerce
            'retail_shop'        => ['retail', 'dubai'],
            'ecommerce'          => ['retail', 'technology'],
            'automotive'         => ['retail'],
            // Education
            'tutoring'           => ['education', 'team'],
            'training_center'    => ['education', 'office', 'team'],
            'online_courses'     => ['education', 'technology'],
            // News / media
            'news_channel'       => ['office', 'technology', 'dubai'],
        ];

        // Always include: the exact industry slug + cross-industry tags +
        // 'general' (universal floor for retagged platform assets).
        return array_values(array_unique(array_merge(
            [$industry],
            $crossTags[$industry] ?? [],
            ['general']
        )));
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

        // PATCH (cross-industry-tags, 2026-05-09) — Auto-derive semantic
        // cross-industry tags from the prompt (skyline / office / medical /
        // food / luxury_interior / fitness / technology / nature etc.) so
        // the same image can serve multiple industries via findOrGenerate.
        // Always append 'general' as the universal floor tag.
        $semanticTags = self::deriveSemanticTags((string) $prompt);
        $semanticTags[] = 'general';

        $tags = array_values(array_unique(array_merge(
            $autoTags,
            $semanticTags,
            is_array($tags) ? $tags : []
        )));

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

    /**
     * PATCH (cross-industry-tags, 2026-05-09)
     *
     * Derive semantic cross-industry tags from a generation prompt so the
     * resulting image can be reused across more than just its origin
     * industry. The keyword map is intentionally narrow — we only add a
     * tag when the prompt contains the matching keyword. Order doesn't
     * matter (the tags array is deduped + reordered downstream).
     */
    private static function deriveSemanticTags(string $prompt): array
    {
        $p = strtolower($prompt);
        if ($p === '') return [];
        $rules = [
            'skyline'        => ['/skyline|cityscape|panorama|aerial.*city|downtown/'],
            'dubai'          => ['/dubai|burj|marina|jumeirah|mena|gulf|emirates|abu dhabi|sharjah/'],
            'office'         => ['/office|workspace|boardroom|coworking|cubicle|conference room|meeting room/'],
            'building'       => ['/building|tower|facade|exterior|architectural|construction site/'],
            'luxury_interior'=> ['/luxury|elegant|premium|marble|chandelier|five.?star|opulent|sophisticated interior/'],
            'medical'        => ['/medical|clinic|dental|doctor|hospital|surgical|treatment room|health/'],
            'food'           => ['/restaurant|food|kitchen|dining|chef|cuisine|coffee|cafe|bakery|catering|dish|plating/'],
            'nature'         => ['/nature|beach|ocean|mountain|forest|tropical|landscape|garden|outdoor|sunset|sunrise/'],
            'technology'     => ['/technology|tech|coding|server|monitor|software|digital|laptop|cloud|cyber|ai\\b|data/'],
            'team'           => ['/team|collaboration|colleagues|group of people|workshop|conference|standup/'],
            'retail'         => ['/retail|shop|store|boutique|product display|shelving|merchandise/'],
            'education'      => ['/education|tutoring|classroom|study|student|learning|library|whiteboard/'],
            'fitness'        => ['/gym|fitness|workout|training|crossfit|pilates|yoga|athletic|exercise/'],
        ];
        $tags = [];
        foreach ($rules as $tag => $patterns) {
            foreach ($patterns as $rx) {
                if (preg_match($rx, $p)) { $tags[] = $tag; break; }
            }
        }
        return $tags;
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
