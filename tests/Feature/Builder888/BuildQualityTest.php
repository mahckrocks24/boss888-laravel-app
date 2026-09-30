<?php

namespace Tests\Feature\Builder888;

use App\Engines\Builder\Services\ArthurService;
use App\Engines\Builder\Support\BuildQuality;
use Tests\TestCase;

/**
 * ARTHUR-4 (RFC-0021 wave 4) — fail-first tests from REPORT-0067 #1 (pages ignored), #2 (another trade's copy and photos),
 * #5 (colours ignored), #11 (Arabic site left-to-right, English chrome).
 */
class BuildQualityTest extends TestCase
{
    private bool $madeSwitch = false;
    protected function setUp(): void { parent::setUp(); $sw = storage_path(BuildQuality::SWITCH); if (! is_file($sw)) { touch($sw); $this->madeSwitch = true; } }
    protected function tearDown(): void { if ($this->madeSwitch) @unlink(storage_path(BuildQuality::SWITCH)); parent::tearDown(); }

    public function test_the_designs_trade_words_are_caught_when_the_brief_never_uses_them(): void
    {
        $brief = 'Crumb and Co is a small artisan bakery in Norwich. We bake sourdough, croissants and pastries daily and make custom birthday and wedding cakes to order.';
        $fw = BuildQuality::foreignWords('cafe', $brief);
        $this->assertContains('roast', $fw); $this->assertContains('the room', BuildQuality::foreignWords('restaurant_sorrelroom', 'a Lebanese family restaurant in Dubai'), 'a design variant speaks its base trade'); $this->assertContains('espresso', $fw); $this->assertContains('wholesale', $fw);
        $vars = ['nav_2' => 'Our roast', 'hero_cta_2' => 'Our coffee', 'hero_subtitle' => 'Norwich-roasted filter coffee, loose-leaf tea and pastries', 'story_body' => 'Fresh bread every morning on Elm Hill.', 'offers_title' => 'Daily bakes', 'hero_image' => '/x.jpg', 'contact_phone' => '01603 555 214', 'steps_title' => 'How we bake'];
        $manifest = ['steps_title' => ['default' => 'How we bake'], 'offers_title' => ['default' => 'What is on the counter today']];
        $manifest['nav_4'] = ['default' => 'Wholesale']; $manifest['hero_cta_2'] = ['default' => 'Our coffee'];
        $hits = BuildQuality::findForeign($vars, $manifest, $fw);
        $this->assertSame('wholesale', $hits['nav_4'], 'a design default the model never filled is caught too');
        $this->assertSame('roast', $hits['nav_2']); $this->assertSame('coffee', $hits['hero_cta_2']); $this->assertArrayHasKey('hero_subtitle', $hits);
        $this->assertArrayNotHasKey('story_body', $hits); $this->assertArrayNotHasKey('hero_image', $hits); $this->assertArrayNotHasKey('contact_phone', $hits);
        $this->assertArrayNotHasKey('steps_title', $hits, 'a short label equal to its default is not sample copy');
        $this->assertTrue(BuildQuality::stillForeign('Our coffee corner', $fw)); $this->assertFalse(BuildQuality::stillForeign('Our counter', $fw));
    }

    public function test_photos_follow_the_business_not_the_design(): void
    {
        $this->assertContains('subject:aerial', BuildQuality::poolTagsFor('drone roof surveys', 'thermal imaging'));
        $this->assertContains('food', BuildQuality::poolTagsFor('bakery', 'sourdough, croissants'));
        $this->assertContains('retail', BuildQuality::poolTagsFor('online shoe shop', 'sneakers'));
        $this->assertSame(['nature', 'building', 'office', 'subject:cityscape', 'subject:architecture'], BuildQuality::poolTagsFor('quantum widget brokerage'));
    }

    public function test_requested_pages_map_to_the_catalogue(): void
    {
        $slugs = BuildQuality::pagesFromRequest(['Home', 'Menu', 'Cakes to Order', 'About', 'Contact'], 'cafe');
        $this->assertContains('menu', $slugs); $this->assertContains('about', $slugs); $this->assertContains('contact', $slugs);
        $this->assertNotContains('home', $slugs); $this->assertLessThanOrEqual(5, count($slugs));
        $this->assertSame([], BuildQuality::pagesFromRequest([], 'cafe'));
        $s2 = BuildQuality::pagesFromRequest(['Services', 'Case Studies', 'Pricing', 'Contact'], 'home_services');
        $this->assertContains('services', $s2); $this->assertContains('pricing', $s2); $this->assertContains('contact', $s2);
    }

