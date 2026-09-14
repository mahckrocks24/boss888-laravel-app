<?php

namespace Tests\Feature\Builder888;

use App\Engines\Builder\Services\TemplateService;
use App\Engines\Builder\Support\BuilderCapabilities;
use Tests\TestCase;

/**
 * ARTHUR DELEGATION (2026-09-06). Boss's rule: Sarah never builds — she asks Arthur; Arthur adds pages and
 * sections FROM THE TEMPLATES, in the site's palette, priced. These tests pin the capability manifest, the
 * request classifier, and the template-chrome export helpers.
 */
class ArthurDelegationTest extends TestCase
{
    public function test_every_industry_can_add_a_booking_page_and_pet_shops_are_no_longer_excluded(): void
    {
        foreach (['pet_services', 'childcare', 'travel_agency', 'dental', 'restaurant', 'it_services'] as $ind) {
            $this->assertArrayHasKey('booking', BuilderCapabilities::pages($ind), "$ind must be offered a booking page");
        }
        $this->assertArrayHasKey('listing_browser', BuilderCapabilities::pages('travel_agency'), 'travel gets a packages browser');
        $this->assertArrayNotHasKey('cart', BuilderCapabilities::pages('pet_services'), 'cart stays shop-only');
        $this->assertArrayHasKey('cart', BuilderCapabilities::pages('ecommerce'));
    }

    public function test_pricing_is_declared_once_and_quoted_in_plans(): void
    {
        $p = BuilderCapabilities::pricing();
        $this->assertSame(['page', 'section', 'text_edit', 'style', 'draft'], array_keys($p)); // style 2026-09-11, draft DEC-0045
        $this->assertSame($p['page'], BuilderCapabilities::classify('add a booking page', 'pet_services')['credits']);
        $this->assertSame($p['section'], BuilderCapabilities::classify('add a faq section', 'pet_services')['credits']);
        $this->assertSame($p['text_edit'], BuilderCapabilities::classify('rewrite the hero headline', 'pet_services')['credits']);
    }

    public function test_classifier_reads_the_customers_words(): void
    {
        $c = fn(string $q) => BuilderCapabilities::classify($q, 'pet_services');
        $this->assertSame(['page', 'booking'], [$c('Ask Arthur to add a page for booking schedule')['kind'], $c('Ask Arthur to add a page for booking schedule')['page']]);
        $s = $c('add an appointment calendar after the services section');
        $this->assertSame('section', $s['kind']); $this->assertSame('booking_form', $s['section']); $this->assertSame(['after', 'services'], [$s['where'], $s['anchor']]);
        $s2 = $c('put a pricing table above the footer');
        $this->assertSame(['section', 'pricing', 'before', 'footer'], [$s2['kind'], $s2['section'], $s2['where'], $s2['anchor']]);
        $this->assertSame('section', $c('can you add a FAQ section')['kind']);
        $this->assertSame('remove', $c('remove the gallery')['kind']);
        $this->assertSame('unsupported', $c('add a swimming pool')['kind']);
        $this->assertSame('unsupported', $c('add a cart page')['kind'], 'cart is not offered to a pet shop');
        $this->assertSame('edit', $c('change the headline to mention grooming')['kind']);
    }

    public function test_describe_is_compact_and_names_the_rule(): void
    {
        $d = BuilderCapabilities::describe('pet_services');
        $this->assertStringContainsString('Sarah asks Arthur, she never builds herself', $d);
        $this->assertStringContainsString('booking', $d);
        $this->assertLessThan(2000, strlen($d), 'must stay small enough for every prompt');
    }

