<?php

namespace Tests\Feature\Builder888;

use App\Engines\Builder\Support\ResponsiveNav;
use Tests\TestCase;

/**
 * The generated site's mobile navigation.
 *
 * It used to make the nav WRAP, which on a phone folded seven links onto three cramped rows — the pinched
 * menu the owner reported. It now collapses behind a hamburger, and two properties matter as much as that:
 * it must reach sites that were exported before the hamburger existed, and it must not touch a site that
 * already has a working mobile nav of its own.
 */
class ResponsiveNavTest extends TestCase
{
    private function page(string $body, string $head = ''): string
    {
        return "<!doctype html><html><head>{$head}</head><body>{$body}</body></html>";
    }

    private function generatedSite(): string
    {
        return $this->page(
            '<header><nav data-block="nav"><div class="wrap"><div class="inner">'
            . '<div class="logo">A Business</div>'
            . '<div class="nav-links"><a href="#s">Services</a><a href="#c">Contact</a>'
            . '<a href="#b" class="nav-cta">Book</a></div>'
            . '</div></div></nav></header>'
        );
    }

    /*──────────────────────────────────────────── the hamburger */

    public function test_a_generated_site_gets_the_hamburger_and_its_toggle(): void
    {
        $out = ResponsiveNav::inject($this->generatedSite());

        $this->assertStringContainsString('lu-nav-toggle', $out, 'the button is styled');
        $this->assertStringContainsString('lu-nav-toggle', $out);
        $this->assertStringContainsString('aria-expanded', $out, 'the toggle reports its state');
        $this->assertStringContainsString('aria-controls', $out);
        $this->assertMatchesRegularExpression('/\.nav-links\{display:none!important/', $out,
            'the links start collapsed on a phone rather than wrapping');
        $this->assertStringContainsString('.lu-nav-open .nav-links{display:flex!important}', $out);
    }

    /** A page with no nav is left completely alone. */
    public function test_a_page_without_a_nav_is_untouched(): void
    {
        $html = $this->page('<main><p>Just a page.</p></main>');

        $this->assertSame($html, ResponsiveNav::inject($html));
    }

    /*──────────────────────────────────────────── already-exported sites */

    /**
     * The trap this nearly fell into: exports already on disk carry the previous block baked in at build
     * time, so an "already present, skip" check would have left every existing site on the pinched menu
     * while the code looked fixed.
     */
    public function test_an_older_block_is_replaced_rather_than_skipped(): void
    {
        $stale = $this->page(
            '<header><nav><div class="inner"><div class="nav-links"><a href="#s">S</a></div></div></nav></header>',
            '<style id="lu-mobile-nav">.nav-links{flex-wrap:wrap!important}</style>'
        );

        $out = ResponsiveNav::inject($stale);

        $this->assertStringNotContainsString('flex-wrap:wrap!important}</style>', $out,
            'the old wrapping rule must not survive');
        $this->assertStringContainsString(ResponsiveNav::VERSION, $out, 'the current build replaces it');
        $this->assertStringContainsString('lu-nav-toggle', $out);
    }

    /** Running twice must not stack two copies. */
    public function test_injection_is_idempotent(): void
    {
        $once = ResponsiveNav::inject($this->generatedSite());
        $twice = ResponsiveNav::inject($once);

        $this->assertSame($once, $twice);
        $this->assertSame(1, substr_count($twice, 'id="' . ResponsiveNav::MARKER . '-' . ResponsiveNav::VERSION . '"'));
    }

    /*──────────────────────────────────────────── sites that solved it themselves */

    /**
     * Chef Red's site ships its own `<button class="nav-burger" onclick="toggleMob()">`. Adding ours on top
     * put two hamburgers in the corner of a live customer site — a regression introduced by a fix.
     */
    public function test_a_site_with_its_own_mobile_nav_keeps_it_and_gets_no_second_button(): void
    {
        $own = $this->page(
            '<header><nav id="main-nav"><div class="inner">'
            . '<div class="nav-links"><a href="#s">Services</a></div>'
            . '<button class="nav-burger" onclick="toggleMob()">Menu</button>'
            . '</div></nav></header><div class="mob-nav"><a href="#s">Services</a></div>'
        );

        $out = ResponsiveNav::inject($own);

        $this->assertStringNotContainsString('lu-nav-toggle', $out,
            'a site that already has a hamburger must not be given a second one');
        $this->assertStringContainsString('nav-burger', $out, 'its own button is untouched');

        // The overflow rules are orthogonal and still wanted.
        $this->assertStringContainsString('grid-template-columns:repeat(auto-fit', $out);
    }

    /** The overflow work applies to every site, with or without our nav. */
    public function test_overflow_rules_ship_to_every_site(): void
    {
        foreach ([$this->generatedSite(),
                  $this->page('<nav><div class="inner"><div class="nav-links"><a href="#a">A</a></div>'
                            . '<button class="nav-burger"></button></div></nav>')] as $html) {
            $out = ResponsiveNav::inject($html);
            $this->assertStringContainsString('min-width:0!important', $out);
            $this->assertStringContainsString('img,video,iframe,table{max-width:100%!important}', $out);
        }
    }
}
