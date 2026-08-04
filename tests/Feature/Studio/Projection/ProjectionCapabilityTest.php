<?php

namespace Tests\Feature\Studio\Projection;

use App\Engines\Studio\Projection\ProjectionCapability;
use App\Engines\Studio\Projection\ProjectionResult;
use App\Engines\Studio\Projection\ProjectionStatus;
use PHPUnit\Framework\TestCase;

/** Pure - capability negotiation before projection. */
final class ProjectionCapabilityTest extends TestCase
{
    public function test_negotiation_queries(): void
    {
        $c = ProjectionCapability::referenceDefault();
        $this->assertTrue($c->supportsOperation('replace_text'));
        $this->assertTrue($c->supportsProperty('color'));
        $this->assertTrue($c->supportsField('style.color'));
        $this->assertTrue($c->supportsField('visible'));
        $this->assertTrue($c->supportsVerification);
        $this->assertTrue($c->supportsTransactions);
        $this->assertTrue($c->supportsVersioning);
        $this->assertFalse($c->supportsProperty('border-radius'));
        $this->assertFalse($c->supportsGeometry);
        $this->assertFalse($c->supportsField('style.border-radius'));
    }

    public function test_supported_property_applies(): void
    {
        $a = ProjectionFixture::adapter();
        $r = $a->project(ProjectionFixture::color('stat', '#ff0000'));
        $this->assertSame(ProjectionStatus::APPLIED, $r->status);
        $this->assertSame(['style.color'], $r->appliedFields);
    }

    public function test_unsupported_property_is_rejected(): void
    {
        $a = ProjectionFixture::adapter();
        $r = $a->project(ProjectionFixture::request('stat', ['style.border-radius'], ['style.border-radius' => '8px']));
        $this->assertSame(ProjectionStatus::UNSUPPORTED, $r->status);
        $this->assertSame(ProjectionResult::R_UNSUPPORTED_PROPERTY, $r->errorReason);
        $this->assertSame(['style.border-radius'], $r->rejectedFields);
    }

    public function test_partial_when_some_fields_unsupported(): void
    {
        $a = ProjectionFixture::adapter();
        $r = $a->project(ProjectionFixture::request(
            'stat',
            ['style.color', 'style.border-radius'],
            ['style.color' => '#ff0000', 'style.border-radius' => '8px']
        ));
        $this->assertSame(ProjectionStatus::PARTIAL, $r->status);
        $this->assertSame(['style.color'], $r->appliedFields);
        $this->assertSame(['style.border-radius'], $r->rejectedFields);
        $this->assertSame('#ff0000', $a->fieldValue('stat', 'style.color'));
    }
}
