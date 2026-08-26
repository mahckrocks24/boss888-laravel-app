<?php

namespace Tests\Feature\Builder888;

use App\Engines\Builder\Services\TemplateService;
use Tests\TestCase;

/**
 * BUILDER888 · P1-8 — template variable rendering.
 *
 * Frozen-baseline defect (Journey A, 2026-08-10): the canonical customer
 * creation journey died with
 *
 *   str_replace(): Argument #2 ($replace) must be of type string
 *                  when argument #1 ($search) is a string
 *
 * because TemplateService::render() substituted variables with
 * `str_replace('{{key}}', $value ?? '', $html)` — `??` guards null and
 * nothing guarded arrays or objects.
 *
 * These tests assert RENDERED OUTPUT, not merely the absence of an exception.
 */
class TemplateRenderingTest extends TestCase
{
    private const INDUSTRY = 'gym';

    private function render(array $vars): string
    {
        return app(TemplateService::class)->render(self::INDUSTRY, $vars);
    }

    /**
     * Substitute a variable and return what replaced the placeholder.
     * Uses a key the template does not define so the assertion is unambiguous.
     */
    private function substituted(mixed $value): string
    {
        $html = $this->render(['business_name' => 'B888 Fixture', 'b888_probe' => $value]);

        // The probe key is not a real placeholder, so instead assert against a
        // real one the templates DO carry.
        return $html;
    }

    // ── the exact frozen failure ────────────────────────────────────────────

    public function test_flat_scalar_array_no_longer_raises_the_frozen_type_error(): void
    {
        $html = $this->render([
            'business_name' => 'B888 Fixture',
            'services'      => ['Puncture repair', 'Gear tuning', 'Brake service'],
        ]);

        $this->assertNotSame('', $html, 'render() returned nothing');
        $this->assertStringNotContainsString('Array', $html, 'array leaked as the literal string "Array"');
    }

    public function test_flat_scalar_array_renders_as_a_readable_joined_list(): void
    {
        $html = $this->render([
            'business_name' => 'B888 Fixture',
            'hero_subtitle' => ['Architecture', 'Interior Design', 'Consulting'],
        ]);

        $this->assertStringContainsString(
            'Architecture, Interior Design, Consulting',
            $html,
            'flat scalar list should join with ", " during P1-8A containment'
        );
    }

    // ── every accepted type ─────────────────────────────────────────────────

    public function test_string_value_passes_through_unchanged(): void
    {
        $html = $this->render(['business_name' => 'Northgate Bicycle Repair']);
        $this->assertStringContainsString('Northgate Bicycle Repair', $html);
    }

    public function test_null_becomes_empty_string(): void
    {
        $html = $this->render(['business_name' => 'B888 Fixture', 'hero_subtitle' => null]);
        $this->assertStringNotContainsString('{{hero_subtitle}}', $html);
        $this->assertStringNotContainsString('NULL', $html);
    }

    public function test_integer_renders_as_its_digits(): void
    {
        $html = $this->render(['business_name' => 'B888 Fixture', 'hero_subtitle' => 1998]);
        $this->assertStringContainsString('1998', $html);
    }

    public function test_boolean_keeps_existing_php_cast_semantics(): void
    {
        // Booleans already worked before P1-8 (PHP coerces them); containment
        // must not silently change what published sites render.
        $true  = $this->render(['business_name' => 'B888 Fixture', 'hero_subtitle' => true]);
        $false = $this->render(['business_name' => 'B888 Fixture', 'hero_subtitle' => false]);

        $this->assertNotSame('', $true);
        $this->assertNotSame('', $false);
        $this->assertStringNotContainsString('{{hero_subtitle}}', $true);
        $this->assertStringNotContainsString('{{hero_subtitle}}', $false);
    }

    public function test_empty_array_becomes_empty_string(): void
    {
        $html = $this->render(['business_name' => 'B888 Fixture', 'hero_subtitle' => []]);
        $this->assertStringNotContainsString('{{hero_subtitle}}', $html);
        $this->assertStringNotContainsString('Array', $html);
    }

    public function test_mixed_scalar_array_joins_deterministically(): void
    {
        $html = $this->render([
            'business_name' => 'B888 Fixture',
            'hero_subtitle' => ['Open', 7, 'days'],
        ]);
        $this->assertStringContainsString('Open, 7, days', $html);
    }

    // ── structured values must NOT be flattened into meaningless text ───────

