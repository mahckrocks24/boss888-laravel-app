<?php

namespace Tests\Feature\Builder;

use App\Engines\Builder\Schema\SectionSchema;
use App\Engines\Builder\Services\BuilderRenderer;
use Tests\TestCase;

/**
 * Contact provision (2026-09-02): email / phone / address are basic contact-us elements that had no schema
 * or renderer support, so Arthur could never add them to a site. They are now first-class footer fields.
 */
class FooterContactTest extends TestCase
{
    public function test_footer_schema_accepts_phone_email_address(): void
    {
        $allowed = SectionSchema::allowedFieldsFor('footer');
        $this->assertContains('phone', $allowed);
        $this->assertContains('email', $allowed);
        $this->assertContains('address', $allowed);

        // A footer section carrying contact info must validate (so Arthur's update_field is not rejected).
        $v = SectionSchema::validate(['type' => 'footer', 'phone' => '+1 862 290 5020', 'email' => 'hi@x.com', 'address' => 'Austin, TX']);
        $this->assertTrue($v['ok'] ?? false, 'footer w/ contact fields should validate: ' . json_encode($v['errors'] ?? []));
    }

    public function test_renderer_outputs_a_clickable_contact_block(): void
    {
        $r = app(BuilderRenderer::class);
        $m = new \ReflectionMethod($r, 'renderFooter');
        $m->setAccessible(true);
        $html = (string) $m->invoke($r, [
            'type' => 'footer',
            'phone' => '+1 862 290 5020',
            'email' => 'hello@chefred.com',
            'address' => '123 Main St, Austin',
        ], ['font_heading' => 'Inter', 'primary' => '#7C3AED']);

        $this->assertStringContainsString('tel:+18622905020', $html, 'phone renders as a tel: link');
        $this->assertStringContainsString('mailto:hello@chefred.com', $html, 'email renders as a mailto: link');
        $this->assertStringContainsString('123 Main St, Austin', $html, 'address renders');
    }

    public function test_footer_without_contact_fields_renders_no_contact_block(): void
    {
        $r = app(BuilderRenderer::class);
        $m = new \ReflectionMethod($r, 'renderFooter');
        $m->setAccessible(true);
        $html = (string) $m->invoke($r, ['type' => 'footer'], ['font_heading' => 'Inter', 'primary' => '#7C3AED']);
        $this->assertStringNotContainsString('tel:', $html);
        $this->assertStringNotContainsString('mailto:', $html);
    }
}
