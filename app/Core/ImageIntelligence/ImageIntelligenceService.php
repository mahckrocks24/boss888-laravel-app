<?php

namespace App\Core\ImageIntelligence;

use App\Connectors\RuntimeClient;
use App\Core\Billing\CreditService;
use App\Engines\Creative\Services\CreativeService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * ImageIntelligenceService — THE single canonical image-generation orchestrator
 * for LevelUpGrowth. Every entry point (Studio, Creative, SEO, blog,
 * campaign, social, automation) calls generate($context).
 *
 * Flow: context resolution → LLM reasoning (ImageBlueprint) → prompt compiler
 * → cost estimate → credit reserve → audit start → asset create → provider
 * request → actual-cost capture → asset complete → credit settle → audit close.
 */
class ImageIntelligenceService
{
    // gpt-image-1 image-output token counts by size + quality (OpenAI published).
    private const TOKENS = [
        '1024x1024' => ['low' => 272, 'medium' => 1056, 'high' => 4160],
        '1024x1536' => ['low' => 408, 'medium' => 1584, 'high' => 6240],
        '1536x1024' => ['low' => 408, 'medium' => 1584, 'high' => 6240],
    ];
    private const OUT_TOKEN_USD = 0.00004; // $40 / 1M image output tokens
    private const IN_TOKEN_USD  = 0.000005; // $5 / 1M text input tokens

    // CANONICAL credit price by quality — unifies the old Studio(8)/Creative(2)
    // split. Aligns with CapabilityMapService (mini=1, generate_image=2, high=4).
    // NOTE: applying this to the Studio path lowers it from 8 → 2 (medium);
    // flagged for pricing ratification (see audit report).
    private const CREDIT_BY_QUALITY = ['low' => 1, 'medium' => 2, 'high' => 4];

    public function __construct(
        private RuntimeClient $runtime,
        private ImageReasoningService $reasoner,
        private ImagePromptCompiler $compiler,
        private CreditService $credits,
        private CreativeService $creative,
        private ImageOverlayRenderer $overlayRenderer,
    ) {}

    /**
     * PURE intelligence — reasoning + compile only, NO side effects (no credits,
     * asset, provider call or audit). Shared by generate() and by callers that
     * own their own asset/credit flow (e.g. the Creative article chain).
     *
     * @return array{context:array, reasoning:array, blueprint:array, compiled:array}
     */
    public function plan(array $context): array
    {
        $ctx = $this->resolveContext($context);
        $reason = $this->reasoner->reason($ctx);
        $blueprint = $this->attachCompilerContext($reason['blueprint'], $ctx);
        $compiled = $this->compiler->compile($blueprint);
        return ['context' => $ctx, 'reasoning' => $reason, 'blueprint' => $blueprint, 'compiled' => $compiled];
    }

    /**
     * @param array $context ImageGenerationContext (see docblock / audit).
     * @return array full result incl. success, url, asset_id, blueprint, provider_prompt, cost, credits, audit_id.
     */
    public function generate(array $context): array
    {
        $ctx = $this->resolveContext($context);
        $wsId = (int) $ctx['workspace_id'];
        if ($wsId <= 0) return ['success' => false, 'error' => 'workspace_required'];
        if (trim((string) ($ctx['user_prompt'] ?? '')) === '') return ['success' => false, 'error' => 'prompt_required'];

        // 1) LLM reasoning → ImageBlueprint
        $reason = $this->reasoner->reason($ctx);
        $blueprint = $this->attachCompilerContext($reason['blueprint'], $ctx);

        // 2) compile provider request + typography enforcement
        $compiled = $this->compiler->compile($blueprint);

        // WAVE 4A — manual Studio image SHADOW (default OFF). When enabled AND the
        // caller is the manual Studio route (source==='studio'), ALSO derive a
        // Creative888-brief plan (getImageBlueprint → compileFromBrief) purely for
        // comparison. Side-effect-free: NO provider call, NO credit reserve, NO
        // asset/creative_job, NO persistence, and it NEVER touches the user
        // response. Wrapped so a shadow failure can never disturb the authoritative
        // legacy path below. OFF preserves current behaviour byte-for-byte.
        if ($this->manualImageShadowEnabled($ctx)) {
            try { $this->runManualImageShadow($ctx, $blueprint, $compiled); }
            catch (\Throwable $e) { Log::debug('[Wave4A shadow] skipped: ' . $e->getMessage()); }
        }

        // 3) cost estimate + canonical credit reserve
        $estCost = $this->estimateCost($compiled['size'], $compiled['quality'], $compiled['provider_prompt']);
        $credits = self::CREDIT_BY_QUALITY[$compiled['quality']] ?? 2;
        $reason_str = 'image_intelligence:' . ($ctx['source'] ?? 'studio');
        try {
            $resRef = $this->credits->reserve($wsId, $credits, $reason_str);
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => 'insufficient_credits', 'message' => $e->getMessage(), 'credits_required' => $credits];
        }

