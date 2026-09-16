<?php

namespace Tests\Feature\Studio;

use App\Connectors\CreativeConnector;
use App\Connectors\RuntimeClient;
use App\Core\Brand\WorkspaceBrandKitResolver;
use App\Engines\Creative\Services\CreativeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Helpers\Boss888TestHelper;
use Tests\TestCase;

/**
 * RFC-0009 P1 (2026-09-16) — the preview a customer approves IS the prompt the provider receives.
 * No provider is ever called here: the connector is a mock that records the prompt it was handed.
 */
class Rfc0009PreviewIsFinalTest extends TestCase
{
    use RefreshDatabase, Boss888TestHelper;

    private int $ws;
    private int $otherWs;
    private int $reasonCalls = 0;
    private array $handedToProvider = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBoss888();
        $this->ws = (int) $this->testWorkspace->id;
        $other = \App\Models\Workspace::create(['name' => 'RFC-0009 other ws', 'slug' => 'rfc9-other-' . uniqid(), 'created_by' => $this->testUser->id]);
        $this->otherWs = (int) $other->id;
        $resolver = \Mockery::mock(WorkspaceBrandKitResolver::class);
        $resolver->shouldReceive('resolve')->andReturn(['is_neutral' => false, 'brand_name' => 'LevelUpGrowth', 'primary_color' => '#6C5CE7',
            'secondary_color' => '#00E5A8', 'accent_color' => '#F59E0B', 'heading_font' => 'Syne', 'body_font' => 'DM Sans', 'logo_url' => null, 'visual_style' => null, 'voice' => 'professional', 'tone' => 'friendly']);
        $this->app->instance(WorkspaceBrandKitResolver::class, $resolver);

        // The reasoner: a canned blueprint whose headline changes every call, so a second plan is detectable.
        $this->reasonCalls = 0;
        $rt = \Mockery::mock(RuntimeClient::class)->makePartial();
        $rt->shouldReceive('isConfigured')->andReturn(true);
        $rt->shouldReceive('chatJson')->andReturnUsing(function () {
            $this->reasonCalls++;
            $n = $this->reasonCalls;
            return ['success' => true, 'parsed' => [
                'intent' => 'promo', 'subject' => 'Sarah, the AI growth manager', 'audience' => 'owners', 'platform' => 'null', 'asset_type' => 'social_post',
                'aspect_ratio' => '1:1', 'dimensions' => ['width' => 1024, 'height' => 1024], 'composition' => 'portrait', 'scene' => 'office',
                'visual_hierarchy' => 'Sarah first', 'lighting' => 'bright', 'mood' => 'confident', 'color_palette' => ['#6C5CE7'],
                'brand_application' => 'brand colours', 'historical_or_factual_constraints' => [], 'negative_constraints' => [],
                'typography_strategy' => ['mode' => 'baked_in', 'headline' => "Headline number {$n}", 'placement' => 'top', 'style' => ''],
                'provider_prompt' => "Promo image of Sarah, call {$n}", 'quality' => 'medium', 'reasoning_summary' => 's',
            ]];
        });
        $this->app->instance(RuntimeClient::class, $rt);

