<?php

namespace Tests\Feature\Studio\Projection\Html;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Markup boundary: HTML-parsing/markup APIs may exist ONLY inside
 * app/Engines/Studio/Projection/Html. The rest of the Studio engine must stay
 * HTML/DOM-free. Pure - reads source files only; no DB/Runtime/browser.
 */
final class HtmlBoundaryGuardTest extends TestCase
{
    private const MARKUP_TOKENS = [
        'DOMDocument', 'loadHTML', 'saveHTML', 'htmlspecialchars', 'html_entity_decode',
        'SimpleXML', 'simplexml_load', '->nodeValue', 'libxml', 'DOMXPath',
    ];

    /**
     * The transformation-engine namespaces built in Phases 1-4. Legacy Studio
     * code (e.g. Services/StudioService.php, the existing renderer) predates
     * this engine and is intentionally out of scope.
     */
    private const ENGINE_DIRS = ['Transform', 'Document', 'Selection', 'Execution', 'Projection'];

    public function test_markup_apis_do_not_leak_outside_projection_html(): void
    {
        $root = dirname(__DIR__, 5); // project root
        $violations = [];

        foreach (self::ENGINE_DIRS as $dir) {
            $base = $root . '/app/Engines/Studio/' . $dir;
            if (! is_dir($base)) {
                continue;
            }
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, RecursiveDirectoryIterator::SKIP_DOTS));
            foreach ($it as $file) {
                $path = str_replace('\\', '/', (string) $file);
                if (! str_ends_with($path, '.php') || str_contains($path, '/Projection/Html/')) {
                    continue; // Projection/Html is the one allowed markup-aware namespace
                }
                $src = (string) file_get_contents($path);
                foreach (self::MARKUP_TOKENS as $tok) {
                    if (str_contains($src, $tok)) {
                        $violations[] = basename($path) . " :: {$tok}";
                    }
                }
            }
        }

        $this->assertSame([], $violations, 'markup API leaked outside Projection/Html: ' . implode(', ', $violations));
    }
}
