<?php

namespace Tests\Unit\Studio;

use App\Engines\Studio\Projection\Html\HtmlProjectionAdapter;
use App\Engines\Studio\Projection\Html\HtmlProjectionSecurityPolicy;
use App\Engines\Studio\Projection\ProjectionRequest;
use App\Engines\Studio\Projection\ProjectionStatus;
use PHPUnit\Framework\TestCase;

/**
 * STUDIO888 · Feature Sprint 3 — verified brand colour application (:root palette projection).
 * Pure unit test (no DB/app): exercises the committed projection calls the /studio/chat
 * apply_brand block makes, plus the colour-value safety gate. Brand-kit resolution + route
 * wiring are proven by browser QA in the report.
 */
final class BrandColorServerTest extends TestCase
{
    private const RAW =
        '<!doctype html><html><head><style>:root{--primary:#111111;--accent:#222222;--bg:#000000;--text:#eeeeee;--custom:#abcabc}</style></head><body>'
        . '<img data-field="hero" src="/a.png" alt="H">'
        . '<h1 data-field="headline" style="color:#111111">Big Sale</h1>'
        . '</body></html>';

    private const NONSTD = '<html><head><style>:root{--coral:#f00;--ink:#012}</style></head><body><h1 data-field="h">x</h1></body></html>';
    private const NOROOT = '<html><body><h1 data-field="h">x</h1></body></html>';
    private const TWOROOT = '<html><head><style>:root{--primary:#111}</style><style>@media(x){:root{--primary:#222}}</style></head><body><h1 data-field="h">x</h1></body></html>';

    private static function req(string $target, array $fields, array $desired): ProjectionRequest
    {
        return ProjectionRequest::fromArray([
            'schema_version' => 1, 'operation_id' => 'b', 'document_id' => 'd', 'target_id' => $target,
            'changed_fields' => $fields, 'desired_after_state' => $desired,
            'expected_document_version' => null, 'before_snapshot_hash' => null,
            'correlation_id' => 'c', 'idempotency_key' => null, 'batch_id' => null, 'projection_meta' => [],
        ]);
    }

    public function test_single_var_applied_and_verified_preserving_everything(): void
    {
        $a = HtmlProjectionAdapter::forRawHtml(self::RAW);
        $r = $a->project(self::req(':root', ['var.--primary'], ['var.--primary' => '#0b5fff']));
        $this->assertSame(ProjectionStatus::APPLIED, $r->status);
        $this->assertTrue(($r->verification['verified'] ?? false) === true);
        $this->assertSame('#0b5fff', $r->actualAfterState['var.--primary']);
        $html = (string) $a->payload();
        $this->assertStringContainsString('--primary:#0b5fff', $html);
        $this->assertStringContainsString('--accent:#222222', $html);   // other canonical preserved
        $this->assertStringContainsString('--bg:#000000', $html);       // non-canonical preserved
        $this->assertStringContainsString('--custom:#abcabc', $html);   // unrelated var preserved
        $this->assertStringContainsString('Big Sale', $html);           // text preserved
        $this->assertStringContainsString('color:#111111', $html);      // inline element style preserved
        $this->assertStringContainsString('src="/a.png"', $html);       // image preserved
    }

    public function test_atomic_multi_var_update(): void
    {
        $a = HtmlProjectionAdapter::forRawHtml(self::RAW);
        $r = $a->project(self::req(':root', ['var.--primary', 'var.--accent', 'var.--text'],
            ['var.--primary' => '#0b5fff', 'var.--accent' => '#ff6b00', 'var.--text' => '#101828']));
        $this->assertSame(ProjectionStatus::APPLIED, $r->status);
        $this->assertTrue(($r->verification['verified'] ?? false) === true);
        $html = (string) $a->payload();
        $this->assertStringContainsString('--primary:#0b5fff', $html);
        $this->assertStringContainsString('--accent:#ff6b00', $html);
        $this->assertStringContainsString('--text:#101828', $html);
    }

