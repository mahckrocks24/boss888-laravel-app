<?php

namespace Tests\Feature\Builder888;

use App\Engines\Builder\Support\Platform6;
use Tests\TestCase;

/**
 * PLATFORM-6 (RFC-0021 wave 6) — fail-first tests from REPORT-0067 #12 (drafts public by number), #15 (LevelUp-branded 404,
 * uploads under /storage/tmp/) and REPORT-0068 #8 ("Boss" — the JavaScript side is proven in the browser).
 */
class Platform6Test extends TestCase
{
    private const SITE_ID = 999999905;

    protected function tearDown(): void
    {
        foreach (glob(storage_path('app/public/sites/' . self::SITE_ID . '/img/*')) ?: [] as $f) @unlink($f);
        @rmdir(storage_path('app/public/sites/' . self::SITE_ID . '/img')); @rmdir(storage_path('app/public/sites/' . self::SITE_ID));
        @unlink(storage_path('app/public/tmp/images/p6test.jpg'));
        parent::tearDown();
    }

    public function test_a_draft_link_is_signed_short_lived_and_bound_to_its_site(): void
    {
        $t = Platform6::signDraft(self::SITE_ID, 600);
        $this->assertTrue(Platform6::verifyDraft(self::SITE_ID, $t));
        $this->assertFalse(Platform6::verifyDraft(self::SITE_ID + 1, $t), 'another site');
        $this->assertFalse(Platform6::verifyDraft(self::SITE_ID, null)); $this->assertFalse(Platform6::verifyDraft(self::SITE_ID, 'nonsense'));
        [$exp, $sig] = explode('.', $t); $this->assertFalse(Platform6::verifyDraft(self::SITE_ID, ($exp - 1000) . '.' . $sig), 'tampered expiry');
        $old = (time() - 10) . '.' . substr(hash_hmac('sha256', self::SITE_ID . '|' . (time() - 10), (string) config('app.key')), 0, 32);
        $this->assertFalse(Platform6::verifyDraft(self::SITE_ID, $old), 'expired');
    }

    public function test_the_not_found_page_is_the_customers_not_ours(): void
    {
        $html = Platform6::notFoundPage((object) ['name' => 'Crumb and Co'], ['primary_color' => '#B05020']);
        $this->assertStringContainsString('Crumb and Co', $html); $this->assertStringContainsString('#B05020', $html);
        $this->assertStringContainsString('href="/"', $html);
        $this->assertStringNotContainsString('LevelUp', $html); $this->assertStringNotContainsString('levelupgrowth', $html);
        $ar = Platform6::notFoundPage((object) ['name' => 'مطعم الواحة'], ['business_name' => 'مطعم الواحة']);
        $this->assertStringContainsString('lang="ar" dir="rtl"', $ar); $this->assertStringContainsString('الصفحة غير موجودة', $ar);
    }

    public function test_temporary_uploads_move_into_the_sites_own_folder(): void
    {
        @mkdir(storage_path('app/public/tmp/images'), 0775, true);
        file_put_contents(storage_path('app/public/tmp/images/p6test.jpg'), 'jpg');
        [$vars, $moved] = Platform6::tmpImagesToSite(self::SITE_ID, ['hero_image' => '/storage/tmp/images/p6test.jpg', 'gallery_1' => '/storage/tmp/images/p6test.jpg', 'story_image' => '/storage/template-images/cafe/story_image.jpg', 'hero_title' => 'x']);
        $this->assertSame(1, $moved, 'one file copied once, both variables follow');
        $this->assertSame('/storage/sites/' . self::SITE_ID . '/img/p6test.jpg', $vars['hero_image']); $this->assertSame($vars['hero_image'], $vars['gallery_1']);
        $this->assertSame('/storage/template-images/cafe/story_image.jpg', $vars['story_image']); $this->assertSame('x', $vars['hero_title']);
        $this->assertFileExists(storage_path('app/public/sites/' . self::SITE_ID . '/img/p6test.jpg'));
    }
}
