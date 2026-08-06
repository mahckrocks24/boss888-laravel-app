<?php

namespace Tests\Unit\Studio;

use App\Engines\Studio\Projection\Html\HtmlProjectionAdapter;
use App\Engines\Studio\Projection\ProjectionBatch;
use App\Engines\Studio\Projection\ProjectionRequest;
use App\Engines\Studio\Projection\ProjectionStatus;
use App\Engines\Studio\Projection\ProjectionTransactionBoundary;
use PHPUnit\Framework\TestCase;

/**
 * STUDIO888 · Feature Sprint 1 — AI Style Editing.
 *
 * Pure unit test (NO database, NO app boot): exercises exactly the committed
 * set_style projection + ProjectionBatch calls the /studio/chat closure makes.
 * Route wiring (flag/scope/CAS/response) is proven by browser QA in the report.
 */
final class StyleEditServerTest extends TestCase
{
    private const RAW =
        '<!doctype html><html><head><style>:root{--primary:#FFD60A}</style></head><body>'
        . '<h1 data-field="headline" style="color:#111111">Big Sale</h1>'
        . '<span data-field="stat_2_val" style="color:#ffd60a">98%</span>'
        . '<span data-field="cta_label" style="color:#111">Buy now</span>'
        . '</body></html>';

    private static function structured(): array
    {
        return ['template_slug' => 'restaurant', 'fields' => ['headline' => 'Old Title']];
    }

    /** Build a style ProjectionRequest exactly like the closure. */
    private static function styleReq(string $target, string $prop, string $value, int $i = 0): ProjectionRequest
    {
        $p = 'style.' . $prop;
        return ProjectionRequest::fromArray([
            'schema_version' => 1, 'operation_id' => 'chat-style-' . $i, 'document_id' => 'd',
            'target_id' => $target, 'changed_fields' => [$p], 'desired_after_state' => [$p => $value],
            'expected_document_version' => null, 'before_snapshot_hash' => null,
            'correlation_id' => 'c', 'idempotency_key' => null, 'batch_id' => null, 'projection_meta' => [],
        ]);
    }

    private static function batch(HtmlProjectionAdapter $a, array $reqs)
    {
        return $a->projectBatch(new ProjectionBatch('b', 'd', $reqs, ProjectionTransactionBoundary::atomic(), null, 'c'));
    }

    public function test_single_color_blue_applies_and_verifies(): void
    {
        $a = HtmlProjectionAdapter::forRawHtml(self::RAW);
        $res = self::batch($a, [self::styleReq('headline', 'color', 'blue')]);
        $this->assertSame(ProjectionStatus::APPLIED, $res->status);
        $this->assertSame(1, $res->appliedCount());
        $this->assertTrue(($res->results[0]->verification['verified'] ?? false) === true);
        $this->assertSame('#0000ff', $res->results[0]->actualAfterState['style.color']);
        $this->assertStringContainsString('color:#0000ff', (string) $a->payload());
    }

    public function test_font_size_weight_align_opacity_background(): void
    {
        foreach ([['font-size', '72px'], ['font-weight', 'bold'], ['text-align', 'center'], ['opacity', '0.5'], ['background-color', 'blue']] as $c) {
            $a = HtmlProjectionAdapter::forRawHtml(self::RAW);
            $res = self::batch($a, [self::styleReq('headline', $c[0], $c[1])]);
            $this->assertSame(ProjectionStatus::APPLIED, $res->status, "prop {$c[0]}");
            $this->assertTrue(($res->results[0]->verification['verified'] ?? false) === true, "verify {$c[0]}");
        }
    }

    public function test_invalid_color_rejected_not_applied(): void
    {
        $a = HtmlProjectionAdapter::forRawHtml(self::RAW);
        $before = (string) $a->payload();
        $res = self::batch($a, [self::styleReq('headline', 'color', 'definitely-not-a-color')]);
        $this->assertNotSame(ProjectionStatus::APPLIED, $res->status);
        $this->assertSame($before, (string) $a->payload());   // atomic => unchanged
    }

    public function test_unsupported_property_rejected(): void
    {
        $a = HtmlProjectionAdapter::forRawHtml(self::RAW);
        $res = self::batch($a, [self::styleReq('headline', 'margin', '10px')]);
        $this->assertNotSame(ProjectionStatus::APPLIED, $res->status);
    }

    public function test_batch_all_valid_verified_once(): void
    {
        $a = HtmlProjectionAdapter::forRawHtml(self::RAW);
        $res = self::batch($a, [
            self::styleReq('headline', 'color', 'blue', 0),
            self::styleReq('stat_2_val', 'color', 'blue', 1),
            self::styleReq('cta_label', 'background-color', 'blue', 2),
        ]);
        $this->assertSame(ProjectionStatus::APPLIED, $res->status);
        $this->assertSame(3, $res->appliedCount());
        $html = (string) $a->payload();
        $this->assertSame(3, substr_count($html, '#0000ff'));  // all three now blue
    }

    public function test_batch_atomic_one_invalid_rolls_back_all(): void
    {
        $a = HtmlProjectionAdapter::forRawHtml(self::RAW);
        $before = (string) $a->payload();
        $res = self::batch($a, [
            self::styleReq('headline', 'color', 'blue', 0),           // valid
            self::styleReq('stat_2_val', 'color', 'not-a-color', 1),  // invalid => whole batch fails
        ]);
        $this->assertSame(ProjectionStatus::FAILED, $res->status);
        $this->assertSame($before, (string) $a->payload());          // headline NOT blue (rolled back)
    }

    public function test_structured_template_declines_style(): void
    {
        $a = HtmlProjectionAdapter::forStructured(self::structured());
        $before = $a->payload();
        $res = self::batch($a, [self::styleReq('headline', 'color', 'blue')]);
        $this->assertSame(ProjectionStatus::FAILED, $res->status);   // structured supports text only
        $this->assertSame($before, $a->payload());                    // untouched
    }
}