        // 4) audit start (creative_jobs) + asset create (pending → Library flow)
        $jobUuid = (string) Str::uuid();
        $jobId = $this->auditStart($wsId, $ctx, $blueprint, $compiled, $reason, $jobUuid);
        $asset = $this->creative->createAsset($wsId, [
            'type'         => 'image',
            'title'        => mb_substr((string) ($ctx['user_prompt'] ?? 'Image'), 0, 120),
            'prompt'       => $compiled['provider_prompt'],
            'aspect_ratio' => $blueprint['aspect_ratio'] ?? '1:1',
            'quality'      => $compiled['quality'],
            'metadata'     => [
                'original_prompt'  => $ctx['user_prompt'] ?? '',
                'source'           => $ctx['source'] ?? 'studio',
                'platform'         => $blueprint['platform'] ?? null,
                'asset_type'       => $blueprint['asset_type'] ?? null,
                'typography_mode'  => $compiled['typography']['mode'] ?? 'none',
                'enhanced'         => true,
                'creative_job'     => $jobUuid,
            ],
        ]);
        $assetId = $asset['asset_id'] ?? null;
        if ($assetId) DB::table('assets')->where('id', $assetId)->update(['status' => 'generating', 'creative_job_id' => $jobId, 'updated_at' => now()]);

        // 5) provider request (quality forwarded — runtime honours what it can)
        $t0 = microtime(true);
        $img = $this->runtime->imageGenerate($compiled['provider_prompt'], [
            'workspace_id' => $wsId,
            'style'        => $ctx['style'] ?? 'natural',
            'size'         => $compiled['size'],
            'quality'      => $compiled['quality'],
        ]);
        $elapsedMs = (int) round((microtime(true) - $t0) * 1000);

        if (empty($img['success']) || empty($img['url'])) {
            $this->credits->release($wsId, $resRef);
            $this->auditFail($jobId, $img['error'] ?? 'provider_failed', $img);
            if ($assetId) DB::table('assets')->where('id', $assetId)->update(['status' => 'failed', 'updated_at' => now()]);
            return ['success' => false, 'error' => 'image_generation_failed', 'message' => $img['error'] ?? 'unknown', 'blueprint' => $blueprint];
        }

        // 6) capture ACTUAL provider params (runtime forces low today) + real cost
        $actualQuality = strtolower((string) ($img['quality'] ?? $compiled['quality']));
        $actualSize    = (string) ($img['size'] ?? $compiled['size']);
        $actualCost    = $this->estimateCost($actualSize, $actualQuality, $compiled['provider_prompt']);
        $url           = $img['url'];
        [$w, $h]       = array_map('intval', array_pad(explode('x', $actualSize), 2, 1024));