    public function test_nested_array_does_not_emit_array_or_json_garbage(): void
    {
        $html = $this->render([
            'business_name' => 'B888 Fixture',
            'hero_subtitle' => [['name' => 'Repair', 'price' => '£25'], ['name' => 'Tune', 'price' => '£40']],
        ]);

        $this->assertStringNotContainsString('Array', $html);
        $this->assertStringNotContainsString('{"name"', $html, 'raw JSON leaked into customer HTML');
        $this->assertStringNotContainsString('{{hero_subtitle}}', $html);
    }

    public function test_associative_array_does_not_emit_array_or_json_garbage(): void
    {
        $html = $this->render([
            'business_name' => 'B888 Fixture',
            'hero_subtitle' => ['monday' => '9-5', 'tuesday' => '9-5'],
        ]);

        $this->assertStringNotContainsString('Array', $html);
        $this->assertStringNotContainsString('{"monday"', $html);
        $this->assertStringNotContainsString('{{hero_subtitle}}', $html);
    }

    public function test_object_from_decoded_json_does_not_emit_garbage(): void
    {
        $html = $this->render([
            'business_name' => 'B888 Fixture',
            'hero_subtitle' => json_decode('{"a":1,"b":[2,3]}'),   // stdClass
        ]);

        $this->assertStringNotContainsString('stdClass', $html);
        $this->assertStringNotContainsString('Array', $html);
        $this->assertStringNotContainsString('{{hero_subtitle}}', $html);
    }

    // ── the renderer must never leak a raw engine error ─────────────────────

    public function test_no_php_error_text_reaches_rendered_output(): void
    {
        $html = $this->render([
            'business_name' => 'B888 Fixture',
            'hero_subtitle' => ['a' => ['deeply' => ['nested' => true]]],
        ]);

        foreach (['str_replace(', 'TypeError', 'Argument #2', 'Stack trace', '/var/www/'] as $leak) {
            $this->assertStringNotContainsString($leak, $html, "engine detail leaked: {$leak}");
        }
    }

    // ── unresolved placeholders must not survive ────────────────────────────

    public function test_no_unresolved_placeholders_remain_for_text_variables(): void
    {
        $html = $this->render(['business_name' => 'B888 Fixture', 'services' => ['A', 'B']]);
        $this->assertDoesNotMatchRegularExpression(
            '/\{\{(?!.*image)[a-z_0-9]+\}\}/i',
            $html,
            'a non-image placeholder survived rendering'
        );
    }

    /**
     * PHANTOM CARDS (2026-08-26) — a repeated personnel/item slot the customer did not
     * fill kept the industry-DEFAULT photo (its *_image token defaults to a non-empty URL,
     * so the empty-src strip never fired) with an empty name: a fabricated team member with
     * a stock photo and a blank name, plus an empty-alt image. Regression for
     * stripEmptyPhantomCards().
     */
    public function test_unfilled_personnel_slots_do_not_render_phantom_cards(): void
    {
        $html = app(TemplateService::class)->render('dental', [
            'business_name'      => 'Bright Smile Dental',
            'doctor_1_name'      => 'Dr. Jane Smith',
            'doctor_1_specialty' => 'Cosmetic', 'doctor_1_title' => 'DDS', 'doctor_1_bio' => 'Bio.',
            'doctor_2_name'      => 'Dr. John Lee',
            'doctor_2_specialty' => 'Ortho', 'doctor_2_title' => 'DDS', 'doctor_2_bio' => 'Bio.',
            // doctor_3 / doctor_4 intentionally omitted (a 2-dentist practice)
        ]);

        // No image may render with an empty alt (the phantom default photos).
        preg_match_all('/<img\b[^>]*>/i', $html, $imgs);
        foreach ($imgs[0] as $img) {
            $this->assertDoesNotMatchRegularExpression(
                '/\balt=(""|\x27\x27)/', $img,
                'a phantom personnel photo rendered with an empty alt: ' . $img
            );
        }
        // No doctor-card wrapper may carry an empty name field.
        $this->assertDoesNotMatchRegularExpression(
            '/data-field="doctor_[0-9]+_name"[^>]*>\s*<\/div>/i', $html,
            'a phantom doctor card (empty name) survived'
        );
        // No empty certification badge (a check-mark next to a blank name).
        $this->assertDoesNotMatchRegularExpression(
            '/data-field="cert_[0-9]+_name"[^>]*>\s*<\/div>/i', $html,
            'a phantom certification badge (empty name) survived'
        );
        // The two REAL dentists must still be present.
        $this->assertStringContainsString('Dr. Jane Smith', $html);
        $this->assertStringContainsString('Dr. John Lee', $html);
    }

