<?php

namespace Tests\Feature\Studio\Bridge;

use App\Engines\Studio\Bridge\SelectionProjectionBridge;
use App\Engines\Studio\Document\SemanticGraph;
use App\Engines\Studio\Execution\ExecutionContext;
use App\Engines\Studio\Execution\ExecutionResult;
use App\Engines\Studio\Execution\Operation;
use App\Engines\Studio\Projection\Html\HtmlProjectionAdapter;
use App\Engines\Studio\Projection\ProjectionTransactionBoundary;
use App\Engines\Studio\Selection\SelectionQuery;
use PHPUnit\Framework\TestCase;

/** Pure - batch transaction semantics propagated through the bridge. */
final class BridgeBatchTest extends TestCase
{
    private SelectionProjectionBridge $bridge;
    private SemanticGraph $graph;
    private HtmlProjectionAdapter $adapter;

    protected function setUp(): void
    {
        $this->bridge = new SelectionProjectionBridge();
        $this->graph = BridgeFixture::graph();
        $this->adapter = BridgeFixture::rawAdapter();
    }

    private function sel(SelectionQuery $q)
    {
        return BridgeFixture::engine()->select($q, $this->graph);
    }

    private function good1(): array
    {
        return ['selection' => $this->sel(new SelectionQuery(role: 'headline')), 'operation' => new Operation(type: 'set_style', property: 'color', value: 'red', opId: 'g1')];
    }

    private function good2(): array
    {
        return ['selection' => $this->sel(new SelectionQuery(role: 'cta')), 'operation' => new Operation(type: 'set_style', property: 'color', value: '#0000ff', opId: 'g2')];
    }

    private function bad(): array
    {
        // supported target + snapshot-clean, but an unsupported property -> projection UNSUPPORTED.
        return ['selection' => $this->sel(new SelectionQuery(visualTags: ['yellow'], semanticTags: ['metric'])), 'operation' => new Operation(type: 'set_style', property: 'border-radius', value: '8px', opId: 'bad')];
    }

    public function test_atomic_all_succeed(): void
    {
        $out = $this->bridge->projectBatch([$this->good1(), $this->good2()], $this->graph, $this->adapter, new ExecutionContext(), ProjectionTransactionBoundary::atomic());
        $this->assertSame(ExecutionResult::APPLIED, $out['status']);
        $this->assertSame(ExecutionResult::APPLIED, $out['results'][0]->status);
        $this->assertSame(ExecutionResult::APPLIED, $out['results'][1]->status);
        $this->assertStringContainsString('data-field="headline" style="color:#ff0000"', $this->adapter->payload());
        $this->assertStringContainsString('color:#0000ff', $this->adapter->payload());
    }

    public function test_atomic_failure_rolls_back(): void
    {
        $out = $this->bridge->projectBatch([$this->good1(), $this->bad()], $this->graph, $this->adapter, new ExecutionContext(), ProjectionTransactionBoundary::atomic());
        $this->assertSame(ExecutionResult::FAILED, $out['status']);
        $this->assertSame('rolled_back', $out['results'][0]->failureReason);
        $this->assertStringContainsString('data-field="headline" style="color:#111111"', $this->adapter->payload()); // rolled back
    }

    public function test_best_effort_continue_partial(): void
    {
        $out = $this->bridge->projectBatch([$this->good1(), $this->bad(), $this->good2()], $this->graph, $this->adapter, new ExecutionContext(), ProjectionTransactionBoundary::bestEffortContinue());
        $this->assertSame(ExecutionResult::PARTIALLY_APPLIED, $out['status']);
        $this->assertSame(ExecutionResult::APPLIED, $out['results'][0]->status);
        $this->assertSame(ExecutionResult::REJECTED, $out['results'][1]->status);
        $this->assertSame(ExecutionResult::APPLIED, $out['results'][2]->status);
    }

    public function test_best_effort_stop_on_first_failure(): void
    {
        $out = $this->bridge->projectBatch([$this->good1(), $this->bad(), $this->good2()], $this->graph, $this->adapter, new ExecutionContext(), ProjectionTransactionBoundary::bestEffortStop());
        $this->assertSame(ExecutionResult::PARTIALLY_APPLIED, $out['status']);
        $this->assertSame('skipped', $out['results'][2]->failureReason);
        $this->assertStringContainsString('data-field="cta_label" style="color:#ffd60a"', $this->adapter->payload()); // never reached
    }

    public function test_atomic_aborts_when_a_sibling_selection_fails(): void
    {
        $out = $this->bridge->projectBatch(
            [$this->good1(), ['selection' => BridgeFixture::selectNotFound(), 'operation' => new Operation(type: 'replace_text', value: 'x', opId: 'nf')]],
            $this->graph, $this->adapter, new ExecutionContext(), ProjectionTransactionBoundary::atomic()
        );
        $this->assertSame(ExecutionResult::REJECTED, $out['status']);
        $this->assertStringContainsString('data-field="headline" style="color:#111111"', $this->adapter->payload()); // nothing applied
    }
}
