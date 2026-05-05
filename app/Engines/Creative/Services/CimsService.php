<?php

namespace App\Engines\Creative\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * CimsService — Creative Intelligence Memory System
 *
 * Manages two things:
 *  1. Brand Identity — the stable identity of a workspace (colors, fonts, voice,
 *     tone, visual style, logo). This is what makes all outputs consistent.
 *  2. Generation Memory — a history of what was made, what worked, what to avoid.
 *     Used by the BlueprintService to inject context into new generations so outputs
 *     across engines (Write, Social, Marketing, Builder) feel like they came from
 *     the same brand.
 */
class CimsService
{
    private WhiteLabelService $whiteLabel;

    public function __construct(WhiteLabelService $whiteLabel)
    {
        $this->whiteLabel = $whiteLabel;
    }

    // ═══════════════════════════════════════════════════════
    // BRAND IDENTITY
    // ═══════════════════════════════════════════════════════

    /**
     * Get a workspace's full brand identity.
     * Returns defaults if none has been set.
     */
    public function getBrandIdentity(int $wsId): array
    {
        $row = DB::table('creative_brand_identities')
            ->where('workspace_id', $wsId)
            ->whereNull('deleted_at')
            ->first();

        if ($row) {
            return $this->hydrate($row);
        }

        return $this->defaultIdentity($wsId);
    }

    /**
     * Update or create a workspace's brand identity.
     * Merges with existing — caller only needs to send changed fields.
     */
    public function updateBrandIdentity(int $wsId, array $data): array
    {
        $existing = $this->getBrandIdentity($wsId);

        $merged = array_merge($existing, array_filter($data, fn($v) => $v !== null));

        $payload = [
            'workspace_id'  => $wsId,
            'primary_color' => $merged['primary_color'] ?? null,
            'secondary_color'=> $merged['secondary_color'] ?? null,
            'accent_color'  => $merged['accent_color'] ?? null,
            'colors_json'   => json_encode($merged['colors'] ?? []),
            'fonts_json'    => json_encode($merged['fonts'] ?? []),
            'logo_url'      => $merged['logo_url'] ?? null,
            'voice'         => $merged['voice'] ?? 'professional',
            'tone'          => $merged['tone'] ?? 'professional',
            'style_notes'   => $merged['style_notes'] ?? null,
            'visual_style'  => $merged['visual_style'] ?? null,
            'industry'      => $merged['industry'] ?? null,
            'target_audience'=> $merged['target_audience'] ?? null,
            'updated_at'    => now(),
        ];

        $exists = DB::table('creative_brand_identities')
            ->where('workspace_id', $wsId)
            ->whereNull('deleted_at')
            ->exists();

        if ($exists) {
            DB::table('creative_brand_identities')
                ->where('workspace_id', $wsId)
                ->update($payload);
        } else {
            DB::table('creative_brand_identities')
                ->insert(array_merge($payload, ['created_at' => now()]));
        }

        return $this->getBrandIdentity($wsId);
    }

    /**
     * Build a compact brand context string for injection into LLM prompts.
     * Short enough to fit in a system prompt, dense enough to be useful.
     */
    public function buildBrandContext(int $wsId): string
    {
        $brand = $this->getBrandIdentity($wsId);

        $parts = [];

        if (!empty($brand['voice'])) {
            $parts[] = "Brand voice: {$brand['voice']}";
        }
        if (!empty($brand['tone'])) {
            $parts[] = "Tone: {$brand['tone']}";
        }
        if (!empty($brand['visual_style'])) {
            $parts[] = "Visual style: {$brand['visual_style']}";
        }
        if (!empty($brand['colors'])) {
            $colorList = implode(', ', array_slice($brand['colors'], 0, 3));
            $parts[] = "Brand colors: {$colorList}";
        }
        if (!empty($brand['fonts'])) {
            $fontList = implode(', ', array_slice($brand['fonts'], 0, 2));
            $parts[] = "Fonts: {$fontList}";
        }
        if (!empty($brand['target_audience'])) {
            $parts[] = "Audience: {$brand['target_audience']}";
        }
        if (!empty($brand['style_notes'])) {
            $parts[] = $brand['style_notes'];
        }

        return empty($parts) ? '' : implode('. ', $parts) . '.';
    }

    // ═══════════════════════════════════════════════════════
    // GENERATION MEMORY
    // ═══════════════════════════════════════════════════════

