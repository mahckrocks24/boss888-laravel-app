<?php

namespace Tests\Feature\Builder888;

use App\Engines\Builder\Services\BuilderRenderer;
use Tests\TestCase;

/**
 * BUILDER888 · P1-7 · FAMILY 3 — RENDERER SECURITY.
 *
 * Permanent protection for P0-3: hero heading, cta_link and components[].href
 * reached executable context in HTML served on live customer domains
 * (chefredraymundo.com, amgtravelandtours.com).
 *
 * Renders REAL sections and asserts the resulting HTML — unit-testing
 * safeUrl() alone would not have caught the original defect, because the
 * heading path never called it.
 */
class RendererSecurityTest extends TestCase
{
    private const BRAND = [
        'primary' => '#6C5CE7', 'secondary' => '#00b894',
        'font_heading' => 'Inter', 'font_body' => 'Inter',
    ];

    private function render(array $section): string
    {
        return app(BuilderRenderer::class)
            ->renderSection($section, self::BRAND, ['name' => 'T'], [], 'home');
    }

    /** The only thing that matters: did anything land somewhere it executes? */
    private function assertNotExecutable(string $html, string $case): void
    {
        $this->assertDoesNotMatchRegularExpression(
            '/(href|src)\s*=\s*"?\s*(javascript|vbscript|data:text\/html)/i',
            $html, "executable URL scheme survived: {$case}"
        );
        $this->assertStringNotContainsString('<script', $html, "script tag survived: {$case}");
        $this->assertDoesNotMatchRegularExpression(
            '/\son[a-z]+\s*=\s*"[^"]*alert/i',
            $html, "event handler survived: {$case}"
        );
    }

    public function test_headings_and_body_copy_cannot_inject_markup(): void
    {
        foreach (['hero', 'features', 'cta', 'text'] as $type) {
            foreach ([
                '<script>alert(1)</script>',
                '<img src=x onerror=alert(1)>',
                '"><script>alert(1)</script>',
                '<svg onload=alert(1)>',
            ] as $payload) {
                $html = $this->render([
                    'type' => $type, 'heading' => $payload, 'subheading' => $payload,
                    'body' => $payload, 'cta_text' => 'Go',
                ]);
                $this->assertNotExecutable($html, "{$type} / " . substr($payload, 0, 24));
            }
        }
    }

    public function test_every_cta_url_field_rejects_executable_schemes(): void
    {
        $hostile = [
            'javascript:alert(1)',
            'JaVaScRiPt:alert(1)',
            "java\tscript:alert(1)",
            'java&#09;script:alert(1)',
            "  javascript:alert(1)",
            'vbscript:msgbox(1)',
            'data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg==',
        ];

        foreach (['cta_url', 'cta_link'] as $field) {
            foreach ($hostile as $url) {
                foreach (['hero', 'cta', 'features'] as $type) {
                    $html = $this->render([
                        'type' => $type, 'heading' => 'H', 'cta_text' => 'Go', $field => $url,
                    ]);
                    $this->assertNotExecutable($html, "{$type}.{$field} = " . substr($url, 0, 22));
                }
            }
        }
    }

    public function test_component_hrefs_reject_executable_schemes(): void
    {
        foreach (['javascript:alert(1)', 'vbscript:x', 'data:text/html,<script>alert(1)</script>'] as $url) {
            $html = $this->render([
                'type' => 'hero', 'heading' => 'H',
                'components' => [['type' => 'button', 'text' => 'Go', 'href' => $url]],
            ]);
            $this->assertNotExecutable($html, 'components[].href = ' . substr($url, 0, 22));
        }
    }

    public function test_attribute_breakout_is_impossible(): void
    {
        $break = '#" onmouseover="alert(1)" x="';

        foreach (['cta_url', 'cta_link'] as $field) {
            $html = $this->render(['type' => 'hero', 'heading' => 'H', 'cta_text' => 'Go', $field => $break]);
            $this->assertStringNotContainsString('onmouseover="alert(1)"', $html);
        }

        $html = $this->render(['type' => 'hero', 'heading' => $break, 'cta_text' => 'Go']);
        $this->assertStringNotContainsString('onmouseover="alert(1)"', $html);
    }

    /**
     * EV-0715: section background_image / style.gradient / style.bg were interpolated
     * RAW into style="..." across renderHero/renderCta/account_panel — a double-quote
     * breaks out of the attribute and injects live HTML on the published site. Fixed
     * with safeCss() (bg/gradient) + safeUrl() (background_image). This locks it in.
     */
    public function test_css_background_fields_cannot_break_out(): void
    {
        foreach (['hero', 'cta', 'account_panel'] as $type) {
            $html = $this->render([
                'type'             => $type,
                'heading'          => 'H',
                'background_image' => 'x");}</style><img src=a onerror=alert(1)>',
                'style'            => [
                    'gradient' => 'red;}</section><script>alert(1)</script>',
                    'bg'       => '#fff"><svg onload=alert(1)>',
                ],
            ]);
            // A breakout requires an unescaped live payload tag; safeCss/safeUrl strip
            // or escape the '<' and the attribute-terminating '"'.
            $this->assertStringNotContainsString('<img src=a onerror', $html, "{$type}: img breakout");
            $this->assertStringNotContainsString('<svg onload', $html, "{$type}: svg breakout");
            $this->assertStringNotContainsString('<script>alert(1)', $html, "{$type}: script breakout");
            $this->assertNotExecutable($html, "{$type} css background fields");
        }
    }

    public function test_legitimate_urls_still_work(): void
    {
        // A sanitiser that rejects everything is not a sanitiser.
        foreach ([
            'https://example.com/pricing',
            'http://example.com',
            'mailto:hello@example.com',
            'tel:+441234567890',
            '/contact',
            '#services',
            '?utm_source=x',
        ] as $safe) {
            $html = $this->render(['type' => 'hero', 'heading' => 'H', 'cta_text' => 'Go', 'cta_link' => $safe]);
            $this->assertStringContainsString('href=', $html);
            $this->assertNotExecutable($html, "legitimate {$safe}");
        }
    }

    public function test_legitimate_copy_renders_intact(): void
    {
        $html = $this->render([
            'type' => 'hero',
            'heading' => 'Sails & Rigging — Plymouth',
            'subheading' => "Quality you can trust",
            'cta_text' => 'Book a survey',
        ]);

        $this->assertStringContainsString('Plymouth', $html);
        $this->assertStringContainsString('Book a survey', $html);
        // Ampersands must be escaped, not dropped.
        $this->assertMatchesRegularExpression('/Sails\s*&(amp;|#0?38;)\s*Rigging/i', $html);
    }
}
