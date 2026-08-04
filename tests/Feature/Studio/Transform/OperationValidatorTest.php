<?php

namespace Tests\Feature\Studio\Transform;

use App\Engines\Studio\Transform\ColorNormalizer;
use App\Engines\Studio\Transform\OperationRegistry;
use App\Engines\Studio\Transform\OperationValidator;
use App\Engines\Studio\Transform\ValidationResult;
use PHPUnit\Framework\TestCase;

/**
 * Pure — no DB, Runtime, providers, browser, credits, image-gen, or billing.
 */
final class OperationValidatorTest extends TestCase
{
    private OperationValidator $v;

    protected function setUp(): void
    {
        $this->v = new OperationValidator(new OperationRegistry(true), new ColorNormalizer());
    }

    /** @param array<int,array> $ops */
    private function batch(array $ops, int $version = 1): array
    {
        return ['version' => $version, 'operations' => $ops];
    }

    private function styleColor(string $value, string $text = '98%'): array
    {
        return [
            'op_id' => 'o1', 'type' => 'set_style',
            'target' => ['strategy' => 'visible_text', 'text' => $text],
            'property' => 'color', 'value' => $value,
        ];
    }

    // ---- ACCEPTANCE ----

    public function test_change_98_percent_to_red_validates_to_set_style_color_ff0000_without_palette_mutation(): void
    {
        $r = $this->v->validate($this->batch([$this->styleColor('red')]));

        $this->assertTrue($r->ok);
        $op = $r->accepted()[0];
        $this->assertSame('set_style', $op['type']);
        $this->assertSame('color', $op['property']);
        $this->assertSame('#ff0000', $op['normalized_value']);

        // No palette mutation is introduced by validation.
        foreach ($r->operations as $o) {
            $this->assertNotSame('apply_palette', $o['type']);
        }
    }

    // ---- STRUCTURAL / SCHEMA ----

    public function test_malformed_batch_missing_operations(): void
    {
        $r = $this->v->validate(['version' => 1]);
        $this->assertFalse($r->ok);
        $this->assertTrue($r->isMalformed());
        $this->assertSame([ValidationResult::R_MALFORMED_BATCH], $r->errors);
    }

    public function test_unsupported_schema_version(): void
    {
        $r = $this->v->validate($this->batch([$this->styleColor('red')], 2));
        $this->assertSame([ValidationResult::R_UNSUPPORTED_VERSION], $r->errors);
    }

    // ---- PER-OP REJECTIONS ----

    private function assertRejected(array $op, string $reason): void
    {
        $r = $this->v->validate($this->batch([$op]));
        $this->assertFalse($r->ok, "expected batch to fail for reason $reason");
        $this->assertSame($reason, $r->rejected()[0]['failure_reason']);
    }

    public function test_missing_type(): void
    {
        $this->assertRejected(['target' => ['field' => 'x'], 'value' => 'red'], ValidationResult::R_MISSING_TYPE);
    }

    public function test_unknown_operation(): void
    {
        $this->assertRejected(['type' => 'teleport', 'target' => ['field' => 'x']], ValidationResult::R_UNKNOWN_OPERATION);
    }

    public function test_unavailable_operation_cannot_be_planned(): void
    {
        $this->assertRejected(
            ['type' => 'generate_video', 'target' => ['role' => 'media'], 'value' => 'a cat'],
            ValidationResult::R_UNAVAILABLE
        );
    }

    public function test_missing_target(): void
    {
        $this->assertRejected(['type' => 'set_style', 'property' => 'color', 'value' => 'red'], ValidationResult::R_MISSING_TARGET);
    }

    public function test_raw_selector_target_is_rejected(): void
    {
        $this->assertRejected(
            ['type' => 'set_style', 'target' => '.hero .stat > span', 'property' => 'color', 'value' => 'red'],
            ValidationResult::R_RAW_SELECTOR
        );
    }

    public function test_missing_property_on_property_bearing_op(): void
    {
        $this->assertRejected(
            ['type' => 'set_style', 'target' => ['field' => 'x'], 'value' => 'red'],
            ValidationResult::R_MISSING_PROPERTY
        );
    }

