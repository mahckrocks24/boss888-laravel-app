<?php

namespace Tests\Feature\Builder888;

use App\Engines\Builder\Schema\SectionSchema;
use App\Engines\Builder\Services\ThemeRegistry;
use App\Engines\Builder\Services\TravelFriendlyTheme;
use Tests\TestCase;

/**
 * SGTRAVEL T1/T2 (2026-09-21) — the AMG Friendly design generalised: brand tokens and contact/social come from
 * settings_json, no AMG copy leaks, the inquiry drawer and site-styled controls are present, booking_wizard exists.
 */
class TravelFriendlyThemeTest extends TestCase
{
    private function site(): array
    {
        return ['id' => 0, 'workspace_id' => 0, 'name' => 'SG Travel and Tours', 'subdomain' => 'sgtravel.levelupgrowth.io',
            'settings_json' => json_encode(['theme' => 'travel-friendly', 'primary_color' => '#1E5BC6', 'primary_deep' => '#0F2A5C', 'secondary_color' => '#F5761A', 'accent_color' => '#F5761A', 'whatsapp' => '639274521813', 'phone' => '+63 927 452 1813', 'email' => 'sg@example.com', 'office' => 'Manila', 'booking_ref_prefix' => 'SG'])];
    }

    public function test_registry_and_schema_carry_the_travel_theme_and_booking_wizard(): void
    {
        $this->assertInstanceOf(TravelFriendlyTheme::class, ThemeRegistry::resolve('travel-friendly'));
        $this->assertInstanceOf(\App\Engines\Builder\Services\AmgTravelTheme::class, ThemeRegistry::resolve('amg-travel'), 'AMG keeps its own theme');
        $this->assertContains('booking_wizard', SectionSchema::allowedTypes());
        $this->assertTrue(SectionSchema::validate(['type' => 'booking_wizard', 'heading' => 'Start'])['ok']);
    }

    public function test_brand_tokens_contact_and_whatsapp_come_from_settings_and_no_amg_copy_leaks(): void
    {
        $theme = new TravelFriendlyTheme();
        $secs = [
            ['type' => 'header', 'logo_text' => 'SG Travel and Tours', 'nav_links' => ['Destinations', 'Contact'], 'cta_text' => 'Start a booking'],
            ['type' => 'hero', 'heading' => 'Your dream trip', 'subheading' => 'Sub'],
            ['type' => 'grid', 'variant' => 'packages', 'heading' => 'Packages', 'items' => [['title' => 'Vietnam Highlights', 'nights' => '3N / 4D', 'price' => 'from $499', 'highlights' => ['Hanoi']]]],
            ['type' => 'booking_wizard', 'heading' => 'Tell us about your trip'],
            ['type' => 'contact_form', 'heading' => 'Get in touch'],
            ['type' => 'footer', 'logo_text' => 'SG Travel and Tours', 'badges' => ['DTI Registered']],
        ];
        $html = $theme->renderBody($secs, [], $this->site(), ['slug' => 'home']);
        $this->assertStringContainsString('--sky:#1E5BC6', $html);
        $this->assertStringContainsString('--navy-deep:#0F2A5C', $html);
        $this->assertStringContainsString('--teal:#F5761A', $html);
        $this->assertStringContainsString('wa.me/639274521813', $html, 'WhatsApp links from settings');
        $this->assertStringContainsString('"ref":"SG"', $html, 'booking reference prefix from settings');
        $this->assertStringContainsString('id="wzSubmit"', $html, 'inquiry drawer present');
        $this->assertStringContainsString('data-datepick', $html, 'site-styled date picker inputs');
        $this->assertStringContainsString('data-field="budget"', $html, 'budget is a segmented control');
        $this->assertStringNotContainsString('<select', $html, 'no native selects');
        $this->assertStringNotContainsString('type="date"', $html, 'no native date pickers');
        $this->assertStringContainsString('DTI Registered', $html);
        $this->assertStringContainsString('Vietnam Highlights', $html);
        $this->assertStringContainsString('Tell us about your trip', $html);
        foreach (['AMG Global', 'Santa Rosa', 'Laguna', 'PTAA Member', 'amgglobal'] as $leak) {
            $this->assertStringNotContainsString($leak, str_replace('ptaa-badge', '', $html), "AMG copy leaked: {$leak}");
        }
        $this->assertStringContainsString('data-val="Viber"', $html, 'Viber stays as a contact option, after WhatsApp');
    }

    public function test_bad_colours_fall_back_to_the_friendly_defaults(): void
    {
        $theme = new TravelFriendlyTheme();
        $site = $this->site(); $site['settings_json'] = json_encode(['theme' => 'travel-friendly', 'primary_color' => 'red;}</style><script>alert(1)</script>']);
        $html = $theme->renderBody([['type' => 'header']], [], $site, ['slug' => 'home']);
        $this->assertStringNotContainsString('<script>alert(1)', $html);
        $this->assertStringContainsString('--sky:#1B6FC4', $html);
    }
}