    public function test_an_arabic_business_gets_a_right_to_left_site_with_arabic_chrome(): void
    {
        $html = '<!doctype html><html lang="en"><head><title>x</title></head><body><nav><a href="/">Home</a><a href="#menu">Menu</a><a href="#contact">Contact</a></nav><form><input placeholder="Your name"><input placeholder="Your email"><button type="submit">Send message</button></form><p>Home cooking</p></body></html>';
        $out = BuildQuality::localiseChrome($html, ['business_name' => 'مطعم الواحة', 'hero_title' => 'مطعم الواحة']);
        $this->assertStringContainsString('<html lang="ar" dir="rtl">', $out);
        $this->assertStringContainsString('id="lu-rtl"', $out);
        $this->assertStringContainsString('>الرئيسية<', $out); $this->assertStringContainsString('>القائمة<', $out); $this->assertStringContainsString('>أرسل الرسالة<', $out);
        $this->assertStringContainsString('placeholder="اسمك"', $out);
        $this->assertStringContainsString('<p>Home cooking</p>', $out, 'running copy is not touched');
        $this->assertSame($html, BuildQuality::localiseChrome($html, ['business_name' => 'Crumb and Co']), 'a Latin site is untouched');
    }

    public function test_colour_words_and_typed_page_lists_are_read_from_the_conversation(): void
    {
        $c = BuildQuality::coloursFromText('Warm and friendly feel, cream and terracotta colours.');
        $this->assertSame('#B05020', $c['primary'], 'the darker colour leads'); $this->assertSame('#F5EEDC', $c['secondary']);
        $c2 = BuildQuality::coloursFromText('Serious, technical, trustworthy look, navy and safety orange.');
        $this->assertSame('#1A2744', $c2['primary']); $this->assertSame('#F26522', $c2['secondary']);
        $this->assertSame([], BuildQuality::coloursFromText('no colours here'));
        $this->assertSame('#1A2744', BuildQuality::coloursFromText('use #1a2744 please')['primary']);
        $pages = BuildQuality::pagesFromConversation([['role' => 'user', 'content' => 'The name is Crumb and Co. Pages: Home, Menu, Cakes to Order, About, Contact.'], ['role' => 'assistant', 'content' => 'Pages: none']]);
        $this->assertSame(['Home', 'Menu', 'Cakes to Order', 'About', 'Contact'], $pages);
        $this->assertSame(['الرئيسية', 'القائمة', 'الحجز', 'اتصل بنا'], BuildQuality::pagesFromConversation([['role' => 'user', 'content' => 'الاسم: مطعم الواحة. الصفحات: الرئيسية، القائمة، الحجز، اتصل بنا.']]));
    }

    public function test_arabic_colour_and_page_words_count_too(): void
    {
        $c = BuildQuality::coloursFromText('نريد موقعاً باللغة العربية بألوان ذهبية وخضراء.');
        $this->assertSame('#C9943A', $c['primary']); $this->assertSame('#16A34A', $c['secondary']);
        $p = BuildQuality::pagesFromRequest(['الرئيسية', 'القائمة', 'الحجز', 'اتصل بنا'], 'restaurant');
        $this->assertContains('menu', $p); $this->assertContains('contact', $p); $this->assertNotContains('home', $p);
    }

    public function test_the_owners_stated_colours_lead_the_theme_choice(): void
    {
        $a = app(ArthurService::class);
        $themes = $a->themesFor(['business_name' => 'SkyScope Surveys', 'industry' => 'drone surveys', 'style' => 'serious', 'colors' => ['primary' => '#1A2744', 'secondary' => '#F97316']], 4);
        $this->assertSame('Your colours', $themes[0]['label']); $this->assertSame('your_colours', $themes[0]['id']);
        $this->assertMatchesRegularExpression('/^#[0-9A-F]{6}$/', $themes[0]['primary']); $this->assertTrue((bool) ($themes[0]['recommended'] ?? false));
        $plain = $a->themesFor(['business_name' => 'SkyScope Surveys', 'industry' => 'drone surveys', 'style' => 'serious'], 4);
        $this->assertNotSame('Your colours', $plain[0]['label']);
    }
}
