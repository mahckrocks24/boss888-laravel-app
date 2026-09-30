<?php

namespace Tests\Feature\Builder888;

use App\Engines\Builder\Services\TemplateService;
use App\Engines\Builder\Support\InlineText;
use App\Engines\Builder\Support\TemplateVariableNormalizer;
use Tests\TestCase;

/**
 * TEXTSAFE-1 (RFC-0021 wave 1) — fail-first tests written from REPORT-0068 findings #1 and #2 (2026-09-30):
 * pressing Enter published "<div>Second paragraph typed by the owner.</div>" as visible text on skyscope.levelupgrowth.io,
 * and a formatted paste published "<p><b>Fully insured</b> drone surveys — <i>same-week</i> slots.</p>". The payloads
 * below are the ones the certification captured.
 */
class InlineTextSanitiserTest extends TestCase
{
    private const SITE_ID = 999999902;
    private const FIXTURE = <<<'HTML'
<!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><title>TEXTSAFE fixture</title></head>
<body>
<section data-block="hero"><h1 data-field="hero_title">Old title</h1><p data-field="hero_subtitle">Old subtitle</p></section>
<section data-block="about"><p data-field="story_body">Founded to give installers a faster way.</p></section>
</body></html>
HTML;

    private string $dir; private string $path; private bool $madeSwitch = false;

    protected function setUp(): void
    {
        parent::setUp();
        $sw = storage_path(InlineText::SWITCH);
        if (! is_file($sw)) { touch($sw); $this->madeSwitch = true; }
        $this->dir = storage_path('app/public/sites/' . self::SITE_ID); $this->path = $this->dir . '/index.html';
        if (! is_dir($this->dir)) mkdir($this->dir, 0775, true);
        file_put_contents($this->path, self::FIXTURE);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/.history/*') ?: [] as $f) @unlink($f);
        @rmdir($this->dir . '/.history'); @unlink($this->path); @rmdir($this->dir);
        if ($this->madeSwitch) @unlink(storage_path(InlineText::SWITCH));
        parent::tearDown();
    }

    public function test_enter_in_a_paragraph_becomes_a_line_break_not_a_div(): void
    {
        $raw = 'Founded to give installers a faster way.<div>Second paragraph typed by the owner.</div>';
        $c = InlineText::canonical($raw, 'text', true);
        $this->assertSame("Founded to give installers a faster way.\nSecond paragraph typed by the owner.", $c);
        $this->assertStringNotContainsString('<', $c);
        $this->assertSame('Founded to give installers a faster way.<br>Second paragraph typed by the owner.', InlineText::rendered($c, 'text'));
    }

    public function test_formatted_paste_into_a_text_field_keeps_the_words_and_drops_the_markup(): void
    {
        $raw = '<p><b>Fully insured</b> drone surveys — <i>same-week</i> slots.</p>';
        $this->assertSame('Fully insured drone surveys — same-week slots.', InlineText::canonical($raw, 'text', true));
    }

    public function test_hostile_paste_never_reaches_the_page_as_markup_or_as_literal_tags(): void
    {
        $raw = '<b style="color:red" onclick="alert(1)">Bold pasted</b><script>alert(2)</script><img src=x onerror=alert(3)> <a href="javascript:alert(4)">link</a>';
        $c = InlineText::canonical($raw, 'text', true);
        $this->assertSame('Bold pasted link', $c);
        $h = InlineText::canonical($raw, 'html', true);
        $this->assertStringNotContainsString('onclick', $h); $this->assertStringNotContainsString('script', $h);
        $this->assertStringNotContainsString('onerror', $h); $this->assertStringNotContainsString('javascript', $h);
        $this->assertStringContainsString('<b>Bold pasted</b>', $h);
    }

    public function test_single_line_fields_never_carry_a_line_break(): void
    {
        $this->assertTrue(InlineText::isSingleLine('hero_title')); $this->assertTrue(InlineText::isSingleLine('nav_2'));
        $this->assertTrue(InlineText::isSingleLine('faq_1_q')); $this->assertFalse(InlineText::isSingleLine('story_body'));
        $this->assertFalse(InlineText::isSingleLine('faq_1_a')); $this->assertFalse(InlineText::isSingleLine('contact_hours'));
        $this->assertSame('Drone roof surveys across Greater Manchester', InlineText::canonical("Drone roof surveys<div>across Greater Manchester</div>", 'text', false));
    }

    public function test_blank_line_between_paragraphs_survives_as_one_blank_line(): void
    {
        $raw = 'One<div>Two</div><div><br></div><div>Three</div>';
        $this->assertSame("One\nTwo\n\nThree", InlineText::canonical($raw, 'text', true));
        $this->assertSame('One<br>Two<br><br>Three', InlineText::rendered("One\nTwo\n\nThree", 'text'));
    }

    public function test_entities_and_nbsp_are_decoded_once(): void
    {
        $this->assertSame('Fish & chips', InlineText::canonical('Fish &amp;&nbsp;chips', 'text', true));
        $this->assertSame('Fish &amp; chips', InlineText::rendered('Fish & chips', 'text'));
    }

    public function test_static_export_carries_the_line_break_as_a_br_not_as_text(): void
    {
        $ts = new TemplateService();
        $ok = $ts->updateField(self::SITE_ID, 'story_body', "Founded to give installers a faster way.\nSecond paragraph typed by the owner.", false);
        $this->assertTrue($ok);
        $html = file_get_contents($this->path);
        $this->assertStringContainsString('Founded to give installers a faster way.<br>Second paragraph typed by the owner.</p>', $html);
        $this->assertStringNotContainsString('&lt;div&gt;', $html); $this->assertStringNotContainsString('&lt;br&gt;', $html);
        $this->assertStringContainsString('<h1 data-field="hero_title">Old title</h1>', $html, 'the neighbouring field is untouched');
    }

    public function test_renderer_shows_a_stored_line_break_as_a_br(): void
    {
        $this->assertSame('Open Tue–Sun<br>7am–3pm', TemplateVariableNormalizer::forHtmlTyped('contact_hours', "Open Tue–Sun\n7am–3pm", 'text'));
        $this->assertSame('a &lt;b&gt; c', TemplateVariableNormalizer::forHtmlTyped('story_body', 'a <b> c', 'text'), 'a literal tag in stored text is still escaped');
    }
}
