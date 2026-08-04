<?php

namespace Tests\Feature\Studio\Projection\Html;

use App\Engines\Studio\Projection\Contracts\StudioProjectionAdapterInterface;
use App\Engines\Studio\Projection\Html\HtmlProjectionAdapter;
use App\Engines\Studio\Projection\Html\HtmlProjectionSecurityPolicy;
use App\Engines\Studio\Projection\Html\StudioHtmlProjectionServiceProvider;
use App\Engines\Studio\Projection\ProjectionStatus;
use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;

/** Dependency inversion - the adapter honours the Phase-3B interface; callers depend on it. */
final class HtmlBindingTest extends TestCase
{
    public function test_provider_binds_security_policy(): void
    {
        $c = new Container();
        (new StudioHtmlProjectionServiceProvider($c))->register();
        $this->assertInstanceOf(HtmlProjectionSecurityPolicy::class, $c->make(HtmlProjectionSecurityPolicy::class));
    }

    public function test_adapter_honours_projection_contract(): void
    {
        $this->assertInstanceOf(StudioProjectionAdapterInterface::class, HtmlProjectionFixture::rawAdapter());
        $this->assertInstanceOf(StudioProjectionAdapterInterface::class, HtmlProjectionFixture::structuredAdapter());
    }

    public function test_a_consumer_can_depend_on_the_interface_only(): void
    {
        $consume = fn (StudioProjectionAdapterInterface $adapter): string =>
            $adapter->project(HtmlProjectionFixture::styleReq('headline', 'color', 'red'))->status;

        $this->assertSame(ProjectionStatus::APPLIED, $consume(HtmlProjectionFixture::rawAdapter()));
    }

    public function test_factories_produce_the_two_forms(): void
    {
        $this->assertInstanceOf(HtmlProjectionAdapter::class, HtmlProjectionAdapter::forRawHtml('<span data-field="x">y</span>'));
        $this->assertInstanceOf(HtmlProjectionAdapter::class, HtmlProjectionAdapter::forStructured(['template_slug' => 't', 'fields' => []]));
    }
}
