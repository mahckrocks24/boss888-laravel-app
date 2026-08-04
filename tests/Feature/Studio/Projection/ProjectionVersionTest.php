<?php

namespace Tests\Feature\Studio\Projection;

use App\Engines\Studio\Projection\ProjectionRequest;
use App\Engines\Studio\Projection\ProjectionResult;
use App\Engines\Studio\Projection\ProjectionStatus;
use PHPUnit\Framework\TestCase;

/** Pure - optimistic concurrency: no blind writes. */
final class ProjectionVersionTest extends TestCase
{
    public function test_matching_version_applies(): void
    {
        $a = ProjectionFixture::adapter();
        $r = $a->project(ProjectionFixture::color('stat', '#ff0000', ['ver' => 'v1']));
        $this->assertSame(ProjectionStatus::APPLIED, $r->status);
        $this->assertSame('v2', $a->currentVersion()->token); // version advanced
    }

    public function test_stale_version_is_rejected_without_overwriting(): void
    {
        $a = ProjectionFixture::adapter();
        $r = $a->project(ProjectionFixture::color('stat', '#ff0000', ['ver' => 'v0']));
        $this->assertSame(ProjectionStatus::STALE_VERSION, $r->status);
        $this->assertSame(ProjectionResult::R_STALE_VERSION, $r->errorReason);
        $this->assertSame('#ffd60a', $a->fieldValue('stat', 'style.color')); // untouched
        $this->assertSame('v1', $a->currentVersion()->token);
    }

    public function test_matching_snapshot_hash_applies(): void
    {
        $a = ProjectionFixture::adapter();
        $hash = ProjectionRequest::hashState(['style.color' => '#ffd60a']);
        $r = $a->project(ProjectionFixture::color('stat', '#ff0000', ['hash' => $hash]));
        $this->assertSame(ProjectionStatus::APPLIED, $r->status);
    }

    public function test_snapshot_mismatch_is_rejected(): void
    {
        $a = ProjectionFixture::adapter();
        $r = $a->project(ProjectionFixture::color('stat', '#ff0000', ['hash' => 'deadbeefdeadbeef']));
        $this->assertSame(ProjectionStatus::STALE_VERSION, $r->status);
        $this->assertSame(ProjectionResult::R_SNAPSHOT_MISMATCH, $r->errorReason);
        $this->assertSame('#ffd60a', $a->fieldValue('stat', 'style.color'));
    }

    public function test_target_missing_is_rejected(): void
    {
        $a = ProjectionFixture::adapter();
        $r = $a->project(ProjectionFixture::color('ghost', '#ff0000'));
        $this->assertSame(ProjectionStatus::TARGET_MISSING, $r->status);
    }
}
