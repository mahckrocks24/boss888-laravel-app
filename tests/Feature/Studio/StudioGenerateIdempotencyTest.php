<?php

namespace Tests\Feature\Studio;

use App\Connectors\RuntimeClient;
use App\Core\Billing\FeatureGateService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Helpers\Boss888TestHelper;
use Tests\TestCase;

/**
 * STUDIO888 P2 BUG-001 — Studio Generate idempotency.
 *
 * The Executive Acceptance Test proved that duplicate-clicking Studio "Generate
 * Image" produced two assets / two creative_jobs / two media rows / two charges.
 * This suite pins the fix on the flagship route POST /api/studio/ai/generate-image:
 * one user intent must produce exactly ONE completed generation; a duplicate
 * submission reserves no credits, calls no provider, and creates no side effects.
 */
class StudioGenerateIdempotencyTest extends TestCase
{
    // NOTE: DatabaseTransactions (not RefreshDatabase) is used deliberately —
    // migrate:fresh is currently BLOCKED platform-wide by an unrelated broken
    // migration in another work package (Engineer888:
    // 2026_08_01_120000_create_engineering_deployment_tables.php — index name
    // exceeds MySQL's 64-char limit). This suite runs against the already-migrated
    // levelup_test schema and rolls back per test. Restore RefreshDatabase once
    // that migration is fixed by its owner.
    use DatabaseTransactions, Boss888TestHelper;

    private int $imageCalls = 0;
    private bool $failFirstImage = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBoss888();
        Http::fake(['*' => Http::response('', 200, ['Content-Length' => '204800'])]);

        // Plan gate open (the route calls FeatureGateService::canUseAI first).
        $gate = \Mockery::mock(FeatureGateService::class)->makePartial();
        $gate->shouldReceive('canUseAI')->andReturn(true);
        $this->app->instance(FeatureGateService::class, $gate);

