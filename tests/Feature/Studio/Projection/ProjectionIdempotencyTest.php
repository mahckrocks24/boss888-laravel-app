<?php

namespace Tests\Feature\Studio\Projection;

use App\Engines\Studio\Projection\ProjectionResult;
use App\Engines\Studio\Projection\ProjectionStatus;
use App\Engines\Studio\Projection\ReplayState;
use PHPUnit\Framework\TestCase;

/** Pure - idempotent replay: the same key never applies the same mutation twice. */
final class ProjectionIdempotencyTest extends TestCase
{
    public function test_completed_replay_returns_cached_result_without_reapplying(): void
    {
        $a = ProjectionFixture::adapter();
        $first = $a->project(ProjectionFixture::color('stat', '#ff0000', ['key' => 'k1']));
        $this->assertSame(ProjectionStatus::APPLIED, $first->status);
        $this->assertSame(ReplayState::FIRST, $first->replay);
        $this->assertSame('v2', $a->currentVersion()->token);

        $replay = $a->project(ProjectionFixture::color('stat', '#ff0000', ['key' => 'k1']));
        $this->assertSame(ReplayState::COMPLETED, $replay->replay);
        $this->assertSame(ProjectionStatus::APPLIED, $replay->status);
        $this->assertSame('v2', $a->currentVersion()->token); // NOT re-applied (no version bump)
    }

    public function test_in_progress_replay_does_not_apply(): void
    {
        $a = ProjectionFixture::adapter();
        $a->markInProgress('k2');
        $r = $a->project(ProjectionFixture::color('stat', '#ff0000', ['key' => 'k2']));
        $this->assertSame(ReplayState::IN_PROGRESS, $r->replay);
        $this->assertSame(ProjectionResult::R_IN_PROGRESS, $r->errorReason);
        $this->assertSame('#ffd60a', $a->fieldValue('stat', 'style.color')); // untouched
        $this->assertSame('v1', $a->currentVersion()->token);
    }

    public function test_expired_replay_requires_fresh_key(): void
    {
        $a = ProjectionFixture::adapter();
        $a->expire('k3');
        $r = $a->project(ProjectionFixture::color('stat', '#ff0000', ['key' => 'k3']));
        $this->assertSame(ReplayState::EXPIRED, $r->replay);
        $this->assertSame(ProjectionResult::R_EXPIRED, $r->errorReason);
        $this->assertSame('#ffd60a', $a->fieldValue('stat', 'style.color'));
    }

    public function test_replay_preserves_correlation_id(): void
    {
        $a = ProjectionFixture::adapter();
        $a->project(ProjectionFixture::color('stat', '#ff0000', ['key' => 'k4', 'corr' => 'trace-1']));
        $replay = $a->project(ProjectionFixture::color('stat', '#ff0000', ['key' => 'k4', 'corr' => 'trace-1']));
        $this->assertSame('trace-1', $replay->correlationId);
    }
}
