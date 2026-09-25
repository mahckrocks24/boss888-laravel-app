<?php

namespace Tests\Feature\Builder;

use App\Engines\Builder\Services\TemplateService;
use Tests\TestCase;

/**
 * KB-3 (2026-09-25): every served page and editor preview carries the keyboard guard, including pages published
 * before today that already carry the older blocks (the lug-empty-slot lesson: its own id, its own injection).
 */
class KeyboardGuardInjectedTest extends TestCase
{
    public function test_guard_is_injected_once_before_head_close(): void
    {
        $html = "<!doctype html><html><head><title>x</title></head><body><form><input type=\"text\"></form></body></html>";

        $out = TemplateService::injectMobileSafety($html);

        $this->assertStringContainsString('<script id="lug-keyboard">', $out);
        $this->assertStringContainsString('LU-KEYBOARD', $out);
        $this->assertStringContainsString('window.__luKb3', $out);
        $this->assertSame(1, substr_count($out, 'id="lug-keyboard"'));
        $this->assertLessThan(strpos($out, '</head>'), strpos($out, 'id="lug-keyboard"'), 'inside <head>');

        $again = TemplateService::injectMobileSafety($out);
        $this->assertSame($out, $again, 'idempotent');
    }

    public function test_a_page_that_already_has_the_older_blocks_still_gets_the_guard(): void
    {
        $html = "<html><head><style id=\"lug-mobile-safe\"></style><style id=\"lug-empty-slot\"></style></head><body></body></html>";

        $out = TemplateService::injectMobileSafety($html);

        $this->assertStringContainsString('id="lug-keyboard"', $out);
        $this->assertSame(1, substr_count($out, 'id="lug-mobile-safe"'));
        $this->assertSame(1, substr_count($out, 'id="lug-empty-slot"'));
    }

    public function test_the_guard_cannot_break_out_of_its_script_tag(): void
    {
        $js = TemplateService::keyboardGuardHtml();
        $inner = substr($js, strpos($js, '>') + 1, -strlen('</script>'));
        $this->assertStringNotContainsString('</script', $inner);
    }
}