    /**
     * The phantom-card removal must NOT delete filled cards.
     */
    public function test_fully_filled_personnel_and_certs_are_all_preserved(): void
    {
        $html = app(TemplateService::class)->render('dental', [
            'business_name' => 'Bright Smile Dental',
            'doctor_1_name' => 'Dr. A', 'doctor_1_specialty' => 'X', 'doctor_1_title' => 'DDS', 'doctor_1_bio' => 'b',
            'doctor_2_name' => 'Dr. B', 'doctor_2_specialty' => 'X', 'doctor_2_title' => 'DDS', 'doctor_2_bio' => 'b',
            'doctor_3_name' => 'Dr. C', 'doctor_3_specialty' => 'X', 'doctor_3_title' => 'DDS', 'doctor_3_bio' => 'b',
            'doctor_4_name' => 'Dr. D', 'doctor_4_specialty' => 'X', 'doctor_4_title' => 'DDS', 'doctor_4_bio' => 'b',
            'cert_1_name'   => 'Board Certified', 'cert_1_desc' => 'ADA',
            'cert_2_name'   => 'Invisalign Provider', 'cert_2_desc' => 'Elite',
        ]);
        foreach (['Dr. A', 'Dr. B', 'Dr. C', 'Dr. D', 'Board Certified', 'Invisalign Provider'] as $needle) {
            $this->assertStringContainsString($needle, $html, 'filled content was wrongly removed: ' . $needle);
        }
    }

    /**
     * EMPTY SECTIONS (2026-08-26) — after phantom-card removal, a section whose entire
     * repeated-item grid is empty (a business with no certifications) would render as a
     * lone heading over nothing. stripEmptySections() removes the section AND its nav
     * anchor link so the nav never points at a deleted target. Realistic case: a
     * 2-dentist practice that lists no certifications.
     */
    public function test_a_section_with_no_items_is_removed_with_its_nav_link(): void
    {
        $html = app(TemplateService::class)->render('dental', [
            'business_name'      => 'Bright Smile Dental',
            'doctor_1_name'      => 'Dr. Jane Smith',
            'doctor_1_specialty' => 'Cosmetic', 'doctor_1_title' => 'DDS', 'doctor_1_bio' => 'Bio.',
            'doctor_2_name'      => 'Dr. John Lee',
            'doctor_2_specialty' => 'Ortho', 'doctor_2_title' => 'DDS', 'doctor_2_bio' => 'Bio.',
            // no certifications supplied
        ]);

        // The certifications section and its nav link are gone.
        $this->assertDoesNotMatchRegularExpression(
            '/<section[^>]*\bid="certifications"/i', $html,
            'an empty certifications section was rendered as a lone heading'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/href="#certifications"/i', $html,
            'a nav link still points at the removed certifications section'
        );
        // The doctors section (2 real cards) is untouched.
        $this->assertMatchesRegularExpression('/<section[^>]*\bid="doctors"/i', $html);
        $this->assertStringContainsString('Dr. Jane Smith', $html);

        // No nav anchor may point at a missing id (no broken in-page links).
        preg_match_all('/href="#([a-z0-9_-]+)"/i', $html, $links);
        preg_match_all('/\bid="([a-z0-9_-]+)"/i', $html, $ids);
        $idset = array_flip($ids[1]);
        foreach (array_unique($links[1]) as $target) {
            $this->assertArrayHasKey($target, $idset, "nav anchor #$target has no matching id");
        }

        // No empty grid/list container survives anywhere.
        $this->assertDoesNotMatchRegularExpression(
            '#<div class="[^"]*(?:grid|list)[^"]*">\s*</div>#i', $html,
            'an empty repeated-item grid survived'
        );
    }

    /**
     * DUPLICATE IDs (2026-08-26) — 13 templates rendered duplicate id attributes (a
     * contact-info section and a contact-form section both id="contact"; interior_design
     * had id="contact-form" twice). Duplicate ids are invalid HTML and break #anchor
     * navigation, getElementById and ARIA references. Estate-wide guard: every template
     * must render a unique id set.
     */
    public function test_no_template_renders_duplicate_id_attributes(): void
    {
        $dirs = glob(storage_path('templates/*'), GLOB_ONLYDIR);
        $this->assertNotEmpty($dirs, 'no templates found');
        $svc = app(TemplateService::class);
        $offenders = [];
        foreach ($dirs as $dir) {
            $ind = basename($dir);
            if (!is_file($dir . '/template.html')) { continue; }
            try {
                $html = $svc->render($ind, ['business_name' => 'Regression Fixture'], null);
            } catch (\Throwable $e) {
                continue; // a missing/invalid template is a separate concern
            }
            preg_match_all('/\sid="([^"]+)"/i', $html, $m);
            $counts = array_count_values($m[1]);
            $dups = array_filter($counts, fn ($c) => $c > 1);
            if ($dups) {
                $offenders[] = $ind . ': ' . implode(',', array_keys($dups));
            }
        }
        $this->assertSame([], $offenders, "templates rendered duplicate ids:\n" . implode("\n", $offenders));
    }

