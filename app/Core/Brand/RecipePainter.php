<?php

namespace App\Core\Brand;

use App\Connectors\RuntimeClient;
use App\Core\Billing\CreditService;
use App\Engines\Creative\Services\CreativeService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * RECIPE-1 (Owner 2026-10-01). When a business has chosen its design looks, a social image is made from one of them:
 * the recipe's strict prompt is filled from the brand kit, Sarah's copy and the customer's variables, painted by the image
 * model at high quality, and the words on it are read back by vision. Wrong words: one regeneration at our cost, then
 * the renderer path takes over (the caller falls through). The reference images are never involved.
 */
final class RecipePainter
{
    private const CREDITS = ['low' => 1, 'medium' => 2, 'high' => 4];
    private const AFFINITY = [
        'offer banner' => '/\b(offer|sale|discount|promo|deal|% off|percent off|save|free|special|price)\b/i',
        'quote card' => '/\b(quote|saying|words of|inspir|motivat)\b/i',
        'checklist/tips' => '/\b(tips?|steps|ways|things to|checklist|how to|guide|reasons)\b/i',
        'testimonial' => '/\b(review|testimonial|client said|customer said|feedback|stars?)\b/i',
        'before-after' => '/\b(before|after|transformation|results?|makeover)\b/i',
        'event poster' => '/\b(event|workshop|class|session|webinar|open day|launch party|join us|this (friday|saturday|sunday|weekend))\b/i',
        'announcement' => '/\b(announc|now open|opening|new (location|branch|menu|service|hours)|introducing|launch)\b/i',
        'menu/price card' => '/\b(menu|price list|pricing|packages?|rates)\b/i',
        'team/portrait' => '/\b(meet|team|our chef|our staff|founder|welcome .* to the team)\b/i',
        'product hero' => '/\b(product|dish|item|collection|new arrival|bestseller|featured)\b/i',
        'listing card' => '/\b(listing|for sale|for rent|just listed|property|bedroom|sqm|sq ft)\b/i',
    ];

    public function __construct(private RuntimeClient $runtime, private CreditService $credits, private CreativeService $creative) {}

