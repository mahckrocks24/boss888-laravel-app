<?php

namespace Tests\Feature\Engineering;

use App\Core\Engineering\EngineeringListQuery;
use App\Core\Engineering\EngineeringReadService;
use Illuminate\Http\Request;
use ReflectionClass;
use Tests\TestCase;

/**
 * ENTERPRISE888 E1 M0 — foundation.
 *
 * M0 delivers the read service, the shared list/filter contract and the
 * navigation shell. These tests pin the properties the blueprint made binding:
 * the section is read-only, and it never claims more maturity than exists.
 */
class EngineeringFoundationTest extends TestCase
{
    private const ROOT = '/var/www/levelup-staging';

    private function service(): EngineeringReadService
    {
        return $this->app->make(EngineeringReadService::class);
    }

    private function manifest(array $params = []): array
    {
        $request = Request::create('/api/admin/engineering/manifest', 'GET', $params);
        $query = EngineeringListQuery::fromRequest($request, [], ['status', 'phase']);

        return $this->service()->manifest($query);
    }

    // ── the section is read-only ────────────────────────────────────────────

    /**
     * The intent is milestone-agnostic: whatever methods exist, none may mutate.
     * The original assertion pinned M0's single method and would have failed for
     * the right reason when M1 legitimately added two read methods — so it now
     * asserts the property rather than the inventory.
     */
    public function test_the_controller_exposes_no_mutating_method(): void
    {
        $mutatingVerbs = ['store', 'create', 'update', 'destroy', 'delete', 'patch',
                          'put', 'post', 'execute', 'run', 'approve', 'reject',
                          'restart', 'restore', 'deploy', 'rollback', 'cancel', 'trigger'];

        foreach ((new ReflectionClass(\App\Http\Controllers\Api\Admin\EngineeringController::class))
                     ->getMethods(\ReflectionMethod::IS_PUBLIC) as $m) {
            if ($m->getName() === '__construct') {
                continue;
            }
            foreach ($mutatingVerbs as $verb) {
                $this->assertStringNotContainsStringIgnoringCase(
                    $verb,
                    $m->getName(),
                    "E1 is read-only — EngineeringController::{$m->getName()}() looks mutating"
                );
            }
        }
    }

