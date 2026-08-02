<?php

namespace Tests\Feature\Studio;

use App\Engines\Studio\Services\StudioService;
use Tests\TestCase;

/**
 * STUDIO888 P0 BUG-001 — design render regression guard.
 *
 * A design's content_html may be Arthur JSON ({template_slug, fields}) rather than
 * rendered HTML. The editor preview MUST resolve it to real template HTML — it must
 * NEVER return raw JSON to the canvas (the production-blocking bug this pins).
 *
 * Pure render test — no DB: StudioService::renderHtml() takes a design object and
 * reads the on-disk template, so a stdClass stand-in is sufficient and fast.
 */
class StudioDesignRenderTest extends TestCase
{
    private function design(array $o): object
    {
        // Mirror the studio_designs columns the renderer reads (a real DB row has these).
        return (object) array_merge(
            ['content_html' => '', 'name' => 'Test', 'canvas_width' => 1080, 'canvas_height' => 1080, 'background_value' => null],
            $o
        );
    }

    private function render(object $d): string
    {
        return (string) app(StudioService::class)->renderHtml($d);
    }

    /** @test */
    public function arthur_json_design_renders_as_template_html_never_raw_json(): void
    {
        $json = json_encode([
            'template_slug' => 't06-restaurant-spotlight',
            'fields' => ['headline' => 'SEARED DUCK BREAST', 'subheadline' => 'Crispy skin, tender center', 'cta' => 'Reserve Your Table'],
        ]);
        $html = $this->render($this->design(['content_html' => $json]));

        // The core guarantee: NEVER dump raw JSON onto the canvas.
        $this->assertStringStartsNotWith('{', trim($html), 'render must not begin with raw JSON');
        $this->assertStringNotContainsString('"template_slug"', $html, 'JSON keys must not leak into the canvas');
        // It must be a real, editable template render.
        $this->assertMatchesRegularExpression('/<(html|body|div)/i', $html, 'must be HTML markup');
        $this->assertStringContainsString('data-field', $html, 'must expose editable data-field elements');
        $this->assertStringNotContainsString('"fields"', $html, 'no JSON keys may leak into the canvas');
        $this->assertGreaterThan(500, strlen($html), 'a real template render is substantial, not a stub');
        // Arthur's copy must be populated (field mapper).
        $this->assertStringContainsString('SEARED DUCK BREAST', $html, 'Arthur headline must populate');
        $this->assertStringContainsString('Crispy skin, tender center', $html, 'Arthur subheadline must populate (alias→subheading)');
        $this->assertStringContainsString('Reserve Your Table', $html, 'Arthur CTA must populate');
    }

    /** @test */
    public function already_rendered_html_passes_through_unchanged(): void
    {
        $doc = '<!doctype html><html><body><h1 data-field="headline">Hello</h1></body></html>';
        $html = $this->render($this->design(['content_html' => $doc]));
        $this->assertStringContainsString('<h1 data-field="headline">Hello</h1>', $html);
    }

    /** @test */
    public function empty_content_returns_a_shell_not_json(): void
    {
        $html = $this->render($this->design(['content_html' => '', 'name' => 'Test']));
        $this->assertStringStartsNotWith('{', trim($html));
        $this->assertNotEmpty($html);
        $this->assertMatchesRegularExpression('/<(html|body|div)/i', $html);
        $this->assertStringContainsString('Test', $html, 'shell shows the design name');
    }

    /** @test */
    public function json_with_unknown_template_never_returns_raw_json(): void
    {
        $json = json_encode(['template_slug' => 'does-not-exist-zzz', 'fields' => ['headline' => 'X']]);
        $html = $this->render($this->design(['content_html' => $json]));
        $this->assertStringStartsNotWith('{', trim($html), 'unknown template must fall back to a shell, not raw JSON');
        $this->assertStringNotContainsString('"template_slug"', $html);
        $this->assertMatchesRegularExpression('/<(html|body|div)/i', $html, 'unknown template still yields HTML');
        $this->assertNotEmpty($html);
    }
}
