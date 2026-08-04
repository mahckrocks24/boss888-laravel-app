<?php

namespace Tests\Feature\Studio\Document;

use App\Engines\Studio\Document\Bbox;
use PHPUnit\Framework\TestCase;

/** Pure — no DB/Runtime/provider/browser. */
final class BboxTest extends TestCase
{
    public function test_geometry_helpers(): void
    {
        $b = new Bbox(10, 20, 100, 40);
        $this->assertSame(110.0, $b->right());
        $this->assertSame(60.0, $b->bottom());
        $this->assertSame(60.0, $b->centerX());
        $this->assertSame(40.0, $b->centerY());
        $this->assertSame(4000.0, $b->area());
    }

    public function test_intersects_and_encloses(): void
    {
        $a = new Bbox(0, 0, 100, 100);
        $inside = new Bbox(10, 10, 20, 20);
        $overlap = new Bbox(50, 50, 100, 100);
        $apart = new Bbox(200, 200, 10, 10);

        $this->assertTrue($a->encloses($inside));
        $this->assertTrue($a->intersects($overlap));
        $this->assertFalse($a->encloses($overlap));
        $this->assertFalse($a->intersects($apart));
    }

    public function test_axis_overlap_helpers(): void
    {
        $a = new Bbox(0, 0, 100, 20);       // x 0..100
        $below = new Bbox(10, 40, 80, 20);  // x 10..90 (shares horizontal range)
        $right = new Bbox(0, 5, 40, 20);    // y 5..25 (shares vertical range)

        $this->assertTrue($a->overlapsHorizontally($below));
        $this->assertTrue($a->overlapsVertically($right));
    }
}
