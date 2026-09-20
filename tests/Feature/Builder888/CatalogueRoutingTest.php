<?php

namespace Tests\Feature\Builder888;

use App\Engines\Builder\Services\ArthurService;
use App\Engines\Builder\Services\CatalogueService;
use App\Engines\Builder\Services\TemplateService;
use App\Engines\Builder\Support\BuilderCapabilities;
use App\Engines\Builder\Support\CatalogueKinds;
use Tests\TestCase;

/**
 * CATALOGUE VERIFICATION (2026-09-20) — every page in the catalogue is reachable by the page picker's own wording
 * ("add a <slug words> page") in every industry it is offered to, and a page request is never read as a catalogue
 * item ("add a portfolio page" used to create a project called "Page" for one credit). "Before / After" is a page
 * name, not a position. Every template directory belongs to an industry family that has a base design.
 */
class CatalogueRoutingTest extends TestCase
{
    public function test_every_offered_page_is_reachable_by_the_pickers_wording_in_every_industry(): void
    {
        $ts = app(TemplateService::class);
        $industries = [];
        foreach (glob(storage_path('templates/*/manifest.json')) ?: [] as $mf) { $industries[$ts->industryOf(basename(dirname($mf)))] = true; }
        $this->assertGreaterThanOrEqual(30, count($industries));
        $checked = 0;
        foreach (array_keys($industries) as $ind) {
            foreach (BuilderCapabilities::pages($ind) as $slug => $meta) {
                foreach (['add a ' . str_replace('_', ' ', $slug) . ' page', 'add a ' . strtolower($meta['label']) . ' page'] as $req) {
                    $c = BuilderCapabilities::classify($req, $ind);
                    $this->assertSame('page', $c['kind'], "$ind: \"$req\" → {$c['kind']}");
                    $this->assertSame($slug, $c['page'], "$ind: \"$req\" → {$c['page']}");
                    $checked++;
                }
            }
            // a page the industry is NOT offered stays refused
            foreach (ArthurService::PAGE_TEMPLATE_CATALOGUE as $slug => $meta) {
                if (isset(BuilderCapabilities::pages($ind)[$slug])) continue;
                $c = BuilderCapabilities::classify('add a ' . str_replace('_', ' ', $slug) . ' page', $ind);
                $this->assertFalse($c['kind'] === 'page' && $c['page'] === $slug, "$ind: $slug is not offered but classify accepts it");
            }
        }
        $this->assertGreaterThan(600, $checked);
    }

    public function test_before_after_is_a_page_name_not_a_position(): void
    {
        foreach (['add a before after page', 'add a before / after page', 'add a before-after page', 'add a before and after page'] as $req) {
            $c = BuilderCapabilities::classify($req, 'aesthetic_clinic');
            $this->assertSame(['page', 'before_after'], [$c['kind'], $c['page']], $req);
        }
        $c = BuilderCapabilities::classify('add a before and after section', 'dental');
        $this->assertSame(['section', 'gallery'], [$c['kind'], $c['section']], 'the gallery keeps its synonym');
        $c = BuilderCapabilities::classify('add a faq section before the contact section', 'gym');
        $this->assertSame(['section', 'faq', 'before', 'contact'], [$c['kind'], $c['section'], $c['where'], $c['anchor']], 'a real position still parses');
    }

    public function test_a_page_request_is_never_a_catalogue_item(): void
    {
        $specs = [];
        foreach (['project', 'menu', 'event', 'listing', 'service'] as $kind) { $spec = CatalogueKinds::get($kind); if ($spec) $specs[] = $spec + ['kind' => $kind]; }
        $this->assertNotEmpty($specs);
        foreach (['add a portfolio page', 'add a menu page', 'add an events page', 'add a listing browser page', 'add a services page', 'I want a new menu page', 'create the projects page'] as $req) {
            $this->assertFalse(CatalogueService::looksLikeCatalogueRequest($req, $specs), "\"$req\" must not be a catalogue command");
        }
        foreach (['add a project called Marina Tower', 'add an event called Spring Gala', 'add a cardamom bun to the menu page', 'list a new property at 2 Palm Road', 'mark the Land Cruiser as sold'] as $req) {
            $this->assertTrue(CatalogueService::looksLikeCatalogueRequest($req, $specs), "\"$req\" is a catalogue command");
        }
    }

    public function test_every_template_belongs_to_a_family_with_a_base_design(): void
    {
        $ts = app(TemplateService::class);
        foreach (glob(storage_path('templates/*/manifest.json')) ?: [] as $mf) {
            $slug = basename(dirname($mf)); $ind = $ts->industryOf($slug);
            $this->assertFileExists(storage_path("templates/{$ind}/template.html"), "$slug declares industry $ind which has no base design");
            $this->assertNotEmpty(BuilderCapabilities::pages($ind), "$ind offers no pages");
            $this->assertGreaterThanOrEqual(15, count(BuilderCapabilities::sections($ind)), "$ind offers too few sections");
        }
        $this->assertSame('aesthetic_clinic', $ts->industryOf('aesthetic_clinic_commercial'));
    }
}
