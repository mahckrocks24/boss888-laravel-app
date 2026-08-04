<?php

namespace Tests\Feature\Studio\Selection;

use App\Engines\Studio\Selection\GraphElementResolver;
use App\Engines\Studio\Selection\Inspector;
use App\Engines\Studio\Selection\SelectionEngine;
use PHPUnit\Framework\TestCase;
use Tests\Feature\Studio\Document\DocumentFixture;

/** Pure — read-only inspection; no mutation, no HTML, no execution. */
final class InspectorTest extends TestCase
{
    private function inspector(): Inspector
    {
        return new Inspector(new SelectionEngine(new GraphElementResolver()));
    }

    public function test_font_of(): void
    {
        $this->assertSame('Poppins', $this->inspector()->fontOf(DocumentFixture::graph(), 'headline'));
    }

    public function test_describe(): void
    {
        $d = $this->inspector()->describe(DocumentFixture::graph(), 'stat');
        $this->assertSame('stat', $d['role']);
        $this->assertSame(72, $d['font_size']);
        $this->assertSame('#ffd60a', $d['color']);
        $this->assertNull($this->inspector()->describe(DocumentFixture::graph(), 'nope'));
    }

    public function test_overlaps(): void
    {
        $pairs = $this->inspector()->overlaps(DocumentFixture::graph());
        $this->assertContains(['a' => 'badge', 'b' => 'stat'], $pairs);
    }

    public function test_hidden_and_locked(): void
    {
        $g = DocumentFixture::graph();
        $this->assertContains('hidden_el', $this->inspector()->hidden($g));
        $this->assertContains('locked_el', $this->inspector()->lockedElements($g));
    }

    public function test_uses_color(): void
    {
        $ids = $this->inspector()->usesColor(DocumentFixture::graph(), '#ffd60a');
        $this->assertEqualsCanonicalizing(['stat', 'cta'], $ids);
    }

    public function test_images(): void
    {
        $ids = $this->inspector()->images(DocumentFixture::graph());
        $this->assertContains('hero_img', $ids);
        $this->assertContains('logo', $ids);
        $this->assertContains('locked_el', $ids);
    }

    public function test_editable_text(): void
    {
        $ids = $this->inspector()->editableText(DocumentFixture::graph());
        $this->assertContains('headline', $ids);
        $this->assertContains('para1', $ids);
        $this->assertNotContains('hero_img', $ids);
    }
}
