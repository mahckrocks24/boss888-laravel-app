<?php

namespace Tests\Unit\Studio;

use App\Engines\Studio\Projection\Html\HtmlProjectionAdapter;
use App\Engines\Studio\Projection\ProjectionBatch;
use App\Engines\Studio\Projection\ProjectionRequest;
use App\Engines\Studio\Projection\ProjectionStatus;
use App\Engines\Studio\Projection\ProjectionTransactionBoundary;
use PHPUnit\Framework\TestCase;

/**
 * STUDIO888 · Feature Sprint 5 — AI Section Makeover.
 *
 * Pure unit test (NO database, NO app boot): the makeover is ORCHESTRATION over the
 * already-committed primitives, so its heart is a single HETEROGENEOUS ProjectionBatch
 * (text + style + brand :root var + image src) applied atomically on ONE adapter — exactly
 * what the /studio/chat makeover block builds. These tests prove:
 *   - a mixed 4-group makeover applies + verifies in ONE atomic batch (one persist upstream),
 *   - one invalid op rolls the ENTIRE makeover back (nothing changes),
 *   - the build-time supports() gate omits unbuildable targets (never fakes a change),
 *   - structured templates decline.
 * Route wiring (heterogeneity gate, flag/scope, image generation, dirty guard, CAS persist,
 * aggregated truthful reply, actions:[]/zero-PUT) is proven by browser QA in the report.
 */
final class Sprint5MakeoverServerTest extends TestCase
{
    private const RAW =
        '<!doctype html><html><head><style>:root{--primary:#FFD60A;--accent:#FF3B30;--text:#111111}</style></head><body>'
        . '<h1 data-field="headline" style="color:#111111;font-size:40px">Big Sale</h1>'
        . '<p data-field="subheading" style="color:#333">Everything must go</p>'
        . '<span data-field="cta_label" style="color:#111">Buy now</span>'
        . '<img data-field="hero_image" src="https://cdn.example.com/old.jpg">'
        . '<span data-field="dup">A</span><span data-field="dup">B</span>'   // ambiguous (count 2)
        . '</body></html>';

    private static function structured(): array
    {
        return ['template_slug' => 'restaurant', 'fields' => ['headline' => 'Old Title']];
    }

    private static function textReq(string $t, string $v, int $i): ProjectionRequest
    {
        return ProjectionRequest::fromArray([
            'schema_version' => 1, 'operation_id' => 'chat-mk-text-' . $i, 'document_id' => 'd',
            'target_id' => $t, 'changed_fields' => ['text'], 'desired_after_state' => ['text' => $v],
            'expected_document_version' => null, 'before_snapshot_hash' => null,
            'correlation_id' => 'c', 'idempotency_key' => null, 'batch_id' => null, 'projection_meta' => [],
        ]);
    }

    private static function styleReq(string $t, string $prop, string $v, int $i): ProjectionRequest
    {
        $p = 'style.' . $prop;
        return ProjectionRequest::fromArray([
            'schema_version' => 1, 'operation_id' => 'chat-mk-style-' . $i, 'document_id' => 'd',
            'target_id' => $t, 'changed_fields' => [$p], 'desired_after_state' => [$p => $v],
            'expected_document_version' => null, 'before_snapshot_hash' => null,
            'correlation_id' => 'c', 'idempotency_key' => null, 'batch_id' => null, 'projection_meta' => [],
        ]);
    }

    private static function varReq(string $var, string $v): ProjectionRequest
    {
        return ProjectionRequest::fromArray([
            'schema_version' => 1, 'operation_id' => 'chat-mk-brand-' . ltrim($var, '-'), 'document_id' => 'd',
            'target_id' => ':root', 'changed_fields' => ['var.' . $var], 'desired_after_state' => ['var.' . $var => $v],
            'expected_document_version' => null, 'before_snapshot_hash' => null,
            'correlation_id' => 'c', 'idempotency_key' => null, 'batch_id' => null, 'projection_meta' => [],
        ]);
    }

    private static function srcReq(string $t, string $v): ProjectionRequest
    {
        return ProjectionRequest::fromArray([
            'schema_version' => 1, 'operation_id' => 'chat-mk-img', 'document_id' => 'd',
            'target_id' => $t, 'changed_fields' => ['src'], 'desired_after_state' => ['src' => $v],
            'expected_document_version' => null, 'before_snapshot_hash' => null,
            'correlation_id' => 'c', 'idempotency_key' => null, 'batch_id' => null, 'projection_meta' => [],
        ]);
    }

    private static function batch(HtmlProjectionAdapter $a, array $reqs)
    {
        return $a->projectBatch(new ProjectionBatch('b', 'd', $reqs, ProjectionTransactionBoundary::atomic(), null, 'c'));
    }

    /** 1. Luxury makeover: headline text + headline style + brand var + hero src — all four groups, one atomic batch. */
    public function test_luxury_makeover_all_four_groups_verify_atomically(): void
    {
        $a = HtmlProjectionAdapter::forRawHtml(self::RAW);
        $res = self::batch($a, [
            self::textReq('headline', 'The Private Collection', 0),
            self::styleReq('headline', 'font-size', '72px', 1),
            self::varReq('--primary', '#0B5FFF'),
            self::srcReq('hero_image', 'https://cdn.example.com/lux.jpg'),
        ]);
        $this->assertSame(ProjectionStatus::APPLIED, $res->status);
        $this->assertSame(4, $res->appliedCount());
        foreach ($res->results as $rr) {
            $this->assertTrue(($rr->verification['verified'] ?? false) === true);
        }
        $html = (string) $a->payload();
        $this->assertStringContainsString('The Private Collection', $html);
        $this->assertStringContainsString('font-size:72px', $html);
        $this->assertStringContainsString('--primary:#0b5fff', strtolower($html));
        $this->assertStringContainsString('https://cdn.example.com/lux.jpg', $html);
    }

