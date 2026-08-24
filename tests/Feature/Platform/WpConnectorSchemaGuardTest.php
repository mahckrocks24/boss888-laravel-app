<?php

namespace Tests\Feature\Platform;

use App\Core\Billing\StripeService;
use App\Core\Platform\Connector\WpConnectorSchema;
use App\Http\Controllers\Api\Admin\AdminController;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * The WP Connector compatibility guard.
 *
 * The connector's consumer code shipped on 2026-05-05 without any of its
 * storage: no `wp_site_connections` table, no `api_keys.site_connection_id`
 * column, no `App\Models\WpSiteConnection` class. Six call sites queried it
 * anyway. Two were admin endpoints that returned 500 for three months; two were
 * billing helpers whose catch(\Throwable) logged "non-fatal" on every
 * subscription event.
 *
 * NO DDL IN THIS SUITE, DELIBERATELY. The first version created and dropped
 * tables to exercise the schema-present path. MySQL implicitly commits on DDL,
 * which breaks the transaction RefreshDatabase wraps each test in — observed
 * live on 2026-08-04 as migrate:fresh restarting mid-run and the suite hanging
 * for 25 minutes. The schema-present path is therefore proven by forcing the
 * capability check's answer and asserting the call site ATTEMPTS the real
 * query, which is the behaviour that matters: the guard must delegate, not
 * short-circuit, once the schema exists.
 *
 * These tests do not make the feature work. They prove an absent optional
 * feature degrades truthfully and stays visible. The schema itself is tracked
 * as WP Connector Schema Recovery (BLOCKED_PENDING_AUTHORITATIVE_SPEC).
 */
class WpConnectorSchemaGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('levelup_e888_test', DB::connection()->getDatabaseName());
        WpConnectorSchema::forget();
        $this->seedWorkspace();
    }

    protected function tearDown(): void
    {
        WpConnectorSchema::forget();
        parent::tearDown();
    }

    // ── the premise ──────────────────────────────────────────────────

    public function test_the_connector_schema_really_is_absent(): void
    {
        $this->assertFalse(WpConnectorSchema::available());
        $this->assertSame([
            'table:wp_site_connections',
            'column:api_keys.site_connection_id',
            'class:App\Models\WpSiteConnection',
        ], WpConnectorSchema::missing(), 'the guard must name every absent piece, not just the table');
    }

    // ── call site 1: listUsers ───────────────────────────────────────

    public function test_list_users_does_not_throw_and_reports_zero_truthfully(): void
    {
        $response = $this->controller()->listUsers($this->request(['per_page' => 10]));

        $this->assertSame(200, $response->getStatusCode());
        $rows = json_decode($response->getContent(), true)['data'];
        $this->assertNotEmpty($rows, 'the endpoint must still return the users it exists to return');

        foreach ($rows as $row) {
            // Truthful, not merely non-throwing: nobody can have connected a
            // site to a feature that has no storage.
            $this->assertSame(0, $row['wp_sites_count']);
        }
    }

    // ── call site 2: listWorkspaces ──────────────────────────────────

    public function test_list_workspaces_does_not_throw_and_reports_zero_truthfully(): void
    {
        // This was a pinned-defect test until 2026-08-04: the endpoint still
        // failed, on users.signup_source — a SEPARATE never-shipped column that
        // was outside the connector remediation's scope. That column now has
        // its own guard (SignupSourceGuardTest), so the full assertion holds.
        $response = $this->controller()->listWorkspaces($this->request(['per_page' => 10]));

        $this->assertSame(200, $response->getStatusCode());

        $rows = json_decode($response->getContent(), true)['data'];
        $this->assertNotEmpty($rows);

        foreach ($rows as $row) {
            $this->assertSame(0, $row['wp_sites_count']);
            $this->assertSame(0, $row['wp_active_count']);
            $this->assertSame(0, $row['wp_suspended_count']);
        }
    }

    // ── call site 3: listConnectorSites ──────────────────────────────

    public function test_connector_list_is_empty_and_says_why(): void
    {
        $response = $this->controller()->listConnectorSites($this->request());
        $body = json_decode($response->getContent(), true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([], $body['data']);
        $this->assertSame(0, $body['total']);

        // "Unavailable" must not be presented as "none connected yet" — to a
        // client they are otherwise identical.
        $this->assertFalse($body['connector_available']);
        $this->assertSame(WpConnectorSchema::MISSING_EVENT, $body['unavailable_reason']);

        foreach (['per_page', 'current_page', 'last_page', 'from', 'to'] as $key) {
            $this->assertArrayHasKey($key, $body, 'the paginator envelope must survive');
        }
    }

    // ── call site 4: getWorkspace ────────────────────────────────────

    public function test_workspace_detail_does_not_throw_and_both_queries_are_guarded(): void
    {
        // Guarding only the wp_site_connections query would still throw on the
        // api_keys.site_connection_id aggregation immediately below it.
        $response = $this->controller()->getWorkspace(1);
        $body = json_decode($response->getContent(), true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([], $body['wp_sites']);
        $this->assertFalse($body['connector_available']);
        $this->assertArrayHasKey('workspace', $body);
        $this->assertArrayHasKey('task_count', $body);
    }

    // ── call sites 5 and 6: billing ──────────────────────────────────

    public function test_billing_suspend_and_reactivate_do_not_throw(): void
    {
        $this->invokeBilling('suspendBillingForWorkspace', 1, 'subscription_cancelled');
        $this->invokeBilling('reactivateBillingForWorkspace', 1, 'subscription_active');

        $this->assertTrue(true, 'neither billing helper threw');
    }

    public function test_billing_invents_no_connector_record(): void
    {
        $this->invokeBilling('suspendBillingForWorkspace', 1, 'subscription_cancelled');

        // An absent feature suspends nothing. Creating a placeholder row would
        // be inventing state the platform cannot own.
        $this->assertFalse(
            \Illuminate\Support\Facades\Schema::hasTable('wp_site_connections'),
            'the guard must never create the table it guards against'
        );
    }

    public function test_billing_primary_operation_is_untouched_by_connector_absence(): void
    {
        // The connector helpers are optional work hanging off a subscription
        // change. The subscription is the primary operation and must survive
        // whether or not the connector exists.
        DB::table('subscriptions')->insert([
            'workspace_id' => 1, 'plan_id' => 1, 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->invokeBilling('suspendBillingForWorkspace', 1, 'subscription_cancelled');

        $this->assertSame('active',
            DB::table('subscriptions')->where('workspace_id', 1)->value('status'),
            'connector cleanup must not disturb subscription state');
    }

    // ── evidence ─────────────────────────────────────────────────────

    public function test_the_absence_is_reported_under_a_structured_marker(): void
    {
        $captured = $this->captureMarker(fn () => $this->controller()->listUsers($this->request(['per_page' => 5])));

        $this->assertCount(1, $captured);
        $this->assertSame('admin.list_users', $captured[0]['operation']);
        $this->assertContains('table:wp_site_connections', $captured[0]['missing']);
    }

    public function test_the_warning_is_deduplicated_per_operation(): void
    {
        $captured = $this->captureMarker(function () {
            // A webhook storm must not turn one missing table into thousands of
            // identical lines, but the first occurrence must still be visible.
            for ($i = 0; $i < 5; $i++) {
                $this->controller()->listUsers($this->request(['per_page' => 5]));
            }
        });

        $this->assertCount(1, $captured, 'five calls must produce exactly one warning');
    }

    public function test_distinct_operations_are_reported_separately(): void
    {
        $captured = $this->captureMarker(function () {
            $this->controller()->listUsers($this->request(['per_page' => 5]));
            $this->controller()->listConnectorSites($this->request());
        });

        $this->assertSame(
            ['admin.list_users', 'admin.list_connector_sites'],
            array_column($captured, 'operation')
        );
    }

    public function test_the_warning_carries_no_secrets_and_no_request_body(): void
    {
        $captured = $this->captureMarker(fn () => $this->controller()->listUsers($this->request([
            'per_page' => 5,
            'password' => 'super-secret-value',
            'api_key' => 'sk_live_should_never_be_logged',
        ])));

        $serialised = json_encode($captured);

        // The values are what must never appear. Key NAMES are not asserted on
        // here: "api_key" is a substring of the schema object the guard has to
        // name — column:api_keys.site_connection_id — so forbidding it would
        // fail on the guard's own subject matter rather than on a leak.
        foreach (['super-secret-value', 'sk_live_should_never_be_logged'] as $secret) {
            $this->assertStringNotContainsString($secret, $serialised,
                'a credential from the request reached the log');
        }

        // The stronger claim, and the one that actually forecloses leakage:
        // the context has exactly four keys, none of which is request-derived.
        // Nothing from the query string can appear because nothing carries it.
        $this->assertSame(['operation', 'missing', 'effect', 'remediation'], array_keys($captured[0]));
        $this->assertSame('admin.list_users', $captured[0]['operation']);
    }

    // ── and when the schema finally lands ────────────────────────────

    public function test_when_the_capability_reports_available_the_original_query_runs(): void
    {
        // The claim: the guard DELEGATES, it does not permanently replace the
        // query with a zero. Forcing the capability to report available must
        // make each call site attempt the real table again — proven here by the
        // attempt reaching the database and failing on the genuinely absent
        // table, rather than silently returning the guarded value.
        $this->forceAvailable();

        foreach ([
            'listUsers' => fn () => $this->controller()->listUsers($this->request(['per_page' => 5])),
            'listConnectorSites' => fn () => $this->controller()->listConnectorSites($this->request()),
            'getWorkspace' => fn () => $this->controller()->getWorkspace(1),
        ] as $name => $call) {
            try {
                $call();
                $this->fail("{$name} short-circuited even though the capability reported available");
            } catch (QueryException $e) {
                $this->assertStringContainsString('wp_site_connections', $e->getMessage(),
                    "{$name} must query the real table once the schema is reported present");
            }
        }
    }

    public function test_nothing_is_reported_missing_when_the_capability_is_available(): void
    {
        $this->forceAvailable();

        $captured = $this->captureMarker(function () {
            try { $this->controller()->listConnectorSites($this->request()); } catch (QueryException) {}
        });

        $this->assertSame([], $captured, 'a present schema must produce no absence warning');
    }

    // ── helpers ──────────────────────────────────────────────────────

    private function controller(): AdminController
    {
        return app(AdminController::class);
    }

    private function request(array $query = []): Request
    {
        $request = Request::create('/api/admin/probe', 'GET', $query);
        app()->instance('request', $request);

        return $request;
    }

    private function invokeBilling(string $method, int $workspaceId, string $reason): void
    {
        // Both helpers are private and use facades rather than constructor
        // dependencies, so the object needs no wiring to exercise them.
        $service = (new \ReflectionClass(StripeService::class))->newInstanceWithoutConstructor();
        $m = new \ReflectionMethod(StripeService::class, $method);
        $m->setAccessible(true);
        $m->invoke($service, $workspaceId, $reason);
    }

    /**
     * Force the cached capability answer.
     *
     * Cheaper and far safer than creating real tables: DDL commits implicitly
     * in MySQL and destroys the surrounding test transaction.
     */
    private function forceAvailable(): void
    {
        $p = new \ReflectionProperty(WpConnectorSchema::class, 'available');
        $p->setAccessible(true);
        $p->setValue(null, true);
    }

    /** @return array<int,array<string,mixed>> the contexts of every marker logged during $work */
    private function captureMarker(callable $work): array
    {
        $captured = [];
        Log::listen(function ($log) use (&$captured) {
            if ($log->message === WpConnectorSchema::MISSING_EVENT) {
                $captured[] = $log->context;
            }
        });

        $work();

        return $captured;
    }

    private function seedWorkspace(): void
    {
        DB::table('users')->insert([
            'id' => 1, 'name' => 'Cert', 'email' => 'cert@levelupgrowth.io',
            'password' => bcrypt('irrelevant'), 'is_platform_admin' => true, 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('workspaces')->insert([
            'id' => 1, 'name' => 'Cert', 'slug' => 'cert', 'timezone' => 'UTC',
            'created_by' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('workspace_users')->insert([
            'workspace_id' => 1, 'user_id' => 1, 'role' => 'owner',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
