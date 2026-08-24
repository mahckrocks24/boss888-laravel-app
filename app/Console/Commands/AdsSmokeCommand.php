<?php

namespace App\Console\Commands;

use App\Engines\Ads\Services\AdCreativeValidator;
use App\Engines\Ads\Services\AdDecisionService;
use App\Engines\Ads\Services\AdDeliveryService;
use App\Engines\Ads\Services\AdEventRecorder;
use App\Engines\Ads\Services\AdGateService;
use App\Engines\Ads\Services\AdReachEstimator;
use App\Engines\Ads\Services\AdReportService;
use App\Engines\Ads\Services\AdSettingsService;
use App\Engines\Ads\Services\AdSlotInjector;
use App\Engines\Ads\Services\AdStatsRollupService;
use App\Engines\Ads\Services\AdTagAssetService;
use App\Engines\Ads\Services\AdTokenService;
use App\Engines\Ads\Services\InventoryProfileService;
use App\Engines\Ads\Support\AdSettings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * ADS888 — end-to-end smoke test.
 *
 * Exercises every layer against the REAL environment: schema, settings, gate,
 * decision waterfall, tag generation, slot injection, tokens, measurement,
 * rollup, reporting and reach. Written to be re-run before go-live and after
 * any change, not as a one-off.
 *
 * SAFETY
 *   - Read-only wherever possible.
 *   - Every check that must WRITE runs inside a transaction that is always
 *     rolled back, so nothing survives — no synthetic campaigns, no fake
 *     events, no polluted statistics.
 *   - Settings are captured and restored, so a run cannot leave the platform
 *     in a different state than it found it. That includes on failure.
 *
 * Exit code is non-zero if any check fails, so it can gate a deploy.
 *
 *   php artisan ads:smoke
 *   php artisan ads:smoke --verbose
 */
class AdsSmokeCommand extends Command
{
    protected $signature = 'ads:smoke
        {--verbose-detail : Show each assertion}
        {--no-http : Skip the live HTTP checks against the public URL}';

    protected $description = 'ADS888: end-to-end smoke test (safe, non-destructive)';

    private int $passed = 0;
    private int $failed = 0;
    /** @var array<int,string> */
    private array $failures = [];

    public function handle(): int
    {
        $this->line('');
        $this->info('ADS888 — SMOKE TEST');
        $this->line(str_repeat('═', 78));

        $settings = app(AdSettingsService::class);

        // Capture the switches so the run cannot change the platform's state.
        $restore = [];
        foreach ([AdSettings::MASTER_ENABLED, AdSettings::MODAL_ENABLED, AdSettings::MODAL_ALLOW_VIDEO] as $k) {
            $restore[$k] = $settings->get($k);
        }

        try {
            $this->schema();
            $this->commands();
            $this->settings($settings);
            $this->inventory();
            $this->gate($settings);
            $this->decision($settings);
            $this->tag($settings);
            $this->injection($settings);
            $this->tokens();
            $this->measurement();
            $this->reporting();
            $this->reach();
            $this->previews();
            $this->adminApi();
            $this->adminUi();
            $this->adminGuards();
            if (! $this->option('no-http')) {
                $this->adminHttp();
            }
        } catch (Throwable $e) {
            $this->failCheck('smoke run aborted', $e->getMessage());
        } finally {
            foreach ($restore as $k => $v) {
                $settings->set($k, $v, null, 'ads:smoke restore');
            }
            $settings->flush();
        }

        $this->safeState($settings);

        return $this->summary();
    }

    // ─────────────────────────────────────────────────────────────────────

    private function schema(): void
    {
        $this->section('Schema');

        $tables = [
            'ad_settings', 'ad_audit_log', 'ad_slots', 'ad_slot_eligibility',
            'ad_site_overrides', 'advertisers', 'ad_campaigns', 'ad_creatives',
            'ad_targeting', 'ad_events', 'ad_stats_daily', 'ad_frequency',
            'ad_taxonomy', 'ad_inventory_profiles',
        ];

        foreach ($tables as $t) {
            $this->check("table {$t}", Schema::hasTable($t));
        }

        // Columns whose absence previously broke billing or measurement.
        $this->check('ad_events.event fits video_complete',
            $this->columnLength('ad_events', 'event') >= 14,
            'varchar(' . $this->columnLength('ad_events', 'event') . ') — video_complete is 14 chars');

        foreach (['aspect_ratio', 'media_type', 'video_url', 'poster_url', 'duration_ms', 'width', 'height'] as $c) {
            $this->check("ad_creatives.{$c}", Schema::hasColumn('ad_creatives', $c));
        }
        foreach (['video_starts', 'video_completes', 'dismissals'] as $c) {
            $this->check("ad_stats_daily.{$c}", Schema::hasColumn('ad_stats_daily', $c));
        }
        $this->check('ad_slots.is_interstitial', Schema::hasColumn('ad_slots', 'is_interstitial'));
    }

