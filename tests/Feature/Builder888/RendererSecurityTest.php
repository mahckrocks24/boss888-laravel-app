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

    /**
     * STORED XSS (2026-08-26) — cta_url / continue_shopping_url on the grid,
     * events_calendar and cart_summary section renderers were htmlspecialchars()-only,
     * which escapes quotes but does NOT block a javascript:/vbscript:/data: scheme, so a
     * malicious value rendered as href="javascript:alert(1)" on a published page (a
     * click-triggered stored XSS). They now route through safeUrl(). Regression across the
     * affected section types and every executable-scheme smuggling form.
     */
    public function test_cta_url_cannot_carry_an_executable_scheme(): void
    {
        $renderer = app(BuilderRenderer::class);
        $payloads = [
            'javascript:alert(1)',
            'JaVaScRiPt:alert(document.cookie)',
            'vbscript:msgbox(1)',
            "java\tscript:alert(1)",
            'data:text/html,<script>alert(1)</script>',
        ];
        foreach (['grid', 'events_calendar', 'cart_summary', 'account_panel', 'cart', 'events'] as $type) {
            foreach ($payloads as $p) {
                $section = [
                    'type' => $type, 'heading' => 'H', 'cta_text' => 'Go',
                    'cta_url' => $p, 'continue_shopping_url' => $p,
                    'items'  => [['title' => 'a', 'text' => 'b', 'cta_url' => $p, 'cta_text' => 'x']],
                    'events' => [['title' => 'E', 'date' => '2026-01-01', 'cta_url' => $p, 'cta_text' => 'Book']],
                ];
                $html = $renderer->renderSection($section, self::BRAND, ['name' => 'T'], [], 'home');
                $this->assertDoesNotMatchRegularExpression(
                    '/href="\s*(?:javascript|vbscript|data):/i', $html,
                    "cta_url payload reached an executable href on section type '$type': $p"
                );
            }
        }

        // Legitimate URL schemes must still render into the href.
        $ok = $renderer->renderSection(
            ['type' => 'grid', 'heading' => 'H', 'cta_text' => 'Go', 'cta_url' => 'https://example.com/x',
             'items' => [['title' => 'a', 'text' => 'b']]],
            self::BRAND, ['name' => 'T'], [], 'home'
        );
        $this->assertStringContainsString('https://example.com/x', $ok, 'a legitimate https cta_url was stripped');
    }

    /**
     * FULL HREF SWEEP (2026-08-26) — after fixing cta_url (EV-0743) and the remaining
     * nav/account/logout href fields (EV-0744), assert the invariant directly: NO section
     * type may emit an href with a javascript:/vbscript:/data: scheme, no matter which url
     * field the attacker controls. Renders every dispatched section type with the payload
     * planted in every plausible url field (section-level and nested items/events/listings/
     * components) and scans the output.
     */
    public function test_no_section_type_emits_an_executable_scheme_href(): void
    {
        $renderer = app(BuilderRenderer::class);
        $types = [
            'header', 'hero', 'features', 'cta', 'contact_form', 'blog_list', 'footer',
            'services', 'team', 'testimonials', 'faq', 'pricing', 'gallery', 'stats',
            'booking_form', 'events_calendar', 'grid', 'filter_bar', 'map', 'related_listings',
            'trust_signals', 'cart_summary', 'checkout_form', 'account_nav', 'account_panel', 'generic',
        ];
        $p = 'javascript:alert(1)';
        $item = [
            'label' => 'L', 'name' => 'N', 'title' => 'T', 'text' => 'x', 'url' => $p, 'href' => $p,
            'cta_url' => $p, 'link' => $p, 'image' => $p, 'price' => '$1', 'ref' => 'R', 'date' => 'd',
            'status' => 's', 'total' => '$1', 'slug' => 's',
        ];
        foreach ($types as $t) {
            $section = [
                'type' => $t, 'heading' => 'H', 'cta_text' => 'Go', 'cta_url' => $p, 'cta_link' => $p,
                'url' => $p, 'href' => $p, 'link' => $p, 'continue_shopping_url' => $p, 'logout_url' => $p,
                'map_url' => $p, 'image' => $p, 'bg_image' => $p, 'background_image' => $p,
                'items' => [$item, $item], 'events' => [$item], 'listings' => [$item],
                'components' => [['type' => 'button', 'text' => 'B', 'href' => $p]], 'tab' => 'wishlist',
            ];
            try {
                $html = $renderer->renderSection($section, self::BRAND, ['name' => 'T'], [], 'home');
            } catch (\Throwable $e) {
                continue; // a type that refuses this shape is not a vuln
            }
            $this->assertDoesNotMatchRegularExpression(
                '/href="\s*(?:javascript|vbscript|data):/i', $html,
                "section type '$t' emitted an executable-scheme href"
            );
        }
    }

    /**
     * RAW USER HTML (2026-08-26) — the P0-3 escape pass covered hero/features/cta/etc. but
     * MISSED the header ($brandInner text fallback) and footer ($brandName/$tagline/$copyright)
     * chrome renderers, which echoed user brand/footer text raw -> a brand name of
     * "<img src=x onerror=...>" auto-executed on the published page. Assert the invariant for
     * every section type: no user text field (scalar or component) may emit a raw executable
     * tag. Detection is payload-specific so it does not trip on legitimate inline scripts
     * (e.g. the contact form's submit handler).
     */
    public function test_no_section_type_emits_raw_user_html(): void
    {
        $renderer = app(BuilderRenderer::class);
        $x  = '<img src=x onerror=alert(1)>';
        $sx = '<script>alert(31337)</script>';
        $types = [
            'header', 'hero', 'features', 'cta', 'contact_form', 'blog_list', 'footer', 'services',
            'team', 'testimonials', 'faq', 'pricing', 'gallery', 'stats', 'events_calendar', 'grid',
            'filter_bar', 'map', 'related_listings', 'trust_signals', 'cart_summary', 'checkout_form',
            'account_nav', 'account_panel', 'generic',
        ];
        $comps = [
            ['type' => 'heading', 'text' => $x],
            ['type' => 'text', 'text' => $x . ' · ' . $x],
            ['type' => 'text', 'text' => '© ' . $sx],
        ];
        foreach ($types as $t) {
            $section = [
                'type' => $t, 'components' => $comps, 'logo_text' => $x, 'heading' => $sx,
                'subheading' => $x, 'body' => $x, 'tagline' => $x, 'copyright' => $sx,
                'items' => [['title' => $x, 'text' => $x, 'name' => $sx]],
            ];
            try {
                $html = $renderer->renderSection($section, self::BRAND, ['name' => 'T'], [], 'home');
            } catch (\Throwable $e) {
                continue;
            }
            $this->assertDoesNotMatchRegularExpression(
                '/<img[^>]*onerror/i', $html,
                "section type '$t' emitted a raw <img onerror> from user text"
            );
            $this->assertStringNotContainsString('<script>alert(31337)</script>', $html,
                "section type '$t' emitted a raw <script> from user text");
        }
    }

    /**
     * RAW_DOCUMENT WRITE-GUARD (2026-08-26) — import_html_page is auto-approved and its
     * raw_document is served verbatim (no CSP), so BuilderService::stripActiveContentFromRawDoc
     * is the XSS defense. It leaked slash-separated event handlers (<img/onerror=...>,
     * <svg/onload=...> — auto-executing), meta-refresh-to-javascript, data:text/html hrefs,
     * and iframe/object/embed. Hardened (RISK-0095 interim). Assert the bypasses are stripped
     * and legitimate imported HTML (inline data:image, normal links/imgs) is preserved.
     */
    public function test_raw_document_write_guard_strips_known_bypasses(): void
    {
        $bs = app(\App\Engines\Builder\Services\BuilderService::class);
        $m = new \ReflectionMethod($bs, 'stripActiveContentFromRawDoc');
        $m->setAccessible(true);

        $bypasses = [
            '<script>alert(1)</script>',
            '<img src=x onerror=alert(1)>',
            '<img/src=x/onerror=alert(1)>',
            '<svg/onload=alert(1)>',
            '<svg onload="alert(1)">',
            '<a href="javascript:alert(1)">x</a>',
            '<a href="data:text/html,x">x</a>',
            '<meta http-equiv="refresh" content="0;url=javascript:alert(1)">',
            '<iframe src="javascript:alert(1)"></iframe>',
            '<iframe src="https://evil.tld"></iframe>',
            '<body onload=alert(1)>',
        ];
        foreach ($bypasses as $html) {
            $out = $m->invoke($bs, $html);
            $this->assertDoesNotMatchRegularExpression('/\bon[a-z]+\s*=/i', $out, "event handler survived: $html");
            $this->assertStringNotContainsStringIgnoringCase('<script', $out, "script survived: $html");
            $this->assertDoesNotMatchRegularExpression('#(?:href|src|action)\s*=\s*["\']?\s*javascript:#i', $out, "javascript: url survived: $html");
            $this->assertStringNotContainsStringIgnoringCase('data:text/html', $out, "data:text/html survived: $html");
            $this->assertDoesNotMatchRegularExpression('/<(?:iframe|object|embed)/i', $out, "embedding tag survived: $html");
            $this->assertDoesNotMatchRegularExpression('/http-equiv\s*=\s*["\']?\s*refresh/i', $out, "meta refresh survived: $html");
        }

        // Legitimate imported HTML must be preserved unchanged.
        foreach ([
            '<img src="data:image/png;base64,iVBORw0KGgo=">',
            '<a href="/about" class="btn">About</a>',
            '<img src="/storage/hero.jpg" alt="Hero">',
            '<div class="online-now">We are online</div>',
            '<h1>Title</h1><p>Body text here.</p>',
        ] as $legit) {
            $this->assertSame($legit, $m->invoke($bs, $legit), "legit HTML was altered: $legit");
        }
    }
}
