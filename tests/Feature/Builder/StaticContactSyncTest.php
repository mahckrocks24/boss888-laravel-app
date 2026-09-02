<?php

namespace Tests\Feature\Builder;

use App\Engines\Builder\Services\ArthurEditService;
use Tests\TestCase;

/**
 * Static-export contact sync (2026-09-02): an Arthur footer-contact edit must reach sites that are served
 * from a baked static export (index.html/home.html), not just renderer-served sites — injected idempotently.
 */
class StaticContactSyncTest extends TestCase
{
    private ?string $dir = null;

    protected function tearDown(): void
    {
        if ($this->dir) {
            foreach (glob($this->dir . '/*') ?: [] as $f) { @unlink($f); }
            @rmdir($this->dir);
        }
        parent::tearDown();
    }

    public function test_contact_block_injected_into_static_footer_and_idempotent(): void
    {
        $id = 987655;
        $this->dir = storage_path("app/public/sites/{$id}");
        @mkdir($this->dir, 0775, true);
        $file = $this->dir . '/index.html';
        file_put_contents($file, "<html><body><main>hi</main><footer><div class=\"footer-copy\">© 2025</div></footer></body></html>");

        $svc = app(ArthurEditService::class);
        $m = new \ReflectionMethod($svc, 'syncFooterContactToStatic');
        $m->setAccessible(true);

        $sections = [['type' => 'hero', 'heading' => 'x'], ['type' => 'footer', 'phone' => '+1 862 290 5020', 'email' => 'hello@x.com']];
        $r1 = $m->invoke($svc, $id, $sections);
        $this->assertGreaterThan(0, $r1['applied']);
        $html = file_get_contents($file);
        $this->assertStringContainsString('data-lu-contact', $html);
        $this->assertStringContainsString('tel:+18622905020', $html);
        $this->assertStringContainsString('mailto:hello@x.com', $html);
        $this->assertStringContainsString('</footer>', $html, 'footer tag preserved');

        // idempotent: a second run (e.g. a later phone change) must not stack blocks
        $sections2 = [['type' => 'footer', 'phone' => '+1 999 000 1111', 'email' => 'hello@x.com']];
        $m->invoke($svc, $id, $sections2);
        $html2 = file_get_contents($file);
        $this->assertSame(1, substr_count($html2, 'data-lu-contact'), 'exactly one contact block after re-edit');
        $this->assertStringContainsString('tel:+19990001111', $html2, 'phone updated in place');
        $this->assertStringNotContainsString('tel:+18622905020', $html2, 'old phone replaced');
    }

    public function test_no_footer_fields_means_no_injection(): void
    {
        $id = 987656;
        $this->dir = storage_path("app/public/sites/{$id}");
        @mkdir($this->dir, 0775, true);
        $file = $this->dir . '/index.html';
        $orig = "<html><body><footer><div>©</div></footer></body></html>";
        file_put_contents($file, $orig);

        $svc = app(ArthurEditService::class);
        $m = new \ReflectionMethod($svc, 'syncFooterContactToStatic');
        $m->setAccessible(true);
        $r = $m->invoke($svc, $id, [['type' => 'footer']]); // no contact values
        $this->assertSame(0, $r['applied']);
        $this->assertSame($orig, file_get_contents($file), 'file untouched when no contact info');
    }
}
