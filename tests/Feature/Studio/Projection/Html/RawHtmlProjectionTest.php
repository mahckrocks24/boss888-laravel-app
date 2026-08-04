<?php

namespace Tests\Feature\Studio\Projection\Html;

use App\Engines\Studio\Projection\Html\HtmlProjectionAdapter;
use App\Engines\Studio\Projection\Html\HtmlProjectionReason;
use App\Engines\Studio\Projection\ProjectionResult;
use App\Engines\Studio\Projection\ProjectionStatus;
use PHPUnit\Framework\TestCase;

/** Pure - raw HTML targeted mutation, verification, and security. */
final class RawHtmlProjectionTest extends TestCase
{
    // ---- TEXT ----

    public function test_text_replace(): void
    {
        $a = HtmlProjectionFixture::rawAdapter();
        $r = $a->project(HtmlProjectionFixture::textReq('headline', 'Mega Sale'));
        $this->assertSame(ProjectionStatus::APPLIED, $r->status);
        $this->assertSame('Mega Sale', $r->actualAfterState['text']);
        $this->assertStringContainsString('>Mega Sale</h1>', $a->payload());
    }

    public function test_text_append_and_prepend_outcomes(): void
    {
        $a = HtmlProjectionFixture::rawAdapter();
        // append_text/prepend_text collapse to a final 'text' value at the projection layer.
        $this->assertSame(ProjectionStatus::APPLIED, $a->project(HtmlProjectionFixture::textReq('headline', 'Big Sale 2026'))->status);
        $this->assertSame('Big Sale 2026', $a->document()->get('headline', 'text'));
    }

    public function test_text_on_non_leaf_is_unsupported(): void
    {
        $a = HtmlProjectionFixture::rawAdapter();
        $r = $a->project(HtmlProjectionFixture::textReq('wrapper', 'x'));
        $this->assertSame(ProjectionStatus::UNSUPPORTED, $r->status);
        $this->assertSame(HtmlProjectionReason::NON_LEAF_TARGET, $r->errorReason);
    }

    // ---- STYLE ----

    public function test_style_color_normalizes_and_applies(): void
    {
        $a = HtmlProjectionFixture::rawAdapter();
        $r = $a->project(HtmlProjectionFixture::styleReq('headline', 'color', 'red'));
        $this->assertSame(ProjectionStatus::APPLIED, $r->status);
        $this->assertSame('#ff0000', $r->actualAfterState['style.color']);
        $this->assertStringContainsString('color:#ff0000', $a->payload());
    }

    /** @dataProvider styleProps */
    public function test_safe_style_properties(string $prop, string $value, string $expected): void
    {
        $a = HtmlProjectionFixture::rawAdapter();
        $r = $a->project(HtmlProjectionFixture::styleReq('headline', $prop, $value));
        $this->assertSame(ProjectionStatus::APPLIED, $r->status, "prop $prop");
        $this->assertSame($expected, $a->document()->get('headline', 'style.' . $prop));
    }

    public static function styleProps(): array
    {
        return [
            ['background-color', '#000', '#000000'],
            ['opacity', '0.5', '0.5'],
            ['font-size', '60px', '60px'],
            ['font-weight', '700', '700'],
            ['text-align', 'center', 'center'],
        ];
    }

    public function test_invalid_style_value_is_rejected(): void
    {
        $a = HtmlProjectionFixture::rawAdapter();
        $r = $a->project(HtmlProjectionFixture::styleReq('headline', 'color', 'not-a-color'));
        $this->assertSame(ProjectionStatus::REJECTED, $r->status);
        $this->assertSame('invalid_color', $r->errorReason);
        $this->assertStringContainsString('color:#111111', $a->payload()); // untouched
    }

    public function test_unsupported_property_is_rejected(): void
    {
        $a = HtmlProjectionFixture::rawAdapter();
        $r = $a->project(HtmlProjectionFixture::styleReq('headline', 'border-radius', '8px'));
        $this->assertSame(ProjectionStatus::UNSUPPORTED, $r->status);
        $this->assertSame(ProjectionResult::R_UNSUPPORTED_PROPERTY, $r->errorReason);
    }

    // ---- SECURITY ----

    public function test_text_is_escaped_not_written_as_markup(): void
    {
        $a = HtmlProjectionFixture::rawAdapter();
        $r = $a->project(HtmlProjectionFixture::textReq('headline', '<b>x</b>'));
        $this->assertSame(ProjectionStatus::APPLIED, $r->status);
        $this->assertSame('<b>x</b>', $r->actualAfterState['text']); // decoded value matches
        $html = $a->payload();
        $this->assertStringContainsString('&lt;b&gt;x&lt;/b&gt;', $html);
        $this->assertStringNotContainsString('<b>x</b>', $html); // no raw markup injected
    }

    public function test_output_strips_scripts_events_and_edit_only_attrs(): void
    {
        $html = HtmlProjectionFixture::rawAdapter()->payload();
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('onclick', $html);
        $this->assertStringNotContainsString('contenteditable', $html);
    }

    // ---- TARGETING ----

    public function test_missing_target(): void
    {
        $r = HtmlProjectionFixture::rawAdapter()->project(HtmlProjectionFixture::textReq('ghost', 'x'));
        $this->assertSame(ProjectionStatus::TARGET_MISSING, $r->status);
    }

    public function test_duplicate_data_field_is_ambiguous(): void
    {
        $a = HtmlProjectionAdapter::forRawHtml(HtmlProjectionFixture::duplicateHtml());
        $r = $a->project(HtmlProjectionFixture::textReq('dup', 'x'));
        $this->assertSame(ProjectionStatus::REJECTED, $r->status);
        $this->assertSame(HtmlProjectionReason::AMBIGUOUS_TARGET, $r->errorReason);
    }

    public function test_no_op_is_failed_not_applied(): void
    {
        $a = HtmlProjectionFixture::rawAdapter();
        $r = $a->project(HtmlProjectionFixture::styleReq('headline', 'color', '#111111')); // already this
        $this->assertSame(ProjectionStatus::FAILED, $r->status);
        $this->assertSame(ProjectionResult::R_NO_CHANGE, $r->errorReason);
        $this->assertFalse($r->isSuccess());
    }

    // ---- HIDE / SHOW ----

    public function test_hide_and_show(): void
    {
        $a = HtmlProjectionFixture::rawAdapter();
        $hide = $a->project(HtmlProjectionFixture::visibleReq('headline', false));
        $this->assertSame(ProjectionStatus::APPLIED, $hide->status);
        $this->assertFalse($a->document()->get('headline', 'visible'));
        $this->assertStringContainsString('display:none', $a->payload());

        $show = $a->project(HtmlProjectionFixture::visibleReq('headline', true));
        $this->assertSame(ProjectionStatus::APPLIED, $show->status);
        $this->assertTrue($a->document()->get('headline', 'visible'));
        $this->assertStringNotContainsString('display:none', $a->payload());
    }
}
