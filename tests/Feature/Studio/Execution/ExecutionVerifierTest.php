<?php

namespace Tests\Feature\Studio\Execution;

use App\Engines\Studio\Document\Bbox;
use App\Engines\Studio\Document\StudioElement;
use App\Engines\Studio\Execution\ExecutionResult;
use App\Engines\Studio\Execution\ExecutionVerifier;
use App\Engines\Studio\Execution\Handlers\SetStyleOperationHandler;
use App\Engines\Studio\Execution\Handlers\TextOperationHandler;
use App\Engines\Studio\Execution\Operation;
use App\Engines\Studio\Execution\Support\ElementPatch;
use PHPUnit\Framework\TestCase;

/** Pure — proof of success is state, never a reply. */
final class ExecutionVerifierTest extends TestCase
{
    private function el(): StudioElement
    {
        return new StudioElement(id: 'x', role: 'stat', text: 'old', bbox: new Bbox(0, 0, 10, 10), style: ['color' => '#ffd60a']);
    }

    public function test_applied_when_change_verified(): void
    {
        $before = $this->el();
        $op = new Operation('set_style', 'x', 'color', '#ff0000');
        $handler = new SetStyleOperationHandler();
        $after = $handler->apply($before, $op);

        $v = (new ExecutionVerifier())->verify($op, $handler, $before, $after);
        $this->assertSame(ExecutionResult::APPLIED, $v['status']);
        $this->assertSame(['style.color'], $v['changed_fields']);
    }

    public function test_no_op_is_failed_not_success(): void
    {
        $before = $this->el();
        $op = new Operation('set_style', 'x', 'color', '#ffd60a'); // same colour
        $handler = new SetStyleOperationHandler();
        $after = $handler->apply($before, $op);

        $v = (new ExecutionVerifier())->verify($op, $handler, $before, $after);
        $this->assertSame(ExecutionResult::FAILED, $v['status']);
        $this->assertSame([], $v['changed_fields']);
    }

    public function test_failed_when_expected_change_absent(): void
    {
        $before = $this->el();
        $op = new Operation('replace_text', 'x', value: 'new');
        $handler = new TextOperationHandler();
        // Simulate an after where the write did NOT take effect.
        $after = ElementPatch::with($before, ['text' => 'old']);

        $v = (new ExecutionVerifier())->verify($op, $handler, $before, $after);
        $this->assertSame(ExecutionResult::FAILED, $v['status']);
    }
}
