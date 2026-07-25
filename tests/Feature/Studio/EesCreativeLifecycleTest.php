<?php

namespace Tests\Feature\Studio;

use App\Connectors\RuntimeClient;
use App\Core\EngineKernel\EngineExecutionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Helpers\Boss888TestHelper;
use Tests\TestCase;

/**
 * STUDIO888 MRC-2A — EES CREATIVE IMAGE LIFECYCLE CHARACTERIZATION (test-only).
 *
 * Pins the CURRENT behaviour of EngineExecutionService::execute() for
 * creative/generate_image (the path the interactive /api/creative/generate/image
 * route uses). Covers success, provider failure, and thrown failure, with the
 * credit reserve/commit/release ledger and asset lifecycle.
 *
 * generate_image capability: engine=creative, connector=creative, credit_cost=2, approval=auto.
 *
 * PHASE H1 CORRECTION (2026-07-25): EES now inspects semantic success of the
 * downstream creative/studio result. A non-throwing failure (status=failed /
 * success=false / runtime_unavailable / ambiguous) RELEASES the reservation and
 * returns success:false instead of committing and masking. Successful and thrown
 * paths are unchanged. These expectations were flipped from the Phase B baseline
 * that honestly pinned the old (broken) commit-on-failure behaviour.
 */
class EesCreativeLifecycleTest extends TestCase
{
    use RefreshDatabase, Boss888TestHelper;

