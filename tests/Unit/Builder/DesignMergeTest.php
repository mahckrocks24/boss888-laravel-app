<?php

namespace Tests\Unit\Builder;

use App\Engines\Builder\Support\DesignMerge;
use App\Engines\Builder\Support\DesignVersions;
use PHPUnit\Framework\TestCase;

/**
 * DESIGN-UPDATES-1 — the three-way merge behind a design update, and the version hash. Pure string work: no database,
 * no files. "base" is the design the site was built on, "new" the design now, "cur" the owner's page.
 */
class DesignMergeTest extends TestCase
{
    private function page(string $style, string $body, string $head = '<title>Acme</title>'): string
    {
        return '<!doctype html><html><head><meta charset="utf-8">' . $head . '<style>' . $style . '</style></head><body>' . $body . '</body></html>';
    }

    private function hero(string $title = 'Welcome', string $img = '/d/hero.jpg', string $extra = ''): string
    {
        return '<section data-block="hero" class="hero"><h1 data-field="hero_title">' . $title . '</h1><img data-field="hero_image" src="' . $img . '" alt="">' . $extra . '</section>';
    }

    private function nav(string $links = ''): string
    {
        return '<nav data-block="nav"><a class="logo" data-field="logo">Acme</a><div class="nav-links"><a href="#about" data-field="nav_1">About</a>' . $links . '</div><a class="nav-cta" href="#contact">Book</a></nav>';
    }

    private function about(string $p = 'Our story'): string { return '<section data-block="about"><p data-field="about_text">' . $p . '</p></section>'; }
    private function footer(): string { return '<footer data-block="footer"><p data-field="footer_text">© Acme</p></footer>'; }

    public function test_an_untouched_site_becomes_the_new_design_byte_for_byte(): void
    {
        $base = $this->page('.hero{padding:10px}', $this->nav() . $this->hero() . $this->about() . $this->footer());
        $new = $this->page('.hero{padding:24px}', $this->nav() . $this->hero() . $this->about() . $this->footer());
        $r = DesignMerge::merge($base, $base, $new);
        $this->assertTrue($r['ok']);
        $this->assertSame($new, $r['html']);
        $this->assertSame([], $r['changes']);
    }

    public function test_owner_words_and_pictures_carry_into_the_new_sections(): void
    {
        $base = $this->page('a{}', $this->nav() . $this->hero() . $this->about() . $this->footer());
        $cur = $this->page('a{}', $this->nav() . $this->hero('Fresh bread &amp; <b>coffee</b>', '/storage/uploads/mine.jpg') . $this->about('Since 1998') . $this->footer());
        $new = $this->page('a{color:red}', $this->nav() . str_replace('class="hero"', 'class="hero hero--v2"', $this->hero()) . $this->about() . $this->footer());
        $r = DesignMerge::merge($cur, $base, $new);
        $this->assertStringContainsString('class="hero hero--v2"', $r['html']);
        $this->assertStringContainsString('Fresh bread &amp; <b>coffee</b>', $r['html']);
        $this->assertStringContainsString('src="/storage/uploads/mine.jpg"', $r['html']);
        $this->assertStringContainsString('Since 1998', $r['html']);
        $this->assertStringContainsString('a{color:red}', $r['html']);
        $v = DesignMerge::values($r['html']);
        $this->assertSame('/storage/uploads/mine.jpg', $v['hero_image']);
        $this->assertSame('Fresh bread & coffee', $v['hero_title']);
    }

    public function test_a_section_the_owner_restructured_is_kept_and_reported_when_the_fix_touches_it(): void
    {
        $base = $this->page('a{}', $this->nav() . $this->hero() . $this->about() . $this->footer());
        $curHero = $this->hero('Mine', '/d/hero.jpg', '<div class="owner-strip"><span>Open late</span></div>');
        $cur = $this->page('a{}', $this->nav() . $curHero . $this->about() . $this->footer());
        $new = $this->page('a{}', $this->nav() . str_replace('<h1', '<div class="badge"></div><h1', $this->hero()) . $this->about() . $this->footer());
        $r = DesignMerge::merge($cur, $base, $new, ['hero' => 'Top of the page']);
        $this->assertStringContainsString($curHero, $r['html']);
        $this->assertSame(['kept_custom'], array_column($r['changes'], 'kind'));
        $this->assertStringStartsWith('Top of the page:', $r['changes'][0]['text']);
    }

    public function test_a_restructured_section_the_fix_did_not_touch_is_kept_silently(): void
    {
        $base = $this->page('a{}', $this->nav() . $this->hero() . $this->about() . $this->footer());
        $curHero = $this->hero('Mine', '/d/hero.jpg', '<div class="owner-strip"></div>');
        $cur = $this->page('a{}', $this->nav() . $curHero . $this->about() . $this->footer());
        $new = $this->page('a{color:blue}', $this->nav() . $this->hero() . $this->about() . $this->footer());
        $r = DesignMerge::merge($cur, $base, $new);
        $this->assertStringContainsString($curHero, $r['html']);
        $this->assertSame([], $r['changes']);
    }