        // 7) typography compositing — for 'separate_overlay', burn the captured
        // headline + copy onto the text-free background as REAL crisp typography
        // (gpt-image-1 spells badly, esp. at forced-low quality). The composited
        // image becomes the deliverable; the clean background is kept in metadata.
        $backgroundUrl  = $url;
        $backgroundPath = $img['storage_path'] ?? null;
        $finalUrl       = $url;
        $finalPath      = $img['storage_path'] ?? null;
        $composited     = false;
        if (($compiled['typography']['mode'] ?? '') === 'separate_overlay'
            && !empty($compiled['overlay'])
            && !empty($img['storage_path'])) {
            $ov = $this->overlayRenderer->render($img['storage_path'], $compiled['overlay'], $w, $h, $wsId);
            if (!empty($ov['success'])) {
                $finalUrl   = $ov['url'];
                $finalPath  = $ov['storage_path'];
                $composited = true;
            } else {
                Log::warning('[ImageIntelligence] overlay compositing failed, keeping background', ['err' => $ov['error'] ?? '?']);
            }
        }
        $url = $finalUrl;

        // asset complete (→ Media Library dual-write of the FINAL image)
        if ($assetId) {
            $this->creative->completeAsset($assetId, [
                'url'          => $finalUrl,
                'storage_path' => $finalPath,
                'width'        => $w,
                'height'       => $h,
                'mime_type'    => 'image/png',
            ]);
            // record background + composite provenance in asset metadata
            try {
                $existingMeta = json_decode((string) DB::table('assets')->where('id', $assetId)->value('metadata_json'), true) ?: [];
                $existingMeta['composited']     = $composited;
                $existingMeta['background_url'] = $backgroundUrl;
                DB::table('assets')->where('id', $assetId)->update(['metadata_json' => json_encode($existingMeta), 'updated_at' => now()]);
            } catch (\Throwable $e) {}
        }

        // canonical settlement — charge for the DELIVERED quality, not the
        // requested one. The runtime can downgrade quality (today it force-caps
        // gpt-image-1 to 'low'); the customer must only pay for what was
        // actually produced. The reservation was the upper bound.
        $reservedCredits = $credits;
        $actualCredits = self::CREDIT_BY_QUALITY[$actualQuality] ?? $reservedCredits;
        if ($actualCredits > $reservedCredits) $actualCredits = $reservedCredits; // never exceed reserved
        if ($actualCredits === $reservedCredits) {
            $this->credits->commit($wsId, $resRef, $reservedCredits);
        } else {
            $this->credits->release($wsId, $resRef);
            if ($actualCredits > 0) {
                $this->credits->debit($wsId, $actualCredits, 'image_intelligence', $assetId, [
                    'reason'            => 'delivered_quality_settlement',
                    'requested_credits' => $reservedCredits,
                    'requested_quality' => $compiled['quality'],
                    'delivered_quality' => $actualQuality,
                ]);
            }
        }
        $credits = $actualCredits; // downstream cost/margin + response reflect the ACTUAL charge

        // 8) pricing/margin capture + audit close
        $pricing = $this->pricing($wsId, $credits, $actualCost);
        $this->auditComplete($jobId, [
            'provider_response' => ['url' => $url, 'quality' => $actualQuality, 'size' => $actualSize, 'model' => $img['model'] ?? 'gpt-image-1', 'duration_ms' => $img['duration_ms'] ?? $elapsedMs],
            'actual_cost_usd'   => $actualCost,
            'credits_charged'   => $credits,
            'pricing'           => $pricing,
            'asset_id'          => $assetId,
        ]);

