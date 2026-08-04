<?php

namespace Tests\Feature\Studio\Projection\Html;

use App\Engines\Studio\Projection\ProjectionStatus;
use App\Engines\Studio\Projection\ReplayState;
use PHPUnit\Framework\TestCase;

/** Pure - the exact "Change 98% to red" acceptance, at the HTML projection layer. */
final class HtmlAcceptanceTest extends TestCase
{
    public function test_change_98_percent_to_red_element_scoped_only(): void
    {
        $a = HtmlProjectionFixture::rawAdapter();
        $r = $a->project(HtmlProjectionFixture::styleReq('img_stat_val', 'color', 'red'));

        $this->assertSame(ProjectionStatus::APPLIED, $r->status);
        $this->assertSame('img_stat_val', $r->targetId);
        $this->assertSame('#ff0000', $r->actualAfterState['style.color']);
        $this->assertTrue($r->verification['verified']);

        $html = $a->payload();
        // target element received element-scoped color:#ff0000
        $this->assertMatchesRegularExpression('/data-field="img_stat_val"[^>]*color:#ff0000/', $html);
        // text unchanged
        $this->assertStringContainsString('>98%</span>', $html);
        // NO global palette mutation
        $this->assertStringContainsString('--primary:#FFD60A', $html);
        $this->assertStringNotContainsString('--primary:#ff0000', $html);
        // other yellow element unchanged
        $this->assertStringContainsString('data-field="cta_label" style="color:#FFD60A"', $html);
        // unrelated element unchanged
        $this->assertStringContainsString('data-field="headline" style="color:#111111"', $html);
    }

    public function test_replay_does_not_reapply(): void
    {
        $a = HtmlProjectionFixture::rawAdapter();
        $first = $a->project(HtmlProjectionFixture::styleReq('img_stat_val', 'color', 'red', ['key' => 'k1']));
        $this->assertSame(ProjectionStatus::APPLIED, $first->status);
        $versionAfter = $a->currentVersion()->token;

        $replay = $a->project(HtmlProjectionFixture::styleReq('img_stat_val', 'color', 'red', ['key' => 'k1']));
        $this->assertSame(ReplayState::COMPLETED, $replay->replay);
        $this->assertSame($versionAfter, $a->currentVersion()->token); // not re-applied
    }

    public function test_stale_version_refuses_the_mutation(): void
    {
        $a = HtmlProjectionFixture::rawAdapter();
        $r = $a->project(HtmlProjectionFixture::styleReq('img_stat_val', 'color', 'red', ['ver' => 'h:deadbeefdeadbeef']));
        $this->assertSame(ProjectionStatus::STALE_VERSION, $r->status);
        $this->assertStringContainsString('color:var(--primary)', $a->payload()); // untouched
    }
}
