<?php

namespace Tests\Unit\Studio;

use App\Engines\Studio\Projection\Html\HtmlProjectionAdapter;
use App\Engines\Studio\Projection\ProjectionBatch;
use App\Engines\Studio\Projection\ProjectionRequest;
use App\Engines\Studio\Projection\ProjectionStatus;
use App\Engines\Studio\Projection\ProjectionTransactionBoundary;
use PHPUnit\Framework\TestCase;

/**
 * STUDIO888 · Feature Sprint 6 — AI Campaign Creator.
 *
 * Pure unit test (NO database, NO app boot). The campaign is ORCHESTRATION over the committed
 * primitives: for each LLM-declared section it runs ONE ProjectionBatch on a SHARED adapter, so
 * sections accumulate; a section that verifies is kept, a section that fails rolls back ONLY
 * itself (the page keeps the sections that already succeeded), and a section with no buildable
 * ops is skipped. One final persist upstream. These tests prove exactly that section-level
 * behaviour. Route wiring (section tags, flags/scope, one hero generation, brand-once dedup,
 * dirty guard, CAS persist, section-grouped truthful reply, actions:[]/zero-PUT) is proven by
 * browser QA in the report.
 */
final class Sprint6CampaignServerTest extends TestCase
{
    private const RAW =
        '<!doctype html><html><head><style>:root{--primary:#FFD60A;--accent:#FF3B30;--text:#111111}</style></head><body>'
        . '<img data-field="hero_image" src="https://cdn.example.com/old.jpg">'
        . '<h1 data-field="headline_1" style="color:#111;font-size:40px">Big Sale</h1>'
        . '<p data-field="subheading" style="color:#333">Everything must go</p>'
        . '<span data-field="stat_1_val" style="color:#111">98%</span>'
        . '<span data-field="stat_1_label" style="color:#111">Happy</span>'
        . '<span data-field="cta_label" style="color:#111">Buy now</span>'
        . '</body></html>';

    private static function structured(): array
    {
        return ['template_slug' => 'restaurant', 'fields' => ['headline' => 'Old Title']];
    }

    private static function textReq(string $t, string $v, string $id): ProjectionRequest
    {
        return ProjectionRequest::fromArray([
            'schema_version' => 1, 'operation_id' => $id, 'document_id' => 'd',
            'target_id' => $t, 'changed_fields' => ['text'], 'desired_after_state' => ['text' => $v],
            'expected_document_version' => null, 'before_snapshot_hash' => null,
            'correlation_id' => 'c', 'idempotency_key' => null, 'batch_id' => null, 'projection_meta' => [],
        ]);
    }

    private static function styleReq(string $t, string $prop, string $v, string $id): ProjectionRequest
    {
        $p = 'style.' . $prop;
        return ProjectionRequest::fromArray([
            'schema_version' => 1, 'operation_id' => $id, 'document_id' => 'd',
            'target_id' => $t, 'changed_fields' => [$p], 'desired_after_state' => [$p => $v],
            'expected_document_version' => null, 'before_snapshot_hash' => null,
            'correlation_id' => 'c', 'idempotency_key' => null, 'batch_id' => null, 'projection_meta' => [],
        ]);
    }

    /** Run one SECTION as its own atomic batch on the SHARED adapter (as the campaign block does). */
    private static function section(HtmlProjectionAdapter $a, array $reqs, string $sid)
    {
        return $a->projectBatch(new ProjectionBatch('camp-' . $sid, 'd', $reqs, ProjectionTransactionBoundary::atomic(), null, 'camp'));
    }

    /** 1. Two sections each their own batch accumulate on one shared adapter (page builds up). */
    public function test_two_sections_accumulate_on_shared_adapter(): void
    {
        $a = HtmlProjectionAdapter::forRawHtml(self::RAW);
        $hero = self::section($a, [
            self::textReq('headline_1', 'Build Bold', 'h-t'),
            self::styleReq('headline_1', 'font-size', '72px', 'h-s'),
        ], '0');
        $cta = self::section($a, [self::textReq('cta_label', 'Get a quote', 'c-t')], '1');
        $this->assertSame(ProjectionStatus::APPLIED, $hero->status);
        $this->assertSame(ProjectionStatus::APPLIED, $cta->status);
        $html = (string) $a->payload();
        $this->assertStringContainsString('Build Bold', $html);       // hero section persisted
        $this->assertStringContainsString('font-size:72px', $html);
        $this->assertStringContainsString('Get a quote', $html);      // cta section persisted too
    }

    /** 2. A failing section rolls back ONLY itself; earlier sections remain on the adapter. */
    public function test_failed_section_rolls_back_only_itself(): void
    {
        $a = HtmlProjectionAdapter::forRawHtml(self::RAW);
        $hero = self::section($a, [self::textReq('headline_1', 'Kept', 'h-t')], '0');
        $this->assertSame(ProjectionStatus::APPLIED, $hero->status);
        // stats section has one invalid style op => its batch fails
        $stats = self::section($a, [
            self::textReq('stat_1_val', '250+', 's-t'),
            self::styleReq('stat_1_val', 'color', 'not-a-color', 's-s'),
        ], '1');
        $this->assertNotSame(ProjectionStatus::APPLIED, $stats->status);
        $html = (string) $a->payload();
        $this->assertStringContainsString('Kept', $html);             // hero survived
        $this->assertStringContainsString('98%', $html);              // stat NOT changed (section rolled back)
        $this->assertStringNotContainsString('250+', $html);
    }

