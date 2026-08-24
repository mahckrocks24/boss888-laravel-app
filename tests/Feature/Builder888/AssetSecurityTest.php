<?php

namespace Tests\Feature\Builder888;

use App\Engines\Builder\Support\SvgSanitizer;
use Tests\TestCase;

/**
 * BUILDER888 · P1-7 · FAMILY 4 — SVG / ASSET SECURITY.
 *
 * Permanent protection for P0-4: an uploaded SVG executed from
 * staging.levelupgrowth.io — the same origin where the SPA keeps its bearer
 * token AND (proven separately) an admin token, in localStorage.
 *
 * nosniff does not help: the file genuinely IS image/svg+xml, and the observed
 * CSP carries only frame-ancestors.
 */
class AssetSecurityTest extends TestCase
{
    /** Anything that would run, load, or phone home. */
    private function assertInert(?string $svg, string $case): void
    {
        if ($svg === null) {
            return; // outright refusal is a valid outcome
        }

        foreach ([
            '/<script/i'            => 'script element',
            '/\son[a-z]+\s*=/i'     => 'event handler',
            '/foreignObject/i'      => 'foreignObject',
            '/<iframe|<embed|<object/i' => 'embedded frame',
            '/javascript:/i'        => 'javascript: URL',
            '/vbscript:/i'          => 'vbscript: URL',
            '/<animate|<set\b/i'    => 'animation vector',
            '/169\.254\.169\.254/'  => 'metadata-service SSRF target',
            '/@import/i'            => 'css import',
            '/expression\s*\(/i'    => 'css expression',
        ] as $pattern => $label) {
            $this->assertDoesNotMatchRegularExpression($pattern, $svg, "{$label} survived: {$case}");
        }
    }

    public function test_every_known_svg_attack_is_neutralised(): void
    {
        $attacks = [
            'script element'      => '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script><rect width="1" height="1"/></svg>',
            'onload'              => '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"><rect width="1" height="1"/></svg>',
            'onclick on child'    => '<svg xmlns="http://www.w3.org/2000/svg"><rect width="1" height="1" onclick="alert(1)"/></svg>',
            'onmouseover'         => '<svg xmlns="http://www.w3.org/2000/svg"><circle r="5" onmouseover="alert(1)"/></svg>',
            'foreignObject'       => '<svg xmlns="http://www.w3.org/2000/svg"><foreignObject><body xmlns="http://www.w3.org/1999/xhtml"><script>alert(1)</script></body></foreignObject></svg>',
            'iframe'              => '<svg xmlns="http://www.w3.org/2000/svg"><iframe src="javascript:alert(1)"/></svg>',
            'embed'               => '<svg xmlns="http://www.w3.org/2000/svg"><embed src="x.swf"/></svg>',
            'xlink javascript'    => '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink"><a xlink:href="javascript:alert(1)"><text>x</text></a></svg>',
            'entity-encoded js'   => '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink"><a xlink:href="java&#09;script:alert(1)"><text>x</text></a></svg>',
            'animate href'        => '<svg xmlns="http://www.w3.org/2000/svg"><animate attributeName="href" to="javascript:alert(1)"/></svg>',
            'set element'         => '<svg xmlns="http://www.w3.org/2000/svg"><set attributeName="href" to="javascript:alert(1)"/></svg>',
            'SSRF image'          => '<svg xmlns="http://www.w3.org/2000/svg"><image href="http://169.254.169.254/latest/meta-data/"/></svg>',
            'external image'      => '<svg xmlns="http://www.w3.org/2000/svg"><image href="https://evil.example/x.png"/></svg>',
            'style url js'        => '<svg xmlns="http://www.w3.org/2000/svg"><rect style="background:url(javascript:alert(1))" width="1" height="1"/></svg>',
            'css import'          => '<svg xmlns="http://www.w3.org/2000/svg"><style>@import url("https://evil.example/x.css");</style></svg>',
            'entity expansion'    => '<!DOCTYPE svg [<!ENTITY x "y">]><svg xmlns="http://www.w3.org/2000/svg"><text>&x;</text></svg>',
            'vbscript'            => '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink"><a xlink:href="vbscript:msgbox(1)"><text>x</text></a></svg>',
        ];

        foreach ($attacks as $case => $svg) {
            $this->assertInert(SvgSanitizer::sanitize($svg), $case);
        }
    }

    public function test_non_svg_payloads_are_refused_outright(): void
    {
        foreach ([
            'html'          => '<html><script>alert(1)</script></html>',
            'php'           => "<?php echo 'rce'; ?>",
            'plain text'    => 'not an svg at all',
            'empty'         => '',
            'doctype trick' => '<!DOCTYPE svg SYSTEM "http://evil.example/x.dtd"><svg xmlns="http://www.w3.org/2000/svg"/>',
        ] as $case => $payload) {
            $this->assertNull(SvgSanitizer::sanitize($payload), "should be refused: {$case}");
        }
    }

    public function test_a_legitimate_logo_survives_completely(): void
    {
        // A sanitiser that destroys real logos is not shippable.
        $logo = '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 120 40" width="120" height="40">'
              . '<title>Acme</title>'
              . '<defs><linearGradient id="g"><stop offset="0" stop-color="#6C5CE7"/><stop offset="1" stop-color="#00E5A8"/></linearGradient></defs>'
              . '<rect x="0" y="0" width="40" height="40" rx="8" fill="url(#g)"/>'
              . '<circle cx="20" cy="20" r="9" fill="#fff"/>'
              . '<text x="50" y="26" font-family="Inter" font-size="18" fill="#111">Acme</text>'
              . '<path d="M4 36 L36 36" stroke="#333" stroke-width="2"/></svg>';

        $clean = SvgSanitizer::sanitize($logo);

        $this->assertNotNull($clean, 'a legitimate logo must not be refused');
        foreach (['rect', 'circle', 'text', 'path', 'linearGradient', 'stop', 'title',
                  'viewBox', 'fill="url(#g)"', 'Acme'] as $keep) {
            $this->assertStringContainsString($keep, $clean, "lost {$keep} from a legitimate logo");
        }
    }

    public function test_internal_fragment_references_are_preserved(): void
    {
        // url(#id) is how gradients and masks work — it must survive.
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><defs><linearGradient id="g"><stop offset="0"/></linearGradient></defs>'
             . '<rect width="10" height="10" fill="url(#g)"/></svg>';

        $clean = SvgSanitizer::sanitize($svg);
        $this->assertStringContainsString('url(#g)', $clean);
    }

    public function test_the_image_upload_endpoint_still_refuses_svg(): void
    {
        // Defence in depth: the image endpoint's allowlist must not drift to
        // include SVG just because the logo endpoint sanitises it.
        $routes = file_get_contents(base_path('routes/api/authenticated/builder-01.php'));

        $this->assertMatchesRegularExpression(
            "/Unsupported image type\. Use PNG, JPG, or WEBP/",
            $routes,
            'the image endpoint must keep refusing SVG'
        );
        $this->assertStringContainsString('SvgSanitizer::sanitize', $routes,
            'the logo endpoint must keep sanitising SVG');
    }
}