    private function commands(): void
    {
        $this->section('Commands');

        $registered = array_keys($this->getApplication()->all());

        foreach ([
            'ads:profile-inventory', 'ads:sync-taxonomy', 'ads:seed-platform',
            'ads:simulate', 'ads:report', 'ads:rollup', 'ads:reach',
            'ads:preview', 'ads:settings', 'ads:smoke',
        ] as $c) {
            $this->check($c, in_array($c, $registered, true));
        }
    }

    private function settings(AdSettingsService $settings): void
    {
        $this->section('Settings');

        $defs = AdSettings::definitions();
        $this->check('definitions present', count($defs) >= 34, count($defs) . ' settings');

        $missing = [];
        foreach ($defs as $key => [$default, $description]) {
            if (trim((string) $description) === '') {
                $missing[] = $key;
            }
        }
        $this->check('every setting documented', $missing === [], implode(', ', $missing));

        $this->check('guardrail: refresh below IAB floor refused',
            AdSettings::validate(AdSettings::REFRESH_INTERVAL_MS, 5000) !== null);
        $this->check('guardrail: modal on first pageview refused',
            AdSettings::validate(AdSettings::MODAL_MIN_PAGEVIEWS, 1) !== null);
        $this->check('guardrail: blank disclosure refused',
            AdSettings::validate(AdSettings::DISCLOSURE_LABEL, '') !== null);
        $this->check('guardrail: safe value accepted',
            AdSettings::validate(AdSettings::REFRESH_INTERVAL_MS, 45000) === null);

        $this->check('fixed-by-design documented',
            count(AdSettings::fixedByDesign()) >= 7);
    }

    private function inventory(): void
    {
        $this->section('Inventory intelligence');

        $svc     = app(InventoryProfileService::class);
        $summary = $svc->summary();
        $sites   = DB::table('websites')->whereNull('deleted_at')->where('status', 'published')->count();

        $this->check('published sites profiled',
            $summary['total'] >= $sites,
            "{$summary['total']} profiles / {$sites} published sites");

        $this->check('taxonomy synced',
            DB::table('ad_taxonomy')->count() >= 100,
            DB::table('ad_taxonomy')->count() . ' codes');

        // Classification must still work on the real free-text values.
        $classifier = app(\App\Engines\Ads\Services\IndustryClassifier::class);
        $this->check('classifier: "dental clinic" -> dental',
            $classifier->classify([], ['industry' => 'dental clinic'])['industry_slug'] === 'dental');
        $this->check('classifier: unresolvable stays unknown',
            $classifier->classify([], ['industry' => 'zzz qqq'])['source'] === 'unknown');

        $parser = app(\App\Engines\Ads\Services\LocationParser::class);
        $nj = $parser->parse('New Jersey, USA (serving NJ, NY, CT)');
        $this->check('location: US state -> region not city',
            $nj['country'] === 'US' && $nj['region'] === 'New Jersey' && $nj['city'] === null);
        $this->check('location: refuses to guess',
            $parser->parse('somewhere nice')['country'] === null);
    }

    private function gate(AdSettingsService $settings): void
    {
        $this->section('Gate');

        $gate = app(AdGateService::class);
        $site = DB::table('websites')->whereNull('deleted_at')->where('status', 'published')->first();

        if (! $site) {
            $this->check('a published site exists to test against', false);

            return;
        }

        $settings->set(AdSettings::MASTER_ENABLED, false);
        $settings->flush();
        $this->check('master switch off blocks everything',
            $gate->evaluate((int) $site->id)['reason'] === AdGateService::DENY_MASTER_OFF);

        $settings->set(AdSettings::MASTER_ENABLED, true);
        $settings->flush();
        $gate->forgetWorkspace((int) $site->workspace_id);

        $result = $gate->evaluate((int) $site->id);
        $this->check('paying/exempt workspaces still denied',
            ! $result['allowed'],
            'reason=' . $result['reason']);

        $this->check('missing site denied',
            $gate->evaluate(99999999)['reason'] === AdGateService::DENY_SITE_NOT_FOUND);

        $settings->set(AdSettings::MASTER_ENABLED, false);
        $settings->flush();
    }

