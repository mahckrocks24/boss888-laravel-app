<?php

namespace App\Engines\Studio\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * StudioService — foundation for the revamped Canva/CapCut-grade Studio.
 *
 * Phase 1 scope:
 *   • Design CRUD (create / get / update / duplicate / delete)
 *   • Element CRUD + reorder
 *   • Brand kit get/update (per workspace, singleton)
 *   • Design history snapshots (save + list)
 *   • Thumbnail generation (puppeteer via existing studio-render.cjs)
 *   • Fonts catalogue (static list of loadable Google Fonts)
 *
 * NOT in Phase 1 (deferred):
 *   • AI endpoints (aiGenerateDesign, aiGenerateImage, aiSuggestCopy,
 *     aiRemoveBackground) — land in Phase 4
 *   • publishToSocial — lands in Phase 5
 *   • Export pipeline glue — lands in Phase 5
 */
class StudioService
{
    /** Available fonts list. Editor preloads these via Google Fonts API. */
    public const FONTS = [
        'Syne','DM Sans','Inter','Montserrat','Playfair Display','Oswald','Raleway',
        'Bebas Neue','Lato','Roboto','Open Sans','Cormorant Garamond','Unbounded',
        'Figtree','DM Mono','Space Grotesk','Plus Jakarta Sans','Nunito','Poppins','Work Sans',
    ];

    public const FORMATS = [
        'square'           => ['w' => 1080, 'h' => 1080, 'label' => 'Square (Instagram post)'],
        'portrait'         => ['w' => 1080, 'h' => 1350, 'label' => 'Portrait (Instagram portrait)'],
        'story'            => ['w' => 1080, 'h' => 1920, 'label' => 'Story / Reel'],
        'landscape'        => ['w' => 1920, 'h' => 1080, 'label' => 'Landscape (YouTube / LinkedIn)'],
        'facebook_cover'   => ['w' =>  820, 'h' =>  312, 'label' => 'Facebook cover'],
        'twitter_header'   => ['w' => 1500, 'h' =>  500, 'label' => 'Twitter / X header'],
        'pinterest'        => ['w' => 1000, 'h' => 1500, 'label' => 'Pinterest'],
    ];

    // ═══════════════════════════════════════════════════════════════════
    // DESIGN CRUD
    // ═══════════════════════════════════════════════════════════════════

    public function listDesigns(int $wsId, array $filters = []): array
    {
        $q = DB::table('studio_designs')
            ->where('workspace_id', $wsId)
            ->whereNull('deleted_at');

        if (!empty($filters['design_type'])) $q->where('design_type', $filters['design_type']);
        if (!empty($filters['format']))      $q->where('format', $filters['format']);
        if (!empty($filters['search']))      $q->where('name', 'like', '%' . $filters['search'] . '%');

        $rows = $q->orderByDesc('updated_at')
            ->limit((int) ($filters['limit'] ?? 100))
            ->get([
                'id','name','format','design_type','canvas_width','canvas_height',
                'thumbnail_url','exported_url','status','is_template','template_category',
                'background_type','background_value','updated_at','created_at',
            ]);

        return ['designs' => $rows, 'total' => $rows->count()];
    }

