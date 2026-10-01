<?php

namespace Tests\Feature\Builder888;

use App\Engines\Builder\Services\TemplateService;
use Tests\TestCase;

/** SINGLE TRUTH + SERVICE SELECTS (2026-09-06, REPORT-0044 P0 #1 and #10). */
class SingleTruthTest extends TestCase
{
    public function test_booking_form_service_select_lists_the_customers_services_not_demo_specialties(): void
    {
        $t = new TemplateService();
        $m = $t->getManifest('dental') ?: [];
        $vars = [];
        foreach (($m['variables'] ?? []) as $k => $spec) { $vars[$k] = is_array($spec) ? (string) ($spec['default'] ?? '') : ''; }
        $vars['service_1_title'] = 'Teeth Whitening'; $vars['service_2_title'] = 'Invisalign'; $vars['service_3_title'] = 'Implants';
        $vars['service_4_title'] = 'Hidden One'; $vars['service_4_display'] = 'display:none';
        $html = $t->render('dental', $vars, null);
        preg_match('#<select\b[^>]*name="service[^"]*"[^>]*>(.*?)</select>#is', $html, $sel);
        $this->assertNotEmpty($sel, 'template has a service select');
        $this->assertStringContainsString('<option>Teeth Whitening</option>', $sel[1]);
        $this->assertStringContainsString('<option>Implants</option>', $sel[1]);
        $this->assertStringNotContainsString('Hidden One', $sel[1], 'hidden services are not offered');
        $this->assertStringNotContainsString('Cardiology', $sel[1], 'demo specialties never ship');
        $this->assertMatchesRegularExpression('/<option[^>]*(value=""|disabled)/', $sel[1], 'placeholder option kept');
    }

    public function test_fill_service_selects_is_a_no_op_without_services(): void
    {
        $t = new TemplateService();
        $html = '<form><select name="service_type"><option value="">Pick</option><option>Demo A</option></select></form>';
        $this->assertSame($html, $t->fillServiceSelects($html, []));
    }

    public function test_page_preview_serves_the_static_export_when_one_exists(): void
    {
        // The route body is the contract: a static file for the page slug wins over the generic sections render.
        $route = (string) file_get_contents(base_path('routes/api/authenticated/builder-01.php'));
        $this->assertStringContainsString("'source' => 'static_export'", $route);
        $this->assertStringContainsString("\$slug === 'home' || \$slug === '' ? 'index.html' : \$slug . '/index.html'", $route);
    }

    public function test_editor_additions_on_a_static_site_are_delegated_to_arthur(): void
    {
        $src = (string) file_get_contents(base_path('app/Engines/Builder/Services/ArthurEditService.php'));
        $this->assertStringContainsString('BuilderCapabilities::classify($userMessage', $src);
        $this->assertStringContainsString("in_array(\$planEarly['kind'], ['page', 'section', 'clarify', 'edit', 'remove', 'unsupported', 'style', 'image', 'video', 'overlay', 'image_edit'], true)", $src); // every kind: STRESS 2026-09-06; clarify/style/image/video/overlay/image_edit joined since (RISK-0195) — fix-all 2026-10-01
        $this->assertStringContainsString('->handleSiteRequest(', $src);
    }
}
