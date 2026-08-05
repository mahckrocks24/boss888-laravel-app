<?php

namespace Tests\Feature\Studio\Runtime;

use PHPUnit\Framework\TestCase;

/** Pure - runtime lifecycle: document load + renderer attach/detach. */
final class RuntimeLifecycleTest extends TestCase
{
    public function test_load_document_sets_state(): void
    {
        $rt = RuntimeFixture::runtime();
        $this->assertNull($rt->state()->documentId);

        $rt->loadDocument('design-107', RuntimeFixture::graph());
        $this->assertSame('design-107', $rt->state()->documentId);
    }

    public function test_attach_renderer_exposes_capabilities_and_renderer(): void
    {
        $rt = RuntimeFixture::runtime();
        $rt->loadDocument('d1', RuntimeFixture::graph());
        $this->assertNull($rt->currentRenderer());

        $adapter = RuntimeFixture::adapter();
        $rt->attachRenderer($adapter);

        $this->assertSame($adapter, $rt->currentRenderer());
        $this->assertNotNull($rt->capabilities());
        $this->assertTrue($rt->state()->rendererAttached);
        // active version adopted from the renderer when none was supplied
        $this->assertSame($adapter->currentVersion()->token, $rt->state()->documentVersion);
    }

    public function test_detach_renderer_clears(): void
    {
        $rt = RuntimeFixture::runtime();
        $rt->attachRenderer(RuntimeFixture::adapter());
        $rt->detachRenderer();

        $this->assertNull($rt->currentRenderer());
        $this->assertNull($rt->capabilities());
        $this->assertFalse($rt->state()->rendererAttached);
    }
}