    /** 3. A section with only unbuildable ops yields no reqs => skipped, later section still applies. */
    public function test_empty_section_skipped_not_fatal(): void
    {
        $doc = HtmlProjectionAdapter::forRawHtml(self::RAW)->document();
        // 'about_*' fields do not exist => the campaign builds ZERO reqs for that section => skipped
        $this->assertFalse($doc->supports('about_title', 'text'));
        $this->assertFalse($doc->supports('about_body', 'text'));
        // a following buildable section still applies on a fresh shared adapter
        $a = HtmlProjectionAdapter::forRawHtml(self::RAW);
        $cta = self::section($a, [self::textReq('cta_label', 'Contact us', 'c-t')], '2');
        $this->assertSame(ProjectionStatus::APPLIED, $cta->status);
        $this->assertStringContainsString('Contact us', (string) $a->payload());
    }

    /** 4. Section order is preserved (hero before cta) and ops within a section preserved. */
    public function test_section_and_op_order_preserved(): void
    {
        $a = HtmlProjectionAdapter::forRawHtml(self::RAW);
        $r1 = self::section($a, [self::textReq('headline_1', 'First', 'a')], '0');
        $r2 = self::section($a, [self::textReq('subheading', 'Second', 'b')], '1');
        $this->assertSame(ProjectionStatus::APPLIED, $r1->status);
        $this->assertSame(ProjectionStatus::APPLIED, $r2->status);
        $html = (string) $a->payload();
        $this->assertLessThan(strpos($html, 'Second'), strpos($html, 'First')); // hero appears before sub in doc
    }

    /** 5. supports() gate omits unbuildable targets within a section (never faked). */
    public function test_supports_gate_within_section(): void
    {
        $doc = HtmlProjectionAdapter::forRawHtml(self::RAW)->document();
        $this->assertTrue($doc->supports('headline_1', 'text'));
        $this->assertTrue($doc->supports('cta_label', 'style.color'));
        $this->assertTrue($doc->supports('hero_image', 'src'));
        $this->assertTrue($doc->supports(':root', 'var.--primary'));
        $this->assertFalse($doc->supports('services_title', 'text'));  // no such field
        $this->assertFalse($doc->supports('headline_1', 'style.margin'));
    }

    /** 6. Structured template declines (engine is text-only there). */
    public function test_structured_template_declines(): void
    {
        $a = HtmlProjectionAdapter::forStructured(self::structured());
        $before = $a->payload();
        $res = self::section($a, [self::styleReq('headline', 'color', 'blue', 'x')], '0');
        $this->assertSame(ProjectionStatus::FAILED, $res->status);
        $this->assertSame($before, $a->payload());
    }

    /** 7. A section may mix text + style + brand-var + image src (all four groups) and verify. */
    public function test_section_mixes_all_four_groups(): void
    {
        $a = HtmlProjectionAdapter::forRawHtml(self::RAW);
        $res = self::section($a, [
            self::textReq('headline_1', 'Premium Homes', 'h-t'),
            self::styleReq('headline_1', 'font-weight', 'bold', 'h-s'),
            ProjectionRequest::fromArray([
                'schema_version' => 1, 'operation_id' => 'h-b', 'document_id' => 'd',
                'target_id' => ':root', 'changed_fields' => ['var.--primary'], 'desired_after_state' => ['var.--primary' => '#0b5fff'],
                'expected_document_version' => null, 'before_snapshot_hash' => null,
                'correlation_id' => 'c', 'idempotency_key' => null, 'batch_id' => null, 'projection_meta' => [],
            ]),
            ProjectionRequest::fromArray([
                'schema_version' => 1, 'operation_id' => 'h-img', 'document_id' => 'd',
                'target_id' => 'hero_image', 'changed_fields' => ['src'], 'desired_after_state' => ['src' => 'https://cdn.example.com/lux.jpg'],
                'expected_document_version' => null, 'before_snapshot_hash' => null,
                'correlation_id' => 'c', 'idempotency_key' => null, 'batch_id' => null, 'projection_meta' => [],
            ]),
        ], '0');
        $this->assertSame(ProjectionStatus::APPLIED, $res->status);
        $this->assertSame(4, $res->appliedCount());
        $html = strtolower((string) $a->payload());
        $this->assertStringContainsString('premium homes', $html);
        $this->assertStringContainsString('--primary:#0b5fff', $html);
        $this->assertStringContainsString('lux.jpg', $html);
    }

    /** 8. Page completes partial: [ok, skipped-empty, ok] => adapter carries ok sections only. */
    public function test_page_completes_partial_across_sections(): void
    {
        $a = HtmlProjectionAdapter::forRawHtml(self::RAW);
        $s0 = self::section($a, [self::textReq('headline_1', 'Alpha', 'a')], '0');       // ok
        // section 1: no buildable reqs (campaign would skip it entirely — nothing to batch)
        $s2 = self::section($a, [self::textReq('cta_label', 'Omega', 'c')], '2');        // ok
        $this->assertSame(ProjectionStatus::APPLIED, $s0->status);
        $this->assertSame(ProjectionStatus::APPLIED, $s2->status);
        $html = (string) $a->payload();
        $this->assertStringContainsString('Alpha', $html);
        $this->assertStringContainsString('Omega', $html);
    }
}
