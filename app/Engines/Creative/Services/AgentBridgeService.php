<?php

namespace App\Engines\Creative\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * AgentBridgeService — Creative888 Agent Context Bridge
 *
 * Packages creative intelligence into structured, agent-readable context.
 * Ported from WP class-lucreative-agent-bridge.php (Phase 4D).
 *
 * This is a SIDE-CHANNEL INTELLIGENCE EXPORT only.
 * It does NOT modify generation endpoints or any existing pipeline.
 * It does NOT make external API calls.
 *
 * NEVER throws. Returns empty-valued structure on any failure.
 */
class AgentBridgeService
{
    // Recommendation type constants
    const REC_CREATIVE_DIRECTION = 'creative_direction';
    const REC_CAMPAIGN_ANGLE     = 'campaign_angle';
    const REC_CTA_SUGGESTION     = 'cta_suggestion';
    const REC_VARIATION_IDEA     = 'variation_idea';

    /**
     * Build a structured agent context payload from available intelligence data.
     *
     * All parameters are optional — pass only what is available.
     * Missing data returns empty values, never fails.
     */
    public function buildContext(
        array $analysis   = [],
        array $blueprints = [],
        array $strategy   = [],
        array $asset      = []
    ): array {
        try {
            return $this->build($analysis, $blueprints, $strategy, $asset);
        } catch (\Throwable $e) {
            Log::warning('[CREATIVE888 AgentBridge] Exception: ' . $e->getMessage());
            return $this->emptyContext();
        }
    }

    /**
     * Build creative context for a workspace by querying DB tables directly.
     * Used by AgentDispatchService to inject context for creative-related tasks.
     */
    public function buildWorkspaceContext(int $workspaceId): array
    {
        try {
            // Get brand identity context
            $brand = DB::table('creative_brand_identities')
                ->where('workspace_id', $workspaceId)
                ->first();

            $brandContext = [];
            if ($brand) {
                $brandContext = [
                    'visual_style' => $brand->visual_style ?? '',
                    'tone'         => $brand->tone ?? '',
                    'industry'     => $brand->industry ?? '',
                    'colors'       => json_decode($brand->colors_json ?? '[]', true) ?: [],
                ];
            }

            // Get recent generation patterns from memory
            $recentMemory = DB::table('creative_memory_records')
                ->where('workspace_id', $workspaceId)
                ->orderByDesc('created_at')
                ->limit(10)
                ->get();

            $patterns = [];
            foreach ($recentMemory as $mem) {
                $meta = json_decode($mem->metadata_json ?? '{}', true) ?: [];
                if (!empty($meta['type'])) {
                    $patterns[] = $meta['type'];
                }
            }

            // Get recent assets
            $recentAssets = DB::table('assets')
                ->where('workspace_id', $workspaceId)
                ->whereNull('deleted_at')
                ->where('status', 'completed')
                ->orderByDesc('created_at')
                ->limit(5)
                ->get(['id', 'type', 'url', 'title', 'created_at']);

            $assetContext = [];
            foreach ($recentAssets as $a) {
                $assetContext[] = [
                    'asset_id'  => $a->id,
                    'type'      => $a->type,
                    'title'     => $a->title ?? '',
                    'url'       => $a->url ?? '',
                ];
            }

            // Get top blueprints if the table exists
            $topBlueprints = [];
            if (DB::getSchemaBuilder()->hasTable('creative_blueprints')) {
                $bpRows = DB::table('creative_blueprints')
                    ->where('workspace_id', $workspaceId)
                    ->where('source_type', '!=', 'ba888')
                    ->orderByDesc('score')
                    ->limit(5)
                    ->get(['id', 'score', 'layout', 'subject_type', 'style']);

                foreach ($bpRows as $bp) {
                    $topBlueprints[] = [
                        'id'           => $bp->id,
                        'score'        => (float) $bp->score,
                        'layout'       => $bp->layout ?? '',
                        'subject_type' => $bp->subject_type ?? '',
                        'style'        => $bp->style ?? '',
                    ];
                }
            }

            return [
                'creative_context'    => [
                    'brand'              => $brandContext,
                    'recent_patterns'    => array_count_values($patterns),
                ],
                'performance_context' => [
                    'top_blueprints'       => $topBlueprints,
                    'has_external_signals' => collect($topBlueprints)->contains(fn ($b) => ($b['score'] ?? 0) > 5),
                ],
                'asset_context'       => [
                    'recent_assets' => $assetContext,
                    'total_assets'  => DB::table('assets')
                        ->where('workspace_id', $workspaceId)
                        ->whereNull('deleted_at')
                        ->count(),
                ],
            ];
        } catch (\Throwable $e) {
            Log::warning('[CREATIVE888 AgentBridge] buildWorkspaceContext failed: ' . $e->getMessage());
            return [];
        }
    }

