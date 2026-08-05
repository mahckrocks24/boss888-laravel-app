<?php

namespace Tests\Feature\Studio\Bridge;

use App\Engines\Studio\Bridge\SelectionProjectionBridge;
use App\Engines\Studio\Execution\ExecutionContext;
use App\Engines\Studio\Execution\ExecutionResult;
use App\Engines\Studio\Execution\Operation;
use App\Engines\Studio\Selection\SelectionQuery;
use PHPUnit\Framework\TestCase;

/** Pure - Selection -> Execution -> Projection -> read-back -> verify, end-to-end, offline. */
final class BridgeSingleTest extends TestCase
{
    private SelectionProjectionBridge $bridge;

    protected function setUp(): void
    {
        $this->bridge = new SelectionProjectionBridge();
    }

    public function test_end_to_end_change_98_percent_to_red(): void
    {
        $graph = BridgeFixture::graph();
        $adapter = BridgeFixture::rawAdapter();
        $sel = BridgeFixture::engine()->select(new SelectionQuery(visualTags: ['yellow'], semanticTags: ['metric']), $graph);
        $this->assertTrue($sel->isResolved());

        $res = $this->bridge->project($sel, $graph, new Operation(type: 'set_style', property: 'color', value: 'red', opId: 'o1'), $adapter, new ExecutionContext());

        $this->assertSame(ExecutionResult::APPLIED, $res->status);
        $this->assertSame('stat', $res->targetId);
        $this->assertSame('#ff0000', $res->normalizedValue);
        $this->assertSame('#ff0000', $res->afterState['style.color']);
        $this->assertSame(['style.color'], $res->changedFields);
        $this->assertTrue($res->meta['verified']);

        $html = $adapter->payload();
        $this->assertMatchesRegularExpression('/data-field="stat"[^>]*color:#ff0000/', $html);
        $this->assertStringContainsString('>98%</span>', $html);
        $this->assertStringContainsString('--primary:#FFD60A', $html);            // no palette mutation
        $this->assertStringContainsString('data-field="cta_label" style="color:#ffd60a"', $html); // other yellow unchanged
        $this->assertStringContainsString('data-field="headline" style="color:#111111"', $html);  // unrelated unchanged
    }

    public function test_end_to_end_text_replace(): void
    {
        $graph = BridgeFixture::graph();
        $adapter = BridgeFixture::rawAdapter();
        $sel = BridgeFixture::engine()->select(new SelectionQuery(role: 'headline'), $graph);

        $res = $this->bridge->project($sel, $graph, new Operation(type: 'replace_text', value: 'Mega Sale', opId: 't1'), $adapter, new ExecutionContext());
        $this->assertSame(ExecutionResult::APPLIED, $res->status);
        $this->assertSame('Mega Sale', $res->afterState['text']);
        $this->assertStringContainsString('>Mega Sale</h1>', $adapter->payload());
    }

    public function test_append_uses_semantic_before_state(): void
    {
        $graph = BridgeFixture::graph();
        $adapter = BridgeFixture::rawAdapter();
        $sel = BridgeFixture::engine()->select(new SelectionQuery(role: 'headline'), $graph);

        $res = $this->bridge->project($sel, $graph, new Operation(type: 'append_text', value: ' 2026', opId: 'a1'), $adapter, new ExecutionContext());
        $this->assertSame(ExecutionResult::APPLIED, $res->status);
        $this->assertSame('Big Sale 2026', $res->afterState['text']);
    }

    // ---- SELECTION GATES ----

    public function test_not_found_selection_is_rejected(): void
    {
        $res = $this->bridge->project(BridgeFixture::selectNotFound(), BridgeFixture::graph(), new Operation(type: 'replace_text', value: 'x'), BridgeFixture::rawAdapter(), new ExecutionContext());
        $this->assertSame(ExecutionResult::REJECTED, $res->status);
        $this->assertSame(ExecutionResult::R_TARGET_NOT_FOUND, $res->failureReason);
    }

    public function test_ambiguous_selection_is_not_executed(): void
    {
        $res = $this->bridge->project(BridgeFixture::selectAllYellow(), BridgeFixture::graph(), new Operation(type: 'set_style', property: 'color', value: 'red'), BridgeFixture::rawAdapter(), new ExecutionContext());
        $this->assertSame(ExecutionResult::AMBIGUOUS, $res->status);
    }