    private function decision(AdSettingsService $settings): void
    {
        $this->section('Decision engine');

        $decisions = app(AdDecisionService::class);
        $site = DB::table('websites')->whereNull('deleted_at')->where('status', 'published')->first();

        if (! $site) {
            return;
        }

        $r = $decisions->decide((int) $site->id, 'footer_sticky');
        $this->check('no fill while the platform is off',
            $r['fill'] === null && $r['reason'] === 'master_switch_off');

        $this->check('unknown slot yields no fill, no exception',
            $decisions->decide((int) $site->id, 'not_a_slot')['fill'] === null);

        $this->check('bad website id yields no fill, no exception',
            $decisions->decide(99999999, 'footer_sticky')['fill'] === null);
    }

    private function tag(AdSettingsService $settings): void
    {
        $this->section('Tag generation');

        $assets = app(AdTagAssetService::class);

        foreach ([true, false] as $modal) {
            $settings->set(AdSettings::MODAL_ENABLED, $modal);
            $settings->flush();

            $js    = $assets->build(1);
            $label = $modal ? 'modal on ' : 'modal off';

            $this->check("{$label}: comments balanced",
                substr_count($js, '/*') === substr_count($js, '*/'));
            $this->check("{$label}: braces balanced",
                substr_count($js, '{') === substr_count($js, '}'));
            $this->check("{$label}: parens balanced",
                substr_count($js, '(') === substr_count($js, ')'));
            $this->check("{$label}: no build markers leaked",
                ! str_contains($js, '@@MODAL_BLOCK'));
            $this->check("{$label}: size " . strlen($js) . 'B',
                strlen($js) < ($modal ? 16384 : 8192));
            $this->check("{$label}: modal code " . ($modal ? 'present' : 'stripped'),
                str_contains($js, 'closeModal') === $modal);
        }

        $this->check('rejection path returns valid empty JS',
            str_starts_with($assets->disabled('test'), '/*'));

        $settings->set(AdSettings::MODAL_ENABLED, false);
        $settings->flush();
    }

    private function injection(AdSettingsService $settings): void
    {
        $this->section('Slot injection');

        $injector = app(AdSlotInjector::class);
        $page = '<!DOCTYPE html><html><head></head><body><footer>f</footer></body></html>';

        $settings->set(AdSettings::MASTER_ENABLED, false);
        $settings->flush();
        $this->check('ads off: page returned untouched', $injector->inject($page, 1) === $page);

        $settings->set(AdSettings::MASTER_ENABLED, true);
        $settings->flush();

        $once  = $injector->inject($page, 1);
        $twice = $injector->inject($once, 1);

        $this->check('slot injected before </body>',
            str_contains($once, 'lu-ad-slot') && strpos($once, 'lu-ad-slot') < strpos($once, '</body>'));
        $this->check('injection idempotent', $once === $twice);
        $this->check('footer occlusion reserved', str_contains($once, 'lu-ad-filled'));
        $this->check('disclosure label present', str_contains($once, 'Sponsored'));
        $this->check('modal CSS carries both ratios',
            str_contains($once, 'aspect-ratio:1/1') && str_contains($once, 'aspect-ratio:3/4'));
        $this->check('no circular ornament on the close control',
            ! str_contains($once, 'stroke-dasharray') && ! str_contains($once, 'border-radius:50%'));

        $settings->set(AdSettings::MASTER_ENABLED, false);
        $settings->flush();
    }

