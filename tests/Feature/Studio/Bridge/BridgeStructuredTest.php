<?php

namespace Tests\Feature\Studio\Bridge;

use App\Engines\Studio\Bridge\SelectionProjectionBridge;
use App\Engines\Studio\Execution\ExecutionContext;
use App\Engines\Studio\Execution\ExecutionResult;
use App\Engines\Studio\Execution\Operation;
use App\Engines\Studio\Selection\SelectionQuery;
use PHPUnit\Framework\TestCase;

/** Pure - the bridge over a structured template document (text supported, style not). */
final class BridgeStructuredTest extends TestCase
{
    private SelectionProjectionBridge $bridge;

    protected function setUp(): void
    {
        $this->bridge = new SelectionProjectionBridge();
    }

    public function test_structured_text_end_to_end(): void
    {
        $graph = BridgeFixture::structuredGraph();
        $adapter = BridgeFixture::structuredAdapter();
        $sel = BridgeFixture::engine()->select(new SelectionQuery(role: 'headline'), $graph);

        $res = $this->bridge->project($sel, $graph, new Operation(type: 'replace_text', value: 'Mega Sale', opId: 's1'), $adapter, new ExecutionContext());
        $this->assertSame(ExecutionResult::APPLIED, $res->status);
        $this->assertSame('Mega Sale', $res->afterState['text']);

        $payload = $adapter->payload();
        $this->assertIsArray($payload);                          // no collapse
        $this->assertSame('Mega Sale', $payload['fields']['headline']);
        $this->assertSame('promo', $payload['template_slug']);   // identity preserved
        $this->assertSame('Today', $payload['fields']['sub']);   // unrelated field preserved
        $this->assertSame(['rev' => 1], $payload['meta']);       // unknown metadata preserved
    }

    public function test_structured_style_is_unsupported(): void
    {
        $graph = BridgeFixture::structuredGraph();
        $sel = BridgeFixture::engine()->select(new SelectionQuery(role: 'headline'), $graph);

        $res = $this->bridge->project($sel, $graph, new Operation(type: 'set_style', property: 'color', value: 'red'), BridgeFixture::structuredAdapter(), new ExecutionContext());
        $this->assertSame(ExecutionResult::REJECTED, $res->status);
        $this->assertSame('structured_style_unsupported', $res->failureReason);
    }
}