    /** The finished result in the image service's own shape, or null when the renderer path should run. */
    public function attempt(array $ctx): ?array
    {
        $wsId = (int) ($ctx['workspace_id'] ?? 0); $prompt = trim((string) ($ctx['user_prompt'] ?? ''));
        if ($wsId <= 0 || $prompt === '') return null;
        if (! empty($ctx['force_typography_mode']) || ! empty($ctx['retry_of_media_id']) || ! empty($ctx['no_recipe']) || ! empty($ctx['article_id'])) return null;
        $at = strtolower((string) ($ctx['asset_type'] ?? 'social_post'));
        if (! in_array($at, ['social_post', 'banner', 'story', 'post', 'ad', 'social'], true) && ! preg_match('/\b(post|banner|story|stories|instagram|facebook|linkedin|social)\b/i', $prompt)) return null;
        if (preg_match('/\b(featured image|blog|article|hero image|website|logo)\b/i', $prompt)) return null;

        $profiles = app(BrandProfileService::class);
        $bizId = (int) ($ctx['business_id'] ?? 0) ?: null;
        $biz = $profiles->business($wsId, $bizId); $bizId = $biz->id ?? $bizId;
        $row = $profiles->row($wsId, $biz, false); if (! $row) return null;
        $dirs = BrandProfileService::json($row, 'directions_json');
        $ids = array_values(array_filter(array_map('intval', (array) ($dirs['recipes'] ?? []))));
        if (! $ids) return null;
        $recipes = DB::table('design_recipes')->whereIn('id', $ids)->where('active', true)->get()->keyBy('id');
        if ($recipes->isEmpty()) return null;
        $recipe = $this->choose(array_values(array_filter(array_map(fn ($id) => $recipes[$id] ?? null, $ids))), $prompt);
        $brand = (array) ($ctx['brand'] ?? []);
        if (empty($brand['brand_name'])) $brand['brand_name'] = $biz->name ?? 'the business';
        try { $kit = app(WorkspaceBrandKitResolver::class)->resolve($wsId, $bizId); $brand += ['target_audience' => $kit['target_audience'] ?? null, 'primary_color' => $kit['primary_color'] ?? null, 'secondary_color' => $kit['secondary_color'] ?? null, 'accent_color' => $kit['accent_color'] ?? null]; if (empty($brand['heading_font'])) $brand['heading_font'] = $kit['heading_font'] ?? null; if (empty($brand['industry'])) $brand['industry'] = $kit['industry'] ?? null; } catch (\Throwable) {}

        $rj = json_decode((string) $recipe->recipe_json, true) ?: [];
        $maxWords = (int) ((($rj['typography'] ?? [])['max_words_headline'] ?? 5)) ?: 5;
        $copy = $this->copy($wsId, $ctx, $brand, $prompt, $maxWords, ! empty((($rj['typography'] ?? [])['subhead'] ?? [])['present']));
        $vars = RecipeVariables::resolve($wsId, $bizId, $recipe, $prompt, $brand);
        $platform = strtolower((string) ($ctx['platform'] ?? ''));
        $format = str_contains($prompt, 'story') || $platform === 'story' ? 'story_9_16' : ($platform === 'facebook' && $recipe->format === 'portrait_4_5' ? 'portrait_4_5' : (string) $recipe->format);
        $filled = RecipePromptCompiler::fill($recipe, $brand, $copy, $vars['values'], $format);

        // credits: high quality, reserved up front; the renderer path is the fallback when we cannot reserve
        $reserved = self::CREDITS['high'];
        try { $resRef = $this->credits->reserve($wsId, $reserved, 'image_recipe:' . ($ctx['source'] ?? 'creative')); } catch (\Throwable $e) { return null; }

        $jobUuid = (string) Str::uuid(); $t0 = microtime(true);
        $jobId = $this->auditStart($wsId, $ctx, $recipe, $filled, $copy, $vars, $jobUuid);
        $asset = $this->creative->createAsset($wsId, [
            'type' => 'image', 'title' => mb_substr($prompt, 0, 120), 'prompt' => $filled['prompt'], 'aspect_ratio' => $format === 'square' ? '1:1' : ($format === 'story_9_16' ? '9:16' : '4:5'), 'quality' => 'high',
            'metadata' => ['original_prompt' => $prompt, 'source' => $ctx['source'] ?? 'creative', 'platform' => $ctx['platform'] ?? null, 'asset_type' => $at, 'typography_mode' => 'baked_in', 'enhanced' => true, 'creative_job' => $jobUuid,
                'recipe_id' => (int) $recipe->id, 'recipe_title' => (string) $recipe->title, 'business_id' => $bizId,
                'blueprint' => ['subject' => $prompt, 'color_palette' => array_values(array_filter([$brand['colors']['primary'] ?? null, $brand['colors']['accent'] ?? null])), 'typography_strategy' => ['mode' => 'baked_in', 'headline' => $copy['headline'], 'supporting_copy' => array_values(array_filter([$copy['subhead'] ?? ''])), 'style' => (string) ((($rj['typography'] ?? [])['headline'] ?? [])['family_feel'] ?? ''), 'placement' => (string) ((($rj['layout'] ?? [])['text_zone'] ?? ''))], '_context' => ['recipe_id' => (int) $recipe->id]]],
        ]);
        $assetId = $asset['asset_id'] ?? null;
        if ($assetId) DB::table('assets')->where('id', $assetId)->update(['status' => 'generating', 'updated_at' => now()]);

        $img = null; $attempts = 0; $verified = false; $seen = []; $tried = [];
        $p = $filled['prompt']; $textList = $filled['text_list'];
        for ($attempts = 1; $attempts <= 2; $attempts++) {
            $img = $this->runtime->imageGenerate($p, ['workspace_id' => $wsId, 'style' => $ctx['style'] ?? 'natural', 'size' => $filled['size'], 'quality' => 'high']);
            if (empty($img['success']) || empty($img['url'])) break;
            $tried[] = ['url' => $img['url'], 'quality' => $img['quality'] ?? null];
            [$verified, $seen, $failedOn] = $this->verify((string) $img['url'], $textList);
            if ($verified) break;
            Log::info('[RECIPE-1] words not verified, regenerating once at our cost', ['ws' => $wsId, 'recipe' => $recipe->id, 'expected' => $textList, 'seen' => $seen, 'failed_on' => $failedOn, 'url' => $img['url'], 'quality' => $img['quality'] ?? null]);
            // only the subhead was wrong: the second try leaves it out (headline + brand name are what must be exact)
            if ($failedOn === 'subhead' && ! empty($copy['subhead'])) { $copy['subhead'] = ''; $filled = RecipePromptCompiler::fill($recipe, $brand, $copy, $vars['values'], $format); $textList = $filled['text_list']; $p = $filled['prompt']; }
            else $p = $filled['prompt'] . ' Spell every listed word exactly, letter by letter; check each word twice before finishing.';
        }
        $elapsedMs = (int) round((microtime(true) - $t0) * 1000);
        if (empty($img['success']) || empty($img['url']) || ! $verified) {
            $this->credits->release($wsId, $resRef); $this->auditFail($jobId, $verified ? ($img['error'] ?? 'provider_failed') : 'text_not_verified', ['seen' => $seen]);
            if ($assetId) DB::table('assets')->where('id', $assetId)->update(['status' => 'failed', 'updated_at' => now()]);
            Log::info('[RECIPE-1] falling back to the renderer path', ['ws' => $wsId, 'recipe' => $recipe->id, 'verified' => $verified, 'error' => $img['error'] ?? null]);
            return null;
        }
        $actualQuality = strtolower((string) ($img['quality'] ?? 'high')); $actualSize = (string) ($img['size'] ?? $filled['size']);
        [$w, $h] = array_map('intval', array_pad(explode('x', $actualSize), 2, 1024));
        if ($assetId) {
            $this->creative->completeAsset($assetId, ['url' => $img['url'], 'storage_path' => $img['storage_path'] ?? null, 'width' => $w, 'height' => $h, 'mime_type' => 'image/png']);
            try { $m = json_decode((string) DB::table('assets')->where('id', $assetId)->value('metadata_json'), true) ?: []; $m['text_verified'] = true; $m['attempts'] = $attempts; $m['words_seen'] = array_slice($seen, 0, 40); DB::table('assets')->where('id', $assetId)->update(['metadata_json' => json_encode($m, JSON_UNESCAPED_UNICODE), 'updated_at' => now()]); } catch (\Throwable) {}
        }
        $actual = min($reserved, self::CREDITS[$actualQuality] ?? $reserved);
        if ($actual === $reserved) $this->credits->commit($wsId, $resRef, $reserved);
        else { $this->credits->release($wsId, $resRef); if ($actual > 0) $this->credits->debit($wsId, $actual, 'image_recipe', $assetId, ['reason' => 'delivered_quality_settlement', 'requested_credits' => $reserved, 'delivered_quality' => $actualQuality]); }
        $this->auditComplete($jobId, ['url' => $img['url'], 'quality' => $actualQuality, 'size' => $actualSize, 'attempts' => $attempts, 'credits' => $actual, 'asset_id' => $assetId]);
        return [
            'success' => true, 'url' => $img['url'], 'composited' => false, 'background_url' => $img['url'], 'asset_id' => $assetId,
            'blueprint' => ['subject' => $prompt, 'typography_strategy' => ['mode' => 'baked_in', 'headline' => $copy['headline']], '_context' => ['recipe_id' => (int) $recipe->id]],
            'reasoning' => ['summary' => 'Made from the chosen design look "' . $recipe->title . '".', 'model' => 'recipe', 'fallback' => false],
            'provider_prompt' => $filled['prompt'], 'typography_mode' => 'baked_in', 'overlay' => null,
            'requested_quality' => 'high', 'actual_quality' => $actualQuality, 'quality_downgraded_by_runtime' => $actualQuality !== 'high', 'size' => $actualSize,
            'cost_usd' => null, 'credits_reserved' => $reserved, 'credits_charged' => $actual, 'pricing' => null, 'audit_id' => $jobId, 'generation_ms' => $elapsedMs,
            'recipe' => ['id' => (int) $recipe->id, 'title' => (string) $recipe->title], 'text_verified' => true, 'attempts' => $attempts,
            'ask_preferences' => $vars['note'],
        ];
    }

