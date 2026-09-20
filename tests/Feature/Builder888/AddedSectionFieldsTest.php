<?php

namespace Tests\Feature\Builder888;

use App\Engines\Builder\Support\AddedSectionFields;
use Tests\TestCase;

/**
 * RISK-0191 U3 (2026-09-20) — an added section is editable like the rest of the site.
 *
 * Every visible text leaf of a rendered section receives a stable `data-field="added_<type>_<n>"` identity (the
 * inline editor and Arthur's copy edits address text by that attribute); assignment is idempotent, never changes
 * visible text, skips scripts/styles/controls, and the block can be lifted back out of an export by its type.
 */
class AddedSectionFieldsTest extends TestCase
{
    private const HTML = '<section data-block="added_stats" id="lu-stats" data-lu-roles="1" style="padding:24px 0">'
        . '<style>.x{color:red}</style><script>var a = "not text";</script>'
        . '<h2 style="color:var(--lu-text, #111)">What we\'ve done</h2>'
        . '<div class="grid"><div><strong>3</strong><span>Services</span></div><div><strong>5★</strong><span>Client rating</span></div></div>'
        . '<p>Based in <em>Portland</em> since 2009 &amp; still &#10003; going</p>'
        . '<select><option>Pick</option></select><textarea>never</textarea>'
        . '<a href="#book" class="btn">Book now</a><span> </span><i>a</i>'
        . '</section>';

    public function test_every_visible_text_leaf_gets_a_stable_field_identity(): void
    {
        $r = AddedSectionFields::assign(self::HTML, 'stats');
        preg_match_all('/data-field="(added_stats_\d+)"/', $r['html'], $m);
        $this->assertSame(array_values(array_unique($m[1])), $m[1], 'field ids are unique');
        $this->assertSame($r['count'], count($m[1]));
        $this->assertSame(7, $r['count']);   // h2, 3, Services, 5★, Client rating, Portland, Book now (a <p> with element children is not a leaf)
        $this->assertSame('What we\'ve done', $r['values']['added_stats_1']);
        $this->assertContains('3', $r['values']);
        $this->assertContains('5★', $r['values']);
        $this->assertContains('Client rating', $r['values']);
        $this->assertContains('Book now', $r['values']);
        // controls, scripts and styles are not copy; a lone letter or blank span is not a field
        $this->assertStringNotContainsString('data-field="added_stats_', substr($r['html'], 0, strpos($r['html'], '<h2')));
        $this->assertDoesNotMatchRegularExpression('/<(select|option|textarea)[^>]*data-field/', $r['html']);
        $this->assertNotContains('never', $r['values']);
        $this->assertNotContains('a', $r['values']);
        $this->assertNotContains(' ', $r['values']);
    }

    public function test_assignment_is_idempotent_and_keeps_visible_text_and_roles(): void
    {
        $once = AddedSectionFields::assign(self::HTML, 'stats');
        $twice = AddedSectionFields::assign($once['html'], 'stats');
        $this->assertSame($once['html'], $twice['html']);
        $this->assertSame(0, $twice['count']);
        $vis = fn (string $h) => html_entity_decode(strip_tags((string) preg_replace('~<(style|script)\b[^>]*>.*?</\1>~is', '', $h)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $this->assertSame($vis(self::HTML), $vis($once['html']));
        $this->assertStringContainsString('var(--lu-text, #111)', $once['html']);
        $this->assertStringContainsString('data-lu-roles="1"', $once['html']);
        $this->assertStringContainsString('5★', $once['html'], 'UTF-8 survives the DOM round trip');
        $this->assertStringContainsString('var a = "not text";', $once['html']);
    }

    public function test_the_block_is_lifted_out_of_an_export_by_type(): void
    {
        $doc = '<html><body><section data-block="hero"><p>Hero</p></section>' . self::HTML
            . '<section data-block="added_faq"><h2>Q</h2></section></body></html>';
        $block = AddedSectionFields::extractBlock($doc, 'stats');
        $this->assertNotNull($block);
        $this->assertStringStartsWith('<section data-block="added_stats"', $block);
        $this->assertStringEndsWith('</section>', $block);
        $this->assertStringContainsString('Client rating', $block);
        $this->assertStringNotContainsString('added_faq', $block);
        $this->assertStringNotContainsString('Hero', $block);
        $this->assertNull(AddedSectionFields::extractBlock($doc, 'team'));
    }
}
