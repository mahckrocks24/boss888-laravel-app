<?php

namespace Tests\Feature\Studio\Projection\Html;

use App\Engines\Studio\Projection\ProjectionResult;
use App\Engines\Studio\Projection\ProjectionStatus;
use App\Engines\Studio\Projection\ReplayState;
use PHPUnit\Framework\TestCase;

/** Pure - idempotent replay never mutates twice. */
final class HtmlIdempotencyTest extends TestCase
{
    public function test_completed_replay_returns_cached_without_reapplying(): void
    {
        $a = HtmlProjectionFixture::rawAdapter();
        $first = $a->project(HtmlProjectionFixture::styleReq('headline', 'color', 'red', ['key' => 'k1']));
        $this->assertSame(ProjectionStatus::APPLIED, $first->status);
        $version = $a->currentVersion()->token;

        $replay = $a->project(HtmlProjectionFixture::styleReq('headline', 'color', 'red', ['key' => 'k1']));
        $this->assertSame(ReplayState::COMPLETED, $replay->replay);
        $this->assertSame(ProjectionStatus::APPLIED, $replay->status);
        $this->assertSame($version, $a->currentVersion()->token);
    }

    public function test_in_progress_replay_does_not_apply(): void
    {
        $a = HtmlProjectionFixture::rawAdapter();
        $a->markInProgress('k2');
        $r = $a->project(HtmlProjectionFixture::styleReq('headline', 'color', 'red', ['key' => 'k2']));
        $this->assertSame(ReplayState::IN_PROGRESS, $r->replay);
        $this->assertSame(ProjectionResult::R_IN_PROGRESS, $r->errorReason);
        $this->assertStringContainsString('color:#111111', $a->payload());
    }

    public function test_expired_replay_requires_fresh_key(): void
    {
        $a = HtmlProjectionFixture::rawAdapter();
        $a->expire('k3');
        $r = $a->project(HtmlProjectionFixture::styleReq('headline', 'color', 'red', ['key' => 'k3']));
        $this->assertSame(ReplayState::EXPIRED, $r->replay);
        $this->assertSame(ProjectionResult::R_EXPIRED, $r->errorReason);
    }
}
