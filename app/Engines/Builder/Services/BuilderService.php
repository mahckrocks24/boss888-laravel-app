<?php

namespace App\Engines\Builder\Services;

use App\Connectors\DeepSeekConnector;
use App\Core\Intelligence\EngineIntelligenceService;
use App\Core\Billing\TrialService;
use App\Engines\Creative\Services\CreativeService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class BuilderService
{
    public function __construct(
        private DeepSeekConnector         $llm,
        private EngineIntelligenceService  $engineIntel,
        private CreativeService            $creative,
        private TrialService               $trial,
        private \App\Connectors\RuntimeClient $runtime,
    ) {}

    // ── Creative blueprint helper ────────────────────────────────────────────
    private function blueprint(int $wsId, string $type, array $context = []): array
    {
        try {
            $result = $this->creative->generateThroughBlueprint('builder', $type, $wsId, $context);
            return $result['output'] ?? [];
        } catch (\Throwable) {
            return [];
        }
    }

    // ═══════════════════════════════════════════════════════
    // WEBSITES
    // ═══════════════════════════════════════════════════════

    public function createWebsite(int $wsId, array $data): array
    {
        // ── Plan limit enforcement ──────────────────────────────────
        $currentCount = DB::table('websites')
            ->where('workspace_id', $wsId)
            ->whereNull('deleted_at')
            ->count();
        $plan = \App\Models\Plan::find(
            \App\Models\Subscription::where('workspace_id', $wsId)
                ->where('status', 'active')->latest()->value('plan_id')
        ) ?? \App\Models\Plan::where('slug', 'free')->first();
        $maxWebsites = (int) ($plan->max_websites ?? 1);
        if ($currentCount >= $maxWebsites) {
            return [
                'success' => false,
                'error' => "Website limit reached ({$maxWebsites} on {$plan->name} plan). Upgrade to create more.",
                'limit_reached' => true,
                'current' => $currentCount,
                'max' => $maxWebsites,
            ];
        }
        // ────────────────────────────────────────────────────────────
        $id = DB::table('websites')->insertGetId([
            'workspace_id'  => $wsId,
            'name'          => $data['name'] ?? 'My Website',
            'domain'        => $data['domain'] ?? null,
            'subdomain'     => $data['subdomain'] ?? null, // Set by customer on first publish
            'status'        => 'draft',
            'template'      => $data['template'] ?? null,
            'settings_json' => json_encode($data['settings'] ?? ['theme' => 'modern', 'primary_color' => '#6C5CE7', 'secondary_color' => '#00E5A8', 'accent_color' => '#F4F7FB', 'font_heading' => 'Syne', 'font_body' => 'DM Sans']),
            'seo_json'      => json_encode($data['seo'] ?? []),
            'created_by'    => $data['user_id'] ?? null,
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        $this->engineIntel->recordToolUsage('builder', 'create_website');

        // ── Trial activation — fires on first website creation ───────────────────
        try {
            $ws = \App\Models\Workspace::find($wsId);
            if ($ws && !$ws->trial_started_at && !$this->trial->hasHadTrial($wsId)) {
                $siteCount = \Illuminate\Support\Facades\DB::table('websites')
                    ->where('workspace_id', $wsId)
                    ->whereNull('deleted_at')
                    ->count();
                if ($siteCount === 1) {
                    $this->trial->activateTrial($wsId);
                }
            }
        } catch (\Throwable) {
            // Trial activation NEVER blocks website creation
        }
        // ────────────────────────────────────────────────────────────────────────

        return ['website_id' => $id, 'status' => 'draft'];
    }

    public function getWebsite(int $wsId, int $id): ?object
    {
        $site = DB::table('websites')->where('workspace_id', $wsId)->where('id', $id)->first();
        if ($site) {
            $site->page_count = DB::table('pages')->where('website_id', $id)->count();
        }
        return $site;
    }

    public function listWebsites(int $wsId): array
    {
        $sites = DB::table('websites')->where('workspace_id', $wsId)->whereNull('deleted_at')
            ->orderByDesc('created_at')->get();
        $websites = $sites->map(function($s) {
            $s->title = $s->name;
            $s->slug = \Illuminate\Support\Str::slug($s->name ?? '');
            $s->page_count = DB::table('pages')->where('website_id', $s->id)->count();
            $s->publish_state = $s->published_at ? 'published' : 'draft';
            $s->description = $s->description ?? '';
            return $s;
        })->toArray();

        // ── Usage metadata for frontend limit display ───────────────
        $plan = \App\Models\Plan::find(
            \App\Models\Subscription::where('workspace_id', $wsId)
                ->where('status', 'active')->latest()->value('plan_id')
        ) ?? \App\Models\Plan::where('slug', 'free')->first();
        // ────────────────────────────────────────────────────────────

        return [
            'websites' => $websites,
            'usage' => [
                'count' => count($websites),
                'limit' => (int) ($plan->max_websites ?? 1),
                'plan'  => $plan->name ?? 'Free',
            ],
        ];
    }

    public function publishWebsite(int $websiteId): array
    {
        DB::table('websites')->where('id', $websiteId)->update([
            'status' => 'published', 'published_at' => now(), 'updated_at' => now(),
        ]);
        // Also publish all draft pages
        DB::table('pages')->where('website_id', $websiteId)->where('status', 'draft')
            ->update(['status' => 'published']);

        // Invalidate published site cache
        $this->invalidatePublishedCache($websiteId);

        return ['published' => true];
    }

    public function deleteWebsite(int $id): void
    {
        $this->invalidatePublishedCache($id);
        DB::table('websites')->where('id', $id)->update(['deleted_at' => now()]);
    }

    /**
     * Clear Redis cache for all published pages of a website.
     */
    private function invalidatePublishedCache(int $websiteId): void
    {
        $website = DB::table('websites')->where('id', $websiteId)->first();
        if (!$website || !$website->subdomain) return;

        $subdomain = str_replace('.levelupgrowth.io', '', $website->subdomain);
        $pages = DB::table('pages')->where('website_id', $websiteId)->pluck('slug');

        foreach ($pages as $slug) {
            \Illuminate\Support\Facades\Cache::forget("published_site:{$subdomain}:{$slug}");
        }
        \Illuminate\Support\Facades\Cache::forget("published_site:{$subdomain}:home");
    }

    // ═══════════════════════════════════════════════════════
    // PAGES
    // ═══════════════════════════════════════════════════════

    /** Default empty sections schema used when createPage is called without sections. */
    private function defaultPageSchema(): string
    {
        return json_encode(['schemaVersion' => 1, 'sections' => []]);
    }

    public function createPage(int $websiteId, array $data): array
    {
        $position = DB::table('pages')->where('website_id', $websiteId)->max('position') ?? 0;
        $id = DB::table('pages')->insertGetId([
            'website_id' => $websiteId,
            'title' => $data['title'] ?? 'New Page',
            'slug' => $data['slug'] ?? Str::slug($data['title'] ?? 'page'),
            'type' => $data['type'] ?? 'page',
            'status' => 'draft',
            'sections_json' => json_encode(
                (isset($data['sections']) && is_array($data['sections']) && isset($data['sections'][0]))
                    ? ['schemaVersion' => 1, 'sections' => $data['sections']]  // wrap raw array
                    : ($data['sections'] ?? $this->defaultPageSchema())         // already wrapped or default
            ),
            'seo_json' => json_encode($data['seo'] ?? ['title' => $data['title'] ?? '', 'description' => '']),
            'position' => $position + 1,
            'is_homepage' => $data['is_homepage'] ?? false,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // 2026-05-12 Phase 0 — sync to SEO index (non-fatal on error)
        try {
            $page = DB::table('pages')->find($id);
            $website = DB::table('websites')->find($websiteId);
            if ($page && $website && !empty($website->workspace_id)) {
                app(\App\Engines\SEO\Services\SeoService::class)
                    ->syncFromBuilder((int) $website->workspace_id, $page);
            }
        } catch (\Throwable $e) {
            \Log::warning('[SEO] Builder createPage sync failed: ' . $e->getMessage());
        }

        return ['page_id' => $id, 'status' => 'draft'];
    }

    public function updatePage(int $pageId, array $data): void
    {
        // PATCH 8 (2026-05-08) — auto-snapshot before+after every mutation.
        // sections_json is the canonical source of truth; every change is
        // recorded in canvas_states so it can be undone via
        // /api/builder/pages/{id}/restore/{stateId}.
        $isSectionsEdit = isset($data['sections']) || isset($data['sections_json']);
        if ($isSectionsEdit) {
            try {
                app(\App\Engines\Builder\Services\BuilderSnapshotService::class)
                    ->snapshot($pageId, 'before_edit');
            } catch (\Throwable $e) {
                Log::warning('Builder pre-edit snapshot failed', [
                    'page_id' => $pageId,
                    'error'   => $e->getMessage(),
                ]);
            }
        }

        $update = array_intersect_key($data, array_flip(['title', 'slug', 'type', 'status', 'is_homepage', 'position']));
        if (isset($data['sections_json']) && !isset($data['sections'])) { $data['sections'] = is_string($data['sections_json']) ? json_decode($data['sections_json'], true) : $data['sections_json']; }
        if (isset($data['sections'])) $update['sections_json'] = json_encode($data['sections']);
        if (isset($data['seo'])) $update['seo_json'] = json_encode($data['seo']);
        $update['updated_at'] = now();
        DB::table('pages')->where('id', $pageId)->update($update);

        // Invalidate published site cache for this page's website
        $page = DB::table('pages')->where('id', $pageId)->first();
        if ($page) {
            $this->invalidatePublishedCache($page->website_id);
        }

        if ($isSectionsEdit) {
            try {
                app(\App\Engines\Builder\Services\BuilderSnapshotService::class)
                    ->snapshot($pageId, 'after_edit');
            } catch (\Throwable $e) {
                Log::warning('Builder post-edit snapshot failed', [
                    'page_id' => $pageId,
                    'error'   => $e->getMessage(),
                ]);
            }
        }

        // 2026-05-12 Phase 0 — sync to SEO index (non-fatal on error)
        try {
            $fresh = DB::table('pages')->find($pageId);
            if ($fresh && !empty($fresh->website_id)) {
                $website = DB::table('websites')->find($fresh->website_id);
                if ($website && !empty($website->workspace_id)) {
                    app(\App\Engines\SEO\Services\SeoService::class)
                        ->syncFromBuilder((int) $website->workspace_id, $fresh);
                }
            }
        } catch (\Throwable $e) {
            Log::warning('[SEO] Builder updatePage sync failed: ' . $e->getMessage());
        }
    }

    public function listPages(int $websiteId): array
    {
        $rows = DB::table('pages')->where('website_id', $websiteId)->orderBy('position')->get();
        return $rows->map(function($p) {
            $p->has_content = !empty($p->sections_json) && strlen($p->sections_json) > 50;
            $p->slug = $p->slug ?? \Illuminate\Support\Str::slug($p->title ?? '');
            $p->page_type = $p->page_type ?? strtolower(preg_replace('/[^a-z]/i', '', $p->title ?? 'page'));
            $p->thumb_color = $p->thumb_color ?? '#6C5CE7';
            return $p;
        })->toArray();
    }

    public function getPage(int $pageId): ?object
    {
        return DB::table('pages')->where('id', $pageId)->first();
    }

    public function deletePage(int $pageId): void
    {
        DB::table('pages')->where('id', $pageId)->delete();
    }

    // ═══════════════════════════════════════════════════════
    // ARTHUR WIZARD — AI website generation
    // ═══════════════════════════════════════════════════════

    public function wizardGenerate(int $wsId, array $params): array
    {
        // PATCH 3 (2026-05-08) — Disabled. The structured wizard relied on
        // 4 helpers (getWizardPages / generatePageSections / buildCopyPrompt /
        // mergeAiCopy) that were removed 2026-04-19. The fatal Error this
        // produced was the cause of HTTP 500s on /api/builder/wizard and
        // /api/websites/create. Website creation is owned by Arthur now —
        // ArthurService::handleMessage() drives the conversational create
        // flow. Method body kept as a clean 501-style return so any
        // agent-triggered EngineExecutionService dispatch on
        // 'builder/wizard_generate' degrades gracefully instead of crashing.
        $this->engineIntel->recordToolUsage('builder', 'wizard_generate', 0.0);

        return [
            'success'     => false,
            'status'      => 'gone',
            'error'       => 'wizardGenerate is no longer supported. Website creation is handled by Arthur (ArthurService::handleMessage / POST /api/builder/arthur/message).',
            'replacement' => '/api/builder/arthur/message',
        ];
    }

    // ═══════════════════════════════════════════════════════
    // DASHBOARD
    // ═══════════════════════════════════════════════════════

    public function getDashboard(int $wsId): array
    {
        $sites = DB::table('websites')->where('workspace_id', $wsId)->whereNull('deleted_at');
        $totalPages = DB::table('pages')
            ->join('websites', 'pages.website_id', '=', 'websites.id')
            ->where('websites.workspace_id', $wsId)->count();

        return [
            'total_websites' => (clone $sites)->count(),
            'published' => (clone $sites)->where('status', 'published')->count(),
            'draft' => (clone $sites)->where('status', 'draft')->count(),
            'total_pages' => $totalPages,
            'websites' => (clone $sites)->orderByDesc('updated_at')->limit(5)->get(),
        ];
    }

    // ═══════════════════════════════════════════════════════
    // PRIVATE — Wizard helpers
    // ═══════════════════════════════════════════════════════

        // REMOVED: private function getWizardPages — legacy section template

        // REMOVED: private function generatePageSections — legacy section template

        // REMOVED: private function heroSection — legacy section template

    private function mapIndustryToKey(string $industry): string
    {
        $lower = strtolower($industry);
        if (preg_match('/interior|design|furniture|home decor/i', $lower)) return 'interior_design';
        if (preg_match('/restaurant|food|cafe|bakery|catering/i', $lower)) return 'restaurant';
        if (preg_match('/fitness|gym|sport|yoga|wellness/i', $lower)) return 'fitness';
        if (preg_match('/health|medical|dental|clinic|pharma/i', $lower)) return 'healthcare';
        if (preg_match('/law|legal|attorney/i', $lower)) return 'legal';
        if (preg_match('/real.estate|property|realty/i', $lower)) return 'real_estate';
        if (preg_match('/fashion|retail|clothing|boutique/i', $lower)) return 'fashion';
        if (preg_match('/tech|software|saas|digital|it/i', $lower)) return 'technology';
        if (preg_match('/event|wedding|conference|catering/i', $lower)) return 'events';
        if (preg_match('/beauty|spa|salon|cosmetic/i', $lower)) return 'beauty';
        if (preg_match('/finance|accounting|bank|insurance/i', $lower)) return 'legal';
        return 'default';
    }

        // REMOVED: private function featuresSection — legacy section template

    private function defaultFeatures(string $industry): array
    {
        $lower = strtolower($industry);
        if (str_contains($lower, 'interior') || str_contains($lower, 'design') || str_contains($lower, 'furniture')) {
            return [
                ['icon' => '🏠', 'title' => 'Bespoke Design Solutions', 'description' => 'Custom interiors tailored to your lifestyle and space.'],
                ['icon' => '✨', 'title' => 'Premium Materials', 'description' => 'Only the finest materials sourced from trusted suppliers.'],
                ['icon' => '🎯', 'title' => 'On-Time Delivery', 'description' => 'Projects completed on schedule with meticulous attention to detail.'],
            ];
        }
        if (str_contains($lower, 'restaurant') || str_contains($lower, 'food') || str_contains($lower, 'cafe')) {
            return [
                ['icon' => '👨‍🍳', 'title' => 'Expert Chefs', 'description' => 'Culinary masters crafting memorable dishes daily.'],
                ['icon' => '🌿', 'title' => 'Fresh Ingredients', 'description' => 'Locally sourced, seasonal ingredients in every dish.'],
                ['icon' => '⭐', 'title' => 'Award-Winning Service', 'description' => 'Hospitality that keeps guests coming back.'],
            ];
        }
        if (str_contains($lower, 'legal') || str_contains($lower, 'law') || str_contains($lower, 'finance')) {
            return [
                ['icon' => '⚖️', 'title' => 'Expert Legal Counsel', 'description' => 'Decades of experience protecting your interests.'],
                ['icon' => '🔒', 'title' => 'Confidential & Secure', 'description' => 'Your privacy is our highest priority.'],
                ['icon' => '📊', 'title' => 'Proven Results', 'description' => 'Track record of successful outcomes for our clients.'],
            ];
        }
        if (str_contains($lower, 'health') || str_contains($lower, 'medical') || str_contains($lower, 'clinic')) {
            return [
                ['icon' => '🏥', 'title' => 'Expert Medical Team', 'description' => 'Board-certified professionals dedicated to your wellbeing.'],
                ['icon' => '💚', 'title' => 'Patient-Centered Care', 'description' => 'Personalized treatment plans for every individual.'],
                ['icon' => '🔬', 'title' => 'Advanced Technology', 'description' => 'State-of-the-art equipment for accurate diagnosis.'],
            ];
        }
        // Generic fallback
        return [
            ['icon' => '⭐', 'title' => 'Quality Service', 'description' => 'We deliver excellence in everything we do.'],
            ['icon' => '⚡', 'title' => 'Fast Delivery', 'description' => 'Quick turnaround without compromising quality.'],
            ['icon' => '🛡️', 'title' => 'Trusted Team', 'description' => 'Experienced professionals you can rely on.'],
        ];
    }

        // REMOVED: private function ctaSection — legacy section template

        // REMOVED: private function defaultPageSchema — legacy section template

    // ═══════════════════════════════════════════════════════
    // AI COPY GENERATION — Ported from WP class-lubld-website.php
    // ═══════════════════════════════════════════════════════

        // REMOVED: private function buildCopyPrompt — legacy section template

        // REMOVED: private function sectionSchemaSpec — legacy section template

    /**
     * Parse and validate AI JSON response into sections array.
     * Ported from: lu_wizard_parse_ai_sections (WP line ~1300)
     *
     * @return array|null  Array of sections on success, null on any failure
     */
        // REMOVED: private function parseAiSections — legacy section template

    /**
     * Merge AI-generated copy into template sections using type-based matching.
     * Ported from: lu_wizard_merge_ai_copy (WP line ~1420)
     *
     * Template = source of truth for section order, IDs, styles
     * AI = source of truth for all text/copy content
     */
        // REMOVED: private function mergeAiCopy — legacy section template

}