    public function test_low_confidence_is_rejected(): void
    {
        $graph = BridgeFixture::graph();
        $sel = BridgeFixture::engine()->select(new SelectionQuery(visualTags: ['yellow'], semanticTags: ['metric']), $graph);
        $res = $this->bridge->project($sel, $graph, new Operation(type: 'set_style', property: 'color', value: 'red'), BridgeFixture::rawAdapter(), new ExecutionContext(confidenceThreshold: 0.95));
        $this->assertSame(ExecutionResult::REJECTED, $res->status);
        $this->assertSame(ExecutionResult::R_LOW_CONFIDENCE, $res->failureReason);
    }

    // ---- PROJECTION FAILURE PROPAGATION ----

    public function test_no_op_projection_failure_propagates(): void
    {
        $graph = BridgeFixture::graph();
        $adapter = BridgeFixture::rawAdapter();
        $sel = BridgeFixture::engine()->select(new SelectionQuery(role: 'headline'), $graph);
        $res = $this->bridge->project($sel, $graph, new Operation(type: 'set_style', property: 'color', value: '#111111'), $adapter, new ExecutionContext());
        $this->assertSame(ExecutionResult::FAILED, $res->status);
        $this->assertSame('no_change', $res->failureReason);
        $this->assertFalse($res->isSuccess());
    }

    public function test_unsupported_property_propagates_as_rejected(): void
    {
        $graph = BridgeFixture::graph();
        $sel = BridgeFixture::engine()->select(new SelectionQuery(role: 'headline'), $graph);
        $res = $this->bridge->project($sel, $graph, new Operation(type: 'set_style', property: 'border-radius', value: '8px'), BridgeFixture::rawAdapter(), new ExecutionContext());
        $this->assertSame(ExecutionResult::REJECTED, $res->status);
        $this->assertSame('unsupported_property', $res->failureReason);
    }

    // ---- CONCURRENCY ----

    public function test_snapshot_mismatch_refuses(): void
    {
        // Semantic model drifted from the renderer (headline text differs).
        $graph = BridgeFixture::graph('DRIFT');
        $adapter = BridgeFixture::rawAdapter();
        $sel = BridgeFixture::engine()->select(new SelectionQuery(role: 'headline'), $graph);
        $res = $this->bridge->project($sel, $graph, new Operation(type: 'replace_text', value: 'New', opId: 'd1'), $adapter, new ExecutionContext());
        $this->assertSame(ExecutionResult::REJECTED, $res->status);
        $this->assertSame('snapshot_mismatch', $res->failureReason);
        $this->assertStringContainsString('>Big Sale</h1>', $adapter->payload()); // untouched
    }

    public function test_version_mismatch_refuses(): void
    {
        $graph = BridgeFixture::graph();
        $adapter = BridgeFixture::rawAdapter();
        $sel = BridgeFixture::engine()->select(new SelectionQuery(visualTags: ['yellow'], semanticTags: ['metric']), $graph);
        $res = $this->bridge->project($sel, $graph, new Operation(type: 'set_style', property: 'color', value: 'red'), $adapter, new ExecutionContext(), 'h:0000000000000000');
        $this->assertSame(ExecutionResult::REJECTED, $res->status);
        $this->assertSame('stale_version', $res->failureReason);
    }

    public function test_replay_does_not_reapply(): void
    {
        $graph = BridgeFixture::graph();
        $adapter = BridgeFixture::rawAdapter();
        $sel = BridgeFixture::engine()->select(new SelectionQuery(role: 'headline'), $graph);
        $op = new Operation(type: 'set_style', property: 'color', value: 'red', opId: 'r1');

        $first = $this->bridge->project($sel, $graph, $op, $adapter, new ExecutionContext(), null, 'k1');
        $this->assertSame(ExecutionResult::APPLIED, $first->status);
        $version = $adapter->currentVersion()->token;

        $replay = $this->bridge->project($sel, $graph, $op, $adapter, new ExecutionContext(), null, 'k1');
        $this->assertSame('completed', $replay->meta['replay']);
        $this->assertSame($version, $adapter->currentVersion()->token);
    }
}