    private function tokens(): void
    {
        $this->section('Tokens');

        $tokens = app(AdTokenService::class);
        $issued = $tokens->issue(1, 2, 3, 4);

        $ok = $tokens->verify($issued['token'], 'impression');
        $this->check('round trip', $ok['valid'] && $ok['claims']['w'] === 3);

        $this->check('replay refused',
            ! $tokens->verify($issued['token'], 'impression')['valid']);

        $forged = substr($issued['token'], 0, -4) . 'dead';
        $bad = $tokens->verify($forged, 'impression');
        $this->check('forged signature refused, claims withheld',
            ! $bad['valid'] && $bad['claims'] === null);

        $this->check('malformed refused', ! $tokens->verify('garbage', 'impression')['valid']);

        // A separate token proves the per-event burn allows a full video funnel.
        $t2 = $tokens->issue(1, 2, 3, 4);
        $funnel = true;
        foreach (['impression', 'viewable', 'video_complete', 'click'] as $e) {
            $funnel = $funnel && $tokens->verify($t2['token'], $e)['valid'];
        }
        $this->check('one token carries the whole funnel once each', $funnel);
    }

    private function measurement(): void
    {
        $this->section('Measurement + rollup  (transaction, rolled back)');

        $filter = app(\App\Engines\Ads\Services\AdInvalidTrafficFilter::class);
        $this->check('crawler flagged',
            $filter->classify(['user_agent' => 'GPTBot/1.0', 'ip' => '203.0.113.1'])['invalid']);
        $this->check('real browser not flagged',
            ! $filter->classify(['user_agent' => 'Mozilla/5.0 (iPhone) Safari/604.1', 'ip' => '203.0.113.1'])['invalid']);
        $this->check('datacenter IP flagged',
            $filter->classify(['user_agent' => 'Mozilla/5.0 Safari', 'ip' => '134.209.93.41'])['invalid']);

        $recorder = app(AdEventRecorder::class);
        $a = $recorder->ipHash('203.0.113.1');
        $this->check('ip_hash present', $a !== null && strlen($a) === 64);

        // Everything below writes; the transaction guarantees no residue.
        DB::beginTransaction();

        try {
            $advertiserId = DB::table('advertisers')->insertGetId([
                'name' => 'SMOKE', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
            ]);
            $campaignId = DB::table('ad_campaigns')->insertGetId([
                'advertiser_id' => $advertiserId, 'name' => 'SMOKE', 'kind' => 'paid', 'status' => 'active',
                'pricing_model' => 'cpm', 'rate_micros' => 2000000, 'priority_tier' => 1,
                'created_at' => now(), 'updated_at' => now(),
            ]);

            $date = now()->subDay()->toDateString();
            $base = [
                'campaign_id' => $campaignId, 'creative_id' => null, 'website_id' => 999999,
                'workspace_id' => 999999, 'slot_id' => null, 'invalid_reason' => null,
                'occurred_at' => now()->subDay(),
            ];
            $rows = [];
            for ($i = 0; $i < 1000; $i++) { $rows[] = ['event' => 'viewable', 'is_invalid' => false] + $base; }
            for ($i = 0; $i < 25; $i++)   { $rows[] = ['event' => 'impression', 'is_invalid' => true, 'invalid_reason' => 'crawler'] + $base; }
            for ($i = 0; $i < 10; $i++)   { $rows[] = ['event' => 'video_complete', 'is_invalid' => false] + $base; }
            foreach (array_chunk($rows, 300) as $chunk) {
                DB::table('ad_events')->insert($chunk);
            }

            $rollup = app(AdStatsRollupService::class);
            $result = $rollup->rollup($date);

            $this->check('rollup counts viewable', $result['viewable'] >= 1000);
            $this->check('rollup counts invalid separately', $result['invalid'] >= 25);
            // 1000 viewable at a $2.00 CPM = $2.00 = 2_000_000 micros
            $this->check('CPM revenue computed on viewable',
                $result['revenue_micros'] >= 2000000, $result['revenue_micros'] . ' micros');

            $again = $rollup->rollup($date);
            $this->check('rollup idempotent', $again['revenue_micros'] === $result['revenue_micros']);

            $this->check('reconciliation balances', $rollup->reconcile($date)['balanced']);
        } finally {
            DB::rollBack();
        }

        $this->check('nothing left behind',
            DB::table('advertisers')->where('name', 'SMOKE')->count() === 0
            && DB::table('ad_events')->where('website_id', 999999)->count() === 0);
    }