    /** 2. Corporate makeover: multiple style ops in one batch verify together. */
    public function test_corporate_makeover_multiple_style_ops(): void
    {
        $a = HtmlProjectionAdapter::forRawHtml(self::RAW);
        $res = self::batch($a, [
            self::styleReq('headline', 'font-weight', 'bold', 0),
            self::styleReq('headline', 'text-align', 'center', 1),
            self::styleReq('cta_label', 'background-color', '#0B5FFF', 2),
        ]);
        $this->assertSame(ProjectionStatus::APPLIED, $res->status);
        $this->assertSame(3, $res->appliedCount());
    }

    /** 3. Startup makeover: hero src + headline text + cta text. */
    public function test_startup_makeover_image_and_two_text(): void
    {
        $a = HtmlProjectionAdapter::forRawHtml(self::RAW);
        $res = self::batch($a, [
            self::srcReq('hero_image', 'https://cdn.example.com/bright.jpg'),
            self::textReq('headline', 'Ship faster', 0),
            self::textReq('cta_label', 'Start free', 1),
        ]);
        $this->assertSame(ProjectionStatus::APPLIED, $res->status);
        $this->assertSame(3, $res->appliedCount());
        $html = (string) $a->payload();
        $this->assertStringContainsString('Ship faster', $html);
        $this->assertStringContainsString('Start free', $html);
    }

    /** 4. Atomic rollback: one invalid op => the ENTIRE makeover rolls back, nothing changes. */
    public function test_one_invalid_op_rolls_back_entire_makeover(): void
    {
        $a = HtmlProjectionAdapter::forRawHtml(self::RAW);
        $before = (string) $a->payload();
        $res = self::batch($a, [
            self::textReq('headline', 'Premium', 0),                 // valid
            self::styleReq('headline', 'color', 'not-a-real-color', 1), // invalid => whole batch fails
            self::varReq('--primary', '#0B5FFF'),                    // valid
        ]);
        $this->assertNotSame(ProjectionStatus::APPLIED, $res->status);
        $this->assertSame($before, (string) $a->payload());          // headline NOT changed, var NOT changed
    }

    /** 5. Build-time supports() gate: unbuildable targets are omitted, never faked. */
    public function test_supports_gate_omits_unbuildable_targets(): void
    {
        $doc = HtmlProjectionAdapter::forRawHtml(self::RAW)->document();
        // buildable
        $this->assertTrue($doc->supports('headline', 'text'));
        $this->assertTrue($doc->supports('headline', 'style.font-size'));
        $this->assertTrue($doc->supports('hero_image', 'src'));
        $this->assertTrue($doc->supports(':root', 'var.--primary'));
        // NOT buildable => omitted by the makeover before batching
        $this->assertFalse($doc->supports('missing_field', 'text'));       // field absent
        $this->assertFalse($doc->supports('headline', 'style.margin'));    // unsupported property
        $this->assertFalse($doc->supports(':root', 'var.--nonexistent'));  // var absent
        $this->assertFalse($doc->supports('dup', 'text'));                 // ambiguous (count != 1)
        $this->assertFalse($doc->supports('headline', 'src'));             // not an image
    }

    /** 6. Buildable subset still applies atomically after omission (proves partial-but-truthful shape). */
    public function test_buildable_subset_applies_when_some_omitted(): void
    {
        // Simulate the makeover having omitted 'missing_field' and kept the two buildable ops.
        $a = HtmlProjectionAdapter::forRawHtml(self::RAW);
        $res = self::batch($a, [
            self::textReq('headline', 'Refined', 0),
            self::varReq('--text', '#0B5FFF'),
        ]);
        $this->assertSame(ProjectionStatus::APPLIED, $res->status);
        $this->assertSame(2, $res->appliedCount());
    }

    /** 7. Structured template declines (engine is text-only there; makeover returns a decline). */
    public function test_structured_template_declines(): void
    {
        $a = HtmlProjectionAdapter::forStructured(self::structured());
        $before = $a->payload();
        $res = self::batch($a, [self::styleReq('headline', 'color', 'blue', 0)]);
        $this->assertSame(ProjectionStatus::FAILED, $res->status);
        $this->assertSame($before, $a->payload());
    }

    /** 8. Invalid image src target rolls back the whole makeover (atomic across groups). */
    public function test_invalid_image_target_rolls_back_all(): void
    {
        $a = HtmlProjectionAdapter::forRawHtml(self::RAW);
        $before = (string) $a->payload();
        $res = self::batch($a, [
            self::textReq('headline', 'Bold Move', 0),   // valid
            self::srcReq('headline', 'https://x/y.jpg'), // headline is not an <img> => fails
        ]);
        $this->assertNotSame(ProjectionStatus::APPLIED, $res->status);
        $this->assertSame($before, (string) $a->payload());
    }
}
