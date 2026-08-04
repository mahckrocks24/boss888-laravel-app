<?php

namespace Tests\Feature\Studio\Projection;

use App\Engines\Studio\Projection\ProjectionResult;
use App\Engines\Studio\Projection\ProjectionStatus;
use PHPUnit\Framework\TestCase;

/** Pure - the reference adapter reports ACTUAL applied state, never mere acceptance. */
final class ProjectionAdapterTest extends TestCase
{
    public function test_apply_reports_actual_after_state_and_verifies(): void
    {
        $a = ProjectionFixture::adapter();
        $r = $a->project(ProjectionFixture::color('stat', '#ff0000'));
        $this->assertSame(ProjectionStatus::APPLIED, $r->status);
        $this->assertSame('#ff0000', $r->actualAfterState['style.color']);
        $this->assertTrue($r->verification['verified']);
        $this->assertNotNull($r->rendererVersion);
    }

    public function test_no_op_is_failed_not_success(): void
    {
        $a = ProjectionFixture::adapter();
        $r = $a->project(ProjectionFixture::color('stat', '#ffd60a')); // already this colour
        $this->assertSame(ProjectionStatus::FAILED, $r->status);
        $this->assertSame(ProjectionResult::R_NO_CHANGE, $r->errorReason);
        $this->assertFalse($r->isSuccess());
    }

    public function test_correlation_id_is_preserved(): void
    {
        $a = ProjectionFixture::adapter();
        $r = $a->project(ProjectionFixture::color('stat', '#ff0000', ['corr' => 'trace-xyz']));
        $this->assertSame('trace-xyz', $r->correlationId);
    }

    public function test_capability_is_advertised(): void
    {
        $this->assertTrue(ProjectionFixture::adapter()->capability()->supportsVerification);
    }
}