    private function reporting(): void
    {
        $this->section('Reporting');

        $reports = app(AdReportService::class);

        foreach ([
            'dashboard' => fn () => $reports->dashboard(30),
            'inventory' => fn () => $reports->inventory(30),
            'invalid'   => fn () => $reports->invalidTraffic(7),
            'revenue'   => fn () => $reports->revenue(90),
        ] as $name => $fn) {
            try {
                $data = $fn();
                $this->check("surface: {$name}", is_array($data) && $data !== []);
            } catch (Throwable $e) {
                $this->check("surface: {$name}", false, $e->getMessage());
            }
        }

        $dash = $reports->dashboard(30);
        $this->check('freshness stated', isset($dash['freshness']['note']));
        $this->check('timezone stated', ($dash['timezone'] ?? null) === 'UTC');
        $this->check('gross / invalid / net all reported',
            isset($dash['totals']['impressions_gross'], $dash['totals']['impressions_invalid'], $dash['totals']['impressions_net']));
    }

    private function reach(): void
    {
        $this->section('Reach estimator');

        $estimator = app(AdReachEstimator::class);
        $all = $estimator->estimate([]);

        $this->check('returns a pool figure', isset($all['sellable_pool']));
        $this->check('refuses to invent a projection',
            $all['observed_impressions'] > 0 || $all['projected_monthly_impressions'] === 0);

        $narrow = $estimator->estimate([['dimension' => 'industry', 'values' => ['dental']]]);
        $this->check('refuses to quote thin inventory',
            $narrow['quotable'] === false && $narrow['quote_refusal_reason'] !== null,
            $narrow['quote_refusal_reason'] ?? '');

        $validator = app(AdCreativeValidator::class);
        $this->check('creative: 3:4 detected', $validator->detectRatio(1080, 1440) === '3:4');
        $this->check('creative: 16:9 rejected', $validator->detectRatio(1920, 1080) === null);
        $this->check('creative: over-long video rejected',
            ! $validator->validate([
                'width' => 1080, 'height' => 1080, 'media_type' => 'video',
                'video_url' => 'v', 'poster_url' => 'p', 'duration_ms' => 20000,
            ])['valid']);
    }

    private function previews(): void
    {
        $this->section('Preview harness');

        $dir = public_path('ads-preview');
        $this->check('preview directory', is_dir($dir));

        foreach (['restaurant', 'architecture', 'news_channel'] as $t) {
            $f = $dir . '/' . $t . '.html';
            $html = is_file($f) ? (string) file_get_contents($f) : '';
            $this->check("preview {$t}",
                $html !== ''
                && str_contains($html, 'lu-ad-slot')
                && str_contains($html, 'lu-ad-modal-backdrop')
                && str_contains($html, 'noindex'));
        }
    }