    public function test_removed_stays_removed_added_is_carried_dropped_and_new_are_reported_and_order_is_the_owners(): void
    {
        $faq = '<section data-block="faq"><p data-field="faq_1">Q</p></section>';
        $added = '<section data-block="added_booking_form"><h2 data-field="added_booking_form_1">Book a visit</h2></section>';
        $base = $this->page('a{}', $this->nav() . $this->hero() . $this->about() . $faq . $this->footer());
        // the owner removed the about section, moved the FAQ above the hero and added a booking form
        $cur = $this->page('a{}', $this->nav() . $faq . $this->hero() . $added . $this->footer());
        $gallery = '<section data-block="gallery"><img data-field="gallery_1" src="/d/g1.jpg" alt=""></section>';
        // the new design drops the FAQ and brings a gallery after the hero
        $new = $this->page('a{}', $this->nav() . $this->hero() . $gallery . $this->about() . $this->footer());
        $r = DesignMerge::merge($cur, $base, $new);
        $kinds = array_column($r['changes'], 'kind', 'block');
        $this->assertSame('removed_by_design', $kinds['faq']);
        $this->assertSame('added_kept', $kinds['added_booking_form']);
        $this->assertSame('new_section', $kinds['gallery']);
        $this->assertStringNotContainsString('data-block="about"', $r['html']);
        $this->assertStringNotContainsString('data-block="faq"', $r['html']);
        preg_match_all('/data-block="([a-z_]+)"/', $r['html'], $m);
        $this->assertSame(['nav', 'hero', 'gallery', 'added_booking_form', 'footer'], $m[1]);
    }

    public function test_menu_links_of_added_pages_do_not_count_as_restructuring_and_carry_across(): void
    {
        $link = '<a href="menu/" class="nav-link lu-page-link" data-page="menu" data-field="nav_page_menu">Our menu</a>';
        $base = $this->page('a{}', $this->nav() . $this->hero() . $this->footer());
        $cur = $this->page('a{}', $this->nav($link) . $this->hero() . $this->footer());
        $new = $this->page('a{}', str_replace('<nav data-block="nav">', '<nav data-block="nav" class="v2">', $this->nav()) . $this->hero() . $this->footer());
        $r = DesignMerge::merge($cur, $base, $new);
        $this->assertSame([], $r['changes']);
        $this->assertStringContainsString('class="v2"', $r['html']);
        $this->assertStringContainsString('data-page="menu"', $r['html']);
        $this->assertSame(1, substr_count($r['html'], 'data-page="menu"'));
    }

    public function test_the_owners_title_description_and_icon_replace_the_new_heads_own_in_place(): void
    {
        $mine = '<title>Acme Bakery</title><meta name="description" content="Bread since 1998"><link rel="icon" href="/storage/sites/1/icon.png">';
        $base = $this->page('a{}', $this->hero());
        $cur = $this->page('a{}', $this->hero(), $mine);
        $new = $this->page('a{x:1}', $this->hero(), '<title>Design</title><meta name="description" content="Sample">');
        $r = DesignMerge::merge($cur, $base, $new);
        $this->assertStringContainsString('<title>Acme Bakery</title>', $r['html']);
        $this->assertStringContainsString('content="Bread since 1998"', $r['html']);
        $this->assertStringContainsString('/storage/sites/1/icon.png', $r['html']);
        $this->assertStringNotContainsString('Sample', $r['html']);
        $this->assertLessThan(strpos($r['html'], '<title>'), strpos($r['html'], '<meta charset'));
    }

    public function test_content_between_sections_keeps_the_owners_words(): void
    {
        $tick = '<div class="marq"><span data-field="marq_1">Fresh daily</span></div>';
        $base = $this->page('a{}', $this->hero() . $tick . $this->about());
        $cur = $this->page('a{}', $this->hero() . str_replace('Fresh daily', 'Open on Sundays', $tick) . $this->about());
        $new = $this->page('a{}', $this->hero() . str_replace('class="marq"', 'class="marq v2"', $tick) . $this->about());
        $r = DesignMerge::merge($cur, $base, $new);
        $this->assertStringContainsString('<div class="marq v2"><span data-field="marq_1">Open on Sundays</span></div>', $r['html']);
    }

    public function test_markup_inside_scripts_is_never_read_as_a_section(): void
    {
        $page = $this->page('a{}', $this->hero() . '<script>var s = \'<section data-block="fake"></section></div>\';</script>' . $this->about());
        $b = DesignMerge::blocks($page);
        $this->assertTrue($b['ok']);
        $this->assertSame(['hero', 'about'], array_column($b['list'], 'id'));
    }

    public function test_the_version_hash_ignores_what_visitors_never_see(): void
    {
        $mf = ['id' => 'x', 'is_active' => false, 'design' => ['generated' => '2026-10-06', 'palette' => 'teal'], 'variables' => ['a' => ['default' => '1']]];
        $v = DesignVersions::hash('<html>x</html>', $mf);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{12}$/', $v);
        $this->assertSame($v, DesignVersions::hash('<html>x</html>', ['is_active' => true, 'design_version' => 'zzz', 'design_note' => 'n', 'design' => ['palette' => 'teal', 'generated' => '2027-01-01'], 'variables' => ['a' => ['default' => '1']], 'id' => 'x']));
        $this->assertNotSame($v, DesignVersions::hash('<html>y</html>', $mf));
        $this->assertNotSame($v, DesignVersions::hash('<html>x</html>', ['design' => ['palette' => 'rose']] + $mf));
    }
}
