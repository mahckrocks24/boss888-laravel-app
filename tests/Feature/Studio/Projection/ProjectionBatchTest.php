<?php

namespace Tests\Feature\Studio\Projection;

use App\Engines\Studio\Projection\ProjectionBatch;
use App\Engines\Studio\Projection\ProjectionResult;
use App\Engines\Studio\Projection\ProjectionStatus;
use App\Engines\Studio\Projection\ProjectionTransactionBoundary;
use PHPUnit\Framework\TestCase;

/** Pure - batch transaction semantics. */
final class ProjectionBatchTest extends TestCase
{
    private function good1()
    {
        return ProjectionFixture::color('stat', '#ff0000', ['op' => 'g1']);
    }

    private function good2()
    {
        return ProjectionFixture::color('headline', '#00ff00', ['op' => 'g2']);
    }

    private function bad()
    {
        return ProjectionFixture::request('stat', ['style.border-radius'], ['style.border-radius' => '8px'], ['op' => 'bad']);
    }

    public function test_atomic_all_succeed_commits(): void
    {
        $a = ProjectionFixture::adapter();
        $res = $a->projectBatch(new ProjectionBatch('b1', 'doc1', [$this->good1(), $this->good2()], ProjectionTransactionBoundary::atomic()));
        $this->assertSame(ProjectionStatus::APPLIED, $res->status);
        $this->assertSame('#ff0000', $a->fieldValue('stat', 'style.color'));
        $this->assertSame('#00ff00', $a->fieldValue('headline', 'style.color'));
        $this->assertSame('v3', $a->currentVersion()->token);
    }

    public function test_atomic_failure_rolls_back_everything(): void
    {
        $a = ProjectionFixture::adapter();
        $res = $a->projectBatch(new ProjectionBatch('b1', 'doc1', [$this->good1(), $this->bad()], ProjectionTransactionBoundary::atomic()));
        $this->assertSame(ProjectionStatus::FAILED, $res->status);
        // stat colour rolled back; version restored.
        $this->assertSame('#ffd60a', $a->fieldValue('stat', 'style.color'));
        $this->assertSame('v1', $a->currentVersion()->token);
        // the earlier success is reported as rolled_back.
        $this->assertSame(ProjectionResult::R_ROLLED_BACK, $res->results[0]->errorReason);
    }

    public function test_best_effort_continue_applies_the_good_ones(): void
    {
        $a = ProjectionFixture::adapter();
        $res = $a->projectBatch(new ProjectionBatch('b1', 'doc1', [$this->good1(), $this->bad(), $this->good2()], ProjectionTransactionBoundary::bestEffortContinue()));
        $this->assertSame(ProjectionStatus::PARTIAL, $res->status);
        $this->assertSame('#ff0000', $a->fieldValue('stat', 'style.color'));
        $this->assertSame('#00ff00', $a->fieldValue('headline', 'style.color'));
        $this->assertSame(2, $res->appliedCount());
    }

    public function test_best_effort_stop_on_first_failure_skips_the_rest(): void
    {
        $a = ProjectionFixture::adapter();
        $res = $a->projectBatch(new ProjectionBatch('b1', 'doc1', [$this->good1(), $this->bad(), $this->good2()], ProjectionTransactionBoundary::bestEffortStop()));
        $this->assertSame(ProjectionStatus::PARTIAL, $res->status);
        $this->assertSame(ProjectionResult::R_SKIPPED, $res->results[2]->errorReason);
        $this->assertSame('#111111', $a->fieldValue('headline', 'style.color')); // never reached
    }
}