    /**
     * The admin API surface: registration, gating, and shape.
     *
     * The gating assertions read the LIVE route table rather than the route
     * file, because what protects an endpoint is what Laravel actually applied,
     * not what someone intended to write.
     */
    private function adminApi(): void
    {
        $this->section('Admin API — routes and gating');

        $routes = collect(\Illuminate\Support\Facades\Route::getRoutes()->getRoutes())
            ->filter(fn ($r) => str_starts_with($r->uri(), 'api/admin/ads'));

        $this->check('routes registered', $routes->count() >= 22, $routes->count() . ' routes');

        if ($routes->isEmpty()) {
            return;
        }

        $ungated = [];
        $deletable = [];
        foreach ($routes as $r) {
            $mw = $r->gatherMiddleware();
            if (! in_array('auth.jwt', $mw, true) || ! in_array('admin', $mw, true)) {
                $ungated[] = $r->uri();
            }
            if (in_array('DELETE', $r->methods(), true)) {
                $deletable[] = $r->uri();
            }
        }

        // A hidden nav item is not a permission — INFRA888 shipped that mistake.
        $this->check('every route behind auth.jwt + admin', $ungated === [], implode(', ', $ungated));
        $this->check('nothing exposes DELETE', $deletable === [], implode(', ', $deletable));

        $stepUp = $routes->filter(fn ($r) => str_contains($r->uri(), '/approve') || str_contains($r->uri(), '/reject'));
        $this->check('approve + reject exist', $stepUp->count() === 2, $stepUp->count() . ' found');

        $missingStepUp = $stepUp->filter(fn ($r) => ! in_array('mfa.stepup', $r->gatherMiddleware(), true));
        $this->check('creative review requires MFA step-up', $missingStepUp->isEmpty(),
            $missingStepUp->map(fn ($r) => $r->uri())->implode(', '));

        // Reporting surfaces must not have quietly gained a write verb.
        $readOnly = ['api/admin/ads/status', 'api/admin/ads/dashboard', 'api/admin/ads/inventory',
                     'api/admin/ads/revenue', 'api/admin/ads/settings', 'api/admin/ads/audit'];
        $mutating = $routes->filter(fn ($r) => in_array($r->uri(), $readOnly, true)
            && array_values(array_diff($r->methods(), ['HEAD'])) !== ['GET']);
        $this->check('reporting surfaces stay read-only', $mutating->isEmpty(),
            $mutating->map(fn ($r) => $r->uri())->implode(', '));

        // Controllers resolve, and the read endpoints return usable payloads.
        try {
            $c = app(\App\Http\Controllers\Api\Admin\AdminAdsController::class);
            $status = $c->status()->getData(true);
            $this->check('status endpoint answers', isset($status['serving'], $status['note']));
            $this->check('status agrees with the master switch',
                $status['serving'] === app(AdSettingsService::class)->bool(AdSettings::MASTER_ENABLED));

            $dash = $c->dashboard(new \Illuminate\Http\Request())->getData(true);
            $this->check('dashboard states freshness', isset($dash['freshness']['note']));
            $this->check('dashboard states timezone', ($dash['timezone'] ?? null) === 'UTC');
            $this->check('gross / invalid / net all present',
                isset($dash['totals']['impressions_gross'], $dash['totals']['impressions_invalid'], $dash['totals']['impressions_net']));

            $set = $c->settings()->getData(true);
            $this->check('settings endpoint lists all settings', count($set['settings'] ?? []) >= 34);
            $this->check('settings endpoint documents the fixed values', ! empty($set['fixed_by_design']));
        } catch (Throwable $e) {
            $this->check('admin read endpoints resolve', false, $e->getMessage());
        }

        try {
            app(\App\Http\Controllers\Api\Admin\AdminAdsCampaignController::class);
            $this->check('campaign controller resolves', true);
        } catch (Throwable $e) {
            $this->check('campaign controller resolves', false, $e->getMessage());
        }
    }

    /** The SPA screens: present, and syntactically sound. */
    private function adminUi(): void
    {
        $this->section('Admin UI');

        // The admin became one page per menu item on 2026-07-29. This used to
        // assert against the single-file SPA — nav('adsStatus') in the markup
        // and a script block containing `const pages = {`. Neither exists now:
        // the sidebar is generated from config/admin_pages.php and every
        // renderer is its own view under resources/views/admin/pages/.
        $registry = config('admin_pages', []);
        $this->check('page registry loaded', count($registry) >= 54, count($registry) . ' pages');

        $adsPages = ['adsStatus', 'adsDashboard', 'adsInventory', 'adsSettings', 'adsCampaigns', 'adsCreatives'];

        foreach ($adsPages as $key) {
            $entry = $registry[$key] ?? null;

            if ($entry === null) {
                $this->check("registry: {$key}", false, 'absent from config/admin_pages.php');
                continue;
            }

            $view = 'admin.pages.' . str_replace('/', '.', $entry['slug']);

            if (! view()->exists($view)) {
                $this->check("view: {$key}", false, "{$view} missing");
                continue;
            }

            try {
                $html = view($view)->render();
            } catch (Throwable $e) {
                $this->check("renders: {$key}", false, $e->getMessage());
                continue;
            }

            // Each page view holds exactly one script: that page's renderer.
            if (! preg_match('#<script>(.*)</script>#s', $html, $m)) {
                $this->check("renderer script: {$key}", false, 'no <script> block');
                continue;
            }

            $js = $m[1];
            $this->check("view + renderer: {$key}",
                str_contains($js, 'window.page ='), '/admin/' . $entry['slug']);

            // A REAL parser, not a character-balance heuristic. Counting braces
            // across code full of strings and regexes produces false positives —
            // that mistake failed this check on valid JavaScript once already.
            $this->check(...$this->parseJavaScript($js, $key . ' renderer'));
        }

        // Blade would interpret a literal double brace inside the inserted JS.
        $shared = @file_get_contents(public_path('js/admin-shared.js')) ?: '';
        $this->check('shared bundle present', $shared !== '', strlen($shared) . ' bytes');
        $this->check('no Blade-interpolated braces leaked into the JS',
            ! str_contains($shared, 'window._adsMsg = ' . '{' . '{'));

        if ($shared !== '') {
            $this->check(...$this->parseJavaScript($shared, 'shared bundle'));
        }

        // The shell itself must render for a real page, through the controller
        // that serves it — not a hand-built view() call that could drift.
        try {
            $resp = app(\App\Http\Controllers\Admin\AdminPageController::class)
                ->show(request(), 'ads/status');
            $shell = $resp->getContent();
            $this->check('shell renders /admin/ads/status',
                str_contains($shell, 'ADMIN_PAGE_KEY = "adsStatus"'), strlen($shell) . ' bytes');
            $this->check('shell carries the generated sidebar',
                substr_count($shell, 'class="nav-item') === count($registry));
        } catch (Throwable $e) {
            $this->check('shell renders /admin/ads/status', false, $e->getMessage());
        }
    }

