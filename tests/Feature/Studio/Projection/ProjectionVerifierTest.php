<?php

namespace Tests\Feature\Studio\Projection;

use App\Engines\Studio\Projection\ProjectionStatus;
use App\Engines\Studio\Projection\ProjectionVerifier;
use PHPUnit\Framework\TestCase;

/** Pure - abstract verification compares desired vs actual; never fabricates success. */
final class ProjectionVerifierTest extends TestCase
{
    public function test_verified_when_actual_matches_desired(): void
    {
        $a = ProjectionFixture::adapter();
        $req = ProjectionFixture::color('stat', '#ff0000');
        $res = $a->project($req);

        $v = (new ProjectionVerifier())->verify($req, $res);
        $this->assertTrue($v['verified']);
        $this->assertSame(['style.color'], $v['matched']);
        $this->assertSame([], $v['mismatched']);
    }

    public function test_not_verified_when_actual_differs(): void
    {
        // A result whose actual state does not match the request's desired state.
        $req = ProjectionFixture::color('stat', '#ff0000');
        $res = new \App\Engines\Studio\Projection\ProjectionResult(
            ProjectionStatus::APPLIED, 'o1', 'stat', ['style.color'], [], 'v2',
            ['style.color' => '#00ff00'] // actual differs from desired #ff0000
        );

        $v = (new ProjectionVerifier())->verify($req, $res);
        $this->assertFalse($v['verified']);
        $this->assertSame(['style.color'], $v['mismatched']);
    }

    public function test_change_98_percent_to_red_projection(): void
    {
        $a = ProjectionFixture::adapter();
        $req = ProjectionFixture::color('stat', '#ff0000');
        $res = $a->project($req);

        $this->assertSame(ProjectionStatus::APPLIED, $res->status);
        $this->assertSame(['style.color'], $res->appliedFields);
        $this->assertSame('#ff0000', $res->actualAfterState['style.color']);

        // only the stat colour changed; headline + everything else untouched; no palette field.
        $this->assertSame('#ff0000', $a->fieldValue('stat', 'style.color'));
        $this->assertSame('#111111', $a->fieldValue('headline', 'style.color'));
        $this->assertNull($a->fieldValue('stat', 'palette'));

        $v = (new ProjectionVerifier())->verify($req, $res);
        $this->assertTrue($v['verified']);
        $this->assertSame(['style.color'], $v['matched']);
    }
}