        return [
            'success'         => true,
            'url'             => $url,
            'composited'      => $composited,
            'background_url'  => $backgroundUrl,
            'asset_id'        => $assetId,
            'blueprint'       => $blueprint,
            'reasoning'       => ['summary' => $reason['reasoning_summary'] ?? '', 'model' => $reason['model'] ?? '', 'fallback' => $reason['fallback'] ?? false],
            'provider_prompt' => $compiled['provider_prompt'],
            'typography_mode' => $compiled['typography']['mode'] ?? 'none',
            'overlay'         => $compiled['overlay'],
            'requested_quality' => $compiled['quality'],
            'actual_quality'  => $actualQuality,
            'quality_downgraded_by_runtime' => $actualQuality !== $compiled['quality'],
            'size'            => $actualSize,
            'cost_usd'        => $actualCost,
            'credits_reserved'=> $reservedCredits,
            'credits_charged' => $credits,
            'pricing'         => $pricing,
            'audit_id'        => $jobId,
            'generation_ms'   => $elapsedMs,
        ];
    }

    private function resolveContext(array $c): array
    {
        $wsId = (int) ($c['workspace_id'] ?? 0);
        // Brand intelligence input — WP2 Phase 2.1C/2.1D-B.
        // PRECEDENCE (per-field): explicit request override (only fields supplied)
        //   > workspace brand (canonical WorkspaceBrandKitResolver, ADR-0014)
        //   > neutral fallback.
        // The merge is field-level and non-destructive: absent/null overrides never
        // erase workspace values; non-overridden workspace fields are retained
        // exactly (no re-derivation). Workspace identity is never overridable
        // (only brand fields are extracted). The two brand stores are NOT merged
        // and stored kit data is never mutated (resolve() is read-only).
        $overrides = $this->extractBrandOverrides($c);
        // RFC-0009 P3 (2026-09-16): the field mapping above now lives in ONE place —
        // BrandContextForCreative — so the image path (here) and the video path
        // (BlueprintService::getVideoBlueprint) cannot drift apart again. Behaviour is
        // identical to the previous inline block (same precedence, same keys).
        $brand = \App\Core\Brand\BrandContextForCreative::fromWorkspace($wsId, $overrides);

        // RFC-0009 P4/P5 — facts the compiler needs to guard the prompt without rewriting intent:
        //   has_logo        — a real logo asset exists in the brand kit (a null logo_url used to be
        //                     dropped by array_filter, so the reasoner was never told "no logo").
        //   logo_requested  — the customer's own words asked for a logo (never strip those).
        //   exact_text      — quoted strings in the request: verbatim customer copy.
        $userPrompt = (string) ($c['user_prompt'] ?? '');
        $hasLogo = is_string($brand['logo_url'] ?? null) && trim($brand['logo_url']) !== '';
        $logoRequested = (bool) preg_match('/\blogo\b/i', $userPrompt);
        // A requested headline/caption/tagline is copy the customer wants WRITTEN — not an invented fact.
        $headlineRequested = (bool) preg_match('/\b(headline|caption|tagline|slogan|title text)\b/i', $userPrompt);
        $exactText = [];
        if (preg_match_all('/["\x{201C}\x{201D}]([^"\x{201C}\x{201D}]{1,80})["\x{201C}\x{201D}]|\x27([^\x27]{2,80})\x27/u', $userPrompt, $m)) {
            foreach (array_merge($m[1], $m[2]) as $t) { $t = trim($t); if ($t !== '') { $exactText[] = $t; } }
        }

        return array_merge([
            'source'      => 'studio',
            'platform'    => null,
            'asset_type'  => 'social_post',
            'style'       => 'natural',
            'requested_quality' => 'auto',
            'include_text_preference' => 'auto',
            'language'    => 'en',
        ], $c, ['brand' => $brand, 'workspace_id' => $wsId, 'has_logo' => $hasLogo, 'logo_requested' => $logoRequested, 'headline_requested' => $headlineRequested, 'exact_text' => array_values(array_unique($exactText)),
            // RFC-0009 P2: a platform agent named in the request is a real identity (registry + DEC-0055 portrait), never invented.
            'subject_reference' => AgentSubjectResolver::resolve($userPrompt)]);
    }

    /**
     * RFC-0009: carry the grounding facts the compiler enforces (has_logo, logo_requested, exact_text)
     * on the blueprint itself, so compile() stays a pure function of its input and the same facts
     * travel with a cached preview (P1). Prefixed with '_' — never part of the LLM contract.
     */
    private function attachCompilerContext(array $blueprint, array $ctx): array
    {
        $blueprint['_context'] = [
            'has_logo'          => (bool) ($ctx['has_logo'] ?? false),
            'logo_requested'    => (bool) ($ctx['logo_requested'] ?? false),
            'headline_requested' => (bool) ($ctx['headline_requested'] ?? false),
            'exact_text'        => array_values((array) ($ctx['exact_text'] ?? [])),
            'subject_reference' => $ctx['subject_reference'] ?? null,
        ];
        return $blueprint;
    }

    /**
     * Extract EXPLICIT request-level brand overrides from the request context.
     * Only present, non-empty, scalar brand fields are returned (normalized to
     * kit keys). Never extracts workspace identity/ownership/authorization — only
     * brand-composition fields — so overrides can never alter tenancy or isolation.
     * Accepts flat keys, the `brand_color` alias, a nested `brand` object, and a
     * `colors[]` array (→ primary/secondary/accent).
     */
    private function extractBrandOverrides(array $c): array
    {
        $direct = ['primary_color','secondary_color','accent_color','voice','tone',
                   'heading_font','body_font','logo_url','brand_name','visual_style'];
        $ov = [];
        $put = function (string $key, $v) use (&$ov) {
            if ($v !== null && $v !== '' && !is_array($v) && !array_key_exists($key, $ov)) $ov[$key] = $v;
        };
        $fromColors = function ($cols) use ($put) {
            if (!is_array($cols)) return;
            $cols = array_values($cols);
            if (isset($cols[0])) $put('primary_color', $cols[0]);
            if (isset($cols[1])) $put('secondary_color', $cols[1]);
            if (isset($cols[2])) $put('accent_color', $cols[2]);
        };
        foreach ($direct as $k) if (array_key_exists($k, $c)) $put($k, $c[$k]);
        if (array_key_exists('brand_color', $c)) $put('primary_color', $c['brand_color']); // alias
        if (!empty($c['brand']) && is_array($c['brand'])) {
            foreach ($direct as $k) if (array_key_exists($k, $c['brand'])) $put($k, $c['brand'][$k]);
            if (array_key_exists('brand_color', $c['brand'])) $put('primary_color', $c['brand']['brand_color']);
            $fromColors($c['brand']['colors'] ?? null);
        }
        $fromColors($c['colors'] ?? null);
        return $ov;
    }

    private function estimateCost(string $size, string $quality, string $prompt): float
    {
        $tok = self::TOKENS[$size][$quality] ?? self::TOKENS['1024x1024']['medium'];
        $inTok = (int) ceil(mb_strlen($prompt) / 4);
        return round($tok * self::OUT_TOKEN_USD + $inTok * self::IN_TOKEN_USD, 5);
    }

    private function pricing(int $wsId, int $credits, float $costUsd): array
    {
        // Reference $/credit — Pro tier ($199 / 900 credits). Used to record a
        // representative gross-margin figure per generation. (The workspace→plan
        // link is not a direct workspaces column here, so we report against the
        // documented reference rate rather than guessing a per-workspace plan.)
        $perCredit = 0.221;
        $sell = round($credits * $perCredit, 4);
        $profit = round($sell - $costUsd, 4);
        return [
            'credits'      => $credits,
            'usd_per_credit' => $perCredit,
            'selling_usd'  => $sell,
            'cost_usd'     => $costUsd,
            'gross_profit' => $profit,
            'margin_pct'   => $sell > 0 ? round($profit / $sell * 100, 1) : 0,
        ];
    }

    private function auditStart(int $wsId, array $ctx, array $bp, array $compiled, array $reason, string $uuid): ?int
    {
        try {
            return DB::table('creative_jobs')->insertGetId([
                'uuid'            => $uuid,
                'workspace_id'    => $wsId,
                'user_id'         => auth()->id(),
                'type'            => 'generation',
                'capability'      => 'image_intelligence',
                'provider'        => 'openai',
                'provider_model'  => 'gpt-image-1',
                'status'          => 'running',
                'original_prompt' => mb_substr((string) ($ctx['user_prompt'] ?? ''), 0, 2000),
                'compiled_prompt' => mb_substr((string) $compiled['provider_prompt'], 0, 4000),
                'generation_spec' => json_encode($bp, JSON_UNESCAPED_SLASHES),
                'provider_request'=> json_encode(['size' => $compiled['size'], 'quality' => $compiled['quality'], 'typography_mode' => $compiled['typography']['mode'] ?? 'none']),
                'metadata'        => json_encode(['source' => $ctx['source'] ?? null, 'platform' => $bp['platform'] ?? null, 'asset_type' => $bp['asset_type'] ?? null, 'reasoning_model' => $reason['model'] ?? null, 'reasoning_summary' => $reason['reasoning_summary'] ?? null, 'fallback' => $reason['fallback'] ?? false]),
                'started_at'      => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
        } catch (\Throwable $e) { Log::warning('[ImageIntelligence] audit start failed: ' . $e->getMessage()); return null; }
    }

    private function auditComplete(?int $jobId, array $data): void
    {
        if (!$jobId) return;
        try {
            DB::table('creative_jobs')->where('id', $jobId)->update([
                'status'            => 'completed',
                'asset_id'          => $data['asset_id'] ?? null,
                'provider_response' => json_encode($data['provider_response'] ?? []),
                'metadata'          => json_encode(array_merge(json_decode(DB::table('creative_jobs')->where('id', $jobId)->value('metadata') ?: '{}', true) ?: [], ['actual_cost_usd' => $data['actual_cost_usd'] ?? null, 'credits_charged' => $data['credits_charged'] ?? null, 'pricing' => $data['pricing'] ?? null])),
                'completed_at'      => now(), 'updated_at' => now(),
            ]);
        } catch (\Throwable $e) { Log::warning('[ImageIntelligence] audit complete failed: ' . $e->getMessage()); }
    }

    private function auditFail(?int $jobId, string $reason, array $raw): void
    {
        if (!$jobId) return;
        try {
            DB::table('creative_jobs')->where('id', $jobId)->update([
                'status' => 'failed',
                'provider_response' => json_encode(['error' => $reason, 'raw' => $raw]),
                'failed_at' => now(), 'updated_at' => now(),
            ]);
        } catch (\Throwable $e) {}
    }

    // ═══════════════════════════════════════════════════════════════════════
    // WAVE 4A — manual Studio image shadow migration (default OFF, no activation)
    // ═══════════════════════════════════════════════════════════════════════

    /** Shadow runs ONLY for the manual Studio route and ONLY when the path-specific
     *  flag is enabled. Default OFF → false → the legacy path is byte-for-byte
     *  unchanged. NOT the cross-engine STUDIO_IMAGE_INTELLIGENCE_ENABLED flag. */
    private function manualImageShadowEnabled(array $ctx): bool
    {
        return (bool) config('studio_image_migration.manual_shadow_enabled', false)
            && ($ctx['source'] ?? '') === 'studio';
    }

    /** Produce the Creative888-brief plan for COMPARISON ONLY. Reads brand/memory
     *  (no writes), compiles the brief technically, records a scoped structured
     *  diff. No provider call, no reserve, no asset, no creative_job, no persistence. */
    private function runManualImageShadow(array $ctx, array $legacyBlueprint, array $legacyCompiled): void
    {
        $brief = app(\App\Engines\Creative\Services\BlueprintService::class)->getImageBlueprint(
            (int) ($ctx['workspace_id'] ?? 0),
            (string) ($ctx['user_prompt'] ?? ''),
            [
                'platform'          => $ctx['platform'] ?? null,
                'asset_type'        => $ctx['asset_type'] ?? 'social_post',
                'style'             => $ctx['style'] ?? null,
                'requested_quality' => $ctx['requested_quality'] ?? 'auto',
            ]
        );
        $shadowCompiled = $this->compiler->compileFromBrief($brief);
        $diff = $this->compareImagePlans($legacyBlueprint, $legacyCompiled, $brief, $shadowCompiled);

        // Scoped audit only — debug log, NOT production-wide telemetry, NOT persisted,
        // NOT billed. Customer-sensitive prompt text is deliberately omitted.
        Log::debug('[Wave4A manual-image shadow]', [
            'workspace_id'     => (int) ($ctx['workspace_id'] ?? 0),
            'source'           => $ctx['source'] ?? null,
            'differing_fields' => $diff['summary']['differing_fields'],
            'regression_risks' => array_keys(array_filter(
                $diff['fields'],
                fn ($f) => !($f['equal'] ?? true) && ($f['classification'] ?? '') === 'regression_risk'
            )),
        ]);
    }

    /**
     * PURE structured comparison of the legacy plan (reasoner blueprint + compiled)
     * vs the Creative888-brief shadow plan (brief + compiled). Classifies each field:
     *   architectural       — intended ownership move (brief carries it as a field)
     *   missing_field       — the shadow brief did not populate it
     *   compiler_difference — differs in the technical compile stage
     *   regression_risk     — MUST match (provider / model / size / quality / typography)
     *   equivalent_wording  — different text, same meaning
     */
    public function compareImagePlans(array $legacyBp, array $legacyCompiled, array $shadowBrief, array $shadowCompiled): array
    {
        $fields = [];
        $add = function (string $key, $legacy, $shadow, string $whenDiffer) use (&$fields): void {
            $ls = is_array($legacy) ? json_encode($legacy) : (string) $legacy;
            $ss = is_array($shadow) ? json_encode($shadow) : (string) $shadow;
            $equal = $ls === $ss;
            $classification = 'equivalent';
            if (!$equal) {
                $classification = ($ss === '' || $ss === '[]' || $ss === 'null') ? 'missing_field' : $whenDiffer;
            }
            $fields[$key] = ['equal' => $equal, 'classification' => $classification];
        };

        $add('provider_prompt',      $legacyCompiled['provider_prompt'] ?? '',   $shadowCompiled['provider_prompt'] ?? '',   'equivalent_wording');
        $add('audience',             $legacyBp['audience'] ?? '',                $shadowBrief['audience'] ?? '',             'architectural');
        $add('subject',              $legacyBp['subject'] ?? '',                 $shadowBrief['subject'] ?? '',              'equivalent_wording');
        $add('composition',          $legacyBp['composition'] ?? '',             $shadowBrief['composition'] ?? '',          'architectural');
        $add('lighting',             $legacyBp['lighting'] ?? '',                $shadowBrief['lighting'] ?? '',             'architectural');
        $add('mood',                 $legacyBp['mood'] ?? '',                    $shadowBrief['mood'] ?? '',                 'architectural');
        $add('brand_application',    $legacyBp['brand_application'] ?? '',       $shadowBrief['brand_application'] ?? '',    'architectural');
        $add('negative_constraints', $legacyBp['negative_constraints'] ?? [],    $shadowBrief['negative_constraints'] ?? [], 'compiler_difference');
        $add('typography_mode',      $legacyCompiled['typography']['mode'] ?? '', $shadowCompiled['typography']['mode'] ?? '', 'regression_risk');
        $add('dimensions',           $legacyCompiled['size'] ?? '',              $shadowCompiled['size'] ?? '',              'regression_risk');
        $add('requested_quality',    $legacyCompiled['quality'] ?? '',           $shadowCompiled['quality'] ?? '',           'regression_risk');
        $add('provider',             $legacyCompiled['provider'] ?? '',          $shadowCompiled['provider'] ?? '',          'regression_risk');
        $add('model',                $legacyCompiled['model'] ?? '',             $shadowCompiled['model'] ?? '',             'regression_risk');

        $differing = array_values(array_keys(array_filter($fields, fn ($f) => !$f['equal'])));
        return [
            'fields'  => $fields,
            'summary' => [
                'total'            => count($fields),
                'differing'        => count($differing),
                'differing_fields' => $differing,
            ],
        ];
    }
}