    // =========================================================================
    // INTERNAL BUILD (from analysis/blueprints/strategy/asset inputs)
    // =========================================================================

    private function build(array $analysis, array $blueprints, array $strategy, array $asset): array
    {
        // Creative context
        $subject = Str::slug($analysis['dominant_subject'] ?? '', '_');
        $layout  = Str::slug($analysis['structure']['layout'] ?? ($analysis['layout'] ?? ''), '_');
        $style   = Str::slug($analysis['style'] ?? '', '_');
        $hook    = Str::limit($strategy['hook'] ?? '', 200);
        $angle   = Str::limit($strategy['angle'] ?? '', 200);
        $cta     = Str::limit($strategy['cta'] ?? '', 200);
        $tone    = Str::limit($strategy['tone'] ?? '', 200);

        $creativeContext = [
            'subject_type' => $subject ?: '',
            'layout'       => $layout ?: '',
            'visual_style' => $style ?: '',
            'hook'         => $hook ?: '',
            'angle'        => $angle ?: '',
            'cta'          => $cta ?: '',
            'tone'         => $tone ?: '',
        ];

        // Performance context
        $topIds    = [];
        $topScores = [];
        $hasExt    = false;

        foreach (array_slice($blueprints, 0, 5) as $bp) {
            $bpId    = (int) ($bp['_id'] ?? 0);
            $bpScore = (float) ($bp['_computed_score'] ?? $bp['_score'] ?? 0.0);
            $extCnt  = (int) ($bp['_external_count'] ?? 0);

            if ($bpId > 0) {
                $topIds[]    = $bpId;
                $topScores[] = round($bpScore, 2);
                if ($extCnt > 0) {
                    $hasExt = true;
                }
            }
        }

        $performanceContext = [
            'top_blueprint_ids'    => $topIds,
            'top_scores'           => $topScores,
            'has_external_signals' => $hasExt,
        ];

        // Asset context
        $assetContext = [
            'asset_id'    => isset($asset['id']) ? (int) $asset['id'] : null,
            'public_url'  => Str::limit($asset['public_url'] ?? '', 500),
            'source_type' => Str::slug($asset['type'] ?? '', '_'),
        ];

        // Recommendations
        $recommendations = $this->buildRecommendations($layout, $style, $subject, $angle, $cta, $blueprints);

        return [
            'creative_context'    => $creativeContext,
            'performance_context' => $performanceContext,
            'recommendations'     => $recommendations,
            'asset_context'       => $assetContext,
        ];
    }

