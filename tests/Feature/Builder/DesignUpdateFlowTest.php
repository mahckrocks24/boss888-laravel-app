<?php

namespace Tests\Feature\Builder;

use App\Engines\Builder\Services\DesignUpdateService;
use App\Engines\Builder\Services\TemplateService;
use App\Engines\Builder\Support\DesignVersions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * DESIGN-UPDATES-1 — the whole path on a throwaway design and site (ids no real row uses): a deploy records the version,
 * a design change is detected and built beside the site without touching it, the content check holds, Cancel changes
 * nothing, Agree applies exactly the previewed page and opens Revert, Revert puts every file and the record back byte for byte.
 * The browser probe is not run here (the candidate is built with $andProbe = false); the probe has its own proof (EV).
 */
class DesignUpdateFlowTest extends TestCase
{
    private const SLUG = 'zz_dupd_test';
    private const WS = 987790;
    private array $sites = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (! Schema::hasTable('design_updates')) { (require base_path('database/migrations/2026_10_06_160000_design_updates.php'))->up(); }
        \Illuminate\Support\Facades\Queue::fake();   // no site thumbnail browser in a test
        DesignUpdateService::$onlyWorkspaces = [self::WS];
        $this->writeDesign('.hero{padding:10px}');
    }

    protected function tearDown(): void
    {
        DesignUpdateService::$onlyWorkspaces = null;
        foreach ($this->sites as $id) {
            DB::table('design_updates')->where('website_id', $id)->delete();
            DB::table('websites')->where('id', $id)->delete();
            $this->rm(storage_path("app/public/sites/{$id}")); $this->rm(storage_path("app/design-updates/{$id}"));
        }
        $this->rm(storage_path('templates/' . self::SLUG)); $this->rm(storage_path('app/design-versions/' . self::SLUG));
        parent::tearDown();
    }

    private function rm(string $d): void
    {
        if (! is_dir($d)) return;
        foreach (scandir($d) ?: [] as $e) { if ($e === '.' || $e === '..') continue; $p = "{$d}/{$e}"; is_dir($p) ? $this->rm($p) : @unlink($p); }
        @rmdir($d);
    }

    private function writeDesign(string $css): void
    {
        $dir = storage_path('templates/' . self::SLUG); @mkdir($dir, 0775, true);
        $html = '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>{{business_name}}</title><style>' . $css . '</style></head><body>'
            . '<nav data-block="nav"><a class="logo" data-field="logo">{{business_name}}</a><div class="nav-links"><a href="#about" data-field="nav_1">About</a></div></nav>'
            . '<section data-block="hero" class="hero"><h1 data-field="hero_title">{{hero_title}}</h1></section>'
            . '<section data-block="about" id="about"><p data-field="about_text">{{about_text}}</p></section>'
            . '<section data-block="contact" id="contact"><p data-field="contact_phone">{{contact_phone}}</p></section>'
            . '<footer data-block="footer"><p data-field="footer_text">{{business_name}}</p></footer></body></html>';
        file_put_contents("{$dir}/template.html", $html);
        file_put_contents("{$dir}/manifest.json", json_encode(['id' => self::SLUG, 'name' => 'Test', 'industry' => self::SLUG, 'is_active' => false,
            'blocks' => [['id' => 'hero', 'name' => 'Top of the page'], ['id' => 'about', 'name' => 'About']],
            'variables' => ['business_name' => ['type' => 'text', 'default' => 'Sample Co'], 'hero_title' => ['type' => 'text', 'default' => 'Hello'], 'about_text' => ['type' => 'text', 'default' => 'About us'], 'contact_phone' => ['type' => 'text', 'default' => '000']]]));
        clearstatcache();
    }

    private function site(): int
    {
        $id = 987790 + count($this->sites) + 1; $this->sites[] = $id;
        DB::table('websites')->where('id', $id)->delete();
        $vars = ['business_name' => 'Acme Bakery', 'hero_title' => 'Bread since 1998', 'about_text' => 'Family run', 'contact_phone' => '0117 496 0000'];
        DB::statement('SET FOREIGN_KEY_CHECKS=0');   // a throwaway site in a workspace no table row needs
        DB::table('websites')->insert(['id' => $id, 'workspace_id' => self::WS, 'name' => 'Acme Bakery', 'status' => 'draft', 'type' => 'template', 'template_industry' => self::SLUG, 'template' => self::SLUG,
            'template_variables' => json_encode($vars), 'settings_json' => json_encode(['template' => self::SLUG, 'industry' => self::SLUG]), 'created_at' => now(), 'updated_at' => now()]);
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
        $t = app(TemplateService::class); $t->deploy($id, $t->render(self::SLUG, $vars, $id));
        return $id;
    }

    public function test_deploy_records_the_design_version_and_an_unchanged_design_offers_nothing(): void
    {
        $id = $this->site();
        $d = DesignVersions::ofSite($id);
        $this->assertSame(DesignVersions::current(self::SLUG), $d['version']);
        $this->assertTrue(DesignVersions::archived(self::SLUG, $d['version']));
        $this->assertStringContainsString('up to date', app(DesignUpdateService::class)->checkSite($id));
        $this->assertSame(0, DB::table('design_updates')->where('website_id', $id)->count());
    }

    public function test_agree_applies_the_preview_and_revert_restores_every_byte(): void
    {
        $id = $this->site();
        $svc = app(DesignUpdateService::class); $root = storage_path("app/public/sites/{$id}");
        // the owner edits the hero through the editor's own path
        app(TemplateService::class)->updateField($id, 'hero_title', 'Sourdough every morning');
        $beforeHome = file_get_contents("{$root}/index.html"); $beforeRecord = DB::table('websites')->where('id', $id)->first(['template_variables', 'settings_json']);
        $this->writeDesign('.hero{padding:32px}');   // the kit fix
        $log = $svc->checkSite($id);
        $u = DB::table('design_updates')->where('website_id', $id)->first();
        $this->assertNotNull($u, $log);
        $this->assertSame($beforeHome, file_get_contents("{$root}/index.html"), 'building a candidate never touches the site');
        // the candidate exists; no probe in this test
        DB::table('design_updates')->where('id', $u->id)->update(['status' => 'building']);
        $b = $svc->build((int) $u->id, false);
        $this->assertSame('ready', $b['status']);
        $this->assertTrue($b['report']['content']['ok']);
        $after = file_get_contents(DesignUpdateService::dir($id, (int) $u->id) . '/after.html');
        $this->assertStringContainsString('padding:32px', $after);
        $this->assertStringContainsString('Sourdough every morning', $after);
        $this->assertStringContainsString('0117 496 0000', $after);
        // another workspace can not decide it
        $this->assertFalse($svc->agree((int) $u->id, self::WS + 1)['success']);
        $res = $svc->agree((int) $u->id, self::WS, 1);
        $this->assertTrue($res['success'], $res['message'] ?? '');
        $this->assertSame($after, file_get_contents("{$root}/index.html"), 'what goes live is what was previewed');
        $this->assertSame(DesignVersions::current(self::SLUG), DesignVersions::ofSite($id)['version']);
        $state = $svc->forSite($id, self::WS);
        $this->assertTrue($state['applied']['can_revert']);
        // revert: the home page, every other file and the record, exactly
        $rv = $svc->revert((int) $u->id, self::WS, 1);
        $this->assertTrue($rv['identical'], json_encode($rv));
        $this->assertSame($beforeHome, file_get_contents("{$root}/index.html"));
        $now = DB::table('websites')->where('id', $id)->first(['template_variables', 'settings_json']);
        $this->assertSame($beforeRecord->template_variables, $now->template_variables);
        $this->assertSame($beforeRecord->settings_json, $now->settings_json);
        // a reverted version is not offered again
        $this->assertStringContainsString('already handled', $svc->checkSite($id));
        $this->assertFalse($svc->revert((int) $u->id, self::WS)['success']);
    }

    public function test_cancel_changes_nothing_and_the_version_is_not_offered_again(): void
    {
        $id = $this->site(); $svc = app(DesignUpdateService::class); $root = storage_path("app/public/sites/{$id}");
        $before = file_get_contents("{$root}/index.html");
        $this->writeDesign('.hero{padding:40px}');
        $svc->checkSite($id);
        $u = DB::table('design_updates')->where('website_id', $id)->first();
        DB::table('design_updates')->where('id', $u->id)->update(['status' => 'building']); $svc->build((int) $u->id, false);
        $this->assertCount(1, $svc->offers(self::WS));
        $this->assertTrue($svc->cancel((int) $u->id, self::WS)['success']);
        $this->assertSame($before, file_get_contents("{$root}/index.html"));
        $this->assertSame('cancelled', DB::table('design_updates')->where('id', $u->id)->value('status'));
        $this->assertCount(0, $svc->offers(self::WS));
        $this->assertStringContainsString('already handled', $svc->checkSite($id));
        $this->assertFalse($svc->agree((int) $u->id, self::WS)['success'], 'a cancelled update can not be applied');
    }

    public function test_an_edit_after_the_preview_is_rebuilt_before_it_is_applied(): void
    {
        $id = $this->site(); $svc = app(DesignUpdateService::class); $root = storage_path("app/public/sites/{$id}");
        $this->writeDesign('.hero{padding:48px}');
        $svc->checkSite($id);
        $u = DB::table('design_updates')->where('website_id', $id)->first();
        DB::table('design_updates')->where('id', $u->id)->update(['status' => 'building']); $svc->build((int) $u->id, false);
        app(TemplateService::class)->updateField($id, 'about_text', 'Three generations of bakers');   // after the preview was made
        $res = $svc->agree((int) $u->id, self::WS);
        $this->assertTrue($res['success'], $res['message'] ?? '');
        $home = file_get_contents("{$root}/index.html");
        $this->assertStringContainsString('Three generations of bakers', $home);
        $this->assertStringContainsString('padding:48px', $home);
    }

    public function test_the_switch_keeps_other_workspaces_out(): void
    {
        DesignUpdateService::$onlyWorkspaces = [1];
        $this->assertFalse(DesignUpdateService::on(self::WS));
        $this->assertSame([], app(DesignUpdateService::class)->offers(self::WS));
        $this->assertSame(['on' => false], app(DesignUpdateService::class)->forSite(1, self::WS));
    }
}
