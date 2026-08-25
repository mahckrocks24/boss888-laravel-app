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
        // ── P1 (2026-06-24): website = its own workspace ─────────────
        // Quota counts across ALL the user's pool-family workspaces; website
        // #2+ gets its own seeded workspace (shares the credit pool + plan).
        $ownerUserId = (int) ($data['user_id']
            ?? DB::table('workspace_users')->where('workspace_id', $wsId)->where('role', 'owner')->value('user_id')
            ?: DB::table('workspaces')->where('id', $wsId)->value('created_by') ?: 0);
        $billingWs = (int) (DB::table('workspaces')->where('id', $wsId)->value('billing_workspace_id') ?: $wsId);
        // RISK-0093 fix: serialize concurrent creates on the billing family so the
        // max_websites check + insert cannot race, and so a failed insert after
        // provisioning rolls back the seeded workspace (no orphan). lockForUpdate on
        // the billing workspace row is the serialization point.
        return DB::transaction(function () use ($wsId, $data, $ownerUserId, $billingWs) {
        DB::table('workspaces')->where('id', $billingWs)->lockForUpdate()->first();
        $plan = \App\Models\Plan::find(
            \App\Models\Subscription::where('workspace_id', $billingWs)
                ->where('status', 'active')->latest()->value('plan_id')
        ) ?? \App\Models\Plan::where('slug', 'free')->first();
        $maxWebsites = (int) ($plan->max_websites ?? 1);
        $userWsIds = DB::table('workspaces')->where('billing_workspace_id', $billingWs)->pluck('id')->all();
        if (empty($userWsIds)) $userWsIds = [$billingWs];
        $totalSites = (int) DB::table('websites')->whereIn('workspace_id', $userWsIds)->whereNull('deleted_at')->count();
        if ($totalSites >= $maxWebsites) {
            return [
                'success' => false,
                // BUILDER888 P1-6 — $plan can be null when neither an active
                // subscription nor a 'free' plan row resolves. Dereferencing it
                // raised "Attempt to read property name on null" and turned a
                // limit refusal into a 500. State the limit truthfully instead;
                // never invent a plan name.
                'error' => $plan?->name
                    ? "Website limit reached ({$maxWebsites} on {$plan->name} plan). Upgrade to create more."
                    : "Website limit reached ({$maxWebsites}). Upgrade to create more.",
                'limit_reached' => true,
                'current' => $totalSites,
                'max' => $maxWebsites,
            ];
        }
        // Target workspace: current if it has no website yet (no regression);
        // else provision a dedicated workspace for this site.
        try {
            $currentHasSite = DB::table('websites')->where('workspace_id', $wsId)->whereNull('deleted_at')->exists();
            if ($currentHasSite && $ownerUserId > 0) {
                $newWs = app(\App\Engines\Builder\Services\ArthurService::class)
                    ->provisionWebsiteWorkspace($wsId, $ownerUserId, $billingWs, (string) ($data['name'] ?? 'New Website'));
                if ($newWs > 0) {
                    \Illuminate\Support\Facades\Log::info('[Builder] provisioned dedicated workspace for new website', ['source_ws' => $wsId, 'new_ws' => $newWs]);
                    $wsId = $newWs;
                }
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[Builder] workspace provisioning failed; building in current workspace: ' . $e->getMessage());
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
            // BUILDER888 P1-6 — the template representation. Additive and
            // optional: every pre-existing caller omits these and is unaffected.
            // Only contract-normalised (flat, scalar) variables may arrive here;
            // BuilderGenerationDTO refuses anything structured, so raw provider
            // output cannot reach this column.
            'type'               => $data['type'] ?? 'builder',
            'template_industry'  => $data['template_industry'] ?? null,
            'template_variables' => isset($data['template_variables'])
                ? json_encode($data['template_variables'])
                : null,
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

        return ['website_id' => $id, 'workspace_id' => $wsId, 'status' => 'draft'];
        });
    }

    /**
     * BUILDER888 P1-6 — refresh a site's template representation.
     *
     * Exists so callers that legitimately regenerate template variables after
     * creation (news seeding, logo application) do not have to write the
     * `websites` table themselves. Law 11: BuilderService stays the sole writer.
     *
     * Only contract-normalised flat scalar variables are accepted, so raw
     * provider output cannot reach the column by this route either.
     */
    public function updateTemplateVariables(int $websiteId, array $variables): void
    {
        foreach ($variables as $k => $v) {
            if (! is_string($k) || ! is_string($v)) {
                throw new \InvalidArgumentException(
                    'updateTemplateVariables: only flat scalar string variables may be persisted.'
                );
            }
        }

        DB::table('websites')->where('id', $websiteId)->update([
            'template_variables' => json_encode($variables),
            'updated_at'         => now(),
        ]);
    }

    /**
     * BUILDER888 P1-6 — set discrete website columns from a domain caller.
     * Whitelisted so no caller can smuggle arbitrary column writes through it.
     */
    public function updateWebsiteFields(int $websiteId, array $fields): void
    {
        $allowed = array_intersect_key($fields, array_flip([
            'name', 'subdomain', 'custom_domain', 'thumbnail_url', 'settings_json', 'seo_json',
        ]));

        if ($allowed === []) {
            return;
        }

        $allowed['updated_at'] = now();
        DB::table('websites')->where('id', $websiteId)->update($allowed);
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

        // Wave 52 — Index all newly-published pages into seo_content_index
        // so the SEO Engine can see them. Fail open: indexing errors are
        // logged but never fail the publish.
        try {
            $website = DB::table('websites')->where('id', $websiteId)
                ->first(['id', 'workspace_id', 'subdomain', 'domain', 'name']);
            if ($website && $website->workspace_id) {
                app(\App\Engines\SEO\Services\BuilderPageIndexer::class)
                    ->indexWebsite((int) $website->workspace_id, $website);
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[BuilderService] post-publish index failed', [
                'website_id' => $websiteId, 'error' => $e->getMessage(),
            ]);
        }

        return ['published' => true];
    }


    /**
     * Page-level publish (cap-action publish_builder_page). Flips ONE page to
     * published, invalidates its served cache, and re-indexes the site into SEO
     * (fail-open). Tenancy-checked: a page whose website is not in $wsId is "not
     * found". Complements publishWebsite (whole-site).
     */
    public function publishPage(int $pageId, ?int $wsId = null): array
    {
        $page = DB::table('pages')
            ->join('websites', 'websites.id', '=', 'pages.website_id')
            ->where('pages.id', $pageId)
            ->select('pages.id', 'pages.slug', 'pages.website_id', 'websites.workspace_id', 'websites.subdomain')
            ->first();
        if (! $page) { throw new \RuntimeException("Page not found: {$pageId}"); }
        if ($wsId !== null && (int) $page->workspace_id !== (int) $wsId) {
            throw new \RuntimeException("Page not found: {$pageId}");
        }
        DB::table('pages')->where('id', $pageId)->update([
            'status' => 'published', 'updated_at' => now(),
        ]);
        if ($page->subdomain) {
            $sub = str_replace('.levelupgrowth.io', '', $page->subdomain);
            \Illuminate\Support\Facades\Cache::forget("published_site:{$sub}:{$page->slug}");
            \Illuminate\Support\Facades\Cache::forget("published_site:{$sub}:home");
        }
        try {
            $website = DB::table('websites')->where('id', $page->website_id)
                ->first(['id', 'workspace_id', 'subdomain', 'domain', 'name']);
            if ($website && $website->workspace_id) {
                app(\App\Engines\SEO\Services\BuilderPageIndexer::class)
                    ->indexWebsite((int) $website->workspace_id, $website);
            }
        } catch (\Throwable $e) {
            Log::warning('[BuilderService] post-page-publish index failed', [
                'page_id' => $pageId, 'error' => $e->getMessage(),
            ]);
        }
        return ['published' => true, 'page_id' => $pageId, 'status' => 'published'];
    }

    /**
     * import_html_page CONSOLIDATED onto createPage: stores the supplied HTML as a
     * single raw_document section. That section passes through the G-SEC3 write-guard
     * (sanitizeSectionsForWrite) so imported markup cannot carry inline script,
     * event handlers, or javascript: URLs.
     * This replaces the dead-ended import_html_page executor with the one real
     * create path instead of a second, unvalidated ingestion path.
     */
    public function importHtmlPage(int $websiteId, array $params, ?int $wsId = null): array
    {
        if ($wsId !== null && ! DB::table('websites')->where('id', $websiteId)->where('workspace_id', $wsId)->exists()) {
            throw new \RuntimeException("Website not found: {$websiteId}");
        }
        $html = (string) ($params['html'] ?? $params['content'] ?? '');
        if (trim($html) === '') { throw new \RuntimeException('import_html_page requires non-empty html'); }
        return $this->createPage($websiteId, [
            'title'    => $params['title'] ?? 'Imported Page',
            'slug'     => $params['slug'] ?? null,
            'sections' => ['schemaVersion' => 1, 'sections' => [['type' => 'raw_document', 'html' => $html]]],
        ]);
    }

    public function deleteWebsite(int $id, ?int $wsId = null): void
    {
        if ($wsId !== null && !DB::table('websites')->where('id', $id)->where('workspace_id', $wsId)->exists()) {
            throw new \RuntimeException("Website not found: {$id}");
        }
        $this->invalidatePublishedCache($id);
        // Free the subdomain on soft-delete: releases it for reuse and prevents
        // the soft-deleted row from squatting the UNIQUE(subdomain) key on reclaim.
        DB::table('websites')->where('id', $id)->update(['deleted_at' => now(), 'subdomain' => null]);
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

    /**
     * BUILDER888 no-fake-success: remove the edited page's static export file(s) so
     * PublishedSiteMiddleware falls back to the CURRENT dynamic render instead of
     * serving a stale pre-rendered page. Surgical — only the edited page's file(s);
     * other pages keep their static export; site image assets are untouched.
     */
    private function invalidateStaticExport(int $websiteId, ?string $slug, bool $isHomepage): void
    {
        $dir = storage_path("app/public/sites/{$websiteId}");
        if (! is_dir($dir)) { return; }
        $targets = [];
        if ($isHomepage || ! $slug || $slug === 'home') {
            $targets[] = "{$dir}/index.html";
        }
        if ($slug && $slug !== 'home') {
            $targets[] = "{$dir}/{$slug}.html";
            $targets[] = "{$dir}/{$slug}/index.html";
        }
        foreach ($targets as $t) {
            if (is_file($t)) { @unlink($t); }
        }
    }

    // ═══════════════════════════════════════════════════════
    // PAGES
    // ═══════════════════════════════════════════════════════

    /** Default empty sections schema used when createPage is called without sections. */
    private function defaultPageSchema(): string
    {
        return json_encode(['schemaVersion' => 1, 'sections' => []]);
    }

    /**
     * 2026-06-24 — build the rich, industry-aware section stack for a page
     * template, hydrating from workspace_memory (business/services/location).
     * Shared by createPage's rich fallback; mirrors addPageFromTemplate's prep.
     */
    private function templateSections(int $wsId, int $websiteId, string $pageTemplate): array
    {
        $mem = DB::table('workspace_memory')->where('workspace_id', $wsId)->pluck('value_json', 'key')->toArray();
        $unwrap = fn ($v) => is_string($v) ? trim($v, '"') : '';

        // Workspace-level memory = FALLBACK (it holds the workspace's PRIMARY
        // business, which is wrong for secondary websites in the same workspace).
        $data = [
            'business_name' => $unwrap($mem['business_name'] ?? '') ?: 'Your Business',
            'industry'      => $unwrap($mem['industry'] ?? '') ?: 'business',
            'core_service'  => $unwrap($mem['core_service'] ?? ''),
            'location'      => $unwrap($mem['location'] ?? ''),
            'services'      => [],
        ];
        if (is_string($mem['services'] ?? null) && $mem['services'] !== '') {
            $decoded = json_decode($mem['services'], true);
            $data['services'] = is_array($decoded)
                ? $decoded
                : array_map('trim', preg_split('/[,;\n]+/', trim($mem['services'], '"')) ?: []);
        }

        // 2026-06-24 (multi-site fix) — PREFER the specific website's own identity
        // (websites.name/template_industry + template_variables) so an added page
        // reflects THAT site, not the workspace's primary business.
        $site = DB::table('websites')->where('id', $websiteId)->first();
        if ($site) {
            $tv = is_string($site->template_variables ?? null)
                ? (json_decode($site->template_variables, true) ?: [])
                : (is_array($site->template_variables ?? null) ? $site->template_variables : []);
            $bn = (string) ($tv['business_name'] ?? $tv['brand_name'] ?? $site->name ?? '');
            if ($bn !== '') $data['business_name'] = $bn;
            if (! empty($site->template_industry)) $data['industry'] = (string) $site->template_industry;
            $loc = (string) ($tv['city'] ?? $tv['contact_service_area'] ?? '');
            if ($loc !== '') $data['location'] = $loc;
            $svc = [];
            for ($i = 1; $i <= 8; $i++) {
                $title  = trim((string) ($tv["service_{$i}_title"] ?? ''));
                $hidden = (string) ($tv["service_{$i}_display"] ?? '') === 'display:none';
                if ($title !== '' && ! $hidden) $svc[] = $title;
            }
            if (! empty($svc)) {
                $data['services'] = $svc;
                if ($data['core_service'] === '') $data['core_service'] = $svc[0];
            }
        }

        return app(\App\Engines\Builder\Services\ArthurService::class)
            ->buildDefaultSectionsForPage($pageTemplate, $data);
    }

    /**
     * 2026-06-24 — derive a unique page slug. Prefer an explicit slug, else the
     * page_template, else the title; ensure uniqueness within the website
     * (append -2, -3 …) so multiple template adds without a title don't collide
     * on the (website_id, slug) UNIQUE key.
     */
    private function uniquePageSlug(int $websiteId, array $data): string
    {
        $base = $data['slug'] ?? null;
        if (! $base) {
            $tpl = (string) ($data['page_template'] ?? '');
            $base = $tpl !== ''
                ? Str::slug(str_replace('_', '-', strtolower($tpl)))
                : Str::slug($data['title'] ?? 'page');
        }
        $base = $base ?: 'page';
        $slug = $base;
        $n = 1;
        while (DB::table('pages')->where('website_id', $websiteId)->where('slug', $slug)->exists()) {
            $n++;
            $slug = $base . '-' . $n;
        }
        return $slug;
    }

    /**
     * BUILDER888 P1-6 — page status is domain data, not an arbitrary string.
     * Unsupported values are refused rather than silently written.
     */
    private function creationStatus(?string $requested): string
    {
        if ($requested === null || $requested === '') {
            return 'draft';
        }

        if (! in_array($requested, \App\Engines\Builder\Support\BuilderGenerationDTO::PAGE_STATUSES, true)) {
            throw new \InvalidArgumentException("Unsupported page status '{$requested}'.");
        }

        return $requested;
    }

    /**
     * BUILDER888 P1-6-b — one place that decides the stored section shape.
     *
     * Accepts a raw list or the canonical wrapped form, always returns the
     * wrapped form, and never persists a page with no sections: an empty list
     * renders a blank page, so it falls back to the default schema exactly as
     * an omitted value does.
     */
    /**
     * G-SEC3 write-path guard: strip unambiguous active-content XSS vectors
     * (<script>, on* event handlers, javascript:/vbscript: URL schemes) from any
     * raw_document section before it is stored. raw_document is served VERBATIM by
     * BuilderRenderer, so this is the write-side complement to CSP (G-SEC1). Legit
     * embeds/forms/styles are preserved (that is CSP's job, not a strip's).
     */
    private function stripActiveContentFromRawDoc(string $html): string
    {
        $html = preg_replace('#<script\b[^>]*>.*?</script>#is', '', $html) ?? $html;
        $html = preg_replace('#<script\b[^>]*/?>#is', '', $html) ?? $html;
        $html = preg_replace('#\son[a-z]+\s*=\s*"[^"]*"#i', '', $html) ?? $html;
        $html = preg_replace("#\son[a-z]+\s*=\s*'[^']*'#i", '', $html) ?? $html;
        $html = preg_replace('#\son[a-z]+\s*=\s*[^\s>]+#i', '', $html) ?? $html;
        $html = preg_replace('#(href|src|action)\s*=\s*("|\x27)\s*(?:javascript|vbscript):[^"\x27]*\2#i', '$1=$2#$2', $html) ?? $html;
        return $html;
    }

    /** Apply the raw_document write guard across a page section list (either shape). */
    private function sanitizeSectionsForWrite(mixed $sections): mixed
    {
        if (! is_array($sections)) { return $sections; }
        if (isset($sections['sections']) && is_array($sections['sections'])) {
            foreach ($sections['sections'] as &$sec) {
                if (is_array($sec) && ($sec['type'] ?? '') === 'raw_document' && isset($sec['html']) && is_string($sec['html'])) {
                    $sec['html'] = $this->stripActiveContentFromRawDoc($sec['html']);
                }
            }
            unset($sec);
            return $sections;
        }
        foreach ($sections as &$sec) {
            if (is_array($sec) && ($sec['type'] ?? '') === 'raw_document' && isset($sec['html']) && is_string($sec['html'])) {
                $sec['html'] = $this->stripActiveContentFromRawDoc($sec['html']);
            }
        }
        unset($sec);
        return $sections;
    }

    private function normalisePageSections(mixed $sections): mixed
    {
        if (is_array($sections) && isset($sections[0])) {
            return ['schemaVersion' => 1, 'sections' => $sections];      // raw list
        }

        if (is_array($sections)
            && isset($sections['sections'])
            && is_array($sections['sections'])
            && $sections['sections'] !== []) {
            return $sections + ['schemaVersion' => 1];                    // already wrapped
        }

        // defaultPageSchema() hands back pre-encoded JSON. Decode it so the
        // caller's json_encode() cannot double-encode into a string literal.
        $default = $this->defaultPageSchema();
        if (is_string($default)) {
            $decoded = json_decode($default, true);
            return is_array($decoded) ? $decoded : ['schemaVersion' => 1, 'sections' => []];
        }

        return $default;
    }

    public function createPage(int $websiteId, array $data): array
    {
        // 2026-06-24 (G15/G5) — rich-template fallback. If no sections were
        // supplied but a page template is named (or inferable from slug/title),
        // build the full industry-aware section stack via Arthur's
        // buildDefaultSectionsForPage so added pages are NOT thin 1-section
        // stubs. (addPageFromTemplate already passes sections, so this is a
        // no-op for it.)
        // BUILDER888 P1-6-b — sections arrive in TWO shapes: a raw list
        // [ {...}, {...} ] and the canonical wrapped form
        // { schemaVersion, sections: [...] }. This check only recognised the
        // raw list, so wrapped content (everything Arthur and the generation
        // DTO produce) fell through to the fallback below and was REPLACED by
        // a generic scaffold. Recognise both.
        $suppliedSections = $data['sections'] ?? null;
        $hasSections = is_array($suppliedSections)
            && (
                isset($suppliedSections[0])                                  // raw list
                || (isset($suppliedSections['sections'])                     // wrapped
                    && is_array($suppliedSections['sections'])
                    && $suppliedSections['sections'] !== [])
            );
        if (! $hasSections) {
            $tpl = strtolower(trim((string) ($data['page_template'] ?? $data['slug'] ?? $data['title'] ?? '')));
            $tpl = preg_replace('/[\s\-]+/', '_', $tpl);
            $known = ['about','about_us','services','pricing','contact','faq','legal','privacy','terms','blog','home','team','portfolio','menu','booking','events'];
            if ($tpl !== '' && in_array($tpl, $known, true)) {
                $wsId = (int) (DB::table('websites')->where('id', $websiteId)->value('workspace_id') ?? 0);
                if ($wsId > 0) {
                    try {
                        $built = $this->templateSections($wsId, $websiteId, $tpl);
                        if (! empty($built)) {
                            $data['sections'] = $built;
                        }
                    } catch (\Throwable $e) {
                        \Log::warning('[Builder] createPage rich-template fallback failed: ' . $e->getMessage());
                    }
                }
            }
        }

        $position = DB::table('pages')->where('website_id', $websiteId)->max('position') ?? 0;
        $id = DB::table('pages')->insertGetId([
            'website_id' => $websiteId,
            'title' => $data['title'] ?? 'New Page',
            'slug' => $this->uniquePageSlug($websiteId, $data),
            'type' => $data['type'] ?? 'page',
            // BUILDER888 P1-6 — generation must be able to request the status its
            // representation needs to render. Default is unchanged ('draft'), so no
            // existing caller is affected. Validated against the domain vocabulary.
            'status' => $this->creationStatus($data['status'] ?? null),
            'sections_json' => json_encode($this->sanitizeSectionsForWrite($this->normalisePageSections($data['sections'] ?? null))),
            'seo_json' => json_encode($data['seo'] ?? ['title' => $data['title'] ?? '', 'description' => '']),
            'position' => $position + 1,
            'is_homepage' => $data['is_homepage'] ?? false,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // 2026-05-12 Phase 0 — sync to SEO index (non-fatal on error)
        // 2026-05-15 Phase 4 — also auto-seed seo_settings.site_url on first
        // page creation per workspace, so the SEO engine has a starting URL.
        try {
            $page = DB::table('pages')->find($id);
            $website = DB::table('websites')->find($websiteId);
            if ($page && $website && !empty($website->workspace_id)) {
                $wsId = (int) $website->workspace_id;
                // First-page seed of seo_settings — only when no site_url exists.
                $hasSiteUrl = DB::table('seo_settings')
                    ->where('workspace_id', $wsId)->where('key', 'site_url')->exists();
                if (!$hasSiteUrl) {
                    $sub  = $website->subdomain ?? $website->slug ?? null;
                    if ($sub) {
                        DB::table('seo_settings')->insert([
                            'workspace_id' => $wsId,
                            'key'          => 'site_url',
                            'value'        => 'https://' . $sub . '.levelupgrowth.io',
                            'created_at'   => now(),
                            'updated_at'   => now(),
                        ]);
                        DB::table('seo_settings')->insert([
                            'workspace_id' => $wsId,
                            'key'          => 'site_name',
                            'value'        => $website->name ?? 'My Site',
                            'created_at'   => now(),
                            'updated_at'   => now(),
                        ]);
                        Log::info("[SEO] Auto-seeded seo_settings for ws={$wsId} on first Builder page");
                    }
                }
                app(\App\Engines\SEO\Services\SeoService::class)
                    ->syncFromBuilder($wsId, $page);
            }
        } catch (\Throwable $e) {
            \Log::warning('[SEO] Builder createPage sync failed: ' . $e->getMessage());
        }

        return ['page_id' => $id, 'status' => 'draft'];
    }

    public function updatePage(int $pageId, array $data, ?int $wsId = null): void
    {
        if ($wsId !== null && !DB::table('pages')->join('websites', 'websites.id', '=', 'pages.website_id')->where('pages.id', $pageId)->where('websites.workspace_id', $wsId)->exists()) {
            throw new \RuntimeException("Page not found: {$pageId}");
        }
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
        if (isset($data['sections'])) $update['sections_json'] = json_encode($this->sanitizeSectionsForWrite($data['sections']));
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

        // BUILDER888 no-fake-success: a direct sections edit updates sections_json but
        // NOT the static export (sites/{id}/*.html) that PublishedSiteMiddleware serves
        // in preference — the live page would show stale content while the save reported
        // success. Invalidate the edited page's static export so it re-serves fresh.
        if ($isSectionsEdit) {
            try {
                $pg = DB::table('pages')->where('id', $pageId)->first(['website_id', 'slug', 'is_homepage']);
                if ($pg && $pg->website_id) {
                    $this->invalidateStaticExport((int) $pg->website_id, $pg->slug ?? null, (bool) ($pg->is_homepage ?? false));
                }
            } catch (\Throwable $e) {
                Log::warning('[Builder] static export invalidation failed', ['page_id' => $pageId, 'error' => $e->getMessage()]);
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

    public function listPages(int $websiteId, ?int $wsId = null): array
    {
        // BUILDER888 P0-1 (2026-08-09) — workspace scope. Callers that pass a
        // workspace id get a fail-closed check; the site must belong to it.
        if ($wsId !== null && !DB::table('websites')->where('id', $websiteId)->where('workspace_id', $wsId)->exists()) {
            return [];
        }
        $rows = DB::table('pages')->where('website_id', $websiteId)->orderBy('position')->get();
        return $rows->map(function($p) {
            $p->has_content = !empty($p->sections_json) && strlen($p->sections_json) > 50;
            $p->slug = $p->slug ?? \Illuminate\Support\Str::slug($p->title ?? '');
            $p->page_type = $p->page_type ?? strtolower(preg_replace('/[^a-z]/i', '', $p->title ?? 'page'));
            $p->thumb_color = $p->thumb_color ?? '#6C5CE7';
            return $p;
        })->toArray();
    }

    /**
     * v1.4.4 (2026-05-30) — workspace-level page listing across all websites.
     * Joins pages to websites so Sarah (workspace-scoped) can see every
     * landing page without iterating per-website.
     */
    public function listWorkspacePages(int $wsId, array $params = []): array
    {
        $status = strtolower((string) ($params['status'] ?? 'all'));
        $limit  = max(1, min((int) ($params['limit'] ?? 50), 200));
        $q = DB::table('pages as p')
            ->join('websites as w', 'w.id', '=', 'p.website_id')
            ->where('w.workspace_id', $wsId)
            ->whereNull('w.deleted_at');
        if ($status !== 'all') $q->where('p.status', $status);
        $rows = $q->orderByDesc('p.updated_at')
            ->limit($limit)
            ->get(['p.id', 'p.website_id', 'p.title', 'p.slug', 'p.type', 'p.status', 'p.is_homepage', 'p.updated_at', 'w.name as website_name']);
        return $rows->map(function ($p) {
            $obj = (array) $p;
            return $obj;
        })->toArray();
    }

    public function getPage(int $pageId, ?int $wsId = null): ?object
    {
        // BUILDER888 P0-1 (2026-08-09) — workspace scope via the pages →
        // websites join. Without this the endpoint returned any tenant's page.
        $q = DB::table('pages')->where('pages.id', $pageId);
        if ($wsId !== null) {
            $q->join('websites', 'websites.id', '=', 'pages.website_id')
              ->where('websites.workspace_id', $wsId)
              ->select('pages.*');
        }
        return $q->first();
    }

    /**
     * v1.4.4 (2026-05-30) — Add a new page to an existing website using one
     * of the universal page templates (about / services / pricing / contact
     * / faq / legal / blog / home). Pulls workspace_memory facts (business
     * name, industry, services, location) and hands them to Arthur's
     * buildDefaultSectionsForPage() so the new page is industry-aware out
     * of the box.
     */
    public function addPageFromTemplate(int $wsId, array $params): array
    {
        $websiteId    = (int) ($params['website_id'] ?? 0);
        $pageTemplate = (string) ($params['page_template'] ?? '');
        $title        = (string) ($params['title'] ?? '');
        $slug         = (string) ($params['slug']  ?? '');

        if ($websiteId <= 0) {
            return ['success' => false, 'error' => 'website_id is required'];
        }
        if ($pageTemplate === '') {
            return ['success' => false, 'error' => 'page_template is required (about, services, pricing, contact, faq, legal, blog, home)'];
        }

        // Verify the website belongs to this workspace.
        $website = DB::table('websites')->where('id', $websiteId)->where('workspace_id', $wsId)->first();
        if (!$website) {
            return ['success' => false, 'error' => "Website {$websiteId} not found in workspace {$wsId}"];
        }

        // 2026-06-24 — use the shared, website-aware section builder. It prefers
        // THIS website's identity (name/industry/template_variables) over
        // workspace memory, so a secondary site doesn't inherit the workspace's
        // primary-business copy. (Removes the old workspace_memory-only gather.)
        $sections = $this->templateSections($wsId, $websiteId, $pageTemplate);

        // Default title + slug if not supplied.
        $titleDefaults = [
            'about'   => 'About', 'about_us' => 'About Us',
            'services'=> 'Services',
            'pricing' => 'Pricing',
            'contact' => 'Contact',
            'faq'     => 'FAQ',
            'legal'   => 'Legal', 'privacy' => 'Privacy Policy', 'terms' => 'Terms of Service',
            'blog'    => 'Blog',
            'home'    => 'Home',
        ];
        $slugN = strtolower(preg_replace('/[\s\-]+/', '_', $pageTemplate));
        if ($title === '') $title = $titleDefaults[$slugN] ?? Str::title(str_replace('_', ' ', $slugN));
        if ($slug === '')  $slug  = str_replace('_', '-', $slugN);

        // Hand off to the existing createPage which auto-snapshots + syncs SEO.
        $created = $this->createPage($websiteId, [
            'title'    => $title,
            'slug'     => $slug,
            'sections' => $sections,
        ]);

        return [
            'success'       => true,
            'page_id'       => $created['page_id'] ?? null,
            'page_template' => $slugN,
            'title'         => $title,
            'slug'          => $slug,
            'section_count' => count($sections),
            'result'        => "Added '{$title}' page (template: {$slugN}, {$slug}) to website {$websiteId}.",
        ];
    }

    public function deletePage(int $pageId, ?int $wsId = null): void
    {
        if ($wsId !== null && !DB::table('pages')->join('websites', 'websites.id', '=', 'pages.website_id')->where('pages.id', $pageId)->where('websites.workspace_id', $wsId)->exists()) {
            throw new \RuntimeException("Page not found: {$pageId}");
        }
        // Recovery: pages are hard-deleted (no deleted_at column; full soft-delete is a
        // 43-site read sweep tracked separately). Snapshot the content first so a delete
        // is not permanent data loss — canvas_states has no FK to pages, so the snapshot
        // survives the row deletion and the content remains recoverable.
        try {
            app(\App\Engines\Builder\Services\BuilderSnapshotService::class)
                ->snapshot($pageId, 'pre_delete');
        } catch (\Throwable $e) {
            Log::warning('[Builder] pre-delete snapshot failed', ['page_id' => $pageId, 'error' => $e->getMessage()]);
        }
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