    /**
     * Generate 3-5 deterministic structured recommendations.
     */
    private function buildRecommendations(
        string $layout,
        string $style,
        string $subject,
        string $angle,
        string $cta,
        array  $blueprints
    ): array {
        $recs = [];

        // Rec 1: Creative direction (layout + style)
        $layoutDir = [
            'centered' => 'Use a centered composition for strong focal balance and premium product focus.',
            'hero'     => 'Use a full-bleed hero layout to maximise visual impact and brand presence.',
            'split'    => 'Use a split layout to contrast your visual and messaging side by side.',
            'overlay'  => 'Use text overlay to anchor your message directly on the visual.',
            'grid'     => 'Use a grid layout for consistent multi-element product display.',
        ];
        $dirText = $layoutDir[$layout] ?? 'Use a clean, structured composition for clear visual communication.';
        if ($style && $style !== 'unknown') {
            $dirText .= ' Pair with ' . $style . ' aesthetics for a cohesive look.';
        }
        $recs[] = [
            'type'        => self::REC_CREATIVE_DIRECTION,
            'title'       => 'Composition Recommendation',
            'description' => $dirText,
        ];

        // Rec 2: Campaign angle
        $angleCopy = [
            'luxury'       => 'Lead with exclusivity and premium quality as your primary differentiator.',
            'performance'  => 'Lead with results and capability — show what your product achieves.',
            'convenience'  => 'Lead with ease and simplicity — make the benefit immediately obvious.',
            'trust'        => 'Lead with credibility, social proof, and expertise.',
            'authenticity' => 'Lead with genuine, real-world storytelling to build connection.',
        ];
        $recs[] = [
            'type'        => self::REC_CAMPAIGN_ANGLE,
            'title'       => 'Campaign Angle',
            'description' => $angleCopy[$angle] ?? 'Lead with your strongest unique value proposition.',
        ];

        // Rec 3: CTA suggestion
        $ctaRationale = [
            'Shop Now'           => "Use 'Shop Now' for direct transactional intent — strong for product pages.",
            'Discover More'      => "Use 'Discover More' for softer conversion intent — ideal for awareness campaigns.",
            'Get Started'        => "Use 'Get Started' to signal low friction — good for onboarding flows.",
            'Explore Collection' => "Use 'Explore Collection' to invite browsing — strong for lifestyle or fashion.",
            'Learn More'         => "Use 'Learn More' for informational intent — works at top of funnel.",
            'Try It Now'         => "Use 'Try It Now' to reduce risk perception — good for trials or demos.",
        ];
        $ctaDesc = $ctaRationale[$cta] ?? ($cta ? "Use '{$cta}' as your primary call to action." : 'Choose a CTA that matches your funnel stage and conversion intent.');
        $recs[] = [
            'type'        => self::REC_CTA_SUGGESTION,
            'title'       => 'CTA Recommendation',
            'description' => $ctaDesc,
        ];

        // Rec 4: Variation idea from blueprint pool
        if (!empty($blueprints)) {
            $top  = $blueprints[0];
            $bpL  = Str::slug($top['structure']['layout'] ?? '', '_');
            $bpS  = Str::slug($top['visual_style'] ?? '', '_');
            if ($bpL && $bpL !== $layout) {
                $recs[] = [
                    'type'        => self::REC_VARIATION_IDEA,
                    'title'       => 'Test a Blueprint-Backed Variation',
                    'description' => 'Your top-performing blueprint used a ' . $bpL . ($bpS ? ' + ' . $bpS : '') . ' pattern. Consider testing this as an A/B variant.',
                ];
            }
        }

        // Rec 5: Subject-specific enhancement
        if (count($recs) < 5) {
            $subjectTips = [
                'product'  => 'Ensure the product is the undisputed hero — minimise background distractions.',
                'person'   => 'Eyes in focus and natural expression build the strongest emotional connection.',
                'space'    => 'Show architectural depth — foreground, midground, background layers improve immersion.',
                'food'     => 'Use shallow depth of field and warm lighting to amplify appetite appeal.',
                'abstract' => 'Use a strong accent colour and deliberate whitespace to guide the eye.',
            ];
            $tip = $subjectTips[$subject] ?? '';
            if ($tip) {
                $recs[] = [
                    'type'        => self::REC_VARIATION_IDEA,
                    'title'       => 'Subject Enhancement',
                    'description' => $tip,
                ];
            }
        }

        return array_slice($recs, 0, 5);
    }

    /**
     * Return an empty-valued agent context structure.
     */
    private function emptyContext(): array
    {
        return [
            'creative_context'    => [
                'subject_type' => '', 'layout' => '', 'visual_style' => '',
                'hook' => '', 'angle' => '', 'cta' => '', 'tone' => '',
            ],
            'performance_context' => [
                'top_blueprint_ids' => [], 'top_scores' => [], 'has_external_signals' => false,
            ],
            'recommendations'     => [],
            'asset_context'       => ['asset_id' => null, 'public_url' => '', 'source_type' => ''],
        ];
    }
}