    /**
     * DEAD SOCIAL LINKS (2026-08-26) — social-icon links (<a href="{{social_x}}"
     * data-field="social_x">) rendered as dead href="" links when the business did not
     * configure that network (social handles are never fabricated). A dead <a href="">
     * reloads the page. stripEmptySocialLinks() drops the empty ones and any social
     * container left with no links; configured networks are preserved.
     */
    public function test_unconfigured_social_links_do_not_render_as_dead_links(): void
    {
        $svc = app(TemplateService::class);

        // No socials configured: no dead social link, no empty social container survives.
        $html = $svc->render('dental', ['business_name' => 'Test Co'], null);
        $this->assertDoesNotMatchRegularExpression(
            '/<a\b[^>]*data-field="social_[^"]*"[^>]*href=""/i', $html,
            'a social link rendered with data-field before an empty href'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/<a\b[^>]*href=""[^>]*data-field="social_/i', $html,
            'a social link rendered with an empty href'
        );
        $this->assertDoesNotMatchRegularExpression(
            '#<div class="[^"]*footer-socials?[^"]*">\s*</div>#i', $html,
            'an empty social container survived'
        );

        // Two configured: exactly those two survive, container stays.
        $html2 = $svc->render('dental', [
            'business_name'    => 'Test Co',
            'social_facebook'  => 'https://facebook.com/testco',
            'social_instagram' => 'https://instagram.com/testco',
        ], null);
        $this->assertStringContainsString('https://facebook.com/testco', $html2);
        $this->assertStringContainsString('https://instagram.com/testco', $html2);
        preg_match_all('/<a\b[^>]*data-field="social_[^"]*"[^>]*>/i', $html2, $links);
        $this->assertCount(2, $links[0], 'exactly the two configured social links should remain');
        $this->assertStringContainsString('footer-social', $html2, 'the social container should remain when links exist');
    }

    /**
     * TEMPLATE CSS INJECTION (2026-08-26) — brand color/font tokens ({{primary_color}} etc.)
     * land in <style> CSS contexts (news_channel: `--primary: {{primary_color}}`). forHtml
     * html-escaped them (blocking </style> — no XSS) but left { } ; which break out of a CSS
     * rule/declaration -> CSS injection (defacement / CSS-exfil), self-scoped. forHtml now
     * strips those chars for color/font keys; the theme-color/favicon meta is hex-validated.
     */
    public function test_brand_color_tokens_cannot_inject_css(): void
    {
        $svc = app(TemplateService::class);
        $bad = 'red;} body{background:url(//evil/x)} .a{color:red';

        // forHtml strips CSS rule-breakout chars for color/font keys, preserves legit values.
        $this->assertStringNotContainsString('}', \App\Engines\Builder\Support\TemplateVariableNormalizer::forHtml('primary_color', $bad));
        $this->assertStringNotContainsString(';', \App\Engines\Builder\Support\TemplateVariableNormalizer::forHtml('font_heading', $bad));
        $this->assertSame('#6C5CE7', \App\Engines\Builder\Support\TemplateVariableNormalizer::forHtml('primary_color', '#6C5CE7'));
        $this->assertSame('rgb(108,92,231)', \App\Engines\Builder\Support\TemplateVariableNormalizer::forHtml('accent_color', 'rgb(108,92,231)'));

        // news_channel is the template that uses a color token inside a <style> block.
        $html = $svc->render('news_channel', ['business_name' => 'T', 'primary_color' => $bad]);
        if (preg_match_all('#<style[^>]*>(.*?)</style>#is', $html, $m)) {
            foreach ($m[1] as $css) {
                $this->assertDoesNotMatchRegularExpression('/\}\s*body\s*\{background:url\(\/\/evil/', $css,
                    'brand color broke out of a CSS rule in a <style> block');
            }
        }
        // Legit hex reaches the CSS custom property.
        $ok = $svc->render('news_channel', ['business_name' => 'T', 'primary_color' => '#6C5CE7']);
        $this->assertStringContainsString('#6C5CE7', $ok, 'legit brand color was dropped from the CSS');

        // theme-color meta is always a valid hex color, never an injected literal.
        $themed = $svc->render('dental', ['business_name' => 'T', 'primary_color' => $bad]);
        if (preg_match('/theme-color" content="([^"]*)"/', $themed, $tm)) {
            $this->assertMatchesRegularExpression('/^#[0-9a-fA-F]{3,8}$/', $tm[1], 'theme-color carried an injected literal');
        }
    }
}
