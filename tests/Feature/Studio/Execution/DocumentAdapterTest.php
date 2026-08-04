<?php

namespace Tests\Feature\Studio\Execution;

use App\Engines\Studio\Document\Bbox;
use App\Engines\Studio\Document\StudioElement;
use App\Engines\Studio\Execution\InMemoryStudioDocumentAdapter;
use App\Engines\Studio\Execution\Support\ElementPatch;
use PHPUnit\Framework\TestCase;

/** Pure — in-memory adapter; renderer-neutral, no persistence. */
final class DocumentAdapterTest extends TestCase
{
    private function adapter(): InMemoryStudioDocumentAdapter
    {
        return new InMemoryStudioDocumentAdapter([
            new StudioElement(id: 'a', role: 'body', text: 'A', bbox: new Bbox(0, 0, 10, 10)),
            new StudioElement(id: 'b', role: 'body', text: 'B', bbox: new Bbox(0, 20, 10, 10)),
            new StudioElement(id: 'L', role: 'body', text: 'L', locked: true, bbox: new Bbox(0, 40, 10, 10)),
        ]);
    }

    public function test_find_and_all(): void
    {
        $a = $this->adapter();
        $this->assertSame('A', $a->find('a')->text);
        $this->assertNull($a->find('nope'));
        $this->assertCount(3, $a->all());
    }

    public function test_replace_is_immutable_and_preserves_untouched_elements(): void
    {
        $a = $this->adapter();
        $bBefore = $a->find('b');

        $this->assertTrue($a->replace(ElementPatch::with($a->find('a'), ['text' => 'A2'])));
        $this->assertSame('A2', $a->find('a')->text);
        $this->assertSame($bBefore, $a->find('b')); // untouched element identity preserved
    }

    public function test_locked_element_rejects_normal_write(): void
    {
        $a = $this->adapter();
        $ok = $a->replace(ElementPatch::with($a->find('L'), ['text' => 'hacked']));
        $this->assertFalse($ok);
        $this->assertSame('L', $a->find('L')->text); // unchanged
    }

    public function test_locked_element_accepts_unlock_write(): void
    {
        $a = $this->adapter();
        $ok = $a->replace(ElementPatch::with($a->find('L'), ['locked' => false]), true);
        $this->assertTrue($ok);
        $this->assertFalse($a->find('L')->locked);
    }

    public function test_snapshot_shape(): void
    {
        $snap = $this->adapter()->snapshot();
        $this->assertArrayHasKey('a', $snap);
        $this->assertSame('A', $snap['a']['text']);
    }
}