    public function test_unregistered_custom_property_is_rejected(): void
    {
        $this->assertRejected(
            ['type' => 'set_style', 'target' => ['field' => 'x'], 'property' => '--accent', 'value' => 'red'],
            ValidationResult::R_CUSTOM_PROPERTY
        );
    }

    public function test_property_not_allowed(): void
    {
        $this->assertRejected(
            ['type' => 'set_style', 'target' => ['field' => 'x'], 'property' => 'position', 'value' => 'absolute'],
            ValidationResult::R_PROPERTY_NOT_ALLOWED
        );
    }

    public function test_bad_color(): void
    {
        $this->assertRejected($this->styleColor('not-a-color'), ValidationResult::R_INVALID_COLOR);
    }

    public function test_raw_css_value_is_rejected_as_unsafe(): void
    {
        $this->assertRejected($this->styleColor('red;position:fixed'), ValidationResult::R_UNSAFE_VALUE);
    }

    public function test_url_value_is_rejected_as_unsafe(): void
    {
        $this->assertRejected($this->styleColor('url(http://evil/x)'), ValidationResult::R_UNSAFE_VALUE);
    }

    public function test_javascript_value_is_rejected_as_unsafe(): void
    {
        $this->assertRejected($this->styleColor('javascript:alert(1)'), ValidationResult::R_UNSAFE_VALUE);
    }

    public function test_bad_unit_on_length(): void
    {
        $this->assertRejected(
            ['type' => 'move', 'target' => ['field' => 'x'], 'value' => '10vh'],
            ValidationResult::R_INVALID_UNIT
        );
    }

    public function test_out_of_range_length(): void
    {
        $this->assertRejected(
            ['type' => 'move', 'target' => ['field' => 'x'], 'value' => '999999px'],
            ValidationResult::R_OUT_OF_RANGE
        );
    }

    public function test_bad_enum_value(): void
    {
        $this->assertRejected(
            ['type' => 'align', 'target' => ['field' => 'x'], 'value' => 'sideways'],
            ValidationResult::R_INVALID_ENUM
        );
    }

    public function test_media_reference_rejects_external_url(): void
    {
        $this->assertRejected(
            ['type' => 'replace_media', 'target' => ['role' => 'hero'], 'value' => 'https://evil/x.png'],
            ValidationResult::R_UNSAFE_VALUE
        );
    }

    // ---- ACCEPTED VARIANTS (normalization) ----

    public function test_hex_color_normalizes(): void
    {
        $r = $this->v->validate($this->batch([$this->styleColor('#F00')]));
        $this->assertSame('#ff0000', $r->accepted()[0]['normalized_value']);
    }

    public function test_length_move_normalizes(): void
    {
        $r = $this->v->validate($this->batch([['type' => 'move', 'target' => ['field' => 'x'], 'value' => '20px']]));
        $this->assertTrue($r->ok);
        $this->assertSame('20px', $r->accepted()[0]['normalized_value']);
    }

    public function test_enum_align_accepts_valid_value(): void
    {
        $r = $this->v->validate($this->batch([['type' => 'align', 'target' => ['field' => 'x'], 'value' => 'center']]));
        $this->assertTrue($r->ok);
        $this->assertSame('center', $r->accepted()[0]['normalized_value']);
    }

    public function test_set_text_accepts_ordinary_punctuation(): void
    {
        $r = $this->v->validate($this->batch([['type' => 'set_text', 'target' => ['field' => 'x'], 'value' => 'Up to 98% faster; guaranteed']]));
        $this->assertTrue($r->ok, 'plain copy with ; should be accepted for text content');
    }

    public function test_set_text_rejects_script_markup(): void
    {
        $this->assertRejected(
            ['type' => 'set_text', 'target' => ['field' => 'x'], 'value' => '<script>alert(1)</script>'],
            ValidationResult::R_UNSAFE_VALUE
        );
    }

    public function test_batch_is_ok_only_if_all_ops_pass(): void
    {
        $r = $this->v->validate($this->batch([
            $this->styleColor('red'),
            ['type' => 'align', 'target' => ['field' => 'x'], 'value' => 'sideways'],
        ]));
        $this->assertFalse($r->ok);
        $this->assertCount(1, $r->accepted());
        $this->assertCount(1, $r->rejected());
    }
}
