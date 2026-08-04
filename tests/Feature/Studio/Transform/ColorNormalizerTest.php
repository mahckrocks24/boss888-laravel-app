<?php

namespace Tests\Feature\Studio\Transform;

use App\Engines\Studio\Transform\ColorNormalizer;
use PHPUnit\Framework\TestCase;

/**
 * Pure — no DB, Runtime, providers, browser, credits, image-gen, or billing.
 */
final class ColorNormalizerTest extends TestCase
{
    private ColorNormalizer $n;

    protected function setUp(): void
    {
        $this->n = new ColorNormalizer();
    }

    /** @dataProvider validColors */
    public function test_valid_colors_normalize_to_canonical_hex(string $in, string $expected): void
    {
        $this->assertSame($expected, $this->n->normalize($in));
        $this->assertTrue($this->n->isValid($in));
    }

    public static function validColors(): array
    {
        return [
            'named red'        => ['red', '#ff0000'],
            'named RED upper'  => ['RED', '#ff0000'],
            'named with space' => ['  red  ', '#ff0000'],
            'gold'             => ['gold', '#ffd700'],
            'hex short'        => ['#f00', '#ff0000'],
            'hex short alpha'  => ['#f00c', '#ff0000cc'],
            'hex long'         => ['#FF0000', '#ff0000'],
            'hex long alpha'   => ['#ff0000cc', '#ff0000cc'],
            'rgb'              => ['rgb(255, 0, 0)', '#ff0000'],
            'rgba'             => ['rgba(255,0,0,0.8)', '#ff0000cc'],
        ];
    }

    /** @dataProvider invalidColors */
    public function test_unsafe_or_unknown_values_are_rejected(string $in): void
    {
        $this->assertNull($this->n->normalize($in), "should reject: $in");
        $this->assertFalse($this->n->isValid($in));
    }

    public static function invalidColors(): array
    {
        return [
            'empty'         => [''],
            'whitespace'    => ['   '],
            'unknown name'  => ['reddish'],
            'var()'         => ['var(--primary)'],
            'url()'         => ['url(http://evil/x.png)'],
            'javascript'    => ['javascript:alert(1)'],
            'expression'    => ['expression(alert(1))'],
            'calc'          => ['calc(100%)'],
            'markup'        => ['<b>red</b>'],
            'semicolon'     => ['red;color:blue'],
            'brace'         => ['red}body{'],
            'bad hex len'   => ['#ff000'],
            'non-hex'       => ['#gggggg'],
            'rgb overflow'  => ['rgb(300,0,0)'],
            'rgba bad alpha'=> ['rgba(0,0,0,5)'],
        ];
    }
}
