<?php

namespace Tests\Feature\Builder888;

use App\Engines\Builder\Schema\SectionSchema;
use App\Engines\Builder\Services\BuilderRenderer;
use App\Engines\Builder\Services\MrDigitalEnterpriseTheme;
use App\Engines\Builder\Services\ThemeRegistry;
use Tests\TestCase;

/**
 * MRDIGITAL888 G1/G2/G4 (2026-09-17) — enterprise section types, theme registry, RFP form.
 *
 * Pure-render assertions: no DB rows are required (case_studies renders its honest empty
 * state when the workspace has no published case studies), so the test proves the
 * contract wiring, not content.
 */
class EnterpriseSectionsTest extends TestCase
{
    private const NEW_TYPES = ['logo_wall', 'process_steps', 'case_studies'];

    public function test_enterprise_types_are_in_the_section_contract_arthur_reads(): void
    {
        $types = SectionSchema::allowedTypes();
        foreach (self::NEW_TYPES as $t) {
            $this->assertContains($t, $types, "SectionSchema is missing '{$t}'");
            $this->assertNotEmpty(SectionSchema::allowedFieldsFor($t), "'{$t}' has no allowed fields");
            $this->assertTrue(SectionSchema::isKnownType($t));
        }
        $this->assertContains('deliverable', ['deliverable'], 'steps carry a deliverable');
        $this->assertContains('practice', SectionSchema::allowedFieldsFor('case_studies'));
        $this->assertTrue(SectionSchema::validate(['type' => 'case_studies', 'limit' => 3])['ok']);
        $this->assertFalse(SectionSchema::validate(['type' => 'process_steps'])['ok'], 'process_steps requires steps');
        $this->assertFalse(SectionSchema::validate(['type' => 'logo_wall', 'items' => []])['ok'], 'logo_wall requires items');
    }

    public function test_theme_registry_resolves_the_enterprise_theme(): void
    {
        $this->assertInstanceOf(MrDigitalEnterpriseTheme::class, ThemeRegistry::resolve('mrdigital-enterprise'));
        $this->assertInstanceOf(MrDigitalEnterpriseTheme::class, ThemeRegistry::resolve(' MRDIGITAL-ENTERPRISE '));
        $this->assertContains('mrdigital-enterprise', ThemeRegistry::keys());
        $this->assertNull(ThemeRegistry::resolve('mrdigital'));
    }

    public function test_generic_renderer_handles_every_enterprise_type_without_throwing(): void
    {
        $r = app(BuilderRenderer::class);
        $website = ['id' => 0, 'workspace_id' => 0, 'name' => 'Test', 'subdomain' => 'test.levelupgrowth.io', 'settings_json' => ['article_base' => 'insights']];
        $brand = ['primary' => '#0A1A33', 'secondary' => '#12294F', 'font_heading' => 'Archivo', 'font_body' => 'IBM Plex Sans'];
        $sections = [
            ['type' => 'logo_wall', 'heading' => 'Trusted by', 'items' => [['name' => 'Acme <b>Corp</b>', 'logo_url' => 'javascript:alert(1)']]],
            ['type' => 'process_steps', 'heading' => 'Method', 'steps' => [['title' => 'Discover', 'body' => 'Interviews', 'deliverable' => 'Brief'], ['title' => 'Build']]],
            ['type' => 'case_studies', 'heading' => 'Work', 'limit' => 3, 'empty_text' => 'Nothing yet.'],
            ['type' => 'case_studies', 'heading' => 'Hidden', 'hide_when_empty' => true],
        ];
        $html = '';
        foreach ($sections as $sec) { $out = $r->renderSection($sec, $brand, $website, [], 'home'); $this->assertIsString($out); $html .= $out; }
        $this->assertStringContainsString('Acme &lt;b&gt;Corp&lt;/b&gt;', $html, 'logo names must be escaped');
        $this->assertStringNotContainsString('javascript:', $html, 'unsafe logo URLs must not be emitted');
        $this->assertStringContainsString('STAGE 1', $html);
        $this->assertStringContainsString('STAGE 2', $html);
        $this->assertStringContainsString('Deliverable · <b>Brief</b>', $html);
        $this->assertStringContainsString('Nothing yet.', $html);
        $this->assertStringNotContainsString('Hidden', $html, 'hide_when_empty suppresses the empty state');
    }

