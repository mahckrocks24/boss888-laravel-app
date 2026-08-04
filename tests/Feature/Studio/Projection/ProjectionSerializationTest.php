<?php

namespace Tests\Feature\Studio\Projection;

use App\Engines\Studio\Projection\ProjectionBatch;
use App\Engines\Studio\Projection\ProjectionCapability;
use App\Engines\Studio\Projection\ProjectionRequest;
use App\Engines\Studio\Projection\ProjectionResult;
use App\Engines\Studio\Projection\ProjectionStatus;
use App\Engines\Studio\Projection\ProjectionTransactionBoundary;
use App\Engines\Studio\Projection\StudioDocumentVersion;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/** Pure - deterministic serialization; JSON/postMessage-ready. */
final class ProjectionSerializationTest extends TestCase
{
    public function test_request_round_trips(): void
    {
        $r = ProjectionFixture::color('stat', '#ff0000', ['ver' => 'v1', 'key' => 'k1']);
        $this->assertEquals($r->toArray(), ProjectionRequest::fromArray($r->toArray())->toArray());
    }

    public function test_result_round_trips(): void
    {
        $res = new ProjectionResult(ProjectionStatus::APPLIED, 'o1', 'stat', ['style.color'], [], 'v2', ['style.color' => '#ff0000'], ['verified' => true, 'matched' => 1, 'total' => 1], null, 'ok', 0.0, 'first', 'corr-1');
        $this->assertEquals($res->toArray(), ProjectionResult::fromArray($res->toArray())->toArray());
    }

    public function test_capability_round_trips(): void
    {
        $c = ProjectionCapability::referenceDefault();
        $this->assertEquals($c->toArray(), ProjectionCapability::fromArray($c->toArray())->toArray());
    }

    public function test_batch_round_trips(): void
    {
        $b = new ProjectionBatch('b1', 'doc1', [ProjectionFixture::color('stat', '#ff0000')], ProjectionTransactionBoundary::atomic(), 'bk1', 'corr-1');
        $this->assertEquals($b->toArray(), ProjectionBatch::fromArray($b->toArray())->toArray());
    }

    public function test_version_and_boundary_round_trip(): void
    {
        $v = StudioDocumentVersion::of('v7');
        $this->assertTrue($v->equals(StudioDocumentVersion::fromArray($v->toArray())));

        $tb = ProjectionTransactionBoundary::bestEffortContinue();
        $this->assertSame('best_effort_continue', ProjectionTransactionBoundary::fromArray($tb->toArray())->label());
    }

    public function test_schema_version_is_present_and_checked(): void
    {
        $arr = ProjectionFixture::color('stat', '#ff0000')->toArray();
        $this->assertSame(1, $arr['schema_version']);

        $this->expectException(InvalidArgumentException::class);
        ProjectionRequest::fromArray(['schema_version' => 99] + $arr);
    }

    public function test_strict_field_validation_rejects_missing_field(): void
    {
        $arr = ProjectionFixture::color('stat', '#ff0000')->toArray();
        unset($arr['target_id']);
        $this->expectException(InvalidArgumentException::class);
        ProjectionRequest::fromArray($arr);
    }
}
