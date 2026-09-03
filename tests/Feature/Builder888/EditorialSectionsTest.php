<?php

namespace Tests\Feature\Builder888;

use App\Engines\Builder\Schema\SectionSchema;
use App\Engines\Builder\Services\BuilderRenderer;
use App\Engines\Builder\Services\KabayanNewsTheme;
use App\Engines\Builder\Services\ThemeRegistry;
use Tests\TestCase;

/**
 * KABAYAN888 G1/G2 (2026-09-03) — editorial section types + theme registry.
 *
 * Pure-render assertions: no DB rows are required (the data-backed sections render
 * their honest empty states when the workspace has no published articles), so the
 * test proves the contract wiring, not content.
 */
class EditorialSectionsTest extends TestCase
{
    private const NEW_TYPES = ['ticker', 'news_feed', 'category_strips', 'video_embed', 'directory', 'newsletter_signup', 'ad_slot'];

    public function test_editorial_types_are_in_the_section_contract_arthur_reads(): void
    {
        $types = SectionSchema::allowedTypes();
        foreach (self::NEW_TYPES as $t) {
            $this->assertContains($t, $types, "SectionSchema is missing '{$t}'");
            $this->assertNotEmpty(SectionSchema::allowedFieldsFor($t), "'{$t}' has no allowed fields");
            $this->assertTrue(SectionSchema::isKnownType($t));
        }
        $this->assertContains('layout', SectionSchema::allowedFieldsFor('news_feed'));
        $this->assertContains('slot_code', SectionSchema::allowedFieldsFor('ad_slot'));
        $this->assertTrue(SectionSchema::validate(['type' => 'news_feed', 'limit' => 6])['ok']);
    }

    public function test_theme_registry_resolves_known_keys_and_rejects_unknown(): void
    {
        $this->assertInstanceOf(KabayanNewsTheme::class, ThemeRegistry::resolve('kabayan-news'));
        $this->assertInstanceOf(\App\Engines\Builder\Services\AmgTravelTheme::class, ThemeRegistry::resolve('amg-travel'));
        $this->assertNull(ThemeRegistry::resolve('modern'));
        $this->assertNull(ThemeRegistry::resolve(null));
        $this->assertNull(ThemeRegistry::resolve('../../etc/passwd'));
    }

    public function test_generic_renderer_handles_every_editorial_type_without_throwing(): void
    {
        $r = app(BuilderRenderer::class);
        $website = ['id' => 0, 'workspace_id' => 0, 'name' => 'Test', 'subdomain' => 'test.levelupgrowth.io', 'settings_json' => ['article_base' => 'news']];
        $brand = ['primary' => '#0038A8', 'secondary' => '#1A1A2E', 'font_heading' => 'Playfair Display', 'font_body' => 'Inter'];
        $sections = [
            ['type' => 'ticker', 'mode' => 'manual', 'items' => [['text' => 'Hello <b>world</b>', 'url' => '/news/x']]],
            ['type' => 'news_feed', 'heading' => 'Top Stories', 'layout' => 'hero_grid', 'limit' => 7],
            ['type' => 'category_strips', 'categories' => [['name' => 'Money', 'slug' => 'money']]],
            ['type' => 'video_embed', 'heading' => 'Watch', 'video_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ'],
            ['type' => 'video_embed', 'heading' => 'Bad host', 'video_url' => 'https://evil.example/embed/x'],
            ['type' => 'directory', 'heading' => 'Spots', 'city' => 'Dubai'],
            ['type' => 'newsletter_signup', 'heading' => 'Subscribe', 'source_tag' => 'news"letter'],
            ['type' => 'ad_slot', 'slot_code' => 'in_content_mrec'],
        ];
        $html = '';
        foreach ($sections as $sec) {
            $out = $r->renderSection($sec, $brand, $website, [], 'home');
            $this->assertIsString($out);
            $html .= $out;
        }
        $this->assertStringContainsString('Hello &lt;b&gt;world&lt;/b&gt;', $html, 'ticker text must be escaped');
        $this->assertStringContainsString('youtube-nocookie.com/embed/dQw4w9WgXcQ', $html);
        $this->assertStringNotContainsString('evil.example', $html, 'non-allow-listed video hosts must not be embedded');
        $this->assertStringContainsString('data-lu-slot="in_content_mrec"', $html);
        $this->assertStringContainsString("source:'newsletter'", $html, 'source_tag is sanitised to [a-z0-9_-]');
        $this->assertStringContainsString('No stories published yet.', $html);
    }

    public function test_kabayan_theme_renders_full_page_with_chrome_and_fallback(): void
    {
        $theme = new KabayanNewsTheme();
        $website = ['id' => 0, 'workspace_id' => 0, 'name' => 'LevelUp Kabayan', 'subdomain' => 'kabayan.levelupgrowth.io',
                    'settings_json' => json_encode(['theme' => 'kabayan-news', 'article_base' => 'news', 'ticker_label' => 'BREAKING'])];
        $brand = ['primary' => '#0038A8', 'secondary' => '#1A1A2E', 'accent' => '#FCD116', 'font_heading' => 'Playfair Display', 'font_body' => 'Inter'];
        $secs = [
            ['type' => 'header', 'logo_text' => 'LevelUp Kabayan', 'nav_links' => [['label' => 'News', 'url' => '/news']], 'cta_text' => 'Subscribe', 'cta_url' => '#newsletter'],
            ['type' => 'ticker', 'mode' => 'manual', 'items' => [['text' => 'First headline', 'url' => '/news/first']]],
            ['type' => 'news_feed', 'heading' => 'Top Stories', 'layout' => 'hero_grid'],
            ['type' => 'generic', 'heading' => 'About', 'body' => 'Fallback body'],
            ['type' => 'newsletter_signup', 'heading' => 'Subscribe'],
            ['type' => 'footer', 'copyright' => '© 2026 LevelUp Kabayan.'],
        ];
        $calls = 0;
        $html = $theme->renderBody($secs, $brand, $website, ['slug' => 'home'], function (array $sec) use (&$calls) { $calls++; return '<section>FALLBACK ' . e($sec['heading'] ?? '') . '</section>'; });
        $this->assertSame(1, $calls, 'only the generic section should reach the fallback');
        $this->assertStringContainsString('class="kb-header"', $html);
        $this->assertStringContainsString('kb-ticker-kick">BREAKING<', $html);
        $this->assertStringContainsString('First headline', $html);
        $this->assertStringContainsString('FALLBACK About', $html);
        $this->assertStringContainsString('class="kb-footer"', $html);
        $this->assertStringContainsString('--kb-primary:#0038A8', $html);
        $this->assertStringContainsString('© 2026 LevelUp Kabayan.', $html);
    }

    public function test_kabayan_theme_rejects_unsafe_brand_values(): void
    {
        $theme = new KabayanNewsTheme();
        $website = ['id' => 0, 'workspace_id' => 0, 'name' => 'X', 'subdomain' => 'x.levelupgrowth.io', 'settings_json' => '{}'];
        $brand = ['primary' => 'red;}</style><script>alert(1)</script>', 'font_heading' => "Playfair'); evil"];
        $html = $theme->renderBody([['type' => 'header']], $brand, $website, ['slug' => 'home']);
        $this->assertStringNotContainsString('<script>alert(1)', $html);
        $this->assertStringContainsString('--kb-primary:#0038A8', $html, 'bad colour falls back to the theme default');
        $this->assertStringContainsString("--kb-fh:'Playfair Display'", $html, 'bad font falls back to the theme default');
    }
}