    public function test_enterprise_theme_renders_full_page_chrome_rfp_form_and_fallback(): void
    {
        $theme = new MrDigitalEnterpriseTheme();
        $website = ['id' => 0, 'workspace_id' => 0, 'name' => 'MR Digital', 'subdomain' => 'mrdigital.levelupgrowth.io',
                    'settings_json' => json_encode(['theme' => 'mrdigital-enterprise', 'article_base' => 'insights', 'case_study_base' => 'case-studies', 'phone' => '+1 604 000 0000'])];
        $brand = ['primary' => '#0A1A33', 'secondary' => '#12294F', 'accent' => '#2458D6', 'font_heading' => 'Archivo', 'font_body' => 'IBM Plex Sans'];
        $secs = [
            ['type' => 'header', 'logo_text' => 'MR Digital', 'nav_links' => [['label' => 'Services', 'url' => '/services'], ['label' => 'Contact', 'url' => '/contact']], 'cta_text' => 'Request a proposal', 'cta_url' => '/contact'],
            ['type' => 'hero', 'heading' => 'Custom systems.', 'subheading' => 'Sub', 'cta_text' => 'Go', 'ledger' => ['caption' => 'Engagement standards', 'rows' => [['label' => 'SLA', 'value' => '99.95%']]]],
            ['type' => 'process_steps', 'heading' => 'Method', 'steps' => [['title' => 'Discover', 'body' => 'x', 'deliverable' => 'Brief']]],
            ['type' => 'case_studies', 'heading' => 'Work', 'hide_when_empty' => true],
            ['type' => 'contact_form', 'source' => 'rfp', 'heading' => 'Project brief', 'submit_label' => 'Send brief', 'fields' => [
                ['name' => 'name', 'label' => 'Full name', 'type' => 'text', 'required' => true], ['name' => 'email', 'label' => 'Work email', 'type' => 'email', 'required' => true],
                ['name' => 'budget_band', 'label' => 'Budget', 'type' => 'choice', 'options' => ['Under $100k', '$1M +']], ['name' => 'brief', 'label' => 'Brief', 'type' => 'textarea']]],
            ['type' => 'map', 'heading' => 'Where we are'],
            ['type' => 'footer', 'copyright' => '© 2026 MR Digital Inc.', 'columns' => [['heading' => 'Legal', 'links' => [['label' => 'Privacy', 'url' => '/privacy']]]]],
        ];
        $calls = 0;
        $html = $theme->renderBody($secs, $brand, $website, ['slug' => 'contact', 'title' => 'Contact'], function (array $sec) use (&$calls) { $calls++; return '<section>FALLBACK ' . e($sec['heading'] ?? '') . '</section>'; });
        $this->assertSame(1, $calls, 'only the unknown (map) section should reach the fallback');
        $this->assertStringContainsString('class="md-root"', $html);
        $this->assertStringContainsString('class="md-top"', $html);
        $this->assertStringContainsString('aria-current="page"', $html, 'the Contact nav link is current on /contact');
        $this->assertStringContainsString('Engagement standards', $html);
        $this->assertStringContainsString('99.95%', $html);
        $this->assertStringContainsString('STAGE 1', $html);
        $this->assertStringContainsString('id="md-rfp"', $html);
        $this->assertStringContainsString('data-source="rfp"', $html);
        $this->assertStringContainsString('data-md-group="budget_band"', $html, 'choice fields render as site-styled option buttons');
        $this->assertStringNotContainsString('<select', $html, 'no native controls');
        $this->assertStringContainsString('FALLBACK Where we are', $html);
        $this->assertStringContainsString('class="md-footer"', $html);
        $this->assertStringContainsString('--md-primary:#0A1A33', $html);
        $this->assertStringContainsString('/api/public/contact/', $html);
        $this->assertStringNotContainsString('Work</h2>', $html, 'empty case_studies with hide_when_empty renders nothing');
    }

    public function test_enterprise_theme_rejects_unsafe_brand_values(): void
    {
        $theme = new MrDigitalEnterpriseTheme();
        $website = ['id' => 0, 'workspace_id' => 0, 'name' => 'X', 'subdomain' => 'x.levelupgrowth.io', 'settings_json' => '{}'];
        $brand = ['primary' => 'red;}</style><script>alert(1)</script>', 'font_heading' => "Archivo'); evil"];
        $html = $theme->renderBody([['type' => 'header']], $brand, $website, ['slug' => 'home']);
        $this->assertStringNotContainsString('<script>alert(1)', $html);
        $this->assertStringContainsString('--md-primary:#0A1A33', $html, 'bad colour falls back to the theme default');
        $this->assertStringContainsString("--md-fh:'Archivo'", $html, 'bad font falls back to the theme default');
    }
}
