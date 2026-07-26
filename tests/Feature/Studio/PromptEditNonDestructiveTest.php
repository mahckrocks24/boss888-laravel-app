<?php

namespace Tests\Feature\Studio;

use App\Core\EngineKernel\EngineExecutionService;
use App\Engines\Creative\Services\ImageEditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Helpers\Boss888TestHelper;
use Tests\TestCase;

/**
 * STUDIO888 Phase O — masked/local edit backend guarantees.
 * Provider is mocked (no OpenAI cost); asserts non-destructive versioning,
 * truthful billing, idempotency, tenancy, and selection metadata.
 */
class PromptEditNonDestructiveTest extends TestCase
{
    use RefreshDatabase, Boss888TestHelper;

    private int $wsId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBoss888();
        $this->wsId = $this->testWorkspace->id;
        Storage::fake('public');
    }

    /** A 1×1 PNG on the public disk, registered as a completed image asset. */
    private function makeSourceAsset(?int $wsId = null): int
    {
        $wsId = $wsId ?? $this->wsId;
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
        $path = 'ai-images/' . $wsId . '/src_' . uniqid() . '.png';
        Storage::disk('public')->put($path, $png);
        return (int) DB::table('assets')->insertGetId([
            'workspace_id' => $wsId, 'type' => 'image', 'title' => 'orig', 'prompt' => 'orig',
            'provider' => 'LevelUp AI', 'model' => 'LevelUp AI', 'status' => 'completed',
            'url' => Storage::disk('public')->url($path), 'storage_path' => $path,
            'mime_type' => 'image/png', 'width' => 1024, 'height' => 1024, 'version' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function mockProvider(bool $ok = true): void
    {
        $m = \Mockery::mock(ImageEditService::class);
        $m->shouldReceive('inpaint')->andReturn($ok
            ? ['success' => true, 'bytes' => base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='), 'width' => 1024, 'height' => 1024, 'size' => '1024x1024', 'mode' => 'rectangle', 'usage' => []]
            : ['success' => false, 'error' => 'provider_error: boom']);
        $this->app->instance(ImageEditService::class, $m);
    }

    private function ees(): EngineExecutionService { return app(EngineExecutionService::class); }

    private function editParams(int $src, array $over = []): array
    {
        return array_merge([
            'source_asset_id' => $src, 'prompt' => 'Add a yellow duck here.',
            'selection_type' => 'rectangle', 'region' => ['x' => 0.1, 'y' => 0.6, 'w' => 0.3, 'h' => 0.3],
        ], $over);
    }

    /** @test */
    public function edit_creates_a_child_version_and_leaves_the_original_untouched(): void
    {
        $this->mockProvider(true);
        $src = $this->makeSourceAsset();
        $before = DB::table('assets')->find($src);

        $res = $this->ees()->execute($this->wsId, 'creative', 'edit_image', $this->editParams($src), []);

        $this->assertTrue($res['success']);
        $child = DB::table('assets')->where('parent_asset_id', $src)->first();
        $this->assertNotNull($child, 'a child asset must be created');
        $this->assertSame($src, (int) $child->root_asset_id);
        $this->assertSame(2, (int) $child->version);
        $this->assertSame('edit_region', $child->edit_mode);
        $this->assertSame('completed', $child->status);
        $this->assertNotSame($before->url, $child->url);

        // original byte-identical
        $after = DB::table('assets')->find($src);
        $this->assertEquals($before->url, $after->url);
        $this->assertEquals($before->status, $after->status);
        $this->assertEquals(1, (int) $after->version);
        // selection metadata persisted with source-image coords
        $meta = json_decode($child->metadata_json, true);
        $this->assertSame('rectangle', $meta['selection_type']);
        $this->assertEquals(0.1, $meta['region']['x']);
        $this->assertSame($src, $meta['source_asset_id']);
    }

    /** @test */
    public function edit_commits_credits_once_on_success(): void
    {
        $this->mockProvider(true);
        $src = $this->makeSourceAsset();
        $this->ees()->execute($this->wsId, 'creative', 'edit_image', $this->editParams($src), []);
        $this->assertCreditBalance(5000 - 2); // edit_image cost = 2, committed once
    }

    /** @test */
    public function provider_failure_releases_credits_and_creates_no_child(): void
    {
        $this->mockProvider(false);
        $src = $this->makeSourceAsset();
        $res = $this->ees()->execute($this->wsId, 'creative', 'edit_image', $this->editParams($src), []);
        $this->assertFalse($res['success']);
        $this->assertSame(0, DB::table('assets')->where('parent_asset_id', $src)->count());
        $this->assertCreditBalance(5000); // reservation released — no charge for a failed edit
    }

    /** @test */
    public function duplicate_submit_is_idempotent(): void
    {
        $this->mockProvider(true);
        $src = $this->makeSourceAsset();
        $p = $this->editParams($src, ['idempotency_key' => 'dup-key-1']);
        $this->ees()->execute($this->wsId, 'creative', 'edit_image', $p, []);
        $this->ees()->execute($this->wsId, 'creative', 'edit_image', $p, []);
        $this->assertSame(1, DB::table('assets')->where('parent_asset_id', $src)->count(), 'idempotency key must prevent a duplicate edit');
    }

    /** @test */
    public function cross_workspace_source_is_rejected(): void
    {
        $this->mockProvider(true);
        $otherWs = $this->createAdditionalWorkspace('Other WS')->id;
        $foreign = $this->makeSourceAsset($otherWs); // asset owned by a different workspace
        $res = $this->ees()->execute($this->wsId, 'creative', 'edit_image', $this->editParams($foreign), []);
        $this->assertFalse($res['success']);
        $this->assertSame(0, DB::table('assets')->where('parent_asset_id', $foreign)->count());
        $this->assertCreditBalance(5000); // no charge for a rejected cross-tenant edit
    }

    /** @test */
    public function second_edit_increments_version_to_three(): void
    {
        $this->mockProvider(true);
        $src = $this->makeSourceAsset();
        $r1 = $this->ees()->execute($this->wsId, 'creative', 'edit_image', $this->editParams($src), []);
        $childId = $r1['data']['id'] ?? $r1['id'];
        // branch a second edit off the first child
        $r2 = $this->ees()->execute($this->wsId, 'creative', 'edit_image', $this->editParams((int) $childId), []);
        $this->assertTrue($r2['success']);
        $grandchild = DB::table('assets')->where('parent_asset_id', $childId)->first();
        $this->assertSame(3, (int) $grandchild->version);
        $this->assertSame($src, (int) $grandchild->root_asset_id); // same version tree root
    }
}