    public function createDesign(int $wsId, array $data): array
    {
        $format = (string) ($data['format'] ?? 'square');
        $dims   = self::FORMATS[$format] ?? self::FORMATS['square'];
        $w      = (int) ($data['canvas_width']  ?? $dims['w']);
        $h      = (int) ($data['canvas_height'] ?? $dims['h']);

        $id = DB::table('studio_designs')->insertGetId([
            'workspace_id'     => $wsId,
            'name'             => (string) ($data['name'] ?? 'Untitled Design'),
            'format'           => $format,
            'design_type'      => (string) ($data['design_type'] ?? 'image'),
            'canvas_width'     => $w,
            'canvas_height'    => $h,
            'layers_json'      => json_encode([]),
            'background_type'  => (string) ($data['background_type']  ?? 'color'),
            'background_value' => (string) ($data['background_value'] ?? '#FFFFFF'),
            'status'           => 'draft',
            'duration_seconds' => (int) ($data['duration_seconds'] ?? 0),
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);

        return ['design_id' => $id] + $this->getDesign($id);
    }

    public function getDesign(int $id): array
    {
        $design = DB::table('studio_designs')->where('id', $id)->whereNull('deleted_at')->first();
        if (!$design) return ['error' => 'not_found'];

        $elements = DB::table('studio_elements')
            ->where('design_id', $id)
            ->orderBy('layer_order')
            ->get()
            ->map(fn($e) => [
                'id'              => $e->id,
                'element_type'    => $e->element_type,
                'properties_json' => json_decode($e->properties_json ?: '{}', true) ?: [],
                'layer_order'     => (int) $e->layer_order,
                'updated_at'      => $e->updated_at,
            ])
            ->toArray();

        return [
            'design'   => (array) $design,
            'elements' => $elements,
        ];
    }

    public function updateDesign(int $id, array $data): array
    {
        $allowed = [
            'name','format','canvas_width','canvas_height','layers_json',
            'background_type','background_value','status','is_template',
            'template_category','tags_json','thumbnail_url','published_to_social',
        ];
        $up = [];
        foreach ($allowed as $k) {
            if (array_key_exists($k, $data)) $up[$k] = $data[$k];
        }
        if (isset($up['tags_json']) && is_array($up['tags_json'])) {
            $up['tags_json'] = json_encode($up['tags_json']);
        }
        if (isset($up['layers_json']) && is_array($up['layers_json'])) {
            $up['layers_json'] = json_encode($up['layers_json']);
        }
        $up['updated_at'] = now();
        DB::table('studio_designs')->where('id', $id)->update($up);
        return ['updated' => true];
    }

    public function duplicateDesign(int $id, int $wsId): array
    {
        $src = DB::table('studio_designs')->where('id', $id)->whereNull('deleted_at')->first();
        if (!$src) return ['error' => 'not_found'];

        $newId = DB::table('studio_designs')->insertGetId([
            'workspace_id'     => $wsId,
            'template_id'      => $src->template_id,
            'name'             => $src->name . ' (copy)',
            'format'           => $src->format,
            'design_type'      => $src->design_type,
            'canvas_width'     => $src->canvas_width,
            'canvas_height'    => $src->canvas_height,
            'layers_json'      => $src->layers_json,
            'content_html'     => $src->content_html,
            'video_data'       => $src->video_data,
            'background_type'  => $src->background_type  ?? 'color',
            'background_value' => $src->background_value ?? '#FFFFFF',
            'status'           => 'draft',
            'duration_seconds' => $src->duration_seconds,
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);

        // Clone elements
        $elements = DB::table('studio_elements')->where('design_id', $id)->get();
        foreach ($elements as $e) {
            DB::table('studio_elements')->insert([
                'workspace_id'    => $wsId,
                'design_id'       => $newId,
                'element_type'    => $e->element_type,
                'properties_json' => $e->properties_json,
                'layer_order'     => $e->layer_order,
                'created_at'      => now(),
                'updated_at'      => now(),
            ]);
        }
        return ['design_id' => $newId];
    }

    public function deleteDesign(int $id): bool
    {
        DB::table('studio_designs')->where('id', $id)->update([
            'deleted_at' => now(), 'updated_at' => now(),
        ]);
        return true;
    }

    // ═══════════════════════════════════════════════════════════════════
    // ELEMENT CRUD
    // ═══════════════════════════════════════════════════════════════════

    public function getElements(int $designId): array
    {
        return DB::table('studio_elements')
            ->where('design_id', $designId)
            ->orderBy('layer_order')
            ->get()
            ->map(fn($e) => [
                'id'              => $e->id,
                'element_type'    => $e->element_type,
                'properties_json' => json_decode($e->properties_json ?: '{}', true) ?: [],
                'layer_order'     => (int) $e->layer_order,
            ])->toArray();
    }

    public function saveElement(int $wsId, int $designId, array $data): array
    {
        $type = (string) ($data['element_type'] ?? 'text');
        $order = isset($data['layer_order'])
            ? (int) $data['layer_order']
            : ((int) DB::table('studio_elements')->where('design_id', $designId)->max('layer_order') + 1);

        $id = DB::table('studio_elements')->insertGetId([
            'workspace_id'    => $wsId,
            'design_id'       => $designId,
            'element_type'    => $type,
            'properties_json' => json_encode($data['properties_json'] ?? []),
            'layer_order'     => $order,
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);
        return ['element_id' => $id, 'layer_order' => $order];
    }

    public function updateElement(int $elementId, array $data): array
    {
        $up = [];
        if (array_key_exists('properties_json', $data)) {
            $up['properties_json'] = json_encode($data['properties_json']);
        }
        if (array_key_exists('layer_order', $data)) {
            $up['layer_order'] = (int) $data['layer_order'];
        }
        if (array_key_exists('element_type', $data)) {
            $up['element_type'] = (string) $data['element_type'];
        }
        $up['updated_at'] = now();
        DB::table('studio_elements')->where('id', $elementId)->update($up);
        return ['updated' => true];
    }

    public function deleteElement(int $elementId): bool
    {
        DB::table('studio_elements')->where('id', $elementId)->delete();
        return true;
    }

    public function reorderElements(int $designId, array $elementIds): bool
    {
        foreach (array_values($elementIds) as $i => $eid) {
            DB::table('studio_elements')
                ->where('design_id', $designId)
                ->where('id', $eid)
                ->update(['layer_order' => $i + 1, 'updated_at' => now()]);
        }
        return true;
    }

    // ═══════════════════════════════════════════════════════════════════
    // BRAND KIT (one per workspace)
    // ═══════════════════════════════════════════════════════════════════

    public function getBrandKit(int $wsId): array
    {
        $row = DB::table('studio_brand_kits')->where('workspace_id', $wsId)->first();
        if (!$row) {
            // Lazy-create defaults so the UI always has a row to render
            DB::table('studio_brand_kits')->insert([
                'workspace_id' => $wsId,
                'created_at'   => now(),
                'updated_at'   => now(),
            ]);
            $row = DB::table('studio_brand_kits')->where('workspace_id', $wsId)->first();
        }
        return (array) $row;
    }

    public function updateBrandKit(int $wsId, array $data): array
    {
        // Ensure row exists
        $this->getBrandKit($wsId);

        $allowed = [
            'primary_color','secondary_color','accent_color','background_color','text_color',
            'heading_font','body_font','logo_url','logo_dark_url','brand_name','tagline',
        ];
        $up = array_intersect_key($data, array_flip($allowed));
        $up['updated_at'] = now();
        DB::table('studio_brand_kits')->where('workspace_id', $wsId)->update($up);
        return ['updated' => true];
    }

    // ═══════════════════════════════════════════════════════════════════
    // HISTORY (undo/redo snapshots)
    // ═══════════════════════════════════════════════════════════════════

    public function saveHistory(int $designId, array $snapshot): array
    {
        DB::table('studio_design_history')->insert([
            'design_id'    => $designId,
            'snapshot_json'=> json_encode($snapshot),
            'created_at'   => now(),
        ]);
        // Cap at 50 snapshots per design — drop oldest beyond that
        $ids = DB::table('studio_design_history')
            ->where('design_id', $designId)
            ->orderByDesc('id')
            ->limit(50)
            ->pluck('id')->toArray();
        if (count($ids) === 50) {
            DB::table('studio_design_history')
                ->where('design_id', $designId)
                ->whereNotIn('id', $ids)
                ->delete();
        }
        return ['saved' => true, 'count' => count($ids)];
    }

    public function getHistory(int $designId): array
    {
        $rows = DB::table('studio_design_history')
            ->where('design_id', $designId)
            ->orderByDesc('id')
            ->limit(50)
            ->get(['id','snapshot_json','created_at']);
        return ['history' => $rows->map(fn($r) => [
            'id'         => $r->id,
            'snapshot'   => json_decode($r->snapshot_json ?: '{}', true) ?: [],
            'created_at' => $r->created_at,
        ])->toArray()];
    }

    // ═══════════════════════════════════════════════════════════════════
    // THUMBNAIL (reuses existing puppeteer pipeline)
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Generate a thumbnail PNG for the design. Uses the existing
     * tools/studio-render.cjs puppeteer pipeline, then downscales via ffmpeg.
     *
     * Returns: ['thumbnail_url' => '/storage/studio-thumbs/<id>.png']
     */
    public function generateThumbnail(int $designId): array
    {
        $design = DB::table('studio_designs')->where('id', $designId)->first();
        if (!$design) return ['error' => 'design_not_found'];

        $dir = storage_path('app/public/studio-thumbs');
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        $outPath = $dir . '/design-' . $designId . '.png';

        $tool = base_path('tools/studio-render.cjs');
        if (!file_exists($tool)) return ['thumbnail_url' => null, 'error' => 'tool_missing'];

        $html = $this->renderDesignHtml($design);
        if (!$html) return ['thumbnail_url' => null, 'error' => 'no_renderable_html'];

        $tmpHtml = '/tmp/st-thumb-' . $designId . '-' . substr(md5(uniqid('', true)), 0, 6) . '.html';
        file_put_contents($tmpHtml, $html);

        $vpW = (int) ($design->canvas_width  ?: 1080);
        $vpH = (int) ($design->canvas_height ?: 1080);

        // studio-render.cjs signature: <html-file> <width> <height> <out-png>
        $cmd = 'node ' . escapeshellarg($tool) . ' '
             . escapeshellarg($tmpHtml) . ' '
             . $vpW . ' ' . $vpH . ' '
             . escapeshellarg($outPath) . ' 2>&1';
        exec($cmd, $output, $rc);
        @unlink($tmpHtml);

        if ($rc !== 0 || !file_exists($outPath) || filesize($outPath) < 1500) {
            Log::warning('studio thumb render failed', [
                'design_id' => $designId, 'rc' => $rc,
                'output' => substr(implode(' | ', $output), 0, 600),
            ]);
            return ['thumbnail_url' => null, 'error' => 'render_failed', 'rc' => $rc, 'output' => implode(' | ', $output)];
        }

        // Downscale via ffmpeg to ~600px wide for fast card loads
        $small = $dir . '/design-' . $designId . '-thumb.png';
        $ff = '/usr/bin/ffmpeg -y -i ' . escapeshellarg($outPath)
            . ' -vf "scale=600:-1:flags=lanczos" ' . escapeshellarg($small) . ' 2>&1';
        exec($ff, $ffOut, $ffRc);
        if ($ffRc === 0 && file_exists($small) && filesize($small) > 1000) {
            @rename($small, $outPath);
        }
        @chown($outPath, 'www-data'); @chgrp($outPath, 'www-data');

        $url = '/storage/studio-thumbs/design-' . $designId . '.png?v=' . time();
        DB::table('studio_designs')->where('id', $designId)->update([
            'thumbnail_url' => $url, 'updated_at' => now(),
        ]);
        return ['thumbnail_url' => $url, 'note' => 'puppeteer'];
    }

    /**
     * Render a design's full HTML for puppeteer screenshot.
     *
     * Strategy (production):
     *   1. content_html JSON {template_slug, fields}:
     *        - Load template.html + manifest.json from disk
     *        - Take EVERY variable's manifest default
     *        - Apply Arthur field-name mapping (headline_text -> headline_1/2/3,
     *          background_color -> bg, primary_accent_color -> primary, etc.)
     *        - Apply workspace brand-kit colors as another overlay
     *        - Substitute every {{token}} — never leave one empty
     *   2. content_html raw HTML: use as-is.
     *   3. No template/HTML: minimal shell with design name on bg color.
     */
    private function renderDesignHtml(object $design): ?string
    {
        $raw = (string) ($design->content_html ?? '');
        if ($raw === '') return $this->minimalDesignShell($design);

        $meta = json_decode($raw, true);
        if (is_array($meta) && !empty($meta['template_slug'])) {
            $slug = preg_replace('/[^a-z0-9_-]/i', '', $meta['template_slug']);
            $base = '/var/www/levelup-staging/storage/templates/studio/' . $slug;
            $tplPath = $base . '/template.html';
            $mfPath  = $base . '/manifest.json';
            if (file_exists($tplPath)) {
                return $this->renderTemplateWithFields(
                    file_get_contents($tplPath),
                    file_exists($mfPath) ? (json_decode(file_get_contents($mfPath), true) ?: []) : [],
                    (array) ($meta['fields'] ?? []),
                    $design
                );
            }
        }
        if (stripos($raw, '<html') !== false || stripos($raw, '<body') !== false) return $raw;
        return $this->minimalDesignShell($design);
    }

    /**
     * Build the final HTML with manifest defaults + Arthur overrides + brand kit.
     */
    private function renderTemplateWithFields(string $html, array $manifest, array $arthur, object $design): string
    {
        // 1. Start with manifest defaults — every variable gets a real value
        $resolved = [];
        $variables = $manifest['variables'] ?? [];
        foreach ($variables as $name => $spec) {
            $resolved[$name] = $spec['default'] ?? '';
        }

        // 2. Map Arthur's generic field names onto template-specific tokens
        $arthurMap = $this->mapArthurFields($arthur, array_keys($variables));
        foreach ($arthurMap as $tplVar => $value) {
            if ($value !== null && $value !== '') $resolved[$tplVar] = $value;
        }

        // 3. Always have core color tokens (used by template :root vars)
        $bg        = $arthur['background_color']       ?? $arthur['bg']        ?? $resolved['bg']        ?? '#0D0D0D';
        $primary   = $arthur['primary_accent_color']   ?? $arthur['primary']   ?? $resolved['primary']   ?? '#C8FF00';
        $secondary = $arthur['secondary_accent_color'] ?? $arthur['secondary'] ?? $resolved['accent']    ?? '#FF4D00';
        $text      = $arthur['text_color']             ?? $arthur['text']      ?? $resolved['text']      ?? $this->autoTextColor($bg);
        $resolved['bg']      = $bg;
        $resolved['primary'] = $primary;
        $resolved['accent']  = $secondary;
        $resolved['text']    = $text;

        // 4. Substitute ALL {{tokens}} — both spaced and unspaced
        foreach ($resolved as $k => $v) {
            if (!is_scalar($v)) continue;
            $val = (string) $v;
            $html = str_replace('{{' . $k . '}}', $val, $html);
            $html = str_replace('{{ ' . $k . ' }}', $val, $html);
        }

        // 5. Strip any remaining unresolved tokens (shouldn't happen if manifest is complete)
        $html = preg_replace('/\{\{\s*[a-z_0-9]+\s*\}\}/i', '', $html);

        return $html;
    }

    /**
     * Arthur AI uses generic field names (headline_text, subheadline_text, etc.).
     * Templates use specific ones (headline_1/2/3, subheadline, etc.).
     * Map by intent — heuristic but covers every existing template.
     */
    private function mapArthurFields(array $arthur, array $tplVars): array
    {
        $out = [];

        // Direct passthroughs (when names match exactly)
        foreach ($arthur as $k => $v) {
            if (in_array($k, $tplVars, true)) $out[$k] = $v;
        }

        // Headline mapping — split multi-line if template wants multiple lines
        $headline = $arthur['headline_text'] ?? $arthur['headline'] ?? null;
        if ($headline) {
            $parts = preg_split('/\s+/', trim($headline));
            $hasMulti = in_array('headline_1', $tplVars, true) || in_array('headline_2', $tplVars, true) || in_array('headline_3', $tplVars, true);
            if ($hasMulti && count($parts) >= 2) {
                // 3-part: ['Push','Your','Limits.'] style
                if (in_array('headline_3', $tplVars, true) && count($parts) >= 3) {
                    $third = array_pop($parts);
                    $second = array_pop($parts);
                    $first = implode(' ', $parts);
                    if (!isset($out['headline_1'])) $out['headline_1'] = $first;
                    if (!isset($out['headline_2'])) $out['headline_2'] = $second;
                    if (!isset($out['headline_3'])) $out['headline_3'] = $third;
                } else {
                    // 2-part split
                    $half = (int) ceil(count($parts) / 2);
                    if (!isset($out['headline_1'])) $out['headline_1'] = implode(' ', array_slice($parts, 0, $half));
                    if (!isset($out['headline_2'])) $out['headline_2'] = implode(' ', array_slice($parts, $half));
                }
            } else {
                // Template has just one headline slot
                foreach (['headline','headline_1','title','main_headline'] as $cand) {
                    if (in_array($cand, $tplVars, true) && !isset($out[$cand])) { $out[$cand] = $headline; break; }
                }
            }
        }

        // Subheadline
        $sub = $arthur['subheadline_text'] ?? $arthur['subheadline'] ?? $arthur['sub_text'] ?? null;
        if ($sub) {
            foreach (['subheadline','sub','subhead','tagline','description'] as $cand) {
                if (in_array($cand, $tplVars, true) && !isset($out[$cand])) { $out[$cand] = $sub; break; }
            }
        }

        // CTA
        $cta = $arthur['cta_text'] ?? $arthur['cta'] ?? $arthur['button_text'] ?? null;
        if ($cta) {
            foreach (['cta_text','cta','cta_label','button_text'] as $cand) {
                if (in_array($cand, $tplVars, true) && !isset($out[$cand])) { $out[$cand] = $cta; break; }
            }
        }

        // Eyebrow / pre-headline
        $eyebrow = $arthur['eyebrow'] ?? $arthur['eyebrow_text'] ?? null;
        if ($eyebrow && in_array('eyebrow', $tplVars, true) && !isset($out['eyebrow'])) {
            $out['eyebrow'] = $eyebrow;
        }

        return $out;
    }

    /** Pick a readable text color for a given background. */
    private function autoTextColor(string $bg): string
    {
        $hex = ltrim($bg, '#');
        if (strlen($hex) === 3) $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        if (strlen($hex) !== 6) return '#FFFFFF';
        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));
        $luma = (0.299 * $r + 0.587 * $g + 0.114 * $b) / 255;
        return $luma > 0.55 ? '#0D0D0D' : '#FFFFFF';
    }

    private function minimalDesignShell(object $design): string
    {
        $bg = $design->background_value ?: '#1a1a2e';
        $name = htmlspecialchars((string) ($design->name ?? 'Untitled'), ENT_QUOTES);
        $w = (int) ($design->canvas_width  ?: 1080);
        $h = (int) ($design->canvas_height ?: 1080);
        return '<!DOCTYPE html><html><head><meta charset="utf-8"/>'
             . '<style>html,body{margin:0;padding:0;width:100%;height:100%}'
             . '.canvas{width:' . $w . 'px;height:' . $h . 'px;background:' . htmlspecialchars($bg) . ';display:flex;align-items:center;justify-content:center;'
             . 'color:#fff;font-family:Inter,sans-serif;font-size:48px;font-weight:700;letter-spacing:-0.5px;text-align:center;padding:60px;box-sizing:border-box}'
             . '</style></head><body><div class="canvas">' . $name . '</div></body></html>';
    }


    // ═══════════════════════════════════════════════════════════════════
    // PHASE 5 — publish, resize, save-to-media
    // studio-phase5-additions
    // ═══════════════════════════════════════════════════════════════════

    public function publishToSocial(int $designId, int $wsId, array $data): array
    {
        $design = DB::table('studio_designs')->where('id', $designId)->first();
        if (!$design) return ['success' => false, 'error' => 'not_found'];

        $platforms = (array) ($data['platforms'] ?? ['instagram']);
        $caption   = (string) ($data['caption'] ?? '');
        $scheduleAt = $data['schedule_at'] ?? null;

        $imageUrl = $design->exported_url ?: $design->thumbnail_url;
        if (!$imageUrl) return ['success' => false, 'error' => 'no_export', 'message' => 'Export the design before publishing.'];

        // Delegate to Social engine when available
        if (class_exists(\App\Engines\Social\Services\SocialService::class)) {
            try {
                $social = app(\App\Engines\Social\Services\SocialService::class);
                if (method_exists($social, 'queuePost')) {
                    $jobId = $social->queuePost($wsId, [
                        'platforms'   => $platforms,
                        'caption'     => $caption,
                        'image_url'   => $imageUrl,
                        'schedule_at' => $scheduleAt,
                        'source'      => 'studio',
                        'design_id'   => $designId,
                    ]);
                    DB::table('studio_designs')->where('id', $designId)->update(['published_to_social' => 1, 'updated_at' => now()]);
                    return ['success' => true, 'queued' => true, 'job_id' => $jobId, 'message' => 'Queued to Social engine'];
                }
            } catch (\Throwable $e) { Log::warning('social engine queue failed', ['error' => $e->getMessage()]); }
        }
        // No Social engine — mark intent and let user retry via Social tab
        DB::table('studio_designs')->where('id', $designId)->update(['published_to_social' => 1, 'updated_at' => now()]);
        return ['success' => true, 'queued' => false, 'message' => 'Marked for publishing. Complete via the Social engine.'];
    }

    /** Rescale all elements proportionally to a new canvas size. */
    public function resizeDesign(int $designId, int $width, int $height): array
    {
        if ($width < 50 || $width > 8000 || $height < 50 || $height > 8000) {
            return ['success' => false, 'error' => 'invalid_dimensions'];
        }
        $design = DB::table('studio_designs')->where('id', $designId)->first();
        if (!$design) return ['success' => false, 'error' => 'not_found'];

        $oldW = (int) $design->canvas_width;
        $oldH = (int) $design->canvas_height;
        $sx = $width  / max(1, $oldW);
        $sy = $height / max(1, $oldH);
        // Use the MEAN scale for proportional resize so elements keep their aspect ratio
        $s = ($sx + $sy) / 2;

        $elements = DB::table('studio_elements')->where('design_id', $designId)->get();
        foreach ($elements as $e) {
            $p = json_decode($e->properties_json ?: '{}', true) ?: [];
            foreach (['x','y','width','height','font_size'] as $k) {
                if (isset($p[$k]) && is_numeric($p[$k])) $p[$k] = (int) round($p[$k] * $s);
            }
            DB::table('studio_elements')->where('id', $e->id)->update([
                'properties_json' => json_encode($p),
                'updated_at'      => now(),
            ]);
        }
        DB::table('studio_designs')->where('id', $designId)->update([
            'canvas_width'  => $width,
            'canvas_height' => $height,
            'updated_at'    => now(),
        ]);
        // Kick thumbnail regeneration (best-effort)
        try { $this->generateThumbnail($designId); } catch (\Throwable $e) {}
        return ['success' => true, 'canvas_width' => $width, 'canvas_height' => $height];
    }

    /** Insert the design's latest export (PNG or MP4) into the workspace media library. */
    public function saveExportToMedia(int $designId, int $wsId): array
    {
        $design = DB::table('studio_designs')->where('id', $designId)->first();
        if (!$design) return ['success' => false, 'error' => 'not_found'];
        $url = $design->exported_video_url ?: $design->exported_url ?: null;
        if (!$url) return ['success' => false, 'error' => 'no_export', 'message' => 'Export the design first'];
        if (!\Schema::hasTable('media')) return ['success' => false, 'error' => 'no_media_table'];

        $assetType = $design->exported_video_url ? 'video' : 'image';
        $basename  = basename(parse_url($url, PHP_URL_PATH) ?: 'studio-export');
        $cols      = [
            'workspace_id'      => $wsId,
            'filename'          => $basename,
            'path'              => $url,
            'url'               => $url,
            'asset_type'        => $assetType,
            'mime_type'    => $assetType === 'video' ? 'video/mp4' : 'image/png',
            'width'        => (int) $design->canvas_width,
            'height'       => (int) $design->canvas_height,
            'source'       => 'studio',
            'source_id'    => $designId,
            'created_at'   => now(),
            'updated_at'   => now(),
        ];
        $existing = \Schema::getColumnListing('media');
        $insert   = array_intersect_key($cols, array_flip($existing));
        try {
            $id = DB::table('media')->insertGetId($insert);
            return ['success' => true, 'media_id' => $id];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => 'insert_failed', 'message' => $e->getMessage()];
        }
    }

