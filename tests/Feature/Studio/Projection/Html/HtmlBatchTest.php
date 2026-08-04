<?php

namespace Tests\Feature\Studio\Projection\Html;

use App\Engines\Studio\Projection\ProjectionBatch;
use App\Engines\Studio\Projection\ProjectionResult;
use App\Engines\Studio\Projection\ProjectionStatus;
use App\Engines\Studio\Projection\ProjectionTransactionBoundary;
use PHPUnit\Framework\TestCase;

/** Pure - batch transaction semantics over the HTML document. */
final class HtmlBatchTest extends TestCase
{
    private function good1()
    {
        return HtmlProjectionFixture::styleReq('headline', 'color', 'red', ['op' => 'g1']);
    }

    private function good2()
    {
        return HtmlProjectionFixture::styleReq('cta_label', 'color', '#0000ff', ['op' => 'g2']);
    }

    private function bad()
    {
        return HtmlProjectionFixture::textReq('wrapper', 'x', ['op' => 'bad']); // non-leaf -> unsupported
    }

    public function test_atomic_all_succeed(): void
    {
        $a = HtmlProjectionFixture::rawAdapter();
        $res = $a->projectBatch(new ProjectionBatch('b1', 'd1', [$this->good1(), $this->good2()], ProjectionTransactionBoundary::atomic()));
        $this->assertSame(ProjectionStatus::APPLIED, $res->status);
        $this->assertStringContainsString('color:#ff0000', $a->payload());
        $this->assertStringContainsString('color:#0000ff', $a->payload());
    }

    public function test_atomic_failure_rolls_back(): void
    {
        $a = HtmlProjectionFixture::rawAdapter();
        $res = $a->projectBatch(new ProjectionBatch('b1', 'd1', [$this->good1(), $this->bad()], ProjectionTransactionBoundary::atomic()));
        $this->assertSame(ProjectionStatus::FAILED, $res->status);
        // headline colour rolled back to original
        $this->assertStringContainsString('data-field="headline" style="color:#111111"', $a->payload());
        $this->assertStringNotContainsString('color:#ff0000', $a->payload());
        $this->assertSame(ProjectionResult::R_ROLLED_BACK, $res->results[0]->errorReason);
    }

    public function test_best_effort_continue_partial(): void
    {
        $a = HtmlProjectionFixture::rawAdapter();
        $res = $a->projectBatch(new ProjectionBatch('b1', 'd1', [$this->good1(), $this->bad(), $this->good2()], ProjectionTransactionBoundary::bestEffortContinue()));
        $this->assertSame(ProjectionStatus::PARTIAL, $res->status);
        $this->assertSame(2, $res->appliedCount());
        $this->assertStringContainsString('color:#ff0000', $a->payload());
        $this->assertStringContainsString('color:#0000ff', $a->payload());
    }

    public function test_best_effort_stop_on_first_failure(): void
    {
        $a = HtmlProjectionFixture::rawAdapter();
        $res = $a->projectBatch(new ProjectionBatch('b1', 'd1', [$this->good1(), $this->bad(), $this->good2()], ProjectionTransactionBoundary::bestEffortStop()));
        $this->assertSame(ProjectionStatus::PARTIAL, $res->status);
        $this->assertSame(ProjectionResult::R_SKIPPED, $res->results[2]->errorReason);
        $this->assertStringContainsString('data-field="cta_label" style="color:#FFD60A"', $a->payload()); // never reached
    }
}