    public function test_read_existing_var(): void
    {
        $a = HtmlProjectionAdapter::forRawHtml(self::RAW);
        $this->assertSame('#111111', $a->document()->get(':root', 'var.--primary'));
    }

    public function test_missing_canonical_var_not_invented(): void
    {
        $a = HtmlProjectionAdapter::forRawHtml(self::RAW);   // --secondary + --background absent
        $before = (string) $a->payload();
        $r = $a->project(self::req(':root', ['var.--secondary'], ['var.--secondary' => '#123456']));
        $this->assertNotSame(ProjectionStatus::APPLIED, $r->status);
        $this->assertSame($before, (string) $a->payload());
        $this->assertStringNotContainsString('--secondary', (string) $a->payload());
    }

    public function test_same_value_noop_fails(): void
    {
        $a = HtmlProjectionAdapter::forRawHtml(self::RAW);
        $r = $a->project(self::req(':root', ['var.--primary'], ['var.--primary' => '#111111']));
        $this->assertNotSame(ProjectionStatus::APPLIED, $r->status);
    }

    public function test_invalid_and_unsafe_colour_rejected(): void
    {
        foreach (['url(x)', 'var(--y)', 'expression(1)', 'red;color:blue', '#zz', '<svg>', 'a{b}'] as $bad) {
            $a = HtmlProjectionAdapter::forRawHtml(self::RAW);
            $before = (string) $a->payload();
            $r = $a->project(self::req(':root', ['var.--primary'], ['var.--primary' => $bad]));
            $this->assertNotSame(ProjectionStatus::APPLIED, $r->status, "unsafe: {$bad}");
            $this->assertSame($before, (string) $a->payload(), "unchanged: {$bad}");
        }
    }

    public function test_non_canonical_var_not_advertised(): void
    {
        $a = HtmlProjectionAdapter::forRawHtml(self::NONSTD);
        $this->assertFalse($a->capability()->supportsField('var.--coral'));   // allowlist: only 5 canonical
        $r = $a->project(self::req(':root', ['var.--primary'], ['var.--primary' => '#0b5fff']));
        $this->assertNotSame(ProjectionStatus::APPLIED, $r->status);          // --primary absent here
    }

    public function test_zero_and_multiple_root_blocks_reject(): void
    {
        $this->assertNotSame(ProjectionStatus::APPLIED,
            HtmlProjectionAdapter::forRawHtml(self::NOROOT)->project(self::req(':root', ['var.--primary'], ['var.--primary' => '#0b5fff']))->status);
        $this->assertNotSame(ProjectionStatus::APPLIED,
            HtmlProjectionAdapter::forRawHtml(self::TWOROOT)->project(self::req(':root', ['var.--primary'], ['var.--primary' => '#0b5fff']))->status);
    }

    public function test_structured_document_declines(): void
    {
        $a = HtmlProjectionAdapter::forStructured(['template_slug' => 't', 'fields' => ['headline' => 'x']]);
        $r = $a->project(self::req(':root', ['var.--primary'], ['var.--primary' => '#0b5fff']));
        $this->assertNotSame(ProjectionStatus::APPLIED, $r->status);
    }

    public function test_colour_value_policy(): void
    {
        $p = new HtmlProjectionSecurityPolicy();
        foreach (['#fff', '#FFD60A', '#11223344', 'red', 'rgb(1,2,3)', 'rgba(1,2,3,0.5)'] as $ok) {
            $this->assertTrue($p->isSafeColorValue($ok), "accept {$ok}");
        }
        foreach (['url(x)', 'var(--y)', 'expression(1)', 'javascript:1', 'a;b', 'a{b}', '<x>', "a'b", 'linear-gradient(x)'] as $bad) {
            $this->assertFalse($p->isSafeColorValue($bad), "reject {$bad}");
        }
    }
}
