<?php

namespace Tests\Feature\Studio\Runtime;

use App\Engines\Studio\Execution\ExecutionResult;
use App\Engines\Studio\Execution\Operation;
use LogicException;
use PHPUnit\Framework\TestCase;

/** Pure - the runtime coordinates Selection -> Bridge -> Projection -> back. */
final class RuntimeExecuteTest extends TestCase
{
    public function test_execute_applies_through_the_bridge_and_advances_version(): void
    {
        $rt = RuntimeFixture::runtime();
        $graph = RuntimeFixture::graph();
        $adapter = RuntimeFixture::adapter();
        $rt->loadDocument('d1', $graph);
        $rt->attachRenderer($adapter);
        $rt->setSelection(RuntimeFixture::selectYellowNumber($graph));

        $before = $rt->state()->documentVersion;
        $res = $rt->execute(new Operation(type: 'set_style', property: 'color', value: 'red', opId: 'o1'));

        $this->assertSame(ExecutionResult::APPLIED, $res->status);
        $this->assertSame('stat', $res->targetId);
        $this->assertSame('#ff0000', $res->afterState['style.color']);
        $this->assertStringContainsString('color:#ff0000', $adapter->payload());
        $this->assertNotSame($before, $rt->state()->documentVersion); // runtime advanced the active version
    }

    public function test_execute_without_renderer_is_a_logic_error(): void
    {
        $rt = RuntimeFixture::runtime();
        $graph = RuntimeFixture::graph();
        $rt->loadDocument('d1', $graph);
        $rt->setSelection(RuntimeFixture::selectHeadline($graph));

        $this->expectException(LogicException::class);
        $rt->execute(new Operation(type: 'set_style', property: 'color', value: 'red'));
    }

    public function test_execute_without_selection_is_a_logic_error(): void
    {
        $rt = RuntimeFixture::runtime();
        $rt->loadDocument('d1', RuntimeFixture::graph());
        $rt->attachRenderer(RuntimeFixture::adapter());

        $this->expectException(LogicException::class);
        $rt->execute(new Operation(type: 'set_style', property: 'color', value: 'red'));
    }

    public function test_execute_without_document_is_a_logic_error(): void
    {
        $rt = RuntimeFixture::runtime();
        $rt->attachRenderer(RuntimeFixture::adapter());
        $rt->setSelection(RuntimeFixture::selectHeadline(RuntimeFixture::graph()));

        $this->expectException(LogicException::class);
        $rt->execute(new Operation(type: 'set_style', property: 'color', value: 'red'));
    }
}