    public function test_template_chrome_export_nav_link_and_section_splice_on_a_fake_site(): void
    {
        $id = 990000 + random_int(1000, 9999);
        $root = storage_path("app/public/sites/{$id}");
        @mkdir($root, 0755, true);
        $home = '<!doctype html><html lang="en"><head><title>Acme — Home</title><style>h2{font-family:Fredoka}</style></head><body>'
            . '<nav data-block="nav"><div class="nav-links"><a href="#services">Services</a><a href="/blog" class="nav-link">Blog</a><a href="#booking" class="nav-cta">Book</a></div></nav>'
            . '<section class="hero" data-block="hero"><h1>Hi</h1></section>'
            . '<section id="services" data-block="services"><h2>Services</h2></section>'
            . '<section id="contact" data-block="contact"><h2>Contact</h2></section>'
            . '<footer data-block="footer"><a href="#services">Services</a></footer></body></html>';
        file_put_contents($root . '/index.html', $home);
        @mkdir($root . '/blog', 0755, true); file_put_contents($root . '/blog/index.html', str_replace('href="#services"', 'href="../#services"', $home));
        try {
            $t = new TemplateService();
            $path = $t->deployPage($id, 'booking', '<section id="x"><h2>Book</h2></section>', 'Booking / Appointments');
            $this->assertNotNull($path);
            $page = file_get_contents($path);
            $this->assertStringContainsString('<title>Booking / Appointments — Acme</title>', $page);
            $this->assertStringContainsString('font-family:Fredoka', $page, 'head (styles) is the home\'s');
            $this->assertStringContainsString('href="../#services"', $page, 'in-page anchors point back home');
            $this->assertStringContainsString('href="../blog/"', $page);
            $this->assertStringContainsString('lu-nav-toggle', $page, 'mobile nav injected');

            $n = $t->addNavLink($id, 'booking', 'Booking');
            $this->assertSame(3, $n, 'home, blog and the new page all get the link');
            $this->assertMatchesRegularExpression('#<a href="booking/" class="nav-link lu-page-link" data-page="booking">Booking</a><a href="\#booking" class="nav-cta">#', file_get_contents($root . '/index.html'), 'inserted before the CTA, home-relative');
            $this->assertStringContainsString('href="../booking/"', file_get_contents($root . '/blog/index.html'), 'sub-pages link up one level');
            $this->assertSame(0, $t->addNavLink($id, 'booking', 'Booking'), 'idempotent');
            $this->assertSame(3, $t->addNavLink($id, 'services-2', 'Services'), 'a same-label template link is REPOINTED to the page, not duplicated');
            $this->assertMatchesRegularExpression('#<a href="services-2/"[^>]*data-page="services-2">Services</a>#', file_get_contents($root . '/index.html'));
            preg_match('/<nav\b.*?<\/nav>/is', file_get_contents($root . '/index.html'), $navOnly);
            $this->assertSame(1, substr_count($navOnly[0] ?? '', '>Services</a>'), 'still exactly one Services link in the nav (the footer keeps its own)');
            // capacity: after enough links, new pages go into a site-CSS More menu instead of a second nav row
            foreach (['a1', 'a2', 'a3', 'a4', 'a5'] as $s) { @mkdir($root . '/' . $s); file_put_contents($root . '/' . $s . '/index.html', '<html><head></head><body><nav data-block="nav"><div class="nav-links"></div></nav></body></html>'); $t->addNavLink($id, $s, strtoupper($s)); }
            $h2 = file_get_contents($root . '/index.html');
            $this->assertStringContainsString('class="lu-more"', $h2, 'overflow goes to the More menu');
            $this->assertStringContainsString('id="lu-more-css"', $h2);
            $this->assertMatchesRegularExpression('#lu-more-menu">(?:<a [^>]*>[^<]*</a>)+</div></div>#', $h2);

            $out = $t->spliceSectionIntoHome($id, '<section data-block="added_faq" id="lu-faq"><h2>FAQ</h2></section>', 'services', 'after');
            $this->assertNotNull($out);
            $h = file_get_contents($root . '/index.html');
            $this->assertLessThan(strpos($h, 'data-block="added_faq"'), strpos($h, 'data-block="services"'));
            $this->assertLessThan(strpos($h, 'data-block="contact"'), strpos($h, 'data-block="added_faq"'));
            $this->assertStringContainsString('data-page="booking"', $h, 'nav link survives a redeploy');
        } finally {
            foreach (glob($root . '/*/index.html') ?: [] as $f) { @unlink($f); @rmdir(dirname($f)); }
            @unlink($root . '/index.html'); @rmdir($root);
        }
    }

    public function test_sarah_has_the_delegation_tool_and_the_rule_in_her_prompt(): void
    {
        $svc = app(\App\Core\Orchestration\ToolSchemaService::class);
        $prompt = $svc->getToolSchemaPrompt('sarah', null, 'can you add a booking page?');
        $this->assertStringContainsString('builder.ask_arthur', $prompt);
        $this->assertStringContainsString('ASK ARTHUR, NEVER BUILD', $prompt);
        $this->assertTrue(app(\App\Core\Agent\AgentCapabilityService::class)->canUse('sarah', 'ask_arthur'));
        $this->assertSame('ask_arthur', app(\App\Core\EngineKernel\CapabilityMapService::class)->resolve('ask_arthur')['action'] ?? null);
        $this->assertStringContainsString('WEBSITE ADDITIONS', \App\Core\Sarah888\PlatformKnowledge::guide());
        // the task runner's own dispatch map (a second registry) must know the action too — CAP-GAP class of bug
        $orch = (string) file_get_contents(base_path('app/Core/TaskSystem/Orchestrator.php'));
        $this->assertStringContainsString("'builder/ask_arthur'", $orch, 'Orchestrator dispatch map has builder/ask_arthur');
        $this->assertStringContainsString("'builder/add_page_from_template' => fn()", $orch);
    }
}
