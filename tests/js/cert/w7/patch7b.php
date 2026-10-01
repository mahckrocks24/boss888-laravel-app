<?php
// FIX-ALL 7b: the page-vs-section guard becomes a testable static (BuildQuality::pageRequestOverride) that ArthurService calls.
$root = '/var/www/levelup-staging';
function rep(string $file, string $old, string $new, int $expect = 1): void {
    global $root; $p = "$root/$file"; $s = file_get_contents($p); $n = substr_count($s, $old);
    if ($n !== $expect) { fwrite(STDERR, "ANCHOR x$n (want $expect) in $file:\n" . substr($old, 0, 160) . "\n"); exit(1); }
    file_put_contents($p, str_replace($old, $new, $s)); echo "ok  $file  (x$n)\n";
}
rep('app/Engines/Builder/Services/ArthurService.php', <<<'X'
            if ($intent !== null && \App\Engines\Builder\Support\BuildQuality::on() && preg_match('/\bpages?\b/iu', $request)
                && in_array((string) ($intent['intent'] ?? ''), ['answer', 'unsupported', 'clarify', 'section_add', 'catalogue', 'copy_edit'], true)) {
                $__pc = \App\Engines\Builder\Support\BuilderCapabilities::classify($request, $industry ?: null);
                $__ps = (($__pc['kind'] ?? '') === 'page') ? (string) ($__pc['page'] ?? '') : '';
                if ($__ps !== '' && ! is_file(storage_path("app/public/sites/{$websiteId}/{$__ps}/index.html"))) {
                    $intent = array_merge($intent, ['intent' => 'page_add', 'confidence' => 0.99, 'normalized' => 'Add a ' . str_replace('_', ' ', $__ps) . ' page', 'question' => '', 'options' => []]);
                    Log::info('[Arthur] PAGE-NOT-SECTION: page request routed to page_add', ['website' => $websiteId, 'page' => $__ps, 'request' => mb_substr($request, 0, 120)]);
                }
            }
X, <<<'X'
            if ($intent !== null && \App\Engines\Builder\Support\BuildQuality::on()) {
                $__ps = \App\Engines\Builder\Support\BuildQuality::pageRequestOverride($request, (string) ($intent['intent'] ?? ''), (string) $industry, $websiteId);
                if ($__ps !== null) {
                    $intent = array_merge($intent, ['intent' => 'page_add', 'confidence' => 0.99, 'normalized' => 'Add a ' . str_replace('_', ' ', $__ps) . ' page', 'question' => '', 'options' => []]);
                    Log::info('[Arthur] PAGE-NOT-SECTION: page request routed to page_add', ['website' => $websiteId, 'page' => $__ps, 'request' => mb_substr($request, 0, 120)]);
                }
            }
X);
rep('app/Engines/Builder/Support/BuildQuality.php', <<<'X'
    /** The requested page names the catalogue has no page design for (Home and Blog are never missing). */
X, <<<'X'
    /**
     * PAGE-NOT-SECTION: the customer's words ask for a PAGE the catalogue offers, the site has no such page, and the model read
     * it as something else (an answer, a section, a question) — because a home-page section of that name exists. The page slug
     * to add, or null when the model's reading stands.
     */
    public static function pageRequestOverride(string $request, string $intent, string $industry, int $websiteId): ?string
    {
        if (! preg_match('/\bpages?\b/iu', $request)) return null;
        if (! in_array($intent, ['answer', 'unsupported', 'clarify', 'section_add', 'catalogue', 'copy_edit'], true)) return null;
        $c = BuilderCapabilities::classify($request, $industry !== '' ? $industry : null);
        $slug = (($c['kind'] ?? '') === 'page') ? (string) ($c['page'] ?? '') : '';
        if ($slug === '' || ! preg_match('/^[a-z0-9_\-]+$/', $slug)) return null;
        return is_file(storage_path("app/public/sites/{$websiteId}/{$slug}/index.html")) ? null : $slug;
    }

    /** The requested page names the catalogue has no page design for (Home and Blog are never missing). */
X);
rep('tests/Feature/Builder888/FixAllTest.php', <<<'X'
    public function test_a_renderer_sites_draft_is_compared_section_by_section(): void
X, <<<'X'
    public function test_a_home_page_section_is_not_a_page(): void
    {
        $id = 999999906; $root = storage_path("app/public/sites/{$id}");
        @mkdir($root . '/services', 0775, true); file_put_contents($root . '/services/index.html', '<html></html>');
        try {
            // the words ask for a page the cafe catalogue offers, the site has none → page_add whatever the model said
            $this->assertSame('menu', BuildQuality::pageRequestOverride('add a menu page', 'answer', 'cafe', $id));
            $this->assertSame('menu', BuildQuality::pageRequestOverride('I want a menu page please', 'section_add', 'cafe', $id));
            // the page already exists → the model's reading stands
            $this->assertNull(BuildQuality::pageRequestOverride('add a services page', 'answer', 'cafe', $id));
            // no "page" in the words, or the model already said page_add / an edit → untouched
            $this->assertNull(BuildQuality::pageRequestOverride('add a menu section after services', 'section_add', 'cafe', $id));
            $this->assertNull(BuildQuality::pageRequestOverride('add a menu page', 'page_add', 'cafe', $id));
            $this->assertNull(BuildQuality::pageRequestOverride('change the page title to Welcome', 'copy_edit', 'cafe', $id));
        } finally { @unlink($root . '/services/index.html'); @rmdir($root . '/services'); @rmdir($root); }
    }

    public function test_a_renderer_sites_draft_is_compared_section_by_section(): void
X);
echo "7b APPLIED\n";
