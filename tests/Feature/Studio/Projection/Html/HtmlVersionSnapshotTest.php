<?php

namespace Tests\Feature\Studio\Projection\Html;

use App\Engines\Studio\Projection\ProjectionRequest;
use App\Engines\Studio\Projection\ProjectionResult;
use App\Engines\Studio\Projection\ProjectionStatus;
use PHPUnit\Framework\TestCase;

/** Pure - deterministic hashing, optimistic concurrency, snapshot safety. */
final class HtmlVersionSnapshotTest extends TestCase
{
    public function test_hash_is_deterministic_and_changes_on_mutation(): void
    {
        $a = HtmlProjectionFixture::rawAdapter();
        $b = HtmlProjectionFixture::rawAdapter();
        $this->assertSame($a->currentVersion()->token, $b->currentVersion()->token);

        $before = $a->currentVersion()->token;
        $a->project(HtmlProjectionFixture::styleReq('headline', 'color', 'red'));
        $this->assertNotSame($before, $a->currentVersion()->token);
    }

    public function test_serialization_is_deterministic(): void
    {
        $a = HtmlProjectionFixture::rawAdapter();
        $this->assertSame($a->payload(), $a->payload());
    }

    public function test_matching_version_applies(): void
    {
        $a = HtmlProjectionFixture::rawAdapter();
        $r = $a->project(HtmlProjectionFixture::styleReq('headline', 'color', 'red', ['ver' => $a->currentVersion()->token]));
        $this->assertSame(ProjectionStatus::APPLIED, $r->status);
    }

    public function test_stale_version_rejected(): void
    {
        $a = HtmlProjectionFixture::rawAdapter();
        $r = $a->project(HtmlProjectionFixture::styleReq('headline', 'color', 'red', ['ver' => 'h:0000000000000000']));
        $this->assertSame(ProjectionStatus::STALE_VERSION, $r->status);
        $this->assertSame(ProjectionResult::R_STALE_VERSION, $r->errorReason);
    }

    public function test_matching_snapshot_applies(): void
    {
        $a = HtmlProjectionFixture::rawAdapter();
        $hash = ProjectionRequest::hashState(['style.color' => $a->document()->get('headline', 'style.color')]);
        $r = $a->project(HtmlProjectionFixture::styleReq('headline', 'color', 'red', ['hash' => $hash]));
        $this->assertSame(ProjectionStatus::APPLIED, $r->status);
    }

    public function test_snapshot_mismatch_rejected(): void
    {
        $a = HtmlProjectionFixture::rawAdapter();
        $r = $a->project(HtmlProjectionFixture::styleReq('headline', 'color', 'red', ['hash' => 'deadbeefdeadbeef']));
        $this->assertSame(ProjectionStatus::STALE_VERSION, $r->status);
        $this->assertSame(ProjectionResult::R_SNAPSHOT_MISMATCH, $r->errorReason);
    }
}
