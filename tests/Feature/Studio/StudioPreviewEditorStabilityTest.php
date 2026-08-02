<?php

namespace Tests\Feature\Studio;

use Tests\TestCase;

/**
 * STUDIO888 P3/P1 — editor preview renderer-stability guard (BUG-D).
 *
 * The editor canvas iframe loads GET /studio/designs/{id}/preview, whose HTML has an
 * injected "editScript" (in routes/api/authenticated/studio-02.php). That script tags
 * every <img> with crossOrigin (export parity) and keeps doing so for images that
 * appear after first render.
 *
 * The proven freeze (BUG-D): the observer used to watch the WHOLE subtree for 'src'
 * ATTRIBUTE mutations and, on every mutation, re-scan every <img> and re-assign
 * img.src — a 'src' mutation that re-fired the observer. Under editor interaction the
 * DOM mutates continuously, so this thrashed the CPU and hung the editor.
 *
 * These are source-characterization assertions (pure, no DB/auth) that pin the fix so
 * a future edit cannot silently reintroduce the self-triggering observer:
 *   #8  the generated preview script no longer observes 'src' attributes and coalesces
 *       via requestAnimationFrame, scanning only newly-added nodes.
 * They intentionally read the route source (the editScript is an inline string inside
 * the preview closure, not otherwise reachable) rather than standing up the full
 * auth + workspace + design pipeline.
 */
class StudioPreviewEditorStabilityTest extends TestCase
{
    private function editRouteSource(): string
    {
        $path = base_path('routes/api/authenticated/studio-02.php');
        $this->assertFileExists($path, 'Studio preview route file must exist.');

        return file_get_contents($path);
    }

    /** The observer that drives crossOrigin tagging must NOT watch attribute mutations. */
    public function test_preview_editscript_does_not_observe_src_attributes(): void
    {
        $src = $this->editRouteSource();

        $this->assertStringNotContainsString(
            'attributeFilter',
            $src,
            'BUG-D regression: the editScript observer must not filter on attributes (self-triggering src re-assign).'
        );
        $this->assertStringNotContainsString(
            'attributes: true',
            $src,
            'BUG-D regression: the editScript observer must not observe attribute mutations.'
        );
    }

    /** Bursts of DOM mutations must be coalesced (requestAnimationFrame), not scanned per-mutation. */
    public function test_preview_editscript_coalesces_with_request_animation_frame(): void
    {
        $src = $this->editRouteSource();

        $this->assertStringContainsString(
            'requestAnimationFrame',
            $src,
            'The editScript must coalesce crossOrigin scans via requestAnimationFrame to avoid per-mutation thrash.'
        );
        $this->assertStringContainsString(
            'addedNodes',
            $src,
            'The editScript must scan only newly-added nodes, not the whole document on every mutation.'
        );
    }

    /** The observer must remain childList/subtree so images inserted after render are still tagged. */
    public function test_preview_editscript_still_observes_childlist_subtree(): void
    {
        $src = $this->editRouteSource();

        $this->assertMatchesRegularExpression(
            '/observe\(\s*document\.documentElement\s*,\s*\{\s*childList:\s*true\s*,\s*subtree:\s*true\s*\}\s*\)/',
            $src,
            'The editScript must still observe childList+subtree so post-render images (e.g. Media Picker swaps that replace the node) are tagged.'
        );
    }

    /** Export parity: images must still be tagged crossOrigin="anonymous" for html2canvas capture. */
    public function test_preview_editscript_preserves_crossorigin_export_parity(): void
    {
        $src = $this->editRouteSource();

        $this->assertStringContainsString(
            "crossOrigin = 'anonymous'",
            $src,
            'Export parity: the editScript must still tag images crossOrigin="anonymous".'
        );
    }
}