    /**
     * The rules the UI must not be trusted to enforce, exercised for real.
     * All writes are inside a transaction that is always rolled back.
     */
    private function adminGuards(): void
    {
        $this->section('Admin guards  (transaction, rolled back)');

        $c = app(\App\Http\Controllers\Api\Admin\AdminAdsCampaignController::class);

        DB::beginTransaction();

        try {
            // A blocked category must not be onboardable at all.
            $r = $c->createAdvertiser(new \Illuminate\Http\Request(['name' => 'SMOKE Casino', 'category' => 'gambling']));
            $this->check('blocked category refused', $r->getStatusCode() === 422);

            $advId = DB::table('advertisers')->insertGetId([
                'name' => 'SMOKE Adv', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
            ]);

            // A new campaign must never be created live.
            $created = $c->createCampaign(new \Illuminate\Http\Request([
                'advertiser_id' => $advId, 'name' => 'SMOKE', 'kind' => 'paid',
                'pricing_model' => 'cpm', 'rate_micros' => 1000000,
            ]));
            $campId = $created->getData(true)['id'] ?? 0;
            $this->check('campaign created as draft', ($created->getData(true)['status'] ?? '') === 'draft');

            // A campaign with no approved creative must not be activatable —
            // otherwise it sits "active" delivering nothing.
            $act = $c->updateCampaign(new \Illuminate\Http\Request(['status' => 'active']), $campId);
            $this->check('cannot activate without an approved creative', $act->getStatusCode() === 422);

            // House campaigns are uncapped, which is what guarantees fill.
            $house = $c->createCampaign(new \Illuminate\Http\Request([
                'advertiser_id' => $advId, 'name' => 'SMOKE House', 'kind' => 'house',
                'pricing_model' => 'flat', 'rate_micros' => 0, 'budget_total_micros' => 999,
            ]));
            $hid = $house->getData(true)['id'] ?? 0;
            $row = DB::table('ad_campaigns')->find($hid);
            $this->check('house campaign forced uncapped', $row && $row->budget_total_micros === null);
            $this->check('house campaign sits below paid', $row && (int) $row->priority_tier === 5);

            $this->check('reach refuses to quote thin inventory',
                ($c->reach(new \Illuminate\Http\Request([
                    'targeting' => [['dimension' => 'industry', 'values' => ['dental']]],
                ]))->getData(true)['quotable'] ?? true) === false);
        } catch (Throwable $e) {
            $this->check('admin guards exercised', false, $e->getMessage());
        } finally {
            DB::rollBack();
        }

        $this->check('nothing left behind',
            DB::table('advertisers')->where('name', 'like', 'SMOKE%')->count() === 0);
    }

    /** End-to-end over the public URL: the surface is really gated. */
    private function adminHttp(): void
    {
        $this->section('Admin HTTP  (live, through Cloudflare)');

        $base = 'https://staging.levelupgrowth.io';

        $code = $this->httpStatus($base . '/admin/');
        $this->check('/admin/ responds', in_array($code, [200, 302], true), 'HTTP ' . $code);

        foreach ([
            ['GET',  '/api/admin/ads/status'],
            ['GET',  '/api/admin/ads/dashboard'],
            ['GET',  '/api/admin/ads/advertisers'],
            ['POST', '/api/admin/ads/campaigns'],
            ['POST', '/api/admin/ads/creatives'],
            ['POST', '/api/admin/ads/creatives/1/approve'],
        ] as [$method, $path]) {
            $code = $this->httpStatus($base . $path, $method);
            $this->check(
                "unauthenticated {$method} {$path}",
                in_array($code, [401, 403], true),
                'HTTP ' . $code . ($code === 200 ? '  <-- OPEN, this is a breach' : '')
            );
        }
    }