        $this->fakeRuntime();
    }

    private function fakeRuntime(): void
    {
        $this->imageCalls = 0;
        $rt = \Mockery::mock(RuntimeClient::class)->makePartial();
        $rt->shouldReceive('isConfigured')->andReturn(true);
        $rt->shouldReceive('aiRun')->andReturn(['text' => '']);
        // Force the deterministic brand-aware fallback (no live runtime call) so
        // the test never touches the network and runs fast + repeatably.
        $rt->shouldReceive('chatJson')->andReturn(['success' => false, 'error' => 'test_forced_fallback']);
        $rt->shouldReceive('imageGenerate')->andReturnUsing(function () {
            $this->imageCalls++;
            if ($this->failFirstImage && $this->imageCalls === 1) {
                return ['success' => false, 'error' => 'provider_500'];
            }
            return ['success' => true, 'url' => 'https://cdn.test/i' . $this->imageCalls . '.png',
                    'size' => '1024x1024', 'quality' => 'low', 'storage_path' => 'ai-images/1/i.png',
                    'model' => 'gpt-image-1', 'provider' => 'openai'];
        });
        $this->app->instance(RuntimeClient::class, $rt);
    }

    private function gen(array $payload, array $headers = [])
    {
        return $this->postJson('/api/studio/ai/generate-image', $payload, $this->authHeaders() + $headers);
    }

    private function imageAssets(): int
    {
        return DB::table('assets')->where('workspace_id', $this->testWorkspace->id)->where('type', 'image')->count();
    }
    private function jobs(): int
    {
        return DB::table('creative_jobs')->where('workspace_id', $this->testWorkspace->id)->count();
    }

    /** @test */
    public function double_click_identical_request_generates_exactly_once(): void
    {
        $p = ['prompt' => 'Chef Red plated signature dish', 'platform' => 'instagram', 'asset_type' => 'social_post'];

        $r1 = $this->gen($p);
        $r1->assertOk();
        $this->assertTrue($r1->json('success'));
        $this->assertNotTrue($r1->json('idempotent_replay'));

        $r2 = $this->gen($p); // rapid duplicate — same payload, no client key
        $r2->assertOk();
        $this->assertTrue($r2->json('idempotent_replay'), 'second identical submit must replay');

        $this->assertSame(1, $this->imageCalls, 'provider called exactly once');
        $this->assertSame(1, $this->imageAssets(), 'exactly one image asset');
        $this->assertSame(1, $this->jobs(), 'exactly one creative_job');
        $this->assertSame($r1->json('asset_id'), $r2->json('asset_id'), 'same asset replayed');
    }

    /** @test */
    public function triple_click_generates_exactly_once(): void
    {
        $p = ['prompt' => 'Chef Red triple click test', 'platform' => 'instagram', 'asset_type' => 'social_post'];
        $this->gen($p)->assertOk();
        $this->gen($p)->assertOk();
        $this->gen($p)->assertOk();
        $this->assertSame(1, $this->imageCalls);
        $this->assertSame(1, $this->imageAssets());
        $this->assertSame(1, $this->jobs());
    }

    /** @test */
    public function explicit_idempotency_key_dedupes(): void
    {
        $p = ['prompt' => 'anything', 'platform' => 'instagram'];
        $h = ['Idempotency-Key' => 'client-key-001'];
        $this->gen($p, $h)->assertOk();
        $r2 = $this->gen(['prompt' => 'a DIFFERENT prompt entirely'], $h); // same key, different body
        $r2->assertOk();
        $this->assertTrue($r2->json('idempotent_replay'), 'same client key must replay regardless of body');
        $this->assertSame(1, $this->imageCalls);
        $this->assertSame(1, $this->imageAssets());
    }

    /** @test */
    public function different_prompts_generate_separately(): void
    {
        $this->gen(['prompt' => 'first unique dish', 'platform' => 'instagram'])->assertOk();
        $this->gen(['prompt' => 'second unique dish', 'platform' => 'instagram'])->assertOk();
        $this->assertSame(2, $this->imageCalls, 'distinct intents each generate');
        $this->assertSame(2, $this->imageAssets());
    }

    /** @test */
    public function in_progress_identical_request_returns_409_and_does_nothing(): void
    {
        $wsId = $this->testWorkspace->id;
        $key  = 'inflight-key-001';
        $lock = 'studio_gen:' . $wsId . ':' . md5($key);

        // Hold the lock from a SEPARATE MySQL session (simulates the in-flight winner).
        config(['database.connections.mysql_lockholder' => config('database.connections.mysql')]);
        $holder = DB::connection('mysql_lockholder');
        $got = (int) $holder->selectOne('SELECT GET_LOCK(?, 0) AS l', [$lock])->l;
        $this->assertSame(1, $got, 'precondition: lock acquired by holder session');

        try {
            $resp = $this->gen(['prompt' => 'blocked dish'], ['Idempotency-Key' => $key]);
            $resp->assertStatus(409);
            $this->assertSame('in_progress', $resp->json('status'));
            $this->assertSame(0, $this->imageCalls, 'duplicate must NOT call the provider');
            $this->assertSame(0, $this->imageAssets(), 'duplicate must NOT create an asset');
            $this->assertSame(0, $this->jobs(), 'duplicate must NOT create a creative_job');
        } finally {
            $holder->selectOne('SELECT RELEASE_LOCK(?) AS r', [$lock]);
            $holder->disconnect();
        }
    }

    /** @test */
    public function provider_failure_allows_a_fresh_retry(): void
    {
        $this->failFirstImage = true;
        $p = ['prompt' => 'retry after failure', 'platform' => 'instagram'];

        $r1 = $this->gen($p);           // provider fails -> not stamped
        $this->assertFalse((bool) $r1->json('success'));

        $r2 = $this->gen($p);           // identical retry must be allowed (no replay of a failure)
        $r2->assertOk();
        $this->assertTrue($r2->json('success'));
        $this->assertNotTrue($r2->json('idempotent_replay'), 'a failed attempt must not be replayed');
        $this->assertSame(2, $this->imageCalls, 'retry re-hits the provider');
        // The failed attempt leaves a status=failed asset (existing generate()
        // behaviour); the retry produces exactly one COMPLETED asset.
        $completed = DB::table('assets')->where('workspace_id', $this->testWorkspace->id)
            ->where('type', 'image')->where('status', 'completed')->count();
        $this->assertSame(1, $completed, 'exactly one COMPLETED image asset after retry');
    }

    /** @test */
    public function empty_prompt_is_rejected_without_side_effects(): void
    {
        $resp = $this->gen(['prompt' => '', 'platform' => 'instagram']);
        $resp->assertStatus(422);
        $this->assertSame('prompt_required', $resp->json('error'));
        $this->assertSame(0, $this->imageCalls);
        $this->assertSame(0, $this->imageAssets());
    }
}