    private const COST = 2; // generate_image credit_cost

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBoss888();
        // Connector performs a HEAD size-probe on the returned URL — fake all HTTP.
        Http::fake(['*' => Http::response('', 200, ['Content-Length' => '204800'])]);
    }

    /** Bind a fake runtime so the image provider boundary is deterministic. */
    private function fakeRuntime(bool $success): void
    {
        $rt = \Mockery::mock(RuntimeClient::class)->makePartial();
        $rt->shouldReceive('isConfigured')->andReturn(true);
        $rt->shouldReceive('aiRun')->andReturn(['text' => '']); // descriptive-alt path (unused here)
        if ($success) {
            $rt->shouldReceive('imageGenerate')->once()->andReturn([
                'success'        => true,
                'url'            => 'https://cdn.test/generated/img.png',
                'size'           => '1792x1024',
                'quality'        => 'standard',
                'revised_prompt' => 'A serene sunset over Dubai Marina',
                'storage_path'   => null,
                'model'          => 'gpt-image-1',
                'provider'       => 'openai',
            ]);
        } else {
            $rt->shouldReceive('imageGenerate')->once()->andReturn([
                'success' => false,
                'error'   => 'provider_overloaded',
            ]);
        }
        $this->app->instance(RuntimeClient::class, $rt);
    }

    private function ees(): EngineExecutionService
    {
        return app(EngineExecutionService::class);
    }

    private function txns(): array
    {
        return DB::table('credit_transactions')
            ->where('workspace_id', $this->testWorkspace->id)
            ->orderBy('id')->get()->all();
    }

    // ── A. Success ──────────────────────────────────────────────────────

    /** @test */
    public function successful_image_generation_creates_one_completed_asset_and_commits_one_charge(): void
    {
        $this->fakeRuntime(true);
        $wsId = $this->testWorkspace->id;

        $res = $this->ees()->execute($wsId, 'creative', 'generate_image', [
            'prompt' => 'A sunset over Dubai Marina', 'aspect_ratio' => '16:9',
        ]);

        // Exactly one asset, completed, correctly attributed.
        $assets = DB::table('assets')->where('workspace_id', $wsId)->get();
        $this->assertCount(1, $assets);
        $asset = $assets->first();
        $this->assertSame('image', $asset->type);
        $this->assertSame('completed', $asset->status);
        $this->assertSame('https://cdn.test/generated/img.png', $asset->url);
        $this->assertSame('LevelUp AI', $asset->provider);
        $this->assertSame('LevelUp AI', $asset->model);

        // Credit ledger: reserved once, committed once, released never.
        $this->assertCreditBalance(5000 - self::COST);
        $this->assertReservedBalance(0);
        $types = array_map(fn ($t) => $t->type, $this->txns());
        $this->assertContains('reserve', $types);
        $this->assertContains('commit', $types);
        $this->assertNotContains('release', $types);
        $this->assertSame(1, count(array_filter($types, fn ($t) => $t === 'reserve')));
        $this->assertSame(1, count(array_filter($types, fn ($t) => $t === 'commit')));
    }

    // ── B. Provider failure (swallowed → returned) — H1 corrected ───────

    /** @test */
    public function swallowed_provider_failure_releases_credit_and_reports_failure(): void
    {
        $this->fakeRuntime(false);
        $wsId = $this->testWorkspace->id;

        $res = $this->ees()->execute($wsId, 'creative', 'generate_image', ['prompt' => 'A doomed render']);

        // Asset created then marked failed — exactly one, no duplicates.
        $assets = DB::table('assets')->where('workspace_id', $wsId)->get();
        $this->assertCount(1, $assets);
        $this->assertSame('failed', $assets->first()->status);

        // H1: the failure is now truthful — EES reports success:false...
        $this->assertFalse($res['success'] ?? true);

        // ...and RELEASES the reservation (no charge), never commits.
        $this->assertCreditBalance(5000); // NOT charged
        $this->assertReservedBalance(0);
        $types = array_map(fn ($t) => $t->type, $this->txns());
        $this->assertContains('release', $types);
        $this->assertNotContains('commit', $types);
    }

    // ── B2. Ambiguous/malformed downstream payload → fail safe ──────────

    /** @test */
    public function ambiguous_downstream_payload_fails_safe_without_charging(): void
    {
        // Bind a CreativeService that returns a payload with NO success/status
        // signal — EES must fail safe (release, success:false), never bill.
        $svc = \Mockery::mock(\App\Engines\Creative\Services\CreativeService::class);
        $svc->shouldReceive('generateImage')->once()->andReturn(['foo' => 'bar']);
        $this->app->instance(\App\Engines\Creative\Services\CreativeService::class, $svc);
        $wsId = $this->testWorkspace->id;

        $res = $this->ees()->execute($wsId, 'creative', 'generate_image', ['prompt' => 'ambiguous']);

        $this->assertFalse($res['success'] ?? true);
        $this->assertCreditBalance(5000);
        $this->assertReservedBalance(0);
        $types = array_map(fn ($t) => $t->type, $this->txns());
        $this->assertContains('release', $types);
        $this->assertNotContains('commit', $types);
    }

    // ── C. Thrown failure (empty prompt) → release, no charge ───────────

    /** @test */
    public function thrown_failure_releases_the_reservation_and_creates_no_asset(): void
    {
        // No runtime call expected — generateImage throws on empty prompt before dispatch.
        $rt = \Mockery::mock(RuntimeClient::class)->makePartial();
        $rt->shouldReceive('isConfigured')->andReturn(true);
        $rt->shouldReceive('imageGenerate')->never();
        $this->app->instance(RuntimeClient::class, $rt);
        $wsId = $this->testWorkspace->id;

        $res = $this->ees()->execute($wsId, 'creative', 'generate_image', ['prompt' => '']);

        $this->assertFalse($res['success'] ?? true);

        // No asset row (threw before createAsset).
        $this->assertSame(0, DB::table('assets')->where('workspace_id', $wsId)->count());

        // Credit reserved then RELEASED → balance untouched, nothing committed.
        $this->assertCreditBalance(5000);
        $this->assertReservedBalance(0);
        $types = array_map(fn ($t) => $t->type, $this->txns());
        $this->assertContains('reserve', $types);
        $this->assertContains('release', $types);
        $this->assertNotContains('commit', $types);
    }

    // ── No double dispatch / double reservation on one attempt ──────────

    /** @test */
    public function one_attempt_reserves_and_dispatches_exactly_once(): void
    {
        $this->fakeRuntime(true); // imageGenerate mocked with ->once() — a 2nd call would fail the mock
        $wsId = $this->testWorkspace->id;

        $this->ees()->execute($wsId, 'creative', 'generate_image', ['prompt' => 'single dispatch']);

        $reserves = count(array_filter($this->txns(), fn ($t) => $t->type === 'reserve'));
        $this->assertSame(1, $reserves);
        $this->assertSame(1, DB::table('assets')->where('workspace_id', $wsId)->count());
    }
}