    /**
     * Record a completed generation in CIMS memory.
     * Called by every engine after a successful creative generation.
     */
    public function recordGeneration(array $data): int
    {
        return DB::table('creative_memory_records')->insertGetId([
            'workspace_id'   => $data['workspace_id'],
            'engine'         => $data['engine'],          // write, social, marketing, builder, creative
            'type'           => $data['type'],            // image, video, text, email, post, article
            'prompt'         => $data['prompt'] ?? null,
            'result_summary' => $data['result_summary'] ?? null,
            'asset_url'      => $data['asset_url'] ?? null,
            'success'        => $data['success'] ?? true,
            'quality_score'  => $data['quality_score'] ?? null,  // 0.0–1.0 if rated
            'context_json'   => json_encode($data['context'] ?? []),
            'metadata_json'  => json_encode($data['metadata'] ?? []),
            'created_at'     => now(),
        ]);
    }

    /**
     * Get recent generation history for a workspace.
     * Used by BlueprintService to inject "what we've done before" into new blueprints.
     */
    public function getGenerationHistory(int $wsId, string $type = null, int $limit = 10): array
    {
        $q = DB::table('creative_memory_records')
            ->where('workspace_id', $wsId)
            ->where('success', true);

        if ($type) {
            $q->where('type', $type);
        }

        return $q->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->toArray();
    }

    /**
     * Get a compact memory summary string for injection into blueprints.
     * Describes recent successes without bloating the prompt.
     */
    public function buildMemoryContext(int $wsId, string $type = null): string
    {
        $history = $this->getGenerationHistory($wsId, $type, 5);

        if (empty($history)) {
            return '';
        }

        $summaries = array_filter(
            array_map(fn($r) => $r->result_summary ?? null, $history)
        );

        if (empty($summaries)) {
            return '';
        }

        $count = count($history);
        $recent = array_slice($summaries, 0, 3);

        return "Recent {$type} generations ({$count} total): " . implode('; ', $recent);
    }

    /**
     * Get generation stats for a workspace dashboard.
     */
    public function getStats(int $wsId): array
    {
        $base = DB::table('creative_memory_records')->where('workspace_id', $wsId);

        return [
            'total'          => (clone $base)->count(),
            'by_type'        => (clone $base)->selectRaw('type, count(*) as count')->groupBy('type')->get()->pluck('count', 'type')->toArray(),
            'by_engine'      => (clone $base)->selectRaw('engine, count(*) as count')->groupBy('engine')->get()->pluck('count', 'engine')->toArray(),
            'success_rate'   => (clone $base)->count() > 0
                ? round((clone $base)->where('success', true)->count() / (clone $base)->count() * 100, 1)
                : 0,
            'recent'         => (clone $base)->orderByDesc('created_at')->limit(5)->get()->toArray(),
        ];
    }

    // ═══════════════════════════════════════════════════════
    // PRIVATE
    // ═══════════════════════════════════════════════════════

    private function hydrate(object $row): array
    {
        return [
            'id'              => $row->id,
            'workspace_id'    => $row->workspace_id,
            'primary_color'   => $row->primary_color ?? null,
            'secondary_color' => $row->secondary_color ?? null,
            'accent_color'    => $row->accent_color ?? null,
            'colors'          => json_decode($row->colors_json ?? '[]', true),
            'fonts'           => json_decode($row->fonts_json ?? '[]', true),
            'logo_url'        => $row->logo_url ?? null,
            'voice'           => $row->voice ?? 'professional',
            'tone'            => $row->tone ?? 'professional',
            'style_notes'     => $row->style_notes ?? null,
            'visual_style'    => $row->visual_style ?? null,
            'industry'        => $row->industry ?? null,
            'target_audience' => $row->target_audience ?? null,
        ];
    }

    private function defaultIdentity(int $wsId): array
    {
        return [
            'id'              => null,
            'workspace_id'    => $wsId,
            'primary_color'   => null,
            'secondary_color' => null,
            'accent_color'    => null,
            'colors'          => [],
            'fonts'           => [],
            'logo_url'        => null,
            'voice'           => 'professional',
            'tone'            => 'professional',
            'style_notes'     => null,
            'visual_style'    => null,
            'industry'        => null,
            'target_audience' => null,
        ];
    }
}