    public function test_the_read_service_contains_no_write_calls(): void
    {
        foreach ([
            'app/Core/Engineering/EngineeringReadService.php',
            'app/Core/Engineering/EngineeringListQuery.php',
            'app/Http/Controllers/Api/Admin/EngineeringController.php',
        ] as $rel) {
            $src = (string) file_get_contents(self::ROOT . '/' . $rel);

            foreach (['->update(', '->insert(', '->delete(', '->save(', '->create(',
                      'DB::statement', 'Schema::create', 'Schema::drop'] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $src,
                    "{$rel} must not contain a write operation: {$forbidden}");
            }
        }
    }

    /**
     * Every Engineering route, at every milestone, must be read-only. Pinning a
     * count here would fail on each approved milestone; pinning the property
     * keeps guarding as the section grows.
     */
    public function test_every_engineering_route_is_read_only(): void
    {
        $found = 0;

        foreach (\Illuminate\Support\Facades\Route::getRoutes()->getRoutes() as $r) {
            if (!str_starts_with($r->uri(), 'api/admin/engineering')) {
                continue;
            }
            $found++;
            $methods = $r->methods();

            $this->assertEmpty(
                array_intersect($methods, ['POST', 'PUT', 'PATCH', 'DELETE']),
                "{$r->uri()} exposes a mutating verb — E1 is read-only"
            );
            $this->assertContains('GET', $methods);
        }

        $this->assertGreaterThanOrEqual(1, $found, 'the manifest route must exist');
    }

    public function test_the_engineering_route_is_admin_gated(): void
    {
        foreach (\Illuminate\Support\Facades\Route::getRoutes()->getRoutes() as $r) {
            if ($r->uri() === 'api/admin/engineering/manifest') {
                $mw = $r->gatherMiddleware();
                $this->assertContains('auth.jwt', $mw);
                $this->assertContains(\App\Http\Middleware\AdminMiddleware::class, $mw);

                return;
            }
        }
        $this->fail('the manifest route was not found');
    }

    // ── truthfulness ────────────────────────────────────────────────────────

    /**
     * The screen count grows by design each milestone. What must hold is that
     * the manifest is internally consistent and complete — not that it has a
     * particular size.
     */
    public function test_the_manifest_is_internally_consistent(): void
    {
        $m = $this->manifest();

        $this->assertSame('engineering', $m['section']);
        $this->assertSame('E1', $m['phase']);
        $this->assertNotEmpty($m['observed_at']);

        $this->assertSame($m['total'], count($m['screens']),
            'total must equal the number of screens returned when unpaginated');
        $this->assertSame($m['total'], array_sum($m['counts']),
            'the status counts must account for every screen');

        $keys = array_column($m['screens'], 'key');
        $this->assertSame($keys, array_unique($keys), 'screen keys must be unique');

        // The screens the blueprint approved must all be present.
        foreach (['overview', 'health', 'ownership', 'backups', 'audit', 'logs',
                  'documentation', 'marketing_tasks', 'incidents', 'costs',
                  'engineering_tasks', 'runs', 'workers', 'reports',
                  'deployments', 'rollbacks', 'provider_health'] as $required) {
            $this->assertContains($required, $keys, "approved screen '{$required}' is missing");
        }
    }

    public function test_every_screen_declares_status_purpose_and_phase(): void
    {
        foreach ($this->manifest()['screens'] as $s) {
            $this->assertNotEmpty($s['key']);
            $this->assertNotEmpty($s['title']);
            $this->assertNotEmpty($s['purpose'], "{$s['key']} must state its purpose");
            $this->assertNotEmpty($s['phase'], "{$s['key']} must name its owning phase");
            $this->assertContains($s['status'], ['live', 'partial', 'planned', 'not_built']);
        }
    }

    /**
     * The blueprint's empty-state contract: why empty, which phase, what
     * evidence will appear. A not-built screen missing any of these would be a
     * hollow shell, which is the failure this milestone exists to prevent.
     */
    public function test_every_not_built_screen_satisfies_the_empty_state_contract(): void
    {
        $notBuilt = array_filter($this->manifest()['screens'], fn ($s) => $s['status'] === 'not_built');

        $this->assertGreaterThanOrEqual(7, count($notBuilt));

        foreach ($notBuilt as $s) {
            $this->assertNotEmpty($s['why_unavailable'], "{$s['key']} must say WHY it is empty");
            $this->assertNotEmpty($s['phase'], "{$s['key']} must name the phase that introduces it");
            $this->assertNotEmpty($s['expected_evidence'], "{$s['key']} must say what will appear");
            $this->assertNull($s['data_source'], "{$s['key']} has no data source and must not claim one");
        }
    }

    public function test_every_built_or_planned_screen_names_a_data_source(): void
    {
        $planned = array_filter($this->manifest()['screens'], fn ($s) => $s['status'] !== 'not_built');

        foreach ($planned as $s) {
            $this->assertNotEmpty($s['data_source'], "{$s['key']} must name its data source");
            $this->assertNull($s['why_unavailable'], "{$s['key']} is available and must not claim otherwise");
        }
    }

    /**
     * The "why empty" reason is checked against the live schema, not trusted.
     * If someone creates engineering_runs in E3, this must stop claiming it is
     * missing without anyone remembering to edit a constant.
     */
    public function test_unavailability_reasons_are_verified_against_the_live_schema(): void
    {
        foreach ($this->manifest()['screens'] as $s) {
            $this->assertTrue(
                $s['reason_verified'],
                "{$s['key']}: its stated availability disagrees with the live schema "
                . '(missing: ' . implode(', ', $s['missing_tables']) . ')'
            );
        }
    }

    public function test_engineering_tasks_are_declared_separate_from_marketing_tasks(): void
    {
        $byKey = collect($this->manifest()['screens'])->keyBy('key');

        $marketing = $byKey['marketing_tasks'];
        $this->assertSame('Marketing Tasks', $marketing['title'],
            'the customer task engine must not be labelled simply "Tasks"');
        $this->assertStringContainsString('NOT engineering', $marketing['purpose']);

        $engineering = $byKey['engineering_tasks'];
        $this->assertSame('not_built', $engineering['status']);
        $this->assertSame('E3', $engineering['phase']);
    }

    public function test_incidents_screen_declares_its_three_sources(): void
    {
        $incidents = collect($this->manifest()['screens'])->firstWhere('key', 'incidents');

        $this->assertStringContainsString('three', strtolower($incidents['purpose']));
        $this->assertStringContainsString('infra_incidents', $incidents['data_source']);
        $this->assertStringContainsString('register', strtolower($incidents['data_source']));
    }

    public function test_costs_screen_declares_it_is_credit_not_provider_cost(): void
    {
        $costs = collect($this->manifest()['screens'])->firstWhere('key', 'costs');

        $this->assertStringContainsString('CUSTOMER CREDITS', $costs['purpose']);
        $this->assertStringContainsString('not measured', strtolower($costs['purpose']));
    }

    // ── the shared list/filter contract ─────────────────────────────────────

    public function test_limit_is_bounded_and_the_rejection_is_recorded(): void
    {
        $q = EngineeringListQuery::fromRequest(
            Request::create('/x', 'GET', ['limit' => 99999])
        );

        $this->assertSame(EngineeringListQuery::MAX_LIMIT, $q->limit);
        $this->assertNotEmpty($q->applied()['rejected'],
            'a clamped limit must be reported, not silently applied');
    }

    public function test_an_unsupported_sort_is_rejected_not_ignored(): void
    {
        $q = EngineeringListQuery::fromRequest(
            Request::create('/x', 'GET', ['sort' => 'drop_table']),
            allowedSorts: ['created_at']
        );

        $this->assertNull($q->sort);
        $this->assertStringContainsString("sort 'drop_table' is not supported", implode(' ', $q->applied()['rejected']));
    }

    public function test_an_unsupported_filter_is_rejected(): void
    {
        $q = EngineeringListQuery::fromRequest(
            Request::create('/x', 'GET', ['filter' => ['secret' => 'x']]),
            allowedFilters: ['status']
        );

        $this->assertFalse($q->hasFilter('secret'));
        $this->assertStringContainsString("filter 'secret' is not supported", implode(' ', $q->applied()['rejected']));
    }

    public function test_applied_state_is_returned_as_evidence(): void
    {
        $m = $this->manifest(['limit' => 5, 'filter' => ['status' => 'not_built']]);

        $this->assertSame(5, $m['query']['limit']);
        $this->assertSame('not_built', $m['query']['filters']['status']);
        $this->assertLessThanOrEqual(5, count($m['screens']));

        foreach ($m['screens'] as $s) {
            $this->assertSame('not_built', $s['status']);
        }
    }

    public function test_invalid_direction_falls_back_and_is_recorded(): void
    {
        $q = EngineeringListQuery::fromRequest(Request::create('/x', 'GET', ['direction' => 'sideways']));

        $this->assertSame('desc', $q->direction);
        $this->assertNotEmpty($q->applied()['rejected']);
    }

    // ── navigation shell ────────────────────────────────────────────────────

    public function test_the_admin_spa_declares_the_engineering_section(): void
    {
        $spa = (string) file_get_contents(self::ROOT . '/resources/views/admin/app.blade.php');

        $this->assertStringContainsString("nav('engineering')", $spa, 'sidebar entry missing');
        $this->assertStringContainsString("engineering: 'Engineering Operations'", $spa, 'page title missing');
        $this->assertStringContainsString('engineering: async', $spa, 'page renderer missing');
        $this->assertStringContainsString('/engineering/manifest', $spa, 'the shell must read the manifest');
    }

    public function test_the_spa_section_renders_the_empty_state_contract(): void
    {
        $spa = (string) file_get_contents(self::ROOT . '/resources/views/admin/app.blade.php');

        foreach (['Why empty:', 'Introduced in:', 'Will show:'] as $label) {
            $this->assertStringContainsString($label, $spa,
                "the not-built contract label '{$label}' must be rendered");
        }
    }

    // ═══════════════════════════════════════════════════════════════════════
    // M1 — Overview + Health
    // ═══════════════════════════════════════════════════════════════════════

    private function overview(): array
    {
        return $this->service()->overview(EngineeringListQuery::none());
    }

    private function health(): array
    {
        return $this->service()->health(EngineeringListQuery::none());
    }

    /**
     * Milestone-agnostic. Counting routes here needed an edit at M1 and again at
     * M2; the property that actually matters is that each expected screen has a
     * route and that none of them mutate.
     */
    public function test_each_delivered_screen_has_a_read_only_route(): void
    {
        $uris = [];
        foreach (\Illuminate\Support\Facades\Route::getRoutes()->getRoutes() as $r) {
            if (str_starts_with($r->uri(), 'api/admin/engineering')) {
                $uris[$r->uri()] = $r->methods();
            }
        }

        // Every screen the manifest reports as live or partial must be reachable.
        foreach ($this->manifest()['screens'] as $s) {
            if (!in_array($s['status'], ['live', 'partial'], true)) {
                continue;
            }
            $key = str_replace('_', '-', $s['key']);
            $candidates = ["api/admin/engineering/{$s['key']}", "api/admin/engineering/{$key}"];
            $this->assertNotEmpty(
                array_intersect($candidates, array_keys($uris)),
                "screen '{$s['key']}' is reported live but has no route"
            );
        }

        $this->assertArrayHasKey('api/admin/engineering/manifest', $uris);
        foreach ($uris as $uri => $methods) {
            $this->assertEmpty(array_intersect($methods, ['POST', 'PUT', 'PATCH', 'DELETE']),
                "{$uri} must be read-only");
        }
    }

    /**
     * Asserts the property, not the inventory — the inventory grows every
     * milestone by design.
     */
    public function test_every_controller_method_is_a_read_method(): void
    {
        $mutating = ['store', 'create', 'update', 'destroy', 'delete', 'execute',
                     'run', 'approve', 'reject', 'restart', 'restore', 'deploy',
                     'rollback', 'cancel', 'trigger', 'seal', 'write'];

        foreach ((new ReflectionClass(\App\Http\Controllers\Api\Admin\EngineeringController::class))
                     ->getMethods(\ReflectionMethod::IS_PUBLIC) as $m) {
            if ($m->getName() === '__construct') {
                continue;
            }
            foreach ($mutating as $verb) {
                $this->assertStringNotContainsStringIgnoringCase($verb, $m->getName(),
                    "EngineeringController::{$m->getName()}() looks mutating — E1 is read-only");
            }
        }
    }

    public function test_overview_reports_what_needs_attention(): void
    {
        $o = $this->overview();

        $this->assertSame('overview', $o['screen']);
        $this->assertTrue($o['read_only']);
        $this->assertIsArray($o['needs_attention']);
        $this->assertNotEmpty($o['observed_at']);
    }

    /** Every figure must name where it came from — the blueprint's rule. */
    public function test_every_overview_measurement_carries_a_source_or_a_blocked_reason(): void
    {
        $o = $this->overview();

        foreach (['queue', 'workers', 'approvals', 'marketing_tasks_24h'] as $key) {
            $m = $o[$key];
            $this->assertArrayHasKey('available', $m, "{$key} must declare availability");

            if ($m['available']) {
                $this->assertArrayHasKey('source', $m, "{$key} must name its source");
                $this->assertNotEmpty($m['source']);
            } else {
                $this->assertNotEmpty($m['blocked_reason'], "{$key} must say why it is blocked");
                $this->assertNull($m['value'], "{$key} must not carry a value when blocked");
            }
        }
    }

    public function test_marketing_tasks_are_labelled_as_customer_work(): void
    {
        $o = $this->overview();

        $this->assertStringContainsString(
            'CUSTOMER marketing work',
            $o['marketing_tasks_24h']['source'],
            'the overview must not present customer work as engineering execution'
        );
    }

    public function test_overview_declares_what_it_does_not_measure(): void
    {
        $o = $this->overview();

        $this->assertNotEmpty($o['not_measured']);
        $joined = implode(' ', $o['not_measured']);
        $this->assertStringContainsString('E3', $joined);
        $this->assertStringContainsString('E9', $joined);
    }

    public function test_route_integrity_is_compared_against_the_governed_baseline(): void
    {
        $r = $this->overview()['routes'];

        $this->assertArrayHasKey('comparable', $r);
        if ($r['comparable']) {
            $this->assertArrayHasKey('baseline_file', $r);
            $this->assertTrue($r['matches'], 'the live route table must match the governed baseline');
        } else {
            $this->assertNotEmpty($r['blocked_reason']);
        }
    }

    public function test_source_integrity_reports_drift_and_unmanaged_backups(): void
    {
        $s = $this->overview()['source_integrity'];

        $this->assertSame([], $s['drifted'], 'protected source changed outside governed tooling');
        $this->assertSame([], $s['unmanaged_backups']);
        $this->assertTrue($s['clean']);
        $this->assertGreaterThanOrEqual(19, $s['protected_paths']);
    }

    public function test_health_enumerates_probes_and_flags_blocked_ones(): void
    {
        $h = $this->health();

        $this->assertSame('health', $h['screen']);
        $this->assertTrue($h['read_only']);
        $this->assertGreaterThanOrEqual(9, $h['probe_count']);
        $this->assertSame($h['probe_count'], count($h['probes']));

        foreach ($h['probes'] as $p) {
            $this->assertNotEmpty($p['key']);
            $this->assertNotEmpty($p['label']);
            $this->assertArrayHasKey('available', $p['result']);

            if ($p['result']['available'] === false) {
                $this->assertNotEmpty($p['result']['blocked_reason'],
                    "probe {$p['key']} is blocked and must say why");
            }
        }
    }

    /**
     * A probe that cannot run must never be rendered as a healthy zero.
     * This is the difference between "no workers" and "worker state unknown".
     */
    public function test_a_blocked_probe_never_carries_a_value(): void
    {
        foreach ($this->health()['probes'] as $p) {
            if (($p['result']['available'] ?? true) === false) {
                $this->assertNull($p['result']['value'],
                    "blocked probe {$p['key']} must not report a value");
            }
        }
        $this->assertTrue(true);
    }

    public function test_health_states_that_no_history_exists(): void
    {
        $h = $this->health();

        $this->assertFalse($h['history_available']);
        $this->assertStringContainsString('E4', $h['history_note']);
    }

    public function test_the_manifest_now_reports_overview_and_health_as_live(): void
    {
        $byKey = collect($this->manifest()['screens'])->keyBy('key');

        $this->assertSame('live', $byKey['overview']['status']);
        $this->assertSame('live', $byKey['health']['status']);
        $this->assertSame('M1', $byKey['overview']['milestone']);
    }

    public function test_no_engineering_table_was_created(): void
    {
        foreach (['engineering_tasks', 'engineering_runs', 'engineering_workers',
                  'engineering_reports', 'engineering_evidence'] as $table) {
            $this->assertFalse(\Illuminate\Support\Facades\Schema::hasTable($table),
                "E1 creates no tables — {$table} must not exist");
        }
    }

    public function test_the_spa_renders_both_m1_screens(): void
    {
        $spa = (string) file_get_contents(self::ROOT . '/resources/views/admin/app.blade.php');

        foreach (["nav('engOverview')", "nav('engHealth')",
                  'engOverview: async', 'engHealth: async',
                  '/engineering/overview', '/engineering/health'] as $needle) {
            $this->assertStringContainsString($needle, $spa, "SPA missing: {$needle}");
        }

        $this->assertStringContainsString('BLOCKED', $spa,
            'the SPA must be able to render a blocked measurement');
        $this->assertStringContainsString('not engineering execution', $spa,
            'the marketing-tasks label must appear in the UI, not only in the API');
    }

    // ═══════════════════════════════════════════════════════════════════════
    // M2 — Ownership + Backups
    // ═══════════════════════════════════════════════════════════════════════

    private function ownership(): array
    {
        return $this->service()->ownership(EngineeringListQuery::none());
    }

    private function backups(): array
    {
        return $this->service()->backups(EngineeringListQuery::none());
    }

    /**
     * Route count grows per milestone; the invariant is that every engineering
     * route is a GET and that the delivered screens are reachable. Counting is
     * covered by test_each_delivered_screen_has_a_read_only_route().
     */
    public function test_all_engineering_routes_remain_read_only(): void
    {
        $found = 0;

        foreach (\Illuminate\Support\Facades\Route::getRoutes()->getRoutes() as $r) {
            if (!str_starts_with($r->uri(), 'api/admin/engineering')) {
                continue;
            }
            $found++;
            $this->assertEmpty(
                array_intersect($r->methods(), ['POST', 'PUT', 'PATCH', 'DELETE']),
                "{$r->uri()} exposes a mutating verb — E1 is read-only"
            );
        }

        $this->assertGreaterThanOrEqual(5, $found);
    }

    public function test_ownership_reports_integrity_audit_registry_and_seal(): void
    {
        $o = $this->ownership();

        $this->assertSame('ownership', $o['screen']);
        $this->assertTrue($o['read_only']);
        foreach (['integrity', 'audit', 'registry', 'seal'] as $k) {
            $this->assertArrayHasKey($k, $o);
        }
        $this->assertTrue($o['integrity']['clean'], 'protected source must be clean');
        $this->assertSame([], $o['integrity']['unmanaged_backups']);
    }

    public function test_ownership_sections_carry_a_source_or_a_blocked_reason(): void
    {
        $o = $this->ownership();

        foreach (['audit', 'registry', 'seal'] as $k) {
            $m = $o[$k];
            $this->assertArrayHasKey('available', $m);
            if ($m['available']) {
                $this->assertNotEmpty($m['source'], "{$k} must name its source");
            } else {
                $this->assertNotEmpty($m['blocked_reason'], "{$k} must say why it is blocked");
                $this->assertNull($m['value']);
            }
        }
    }

    /** Backups must never present one merged total. */
    public function test_backups_reports_four_separate_systems(): void
    {
        $b = $this->backups();

        $this->assertSame('backups', $b['screen']);
        $this->assertTrue($b['read_only']);

        $expected = ['source_manifest', 'database', 'files', 'quarantine'];
        $this->assertSame($expected, array_keys($b['systems']));

        foreach ($b['systems'] as $key => $sys) {
            $this->assertNotEmpty($sys['title'], "{$key} must have a title");
            $this->assertNotEmpty($sys['covers'], "{$key} must state what it covers");
            $this->assertArrayHasKey('available', $sys['result']);
        }
    }

    public function test_backups_separates_deploy_captures_from_test_captures(): void
    {
        $r = $this->backups()['systems']['source_manifest']['result'];

        if ($r['available'] === false) {
            $this->assertStringContainsString('not readable', $r['blocked_reason']);
            return;
        }

        $v = $r['value'];
        $this->assertArrayHasKey('deploy_captures', $v);
        $this->assertArrayHasKey('test_captures', $v);
        $this->assertSame($v['total'], $v['deploy_captures'] + $v['test_captures'],
            'every capture must be classified as one or the other');
    }

    public function test_backups_states_that_no_restore_is_possible_here(): void
    {
        $b = $this->backups();

        $joined = implode(' ', $b['rules']) . ' ' . implode(' ', $b['not_shown']);
        $this->assertStringContainsString('read-only', strtolower($joined));
        $this->assertStringContainsString('E5', $joined);
    }

    public function test_the_manifest_now_reports_ownership_and_backups_as_live(): void
    {
        $byKey = collect($this->manifest()['screens'])->keyBy('key');

        $this->assertSame('live', $byKey['ownership']['status']);
        $this->assertSame('live', $byKey['backups']['status']);
    }

    // ── the regression M1 did not have ─────────────────────────────────────

    /**
     * M1 shipped a fatal that only appeared in the SERVING context.
     *
     * `routeIntegrity()` omits `matches` when the governed baseline is
     * unreadable, and `/root` is mode 0700 — readable by a root CLI test run,
     * not by www-data. `overview()` then dereferenced the missing key and threw.
     * Every M1 test passed, because they all ran as root.
     *
     * This asserts the shape holds in BOTH branches, so the next person adding
     * a probe cannot reintroduce it.
     */
    public function test_route_integrity_shape_is_safe_in_both_branches(): void
    {
        $ref = new \ReflectionMethod(EngineeringReadService::class, 'routeIntegrity');
        $ref->setAccessible(true);
        $r = $ref->invoke($this->service());

        $this->assertArrayHasKey('comparable', $r);
        $this->assertArrayHasKey('matches', $r,
            'matches must always be present — overview() dereferences it');
        $this->assertArrayHasKey('count', $r);
        $this->assertArrayHasKey('signature', $r);

        if ($r['comparable'] === false) {
            $this->assertNull($r['matches']);
            $this->assertNotEmpty($r['blocked_reason']);
        }
    }

    /**
     * The governed baseline must be readable by the web user, not only by root,
     * or the dashboard silently loses its route-drift check in production.
     */
    public function test_a_web_readable_route_baseline_projection_exists(): void
    {
        $projection = self::ROOT . '/storage/app/source-locks/route-baseline.ordered.txt';

        $this->assertFileExists($projection,
            'the route baseline must have a projection the web user can read');
        $this->assertGreaterThan(0, filesize($projection));

        $perms = substr(sprintf('%o', fileperms($projection)), -4);
        $this->assertContains($perms, ['0644', '0664'], "projection perms {$perms} may not be web-readable");
    }

    public function test_overview_does_not_fatal_when_the_baseline_is_unreadable(): void
    {
        // Simulate the serving context by pointing the check at nothing readable.
        $ref = new \ReflectionClass(EngineeringReadService::class);
        $const = $ref->getConstant('ROUTE_BASELINE_FILES');

        $this->assertIsArray($const);
        $this->assertGreaterThanOrEqual(2, count($const),
            'there must be a fallback chain: a web-readable projection and the root original');
        $this->assertStringContainsString('storage/app/source-locks', $const[0],
            'the web-readable projection must be tried first');
    }

    // ═══════════════════════════════════════════════════════════════════════
    // M3 — Audit + Application Log
    // ═══════════════════════════════════════════════════════════════════════

    private function audit(array $params = []): array
    {
        $req = Request::create('/x', 'GET', $params);
        return $this->service()->audit(EngineeringListQuery::fromRequest(
            $req, [], ['action', 'workspace_id', 'user_id', 'entity_type', 'since']
        ));
    }

    private function logs(array $params = []): array
    {
        $req = Request::create('/x', 'GET', $params);
        return $this->service()->logs(EngineeringListQuery::fromRequest(
            $req, [], ['level', 'include_tests']
        ));
    }

    /**
     * The profile must be present, self-consistent and honest about its source.
     *
     * It must NOT assert that rows exist: the suite connects to the test
     * database, where audit_logs is empty unless another suite happens to have
     * seeded it. Requiring a non-empty table made this test depend on suite
     * order rather than on behaviour. An empty audit trail is a legitimate
     * state the screen has to report correctly.
     */
    public function test_audit_reports_its_profile_and_stays_read_only(): void
    {
        $a = $this->audit();

        $this->assertSame('audit', $a['screen']);
        $this->assertTrue($a['read_only']);
        $this->assertTrue($a['profile']['available']);
        $this->assertNotEmpty($a['profile']['source']);

        $p = $a['profile']['value'];

        $this->assertIsInt($p['total_rows']);
        $this->assertGreaterThanOrEqual(0, $p['total_rows']);
        $this->assertSame($p['total_rows'], $p['with_user'] + $p['without_user'],
            'every row is either attributed to a user or it is not');
        $this->assertLessThanOrEqual($p['total_rows'], $a['returned'],
            'the screen cannot return more entries than exist');

        if ($p['total_rows'] > 0) {
            $this->assertNotEmpty($p['earliest']);
            $this->assertNotEmpty($p['latest']);
            $this->assertGreaterThan(0, $p['distinct_actions']);
        }
    }

    /**
     * Most audit rows have no user. Presenting this as an operator record would
     * misrepresent it, so the screen must say so itself.
     */
    public function test_audit_declares_that_it_is_not_an_operator_record(): void
    {
        $a = $this->audit();
        $p = $a['profile']['value'];

        $this->assertSame($p['total_rows'], $p['with_user'] + $p['without_user']);
        $this->assertGreaterThan(3, count($a['interpretation_limits']));

        $joined = strtolower(implode(' ', $a['interpretation_limits']));
        $this->assertStringContainsString('no user_id', $joined);
        $this->assertStringContainsString('intent', $joined,
            'the screen must state that an audit row is not evidence of intent');
        $this->assertStringContainsString('correlation', $joined);
    }

    public function test_audit_reports_which_indexes_it_used_and_which_are_missing(): void
    {
        $a = $this->audit(['filter' => ['action' => 'user.login']]);

        $this->assertNotEmpty($a['index_use']['applied']);
        $this->assertStringContainsString('action', implode(' ', $a['index_use']['applied']));
        $this->assertStringContainsString('created_at', implode(' ', $a['index_use']['missing']),
            'TD-22 must be surfaced, not hidden');

        foreach ($a['entries'] as $e) {
            $this->assertSame('user.login', $e['action']);
        }
    }

    public function test_audit_warns_when_an_unindexed_date_filter_is_used(): void
    {
        $a = $this->audit(['filter' => ['since' => '2026-07-01 00:00:00'], 'limit' => 5]);

        $this->assertNotNull($a['index_use']['date_note']);
        $this->assertStringContainsString('NOT indexed', $a['index_use']['date_note']);
    }

    public function test_audit_respects_the_shared_list_contract(): void
    {
        $a = $this->audit(['limit' => 7]);

        $this->assertLessThanOrEqual(7, $a['returned']);
        $this->assertSame(7, $a['query']['limit']);
    }

    // ── the application log ─────────────────────────────────────────────────

    public function test_logs_excludes_test_channel_entries_by_default(): void
    {
        $l = $this->logs(['limit' => 200]);

        if (isset($l['stream']) && $l['stream']['available'] === false) {
            $this->markTestSkipped('application log not readable in this environment');
        }

        foreach ($l['entries'] as $e) {
            $this->assertFalse($e['is_test'],
                'a test-channel entry leaked into the default production view');
        }
    }

    public function test_logs_can_include_test_entries_when_asked(): void
    {
        $l = $this->logs(['limit' => 300, 'filter' => ['include_tests' => 'yes']]);

        if (isset($l['stream']) && $l['stream']['available'] === false) {
            $this->markTestSkipped('application log not readable');
        }

        $this->assertTrue($l['tail']['including_tests']);
        foreach ($l['entries'] as $e) {
            $this->assertArrayHasKey('is_test', $e, 'every entry must be classified');
        }
    }

    /** TD-24 must be stated with real numbers, not implied. */
    public function test_logs_states_the_test_pollution_census(): void
    {
        $l = $this->logs();

        if (isset($l['stream']) && $l['stream']['available'] === false) {
            $this->markTestSkipped('application log not readable');
        }

        $c = $l['census']['value'];
        $this->assertGreaterThan(0, $c['total_lines']);
        $this->assertArrayHasKey('test_lines', $c);
        $this->assertNotEmpty($c['by_channel']);
        $this->assertNotEmpty($l['census']['source']);
    }

    public function test_logs_declares_that_it_is_a_tail_not_the_whole_file(): void
    {
        $l = $this->logs();

        if (isset($l['stream']) && $l['stream']['available'] === false) {
            $this->markTestSkipped('application log not readable');
        }

        $joined = strtolower(implode(' ', $l['interpretation_limits']));
        $this->assertStringContainsString('tail', $joined);
        $this->assertStringContainsString('intent', $joined);
        $this->assertArrayHasKey('lines_read', $l['tail']);
    }

    public function test_logs_filters_by_level(): void
    {
        $l = $this->logs(['limit' => 100, 'filter' => ['level' => 'ERROR', 'include_tests' => 'yes']]);

        if (isset($l['stream']) && $l['stream']['available'] === false) {
            $this->markTestSkipped('application log not readable');
        }

        foreach ($l['entries'] as $e) {
            $this->assertSame('ERROR', $e['level']);
        }
    }

    public function test_the_manifest_reports_audit_live_and_logs_partial(): void
    {
        $byKey = collect($this->manifest()['screens'])->keyBy('key');

        $this->assertSame('live', $byKey['audit']['status']);
        $this->assertSame('partial', $byKey['logs']['status'],
            'the log screen reads a tail, not the whole file — it must not claim to be complete');
    }

    public function test_m3_screens_render_in_the_spa(): void
    {
        $spa = (string) file_get_contents(self::ROOT . '/resources/views/admin/app.blade.php');

        foreach (["nav('engAudit')", "nav('engLogs')", 'engAudit: async', 'engLogs: async',
                  '/engineering/audit', '/engineering/logs',
                  'not a "who did what" record', 'writes into this same file'] as $needle) {
            $this->assertStringContainsString($needle, $spa, "SPA missing: {$needle}");
        }
    }

    // ═══════════════════════════════════════════════════════════════════════
    // M4 — Documentation and operator guidance
    // ═══════════════════════════════════════════════════════════════════════

    private function docs(array $params = []): array
    {
        $req = Request::create('/x', 'GET', $params);

        return $this->service()->documentation(
            EngineeringListQuery::fromRequest($req, [], ['screen'])
        );
    }

    public function test_documentation_is_read_only_and_states_its_purpose(): void
    {
        $d = $this->docs();

        $this->assertSame('documentation', $d['screen']);
        $this->assertTrue($d['read_only']);
        $this->assertNotEmpty($d['purpose']);
        $this->assertNotEmpty($d['observed_at']);
    }

    /**
     * The anti-drift invariant this screen exists to hold. A screen an operator
     * can open must be documented; if a later milestone adds one without
     * guidance it is named here rather than quietly omitted.
     */
    public function test_every_reachable_screen_is_documented(): void
    {
        $cov = $this->docs()['coverage']['value'];

        $this->assertSame(
            [],
            $cov['screens_without_guidance'],
            'a reachable screen has no operator guidance: ' . implode(', ', $cov['screens_without_guidance'])
        );
        $this->assertGreaterThanOrEqual($cov['screens_reachable'], $cov['screens_documented']);
        $this->assertNotEmpty($this->docs()['coverage']['source']);
    }

    /** The brief's core requirement: what each screen CANNOT prove. */
    public function test_every_documented_screen_states_what_it_cannot_prove(): void
    {
        foreach ($this->docs()['screens'] as $s) {
            $this->assertNotEmpty($s['measures'], "{$s['key']} does not say what it measures");
            $this->assertNotEmpty($s['cannot_prove'], "{$s['key']} does not say what it cannot prove");
            $this->assertGreaterThanOrEqual(2, count($s['cannot_prove']),
                "{$s['key']} lists fewer than two limits — that is unlikely to be honest");

            foreach ($s['cannot_prove'] as $limit) {
                $this->assertIsString($limit);
                $this->assertNotEmpty(trim($limit));
            }
        }
    }

    public function test_every_documented_screen_names_its_evidence_source(): void
    {
        foreach ($this->docs()['screens'] as $s) {
            $this->assertNotEmpty($s['evidence_source'], "{$s['key']} names no evidence source");
            $this->assertNotEmpty($s['data_source'], "{$s['key']} names no data source");
        }
    }

    /**
     * Documented endpoints are resolved against the live route table, so this
     * page cannot advertise a screen that does not exist.
     */
    public function test_reachable_screens_resolve_to_a_real_read_only_route(): void
    {
        foreach ($this->docs()['screens'] as $s) {
            if (!$s['reachable']) {
                continue;
            }

            $this->assertNotNull($s['route'], "{$s['key']} is reachable but resolves to no route");
            $this->assertSame(['GET'], $s['route']['methods'],
                "{$s['key']} is documented with a non-GET method — E1 is read-only");
            $this->assertStringStartsWith('/api/admin/engineering/', $s['route']['uri']);
        }
    }

    public function test_the_four_required_concepts_are_present_and_explained(): void
    {
        $concepts = collect($this->docs()['concepts'])->keyBy('key');

        foreach ([
            'blocked',
            'audit_is_not_authorization',
            'logs_and_audit_are_separate',
            'evidence_over_interpretation',
        ] as $key) {
            $this->assertTrue($concepts->has($key), "required concept missing: {$key}");
            $this->assertNotEmpty($concepts[$key]['title']);
            $this->assertGreaterThan(200, strlen($concepts[$key]['body']),
                "{$key} is explained too briefly to be useful");
            $this->assertNotEmpty($concepts[$key]['example'], "{$key} has no worked example");
        }
    }

    /** BLOCKED must be distinguished from zero, in those words. */
    public function test_blocked_is_explained_as_distinct_from_zero(): void
    {
        $blocked = collect($this->docs()['concepts'])->firstWhere('key', 'blocked');
        $body = strtolower($blocked['body']);

        $this->assertStringContainsString('zero', $body);
        $this->assertStringContainsString('could not', $body);
        $this->assertStringContainsString('blocked_reason', $body);
    }

    public function test_audit_is_explained_as_not_an_authorization_record(): void
    {
        $c = collect($this->docs()['concepts'])->firstWhere('key', 'audit_is_not_authorization');
        $body = strtolower($c['body']);

        $this->assertStringContainsString('correlation', $body);
        $this->assertStringContainsString('intent', $body);
        $this->assertStringContainsString('no user', $body);
    }

    public function test_logs_and_audit_separation_is_explained(): void
    {
        $c = collect($this->docs()['concepts'])->firstWhere('key', 'logs_and_audit_are_separate');
        $body = strtolower($c['body']);

        $this->assertStringContainsString('structured', $body);
        $this->assertStringContainsString('test suite', $body);
    }

    public function test_evidence_is_explained_as_preferred_over_interpretation(): void
    {
        $c = collect($this->docs()['concepts'])->firstWhere('key', 'evidence_over_interpretation');
        $body = strtolower($c['body']);

        $this->assertStringContainsString('source', $body);
        $this->assertStringContainsString('blocked', $body);
        $this->assertStringContainsString('infer', $body);
    }

    public function test_the_reading_rules_tell_an_operator_how_to_read_a_screen(): void
    {
        $rules = $this->docs()['reading_rules'];

        $this->assertGreaterThanOrEqual(4, count($rules));
        $joined = strtolower(implode(' ', $rules));
        $this->assertStringContainsString('available', $joined);
        $this->assertStringContainsString('source', $joined);
        $this->assertStringContainsString('blocked', $joined);
    }

    /** This page must not present itself as a measurement of the platform. */
    public function test_documentation_disclaims_measuring_the_platform(): void
    {
        $d = $this->docs();
        $self = collect($d['screens'])->firstWhere('key', 'documentation');

        $this->assertNotNull($self, 'the documentation screen must document itself');
        $this->assertStringContainsString('Nothing about the platform', $self['measures']);

        $joined = strtolower(implode(' ', $d['interpretation_limits']));
        $this->assertStringContainsString('authoritative', $joined,
            'the page must say the screen wins where they disagree');
    }

    public function test_documentation_can_be_filtered_to_one_screen(): void
    {
        $d = $this->docs(['filter' => ['screen' => 'audit']]);

        $this->assertSame(1, $d['returned']);
        $this->assertSame('audit', $d['screens'][0]['key']);
    }

    public function test_the_manifest_reports_documentation_as_live(): void
    {
        $byKey = collect($this->manifest()['screens'])->keyBy('key');

        $this->assertSame('live', $byKey['documentation']['status']);
        $this->assertSame('M4', $byKey['documentation']['milestone']);
    }

    public function test_m4_screen_renders_in_the_spa(): void
    {
        $spa = (string) file_get_contents(self::ROOT . '/resources/views/admin/app.blade.php');

        foreach (["nav('engDocs')", 'engDocs: async', '/engineering/documentation',
                  'Cannot prove:', 'reading_rules'] as $needle) {
            $this->assertStringContainsString($needle, $spa, "SPA missing: {$needle}");
        }
    }

    // ═══════════════════════════════════════════════════════════════════════
    // M5 — Marketing Tasks, Incidents, Costs
    // ═══════════════════════════════════════════════════════════════════════

    private function tasksScreen(array $params = []): array
    {
        return $this->service()->marketingTasks(EngineeringListQuery::fromRequest(
            Request::create('/x', 'GET', $params), [],
            ['status', 'category', 'workspace_id', 'source']
        ));
    }

    private function incidentsScreen(array $params = []): array
    {
        return $this->service()->incidents(EngineeringListQuery::fromRequest(
            Request::create('/x', 'GET', $params), [], ['lifecycle_state', 'severity']
        ));
    }

    private function costsScreen(array $params = []): array
    {
        return $this->service()->costs(EngineeringListQuery::fromRequest(
            Request::create('/x', 'GET', $params), [], []
        ));
    }

    // ── marketing tasks ─────────────────────────────────────────────────────

    public function test_marketing_tasks_is_read_only_and_self_consistent(): void
    {
        $d = $this->tasksScreen(['limit' => 10]);

        $this->assertSame('marketing_tasks', $d['screen']);
        $this->assertTrue($d['read_only']);
        $this->assertTrue($d['profile']['available']);
        $this->assertNotEmpty($d['profile']['source']);

        $p = $d['profile']['value'];
        $this->assertSame($p['total_tasks'], array_sum($p['by_status']),
            'the status breakdown must account for every task');
        $this->assertLessThanOrEqual(10, $d['returned']);
        $this->assertLessThanOrEqual($d['matched'], $p['total_tasks']);
    }

    /** The screen must say this is not engineering work, in its own output. */
    public function test_marketing_tasks_declares_it_is_not_engineering_execution(): void
    {
        $joined = strtolower(implode(' ', $this->tasksScreen()['interpretation_limits']));

        $this->assertStringContainsString('not engineering execution', $joined);
        $this->assertStringContainsString('read-only', $joined);
        $this->assertStringContainsString('cannot be retried', $joined);
    }

    /**
     * Absence of an approval is not a rejection. Most tasks never entered an
     * approval flow, and the screen must say so with the real number.
     */
    public function test_marketing_tasks_explains_missing_approval_status(): void
    {
        $d = $this->tasksScreen();
        $joined = implode(' ', $d['interpretation_limits']);

        $this->assertStringContainsString('never entered an approval flow', $joined);
        $this->assertStringNotContainsString('rejected the task', $joined);
        $this->assertArrayHasKey('never_entered_approval', $d['profile']['value']);
    }

    public function test_marketing_tasks_filters_on_an_indexed_column(): void
    {
        $d = $this->tasksScreen(['filter' => ['status' => 'completed'], 'limit' => 5]);

        $this->assertStringContainsString('status (indexed)', implode(' ', $d['index_use']['applied']));
        foreach ($d['entries'] as $e) {
            $this->assertSame('completed', $e['status']);
        }
    }

    public function test_marketing_tasks_never_exposes_a_mutating_affordance(): void
    {
        $d = $this->tasksScreen(['limit' => 5]);

        // The screen-level guarantee holds whether or not any task exists; the
        // per-entry loop below would otherwise assert nothing on an empty table.
        $this->assertTrue($d['read_only']);
        $this->assertArrayNotHasKey('actions', $d);

        foreach ($d['entries'] as $e) {
            foreach (['retry', 'cancel', 'approve', 'action_url', 'execute'] as $forbidden) {
                $this->assertArrayNotHasKey($forbidden, $e,
                    "a task entry exposes '{$forbidden}' — E1 must not offer execution");
            }
        }
    }

    // ── incidents ───────────────────────────────────────────────────────────

    public function test_incidents_reports_three_named_sources(): void
    {
        $d = $this->incidentsScreen();

        $this->assertSame('incidents', $d['screen']);
        $this->assertTrue($d['read_only']);
        $this->assertCount(3, $d['sources']);
        $this->assertEqualsCanonicalizing(
            ['infra_monitor', 'written_register', 'engineering_incidents'],
            array_column($d['sources'], 'key')
        );
    }

    /** The requirement stated in the manifest: never merged into one count. */
    public function test_incidents_produces_no_combined_total(): void
    {
        $d = $this->incidentsScreen();

        $this->assertNull($d['combined_total']);
        $this->assertNotEmpty($d['why_no_combined_total']);
        $this->assertStringContainsString('means nothing', strtolower($d['why_no_combined_total']));
    }

    public function test_every_incident_source_is_either_available_or_blocked_with_a_reason(): void
    {
        foreach ($this->incidentsScreen()['sources'] as $s) {
            $this->assertArrayHasKey('available', $s);

            if ($s['available']) {
                $this->assertArrayHasKey('count', $s);
                $this->assertNotEmpty($s['evidence_source'], "{$s['key']} names no evidence source");
            } else {
                $this->assertNotEmpty($s['blocked_reason'],
                    "{$s['key']} is unavailable but gives no reason — BLOCKED must never be silent");
                $this->assertSame([], $s['entries']);
            }
        }
    }

    /** A source that is not built must say so, not report zero. */
    public function test_the_unbuilt_incident_source_is_blocked_not_zero(): void
    {
        $s = collect($this->incidentsScreen()['sources'])->firstWhere('key', 'engineering_incidents');

        $this->assertFalse($s['available']);
        $this->assertArrayNotHasKey('count', $s);
        $this->assertStringContainsString('not', strtolower($s['blocked_reason']));
    }

    public function test_incidents_states_what_the_monitor_does_not_prove(): void
    {
        $joined = strtolower(implode(' ', $this->incidentsScreen()['interpretation_limits']));

        $this->assertStringContainsString('never merged', $joined);
        $this->assertStringContainsString('configured targets', $joined);
        $this->assertStringContainsString('responded again', $joined);
    }

    // ── costs ───────────────────────────────────────────────────────────────

    /**
     * The arithmetic trap this screen exists to avoid: a reserve and its commit
     * share one reservation_reference, so summing every row multiplies spend.
     */
    public function test_costs_reports_commits_as_spend_not_the_naive_sum(): void
    {
        $d = $this->costsScreen();
        $s = $d['spend']['value'];

        $this->assertTrue($d['spend']['available']);
        $this->assertStringContainsString('commits only', $d['spend']['source']);

        $expected = (float) \Illuminate\Support\Facades\DB::table('credit_transactions')
            ->where('type', 'commit')->sum('amount');
        $this->assertSame($expected, $s['credits_committed']);

        if ($s['naive_sum_all_rows'] > 0.0) {
            $this->assertNotSame($s['naive_sum_all_rows'], $s['credits_committed'],
                'the screen must distinguish real spend from the naive sum');
        }
    }

    /** reserved - released must equal committed, or the model is wrong. */
    public function test_the_credit_legs_reconcile(): void
    {
        $s = $this->costsScreen()['spend']['value'];

        $this->assertEqualsWithDelta(
            $s['credits_committed'],
            $s['credits_reserved'] - $s['credits_released'],
            0.01,
            'reserved minus released must equal committed'
        );
        $this->assertEqualsWithDelta(
            $s['reserved_minus_released'],
            $s['credits_reserved'] - $s['credits_released'],
            0.01
        );
    }

    public function test_costs_explains_the_arithmetic_on_screen(): void
    {
        $note = strtolower($this->costsScreen()['arithmetic_note']);

        $this->assertStringContainsString('reservation_reference', $note);
        $this->assertStringContainsString('summing every row', $note,
            'the note must name the wrong way of computing spend, not just the right one');
        $this->assertStringContainsString('consistency check', $note);
    }

    /** Provider cost is genuinely unmeasured and must be BLOCKED, not zero. */
    public function test_provider_cost_is_blocked_rather_than_reported_as_zero(): void
    {
        $d = $this->costsScreen();

        $this->assertFalse($d['provider_cost']['available']);
        $this->assertArrayNotHasKey('value', array_filter($d['provider_cost'], fn ($v) => $v !== null));
        $this->assertStringContainsString('Railway', $d['provider_cost']['blocked_reason']);
        $this->assertStringContainsString('empty', $d['provider_cost']['blocked_reason']);
    }

    public function test_costs_declares_credits_are_not_money(): void
    {
        $joined = strtolower(implode(' ', $this->costsScreen()['interpretation_limits']));

        $this->assertStringContainsString('not money', $joined);
        $this->assertStringContainsString('reserve is not spend', $joined);
        $this->assertStringContainsString('lifetime totals', $joined);
        $this->assertSame('customer credits', $this->costsScreen()['unit']);
    }

    // ── integration with the rest of the section ─────────────────────────────

    public function test_the_manifest_reports_the_three_m5_screens(): void
    {
        $byKey = collect($this->manifest()['screens'])->keyBy('key');

        $this->assertSame('live', $byKey['marketing_tasks']['status']);
        $this->assertSame('partial', $byKey['incidents']['status'],
            'incidents has one source blocked and one not built — it is not complete');
        $this->assertSame('partial', $byKey['costs']['status'],
            'costs cannot see provider spend — it is not complete');
    }

    public function test_m5_screens_render_in_the_spa(): void
    {
        $spa = (string) file_get_contents(self::ROOT . '/resources/views/admin/app.blade.php');

        foreach (["nav('engTasks')", "nav('engIncidents')", "nav('engCosts')",
                  'engTasks: async', 'engIncidents: async', 'engCosts: async',
                  '/engineering/marketing-tasks', '/engineering/incidents', '/engineering/costs',
                  'not engineering execution', 'never merged into one number',
                  'Naive sum (wrong)'] as $needle) {
            $this->assertStringContainsString($needle, $spa, "SPA missing: {$needle}");
        }
    }

    /**
     * Regression: a zero produced by unreadable directories is not a zero.
     *
     * As root the register is readable; as www-data /root is 0700 while another
     * configured directory is readable and empty. The first implementation
     * reported available=true, count=0 for the web user — a confident zero
     * manufactured by permissions.
     */
    public function test_the_written_register_blocks_rather_than_reporting_a_permissions_zero(): void
    {
        $s = collect($this->incidentsScreen()['sources'])->firstWhere('key', 'written_register');

        if ($s['available']) {
            $this->assertGreaterThan(0, $s['count'],
                'an available register with zero entries must have been reported as blocked');
            $this->assertArrayHasKey('coverage_complete', $s,
                'the register must declare whether it could read everything');
            if (!$s['coverage_complete']) {
                $this->assertNotEmpty($s['directories_unreadable']);
            }
        } else {
            $this->assertNotEmpty($s['blocked_reason']);
            $this->assertSame([], $s['entries']);
        }
    }
}