        // The provider: never called for real; records what it was handed.
        $this->handedToProvider = [];
        $conn = \Mockery::mock(CreativeConnector::class);
        $conn->shouldReceive('generateImage')->andReturnUsing(function ($prompt, $opts) {
            $this->handedToProvider[] = $prompt;
            return ['success' => true, 'url' => 'https://example.test/x.png', 'storage_path' => null, 'width' => 1024, 'height' => 1024, 'file_size' => 10];
        });
        $this->app->instance(CreativeConnector::class, $conn);
    }

    private function svc(): CreativeService { return app(CreativeService::class); }

    public function test_generation_with_a_valid_token_hands_the_provider_exactly_the_previewed_prompt_without_replanning(): void
    {
        $prev = $this->svc()->planPreview($this->ws, ['prompt' => 'Promo of Sarah', 'aspect_ratio' => '1:1']);
        $this->assertSame(1, $this->reasonCalls);
        $this->assertNotEmpty($prev['plan_token']);
        $this->assertSame(CreativeService::PREVIEW_TTL_SECONDS, $prev['plan_expires_in']);

        $gen = $this->svc()->generateImage($this->ws, ['prompt' => 'Promo of Sarah', 'aspect_ratio' => '1:1', 'plan_token' => $prev['plan_token']]);
        $this->assertSame(1, $this->reasonCalls, 'no second plan');
        $this->assertTrue($gen['preview_bound']);
        $this->assertCount(1, $this->handedToProvider);
        $this->assertSame($prev['enhanced_prompt'], $this->handedToProvider[0], 'provider got the previewed prompt, byte for byte');
        $this->assertSame(sha1($prev['enhanced_prompt']), $gen['provider_prompt_sha']);
        $meta = json_decode((string) DB::table('assets')->where('id', $gen['asset_id'])->value('metadata_json'), true) ?: [];
        $this->assertTrue($meta['preview_bound'] ?? false);
        $this->assertSame($prev['plan_token'], $meta['plan_token'] ?? null);
    }

    public function test_a_changed_prompt_or_setting_is_refused_with_preview_required_never_replanned(): void
    {
        $prev = $this->svc()->planPreview($this->ws, ['prompt' => 'Promo of Sarah', 'aspect_ratio' => '1:1']);
        $r = $this->svc()->generateImage($this->ws, ['prompt' => 'Promo of Sarah with a dog', 'aspect_ratio' => '1:1', 'plan_token' => $prev['plan_token']]);
        $this->assertFalse($r['success']); $this->assertSame('PREVIEW_REQUIRED', $r['code']); $this->assertSame('stale', $r['reason']); $this->assertContains('prompt', $r['changed']);
        $r2 = $this->svc()->generateImage($this->ws, ['prompt' => 'Promo of Sarah', 'aspect_ratio' => '9:16', 'plan_token' => $prev['plan_token']]);
        $this->assertSame('PREVIEW_REQUIRED', $r2['code']); $this->assertContains('aspect_ratio', $r2['changed']);
        $this->assertSame(1, $this->reasonCalls, 'a refused generate never plans');
        $this->assertSame([], $this->handedToProvider, 'and never reaches the provider');
        $this->assertSame(0, DB::table('assets')->where('workspace_id', $this->ws)->count(), 'no asset row for a refusal');
    }

    public function test_a_token_from_another_workspace_or_an_expired_token_is_refused(): void
    {
        $prev = $this->svc()->planPreview($this->ws, ['prompt' => 'Promo of Sarah', 'aspect_ratio' => '1:1']);
        $foreign = $this->svc()->generateImage($this->otherWs, ['prompt' => 'Promo of Sarah', 'aspect_ratio' => '1:1', 'plan_token' => $prev['plan_token']]);
        $this->assertSame('PREVIEW_REQUIRED', $foreign['code']); $this->assertSame('expired_or_foreign', $foreign['reason']);
        Cache::forget('studio:plan:' . $this->ws . ':' . $prev['plan_token']);
        $expired = $this->svc()->generateImage($this->ws, ['prompt' => 'Promo of Sarah', 'aspect_ratio' => '1:1', 'plan_token' => $prev['plan_token']]);
        $this->assertSame('PREVIEW_REQUIRED', $expired['code']);
        $this->assertSame([], $this->handedToProvider);
    }

    public function test_without_a_token_the_single_plan_path_still_works_for_agents_and_api_callers(): void
    {
        $gen = $this->svc()->generateImage($this->ws, ['prompt' => 'Promo of Sarah', 'aspect_ratio' => '1:1']);
        $this->assertSame(1, $this->reasonCalls);
        $this->assertFalse($gen['preview_bound']);
        $this->assertCount(1, $this->handedToProvider);
        $this->assertStringContainsString('Headline number 1', $this->handedToProvider[0]);
    }
}
