<?php

namespace Tests\Feature\Builder888;

use App\Engines\Builder\Support\BuilderCapabilities;
use Tests\TestCase;

/**
 * RISK-0195 (2026-09-20) — a section request is a section, a page request is a page, and a request that names
 * neither for a thing that exists as both is a QUESTION, never a guess with a charge.
 *
 * The intent step used to rewrite "add an FAQ section before the contact section" into "Add an FAQ section to the
 * home page, positioned before …" and the classifier read "home page" as a request for a page: six section types
 * (faq, pricing, contact, services, team, gallery) became 5-credit pages. This test would have caught it.
 */
class SectionVsPageTest extends TestCase
{
    /** the six names that exist as a section type AND a page template, with the phrasing a customer uses */
    private const SHARED = [
        'faq'      => ['section' => 'FAQ',          'page' => 'FAQ'],
        'pricing'  => ['section' => 'pricing',      'page' => 'pricing'],
        'contact'  => ['section' => 'contact form', 'page' => 'contact'],
        'services' => ['section' => 'services',     'page' => 'services'],
        'team'     => ['section' => 'team',         'page' => 'team'],
        'gallery'  => ['section' => 'gallery',      'page' => 'gallery'],
    ];

    public function test_an_explicit_section_request_is_a_section_even_when_it_says_where_on_the_page(): void
    {
        foreach (self::SHARED as $name => $w) {
            foreach ([
                "add a {$w['section']} section",
                "add a {$w['section']} section before the contact section",
                "Add a {$w['section']} section to the home page, positioned after the services section",   // the intent step's wording
                "put a {$w['section']} section on the page after the hero",
                "insert a {$w['section']} block below the testimonials",
            ] as $msg) {
                $p = BuilderCapabilities::classify($msg, 'gym');
                $this->assertSame('section', $p['kind'], "$msg → " . json_encode($p));
                $this->assertSame(BuilderCapabilities::pricing()['section'], $p['credits'], $msg);
                $this->assertNull($p['page'], $msg);
            }
        }
    }

    public function test_an_explicit_page_request_is_a_page(): void
    {
        foreach (self::SHARED as $name => $w) {
            foreach (["add a {$w['page']} page", "create a {$w['page']} page for my business", "I need a new {$w['page']} page"] as $msg) {
                $p = BuilderCapabilities::classify($msg, null);   // the full catalogue: a gym has no gallery page, and that is answered honestly elsewhere
                $this->assertSame('page', $p['kind'], "$msg → " . json_encode($p));
                $this->assertSame(BuilderCapabilities::pricing()['page'], $p['credits'], $msg);
            }
        }
    }

    public function test_a_request_that_names_neither_asks_instead_of_guessing_and_costs_nothing(): void
    {
        foreach (['add pricing', 'can you add a faq', 'add services', 'add a team'] as $msg) {
            $p = BuilderCapabilities::classify($msg, null);
            $this->assertSame('clarify', $p['kind'], "$msg → " . json_encode($p));
            $this->assertSame(0, $p['credits'], $msg);
            $this->assertCount(2, $p['options'], $msg);
            $this->assertStringContainsString('section (2 credits)', $p['options'][0]['label']);
            $this->assertStringContainsString('page (5 credits)', $p['options'][1]['label']);
            // and each option, sent back as the next message, resolves without another question
            $this->assertSame('section', BuilderCapabilities::classify($p['options'][0]['label'], null)['kind'], $p['options'][0]['label']);
            $this->assertSame('page', BuilderCapabilities::classify($p['options'][1]['label'], null)['kind'], $p['options'][1]['label']);
        }
    }

    public function test_a_section_type_with_no_page_twin_never_asks(): void
    {
        foreach (['add a testimonials', 'add stats after the hero', 'add a call to action banner', 'add a booking form'] as $msg) {
            $p = BuilderCapabilities::classify($msg, 'gym');
            $this->assertSame('section', $p['kind'], "$msg → " . json_encode($p));
        }
    }

    public function test_the_pricing_registry_is_untouched(): void
    {
        $this->assertSame(2, BuilderCapabilities::PRICING['section']);
        $this->assertSame(5, BuilderCapabilities::PRICING['page']);
    }
}
