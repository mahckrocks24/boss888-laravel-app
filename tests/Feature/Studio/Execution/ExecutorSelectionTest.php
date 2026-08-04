<?php

namespace Tests\Feature\Studio\Execution;

use App\Engines\Studio\Execution\ExecutionContext;
use App\Engines\Studio\Execution\ExecutionResult;
use App\Engines\Studio\Execution\Operation;
use App\Engines\Studio\Selection\SelectionQuery;
use PHPUnit\Framework\TestCase;

/** Pure — executor consumes Selection Engine results; no HTML, no guessing. */
final class ExecutorSelectionTest extends TestCase
{
    public function test_change_98_percent_to_red_changes_only_the_stat_color(): void
    {
        $adapter = ExecutorFixture::adapter();
        $graph = ExecutorFixture::graph();

        // "the yellow number" (98% stat) resolves to exactly one element.
        $selection = ExecutorFixture::engine()->select(
            new SelectionQuery(visualTags: ['yellow'], semanticTags: ['metric']),
            $graph
        );
        $this->assertTrue($selection->isResolved());

        $result = ExecutorFixture::executor()->executeWithSelection(
            new Operation('set_style', property: 'color', value: 'red', opId: 'o1'),
            $selection, $adapter, new ExecutionContext()
        );

        $this->assertSame(ExecutionResult::APPLIED, $result->status);
        $this->assertSame('stat', $result->targetId);
        $this->assertSame('#ff0000', $result->normalizedValue);
        $this->assertSame('#ff0000', $adapter->find('stat')->style['color']);

        // Only the stat changed — no other element and no palette touched.
        $this->assertSame('#111111', $adapter->find('headline')->style['color']);
        $this->assertSame('Hello', $adapter->find('para')->text);
        $this->assertSame(['style.color'], $result->changedFields);
    }

    public function test_ambiguous_selection_is_not_executed(): void
    {
        $adapter = ExecutorFixture::adapter();
        // role=body matches two elements (para + locked) → ambiguous.
        $selection = ExecutorFixture::engine()->select(new SelectionQuery(role: 'body'), ExecutorFixture::graph());
        $this->assertTrue($selection->isAmbiguous());

        $result = ExecutorFixture::executor()->executeWithSelection(
            new Operation('hide'), $selection, $adapter, new ExecutionContext()
        );
        $this->assertSame(ExecutionResult::AMBIGUOUS, $result->status);
        $this->assertSame(ExecutionResult::R_AMBIGUOUS_TARGET, $result->failureReason);
        $this->assertTrue($adapter->find('para')->visible); // untouched
    }

    public function test_not_found_selection_is_not_executed(): void
    {
        $adapter = ExecutorFixture::adapter();
        $selection = ExecutorFixture::engine()->select(new SelectionQuery(role: 'video'), ExecutorFixture::graph());

        $result = ExecutorFixture::executor()->executeWithSelection(
            new Operation('hide'), $selection, $adapter, new ExecutionContext()
        );
        $this->assertSame(ExecutionResult::REJECTED, $result->status);
        $this->assertSame(ExecutionResult::R_TARGET_NOT_FOUND, $result->failureReason);
    }

    public function test_confidence_threshold_blocks_low_confidence_execution(): void
    {
        $adapter = ExecutorFixture::adapter();
        $selection = ExecutorFixture::engine()->select(
            new SelectionQuery(visualTags: ['yellow'], semanticTags: ['metric']),
            ExecutorFixture::graph()
        );
        // Resolved at ~0.84; demand 0.95.
        $strict = new ExecutionContext(confidenceThreshold: 0.95);

        $result = ExecutorFixture::executor()->executeWithSelection(
            new Operation('set_style', property: 'color', value: 'red'), $selection, $adapter, $strict
        );
        $this->assertSame(ExecutionResult::REJECTED, $result->status);
        $this->assertSame(ExecutionResult::R_LOW_CONFIDENCE, $result->failureReason);
        $this->assertSame('#ffd60a', $adapter->find('stat')->style['color']); // untouched
    }
}
