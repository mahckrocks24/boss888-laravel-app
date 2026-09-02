<?php

namespace Tests\Feature\Builder;

use App\Engines\Builder\Services\ArthurEditService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * OPEN-1 (2026-09-02) — Arthur colour changes reach the SERVED output.
 *
 * Static-template sites are served from a baked index.html (PublishedSiteMiddleware prefers it over
 * the renderer), so a colour change must rewrite the export's :root variables. Renderer/section sites
 * read colours live from settings_json. Both paths are proven here without any Runtime/LLM call —
 * applyStyleColors is deterministic.
 */
class ArthurStyleColorTest extends TestCase
{
    private array $madeDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->madeDirs as $dir) {
            foreach (glob($dir . '/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($dir);
        }
        parent::tearDown();
    }

    private function svc(): ArthurEditService
    {
        return app(ArthurEditService::class);
    }

    private function makeStaticExport(int $websiteId, string $html): string
    {
        $dir = storage_path("app/public/sites/{$websiteId}");
        @mkdir($dir, 0775, true);
        $this->madeDirs[] = $dir;
        file_put_contents($dir . '/index.html', $html);
        return $dir . '/index.html';
    }

    public function test_static_site_recolours_root_variables_in_the_export(): void
    {
        // Use a very high id so it can never collide with a real site.
        $id = 987651;
        $html = "<!doctype html><html><head><style>:root{--terra:#D4622A;--cream:#FDF6EC;--espresso:#2C1810}"
              . "a{color:var(--terra)}body{background:var(--cream)}</style></head>"
              . "<body><a href='#'>Book</a></body></html>";
        $file = $this->makeStaticExport($id, $html);

        $res = $this->svc()->applyStyleColors($id, ['--terra' => '#1E5CFF', '--cream' => '#FFFFFF']);

        $this->assertTrue($res['is_static']);
        $this->assertSame(2, $res['applied']);
        $this->assertSame([], $res['missed']);

        $after = file_get_contents($file);
        $this->assertStringContainsString('--terra:#1E5CFF', $after);
        $this->assertStringContainsString('--cream:#FFFFFF', $after);
        $this->assertStringNotContainsString('#D4622A', $after, 'old brand hex is gone');
        // var(--terra) references are untouched and now resolve to the new colour.
        $this->assertStringContainsString('color:var(--terra)', $after);
        // A reversible backup was written.
        $this->assertNotEmpty(glob(dirname($file) . '/index.html.bak-*'));
    }

    public function test_unknown_variable_and_bad_hex_are_reported_not_written(): void
    {
        $id = 987652;
        $html = "<style>:root{--terra:#D4622A}</style>";
        $file = $this->makeStaticExport($id, $html);

        $res = $this->svc()->applyStyleColors($id, [
            '--nope'  => '#123456',   // not declared in :root
            '--terra' => 'blue',      // not a hex
        ]);

        $this->assertSame(0, $res['applied']);
        $this->assertNotEmpty($res['missed']);
        $this->assertStringContainsString('not a hex', implode(' ', $res['missed']));
        $this->assertStringContainsString('no such colour variable', implode(' ', $res['missed']));
        $this->assertStringContainsString('#D4622A', file_get_contents($file), 'export unchanged on failure');
    }

    public function test_renderer_site_writes_roles_into_settings_json(): void
    {
        // No static export for this id → renderer/section path (settings_json).
        $wsId = DB::table('workspaces')->value('id');
        if (! $wsId) {
            $this->markTestSkipped('no workspace row in the test database');
        }
        $id = (int) DB::table('websites')->insertGetId([
            'workspace_id'  => $wsId,
            'name'          => 'OPEN1 renderer test',
            'settings_json' => json_encode(['primary_color' => '#000000']),
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        try {
            $res = $this->svc()->applyStyleColors($id, ['primary' => '#1E5CFF', 'accent' => '#00E5A8']);

            $this->assertFalse($res['is_static']);
            $this->assertSame(2, $res['applied']);

            $settings = json_decode(DB::table('websites')->where('id', $id)->value('settings_json'), true);
            $this->assertSame('#1E5CFF', $settings['primary_color']);
            $this->assertSame('#00E5A8', $settings['accent_color']);
        } finally {
            DB::table('websites')->where('id', $id)->delete();
        }
    }

    public function test_empty_input_is_a_safe_noop(): void
    {
        $res = $this->svc()->applyStyleColors(987653, []);
        $this->assertSame(0, $res['applied']);
        $this->assertSame([], $res['missed']);
    }
}