    /** Generate a 400x400 placeholder PNG matching the design's background color. */
    private function placeholderThumbnail(object $design): string
    {
        $hex = '#E5E7EB';
        if (!empty($design->background_type) && $design->background_type === 'color' && !empty($design->background_value)) {
            $v = preg_replace('/[^0-9a-fA-F]/', '', $design->background_value);
            if (strlen($v) === 6) $hex = '#' . strtoupper($v);
        }
        [$r,$g,$b] = sscanf($hex, "#%02x%02x%02x");

        // Use GD if available — produces a valid solid-color PNG.
        if (function_exists('imagecreatetruecolor')) {
            $img = imagecreatetruecolor(400, 400);
            $col = imagecolorallocate($img, (int) $r, (int) $g, (int) $b);
            imagefilledrectangle($img, 0, 0, 400, 400, $col);
            ob_start();
            imagepng($img);
            $data = ob_get_clean();
            imagedestroy($img);
            return $data;
        }
        // GD unavailable — return a minimal 1x1 PNG that's always valid.
        return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkAAIAAAoAAv/lxKUAAAAASUVORK5CYII=');
    }

    // ═══════════════════════════════════════════════════════════════════
    // FONTS CATALOG (static list)
    // ═══════════════════════════════════════════════════════════════════

    public function getFonts(): array
    {
        return [
            'fonts' => array_map(fn($f) => [
                'family'   => $f,
                'category' => $this->fontCategory($f),
            ], self::FONTS),
        ];
    }

    private function fontCategory(string $font): string
    {
        return match (true) {
            in_array($font, ['Playfair Display','Cormorant Garamond'], true) => 'serif',
            in_array($font, ['DM Mono'], true)                                 => 'monospace',
            in_array($font, ['Bebas Neue','Oswald','Unbounded'], true)         => 'display',
            default                                                             => 'sans-serif',
        };
    }

    // ═══════════════════════════════════════════════════════════════════
    // FORMATS CATALOG
    // ═══════════════════════════════════════════════════════════════════

    public function getFormats(): array
    {
        $out = [];
        foreach (self::FORMATS as $slug => $def) {
            $out[] = [
                'slug'   => $slug,
                'width'  => $def['w'],
                'height' => $def['h'],
                'label'  => $def['label'],
            ];
        }
        return ['formats' => $out];
    }
}