    private function choose(array $recipes, string $prompt): object
    {
        foreach (self::AFFINITY as $arch => $re) { if (preg_match($re, $prompt)) { foreach ($recipes as $r) if ($r->archetype === $arch) return $r; } }
        return $recipes[0];
    }

    /** Sarah's copy for this post: the customer's exact words when given, else written in the brand voice. */
    private function copy(int $wsId, array $ctx, array $brand, string $prompt, int $maxWords, bool $wantSub): array
    {
        $exact = array_values(array_filter(array_map('strval', (array) ($ctx['exact_text'] ?? []))));
        $forced = trim((string) ($ctx['forced_headline'] ?? ''));
        if ($forced !== '' || $exact) return ['headline' => $forced !== '' ? $forced : $exact[0], 'subhead' => $exact[1] ?? ''];
        $fallback = ['headline' => ucwords(implode(' ', array_slice(preg_split('/\s+/', preg_replace('/[^\w\s]/', '', $prompt)) ?: [], 0, min(4, $maxWords)))), 'subhead' => ''];
        try {
            if (! $this->runtime->isConfigured()) return $fallback;
            $sys = "You write the words that go ON a social media image for " . ($brand['brand_name'] ?? 'a business') . " (" . ($brand['industry'] ?? 'small business') . "). Voice: " . ($brand['voice'] ?? 'professional yet warm') . ". Return ONLY json: {\"headline\": \"at most {$maxWords} words, plain words, no emoji, no quotes, no hashtags, no punctuation at the end\", \"subhead\": \"" . ($wantSub ? 'one short line of at most 7 words, or an empty string' : 'an empty string') . "\"}. Never mention companies other than the business.";
            $r = $this->runtime->chatJson($sys, 'The post is about: ' . $prompt . "\nWrite the json now.", ['task' => 'recipe_copy', 'workspace_id' => (string) $wsId], 200);
            $h = trim((string) (($r['parsed']['headline'] ?? '')), " \t\"'.!"); $s = trim((string) (($r['parsed']['subhead'] ?? '')), " \t\"'");
            if ($h === '') return $fallback;
            $hw = preg_split('/\s+/', $h) ?: []; if (count($hw) > $maxWords) $h = implode(' ', array_slice($hw, 0, $maxWords));
            return ['headline' => $h, 'subhead' => $wantSub ? mb_substr($s, 0, 60) : ''];
        } catch (\Throwable) { return $fallback; }
    }

