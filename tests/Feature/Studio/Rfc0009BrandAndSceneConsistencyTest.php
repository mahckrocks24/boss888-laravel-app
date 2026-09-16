<?php

namespace Tests\Feature\Studio;

use App\Connectors\RuntimeClient;
use App\Core\Brand\BrandContextForCreative;
use App\Core\Brand\WorkspaceBrandKitResolver;
use App\Core\ImageIntelligence\ImageIntelligenceService;
use App\Engines\Creative\Services\BlueprintService;
use App\Engines\Creative\Services\ScenePlannerService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * RFC-0009 P3 + P6 (2026-09-16) — image and video read ONE authoritative brand context,
 * and the video blueprint and scene plan cannot disagree.
 */
class Rfc0009BrandAndSceneConsistencyTest extends TestCase
{
    private function kit(): array
    {
        return ['is_neutral' => false, 'brand_name' => 'LevelUpGrowth', 'primary_color' => '#6C5CE7', 'secondary_color' => '#00E5A8',
            'accent_color' => '#F59E0B', 'heading_font' => 'Syne', 'body_font' => 'DM Sans', 'logo_url' => null,
            'visual_style' => null, 'voice' => 'professional', 'tone' => 'friendly'];
    }

    private function fakeKit(): void
    {
        $resolver = \Mockery::mock(WorkspaceBrandKitResolver::class);
        $resolver->shouldReceive('resolve')->andReturn($this->kit());
        $this->app->instance(WorkspaceBrandKitResolver::class, $resolver);
    }

    public function test_image_and_video_consume_the_same_brand_context(): void
    {
        $this->fakeKit();
        $wsId = 424242; // no creative_brand_identities row → previously the video path invented "professional/professional"
        DB::table('creative_brand_identities')->where('workspace_id', $wsId)->delete();

        $rt = \Mockery::mock(RuntimeClient::class)->makePartial();
        $rt->shouldReceive('isConfigured')->andReturn(false); // image reasoning → deterministic fallback, no LLM
        $this->app->instance(RuntimeClient::class, $rt);

        $img = app(ImageIntelligenceService::class)->plan(['workspace_id' => $wsId, 'user_prompt' => 'A promo image', 'source' => 'studio']);
        $vid = app(BlueprintService::class)->getVideoBlueprint($wsId, 'A promo video', ['duration' => 10]);

        $this->assertSame($img['context']['brand'], $vid['brand'], 'image context brand and video blueprint brand must be the same array');
        $this->assertSame('friendly', $vid['brand']['tone']);
        $this->assertStringContainsString('Tone: friendly', $vid['brand_context']);
        $this->assertStringContainsString('Brand: LevelUpGrowth', $vid['brand_context']);
        $this->assertStringContainsString('Logo asset: none', $vid['brand_context']);
        $this->assertFalse($vid['has_logo']);
        $this->assertFalse($img['context']['has_logo']);
    }

    public function test_creative_brand_identity_row_is_an_override_layer_for_both_paths(): void
    {
        $this->fakeKit();
        $wsId = 424243;
        DB::table('creative_brand_identities')->where('workspace_id', $wsId)->delete();
        DB::table('creative_brand_identities')->insert(['workspace_id' => $wsId, 'voice' => 'playful', 'tone' => 'bold', 'visual_style' => 'neon', 'created_at' => now(), 'updated_at' => now()]);
        try {
            $vid = app(BlueprintService::class)->getVideoBlueprint($wsId, 'A promo video', ['duration' => 10]);
            $this->assertSame('bold', $vid['brand']['tone']);
            $this->assertSame('neon', $vid['brand']['visual_style']);
            $this->assertSame('LevelUpGrowth', $vid['brand']['brand_name'], 'kit fields the row does not carry stay');
            $this->assertSame('neon', $vid['style_additions']);
        } finally {
            DB::table('creative_brand_identities')->where('workspace_id', $wsId)->delete();
        }
    }

    public function test_scene_count_rule_is_shared_and_durations_add_up(): void
    {
        $this->assertSame(ScenePlannerService::sceneCountFor(10), app(BlueprintService::class)->getVideoBlueprint(424244, 'x', ['duration' => 10])['scene_count']);
        $this->assertSame(1, ScenePlannerService::sceneCountFor(10), 'launch cap');

        $norm = ScenePlannerService::normaliseScenes([
            ['index' => 1, 'duration' => 4, 'prompt' => 'a'], ['index' => 1, 'duration' => 4, 'prompt' => 'b'],
        ], 10);
        $this->assertSame([1, 2], array_column($norm, 'index'));
        $this->assertSame(10, array_sum(array_column($norm, 'duration')));

        $one = ScenePlannerService::normaliseScenes([['index' => 3, 'duration' => 25, 'prompt' => 'only']], 10);
        $this->assertSame(1, count($one));
        $this->assertSame(10, $one[0]['duration']);
        $this->assertSame(1, $one[0]['index']);
        $this->assertSame([], ScenePlannerService::normaliseScenes([['duration' => 5, 'prompt' => '']], 10), 'empty prompts are dropped');
    }

    public function test_plan_scenes_normalises_what_the_model_returns_and_passes_brand_and_logo_rules(): void
    {
        $captured = [];
        $rt = \Mockery::mock(RuntimeClient::class)->makePartial();
        $rt->shouldReceive('isConfigured')->andReturn(true);
        $rt->shouldReceive('chatJson')->andReturnUsing(function ($system, $user) use (&$captured) {
            $captured = [$system, $user];
            return ['success' => true, 'parsed' => ['scenes' => [['index' => 7, 'duration' => 30, 'prompt' => 'Sarah in the office', 'camera' => 'static', 'description' => 'd']]]];
        });
        $this->app->instance(RuntimeClient::class, $rt);

        $scenes = app(ScenePlannerService::class)->planScenes('Intro Sarah', ['duration' => 10, 'brand_context' => 'Brand: LevelUpGrowth. Tone: friendly. Logo asset: none — do not depict a logo.', 'has_logo' => false]);
        $this->assertCount(1, $scenes);
        $this->assertSame(10, $scenes[0]['duration']);
        $this->assertSame(1, $scenes[0]['index']);
        $this->assertStringContainsString('NO logo asset', $captured[0]);
        $this->assertStringContainsString('never invent metrics', $captured[0]);
        $this->assertStringContainsString('Brand context: Brand: LevelUpGrowth', $captured[1]);
    }

    public function test_to_prose_is_deterministic_and_invents_nothing(): void
    {
        $this->assertSame('Logo asset: none — do not depict a logo.', BrandContextForCreative::toProse([]));
        $p = BrandContextForCreative::toProse(['brand_name' => 'X', 'tone' => 'warm', 'colors' => ['#111'], 'logo_url' => 'https://x/l.png']);
        $this->assertSame('Brand: X. Tone: warm. Brand colors: #111. Logo asset: available.', $p);
    }
}
