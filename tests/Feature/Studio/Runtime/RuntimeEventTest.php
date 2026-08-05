<?php

namespace Tests\Feature\Studio\Runtime;

use App\Engines\Studio\Execution\Operation;
use App\Engines\Studio\Runtime\Events\RuntimeEvent;
use App\Engines\Studio\Runtime\Events\RuntimeEventType;
use PHPUnit\Framework\TestCase;

/** Pure - runtime event dispatch. */
final class RuntimeEventTest extends TestCase
{
    public function test_lifecycle_events_dispatch(): void
    {
        $rt = RuntimeFixture::runtime();
        $seen = [];
        $rt->events()->onAny(function (RuntimeEvent $e) use (&$seen) {
            $seen[] = $e->type;
        });

        $rt->loadDocument('d1', RuntimeFixture::graph());
        $rt->attachRenderer(RuntimeFixture::adapter());
        $rt->setSelection(RuntimeFixture::selectHeadline(RuntimeFixture::graph()));
        $rt->updateViewport($rt->viewport()->withZoom(2.0));
        $rt->detachRenderer();

        $this->assertContains(RuntimeEventType::DOCUMENT_LOADED, $seen);
        $this->assertContains(RuntimeEventType::RENDERER_ATTACHED, $seen);
        $this->assertContains(RuntimeEventType::SELECTION_CHANGED, $seen);
        $this->assertContains(RuntimeEventType::VIEWPORT_CHANGED, $seen);
        $this->assertContains(RuntimeEventType::RENDERER_DETACHED, $seen);
    }

    public function test_typed_subscription_receives_payload(): void
    {
        $rt = RuntimeFixture::runtime();
        $captured = null;
        $rt->events()->on(RuntimeEventType::SELECTION_CHANGED, function (RuntimeEvent $e) use (&$captured) {
            $captured = $e->payload;
        });

        $rt->setSelection(RuntimeFixture::selectHeadline(RuntimeFixture::graph()));
        $this->assertSame(['headline'], $captured['selected_ids']);
    }

    public function test_execute_emits_operation_events(): void
    {
        $rt = RuntimeFixture::runtime();
        $seen = [];
        $rt->events()->onAny(function (RuntimeEvent $e) use (&$seen) {
            $seen[] = $e->type;
        });

        $graph = RuntimeFixture::graph();
        $rt->loadDocument('d1', $graph);
        $rt->attachRenderer(RuntimeFixture::adapter());
        $rt->setSelection(RuntimeFixture::selectYellowNumber($graph));

        $rt->execute(new Operation(type: 'set_style', property: 'color', value: 'red', opId: 'o1'));

        $this->assertContains(RuntimeEventType::OPERATION_STARTED, $seen);
        $this->assertContains(RuntimeEventType::PROJECTION_APPLIED, $seen);
        $this->assertContains(RuntimeEventType::OPERATION_COMPLETED, $seen);
    }

    public function test_failed_operation_emits_projection_failed(): void
    {
        $rt = RuntimeFixture::runtime();
        $graph = RuntimeFixture::graph();
        $rt->loadDocument('d1', $graph);
        $rt->attachRenderer(RuntimeFixture::adapter());
        $rt->setSelection(RuntimeFixture::selectHeadline($graph));

        $failed = false;
        $rt->events()->on(RuntimeEventType::PROJECTION_FAILED, function () use (&$failed) {
            $failed = true;
        });

        // No-op (colour already #111111) -> projection fails.
        $rt->execute(new Operation(type: 'set_style', property: 'color', value: '#111111', opId: 'noop'));
        $this->assertTrue($failed);
    }
}