    /** Every listed word must be readable on the image. @return array{0:bool,1:array,2:?string} which element failed: headline|subhead|brand */
    private function verify(string $url, array $textList): array
    {
        try {
            $v = $this->runtime->visionAnalyze('Transcribe every piece of text visible in this image, exactly as written, including the brand name. Return ONLY a JSON array of strings, one per line of text. If there is no text, return [].', '', $url);
            $raw = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', (string) ($v['analysis'] ?? ''));
            $arr = preg_match('/\[.*\]/s', (string) $raw, $m) ? (json_decode($m[0], true) ?: []) : [];
            $seenWords = []; foreach ((array) $arr as $line) foreach (preg_split('/[^\p{L}\p{N}\']+/u', strtolower((string) $line)) ?: [] as $w) if ($w !== '') $seenWords[$w] = 1;
            if (! $seenWords) return [false, [], 'headline'];
            $n = count($textList);
            foreach ($textList as $i => $t) {
                $isBrand = $i === $n - 1; $label = $isBrand ? 'brand' : ($i === 0 ? 'headline' : 'subhead');
                $words = array_values(array_filter(preg_split('/[^\p{L}\p{N}\']+/u', strtolower($t)) ?: [], fn ($w) => mb_strlen($w) > 1 || is_numeric($w)));
                $need = $isBrand ? array_slice($words, 0, 1) : $words;   // the brand name: its first word is enough
                foreach ($need as $w) if (! isset($seenWords[$w]) && ! isset($seenWords[rtrim($w, 's')]) && ! isset($seenWords[$w . 's'])) return [false, array_keys($seenWords), $label];
            }
            return [true, array_keys($seenWords), null];
        } catch (\Throwable $e) { return [false, [], 'headline']; }
    }

    private function auditStart(int $wsId, array $ctx, object $recipe, array $filled, array $copy, array $vars, string $uuid): ?int
    {
        try {
            return DB::table('creative_jobs')->insertGetId([
                'uuid' => $uuid, 'workspace_id' => $wsId, 'user_id' => auth()->id(), 'type' => 'generation', 'capability' => 'image_recipe', 'provider' => 'openai', 'provider_model' => 'gpt-image-1', 'status' => 'running',
                'original_prompt' => mb_substr((string) ($ctx['user_prompt'] ?? ''), 0, 2000), 'compiled_prompt' => mb_substr($filled['prompt'], 0, 4000),
                'generation_spec' => json_encode(['recipe_id' => (int) $recipe->id, 'recipe_title' => $recipe->title, 'copy' => $copy, 'variables' => $vars['values'], 'missing' => $vars['missing']], JSON_UNESCAPED_UNICODE),
                'provider_request' => json_encode(['size' => $filled['size'], 'quality' => 'high', 'typography_mode' => 'baked_in']),
                'metadata' => json_encode(['source' => $ctx['source'] ?? null, 'platform' => $ctx['platform'] ?? null, 'asset_type' => $ctx['asset_type'] ?? null, 'path' => 'recipe']),
                'started_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
        } catch (\Throwable $e) { Log::warning('[RECIPE-1] audit start failed: ' . $e->getMessage()); return null; }
    }
    private function auditComplete(?int $jobId, array $d): void
    {
        if (! $jobId) return;
        try { DB::table('creative_jobs')->where('id', $jobId)->update(['status' => 'completed', 'asset_id' => $d['asset_id'] ?? null, 'provider_response' => json_encode($d), 'completed_at' => now(), 'updated_at' => now()]); } catch (\Throwable) {}
    }
    private function auditFail(?int $jobId, string $reason, array $raw): void
    {
        if (! $jobId) return;
        try { DB::table('creative_jobs')->where('id', $jobId)->update(['status' => 'failed', 'provider_response' => json_encode(['error' => $reason, 'raw' => $raw]), 'failed_at' => now(), 'updated_at' => now()]); } catch (\Throwable) {}
    }
}
