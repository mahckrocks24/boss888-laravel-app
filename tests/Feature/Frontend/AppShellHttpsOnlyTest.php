<?php

namespace Tests\Feature\Frontend;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * RISK-0190 (2026-09-18). A customer signed up from the Facebook in-app browser, which opened the site over plain
 * http and stayed there (no HTTPS-RR upgrade, no edge redirect). The app worked until the editor preview loaded the
 * exported site's absolute-https chatbot.js: that one https response taught the WebView HSTS, every later /api fetch
 * from the http document was upgraded in flight, Chrome drops Authorization on that cross-scheme hop, and the editor
 * showed empty palettes / settings / history while the refresh interceptor rotated the session 32 times in 7 minutes.
 *
 * The shell is https-only. Source-level pin: the bounce must be there, before anything reads a token.
 */
class AppShellHttpsOnlyTest extends TestCase
{
    public function test_a_plain_http_document_bounces_to_https_before_any_token_is_read(): void
    {
        $html = File::get(public_path('app/index.html'));

        $guard = strpos($html, 'HTTPS-ONLY (RISK-0190');
        $this->assertNotFalse($guard, 'the https-only guard is missing from the app shell');

        $script = substr($html, $guard, strpos($html, '</script>', $guard) - $guard);
        $this->assertStringContainsString('location.protocol==="http:"', $script);
        $this->assertStringContainsString('location.replace("https://"+location.host+location.pathname+location.search+location.hash)', $script);
        $this->assertStringContainsString('levelupgrowth\.io$', $script, 'scoped to our hosts so a local http dev copy is untouched');

        // before the first localStorage read (theme boot, tokens) and before the embed interceptor
        $firstStorage = strpos($html, 'localStorage');
        $this->assertNotFalse($firstStorage);
        $this->assertLessThan($firstStorage, $guard, 'the bounce must run before any script touches localStorage');
        $this->assertLessThan(strpos($html, '_lgscEmbedInterceptor'), $guard);
    }
}