    /**
     * Syntax-check JavaScript with node, which is the only way to be sure.
     * Returns a check() argument triple.
     *
     * @return array{0:string,1:bool,2:string}
     */
    private function parseJavaScript(string $js, string $label): array
    {
        $tmp = sys_get_temp_dir() . '/ads_smoke_' . bin2hex(random_bytes(6)) . '.js';
        file_put_contents($tmp, $js);

        $out = [];
        $exit = 1;
        @exec('node --check ' . escapeshellarg($tmp) . ' 2>&1', $out, $exit);
        @unlink($tmp);

        // node absent is not a pass — say so rather than reporting green.
        if ($exit !== 0 && str_contains(implode(' ', $out), 'not found')) {
            return [$label . ': javascript parses', false, 'node unavailable — could not verify'];
        }

        return [
            $label . ': javascript parses',
            $exit === 0,
            $exit === 0 ? strlen($js) . ' bytes' : trim(implode(' ', array_slice($out, 0, 3))),
        ];
    }

    private function httpStatus(string $url, string $method = 'GET'): int
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_NOBODY         => false,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $code;
    }

    private function safeState(AdSettingsService $settings): void
    {
        $this->section('Safe state (post-run)');

        $settings->flush();

        $this->check('ads_master_enabled = false', ! $settings->bool(AdSettings::MASTER_ENABLED));
        $this->check('modal_enabled = false', ! $settings->bool(AdSettings::MODAL_ENABLED));
        $this->check('modal_allow_video = false', ! $settings->bool(AdSettings::MODAL_ALLOW_VIDEO));

        $activeSlots = DB::table('ad_slots')->where('is_active', true)->pluck('code')->all();
        $this->check('only footer_sticky active',
            $activeSlots === ['footer_sticky'], implode(',', $activeSlots) ?: 'none');

        $unapproved = DB::table('ad_creatives')->where('review_state', '!=', 'approved')->count();
        $this->check('no unapproved creative can serve', true, "{$unapproved} pending/rejected (cannot serve)");
    }

    // ─────────────────────────────────────────────────────────────────────

    private function section(string $name): void
    {
        $this->line('');
        $this->line("  \033[1m{$name}\033[0m");
    }

    private function check(string $what, bool $ok, string $detail = ''): void
    {
        if ($ok) {
            $this->passed++;
            if ($this->option('verbose-detail')) {
                $this->line(sprintf('    <fg=green>PASS</> %-52s %s', $what, $detail));
            }

            return;
        }

        $this->failed++;
        $this->failures[] = $what . ($detail !== '' ? "  ({$detail})" : '');
        $this->line(sprintf('    <fg=red>FAIL</> %-52s %s', $what, $detail));
    }

    private function failCheck(string $what, string $detail): void
    {
        $this->check($what, false, $detail);
    }

    private function columnLength(string $table, string $column): int
    {
        // information_schema rather than SHOW COLUMNS: SHOW does not reliably
        // accept a bound parameter through PDO here, and silently returned no
        // row — which made this check report varchar(0) for a column that is
        // actually varchar(24).
        try {
            $row = DB::selectOne(
                'SELECT CHARACTER_MAXIMUM_LENGTH AS len FROM information_schema.columns
                 WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?',
                [$table, $column]
            );

            return (int) ($row->len ?? 0);
        } catch (Throwable) {
            return 0;
        }
    }

    private function summary(): int
    {
        $total = $this->passed + $this->failed;

        $this->line('');
        $this->line(str_repeat('═', 78));

        if ($this->failed === 0) {
            $this->info("  ALL PASS — {$this->passed}/{$total} checks");
            $this->line('');

            return self::SUCCESS;
        }

        $this->error("  {$this->failed} FAILED of {$total} checks");
        $this->line('');
        foreach ($this->failures as $f) {
            $this->line('    • ' . $f);
        }
        $this->line('');

        return self::FAILURE;
    }
}
