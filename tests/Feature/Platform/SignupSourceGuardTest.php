<?php

namespace Tests\Feature\Platform;

use App\Core\Platform\Schema\SignupSourceSchema;
use App\Http\Controllers\Api\Admin\AdminController;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * The users.signup_source compatibility guard.
 *
 * A separate never-shipped column from the same 2026-05-01 batch as the WP
 * Connector. Its unconditional read in listWorkspaces is what kept
 * GET /api/admin/workspaces at 500 even after the connector guard had fixed
 * every other query on that endpoint.
 *
 * Kept separate from WpConnectorSchemaGuardTest on purpose: two different
 * features that share a cause, and two remediations that must be able to end
 * independently.
 *
 * NO DDL — see WpConnectorSchemaGuardTest for why (MySQL commits implicitly on
 * DDL, which destroys RefreshDatabase's transaction). The column-present path
 * is proven by forcing the capability answer and asserting the call site
 * ATTEMPTS the real query.
 */
class SignupSourceGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('levelup_e888_test', DB::connection()->getDatabaseName());
        SignupSourceSchema::forget();
        $this->seedWorkspace();
    }

    protected function tearDown(): void
    {
        SignupSourceSchema::forget();
        parent::tearDown();
    }

    public function test_the_column_really_is_absent(): void
    {
        $this->assertFalse(SignupSourceSchema::available());
    }

    // ── the endpoint that was broken ─────────────────────────────────

    public function test_list_workspaces_returns_200_with_a_null_owner_source(): void
    {
        $response = $this->controller()->listWorkspaces($this->request(['per_page' => 10]));

        $this->assertSame(200, $response->getStatusCode());

        $rows = json_decode($response->getContent(), true)['data'];
        $this->assertNotEmpty($rows);

        foreach ($rows as $row) {
            $this->assertArrayHasKey('owner_source', $row);
            // null, not "unknown"/"manual"/"" — the platform does not know.
            $this->assertNull($row['owner_source']);
        }
    }

    public function test_no_unrelated_field_changes(): void
    {
        $row = json_decode(
            $this->controller()->listWorkspaces($this->request(['per_page' => 1]))->getContent(),
            true
        )['data'][0];

        // Everything the enrichment loop is contracted to produce is still
        // produced; only owner_source degrades.
        foreach ([
            'id', 'name', 'slug', 'plan_slug', 'plan_name', 'mode',
            'subscription_status', 'wp_sites_count', 'wp_active_count',
            'wp_suspended_count', 'owner_source',
        ] as $field) {
            $this->assertArrayHasKey($field, $row, "{$field} disappeared from the workspace row");
        }

        $this->assertSame('Cert', $row['name'], 'a real column was disturbed by the guard');
        $this->assertSame('full', $row['mode']);
    }

    public function test_the_signup_source_filter_is_skipped_rather_than_thrown_on(): void
    {
        // Applying it would throw; faking a match would claim data that does
        // not exist. It is skipped, and the skip is reported.
        $response = $this->controller()->listUsers($this->request([
            'per_page' => 10,
            'signup_source' => 'organic',
        ]));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertNotEmpty(json_decode($response->getContent(), true)['data']);
    }

    public function test_list_users_is_unaffected_when_no_filter_is_requested(): void
    {
        $captured = $this->captureMarker(
            fn () => $this->controller()->listUsers($this->request(['per_page' => 5]))
        );

        // The filter guard must not fire on a request that never asked to filter.
        $this->assertSame([], $captured);
    }

    // ── evidence ─────────────────────────────────────────────────────

    public function test_the_absence_is_reported_under_a_structured_marker(): void
    {
        $captured = $this->captureMarker(
            fn () => $this->controller()->listWorkspaces($this->request(['per_page' => 10]))
        );

        $this->assertCount(1, $captured);
        $this->assertSame('admin.list_workspaces', $captured[0]['operation']);
        $this->assertSame(['column:users.signup_source'], $captured[0]['missing']);
        $this->assertSame(['operation', 'missing', 'effect', 'remediation'], array_keys($captured[0]));
    }

    public function test_the_warning_is_deduplicated_across_rows_and_calls(): void
    {
        $captured = $this->captureMarker(function () {
            // Several workspaces, several page loads — still one line.
            for ($i = 0; $i < 4; $i++) {
                $this->controller()->listWorkspaces($this->request(['per_page' => 10]));
            }
        });

        $this->assertCount(1, $captured, 'a paginated window must not log once per row');
    }

    public function test_the_warning_carries_no_filter_value(): void
    {
        $captured = $this->captureMarker(fn () => $this->controller()->listUsers($this->request([
            'per_page' => 5,
            'signup_source' => 'a-value-that-must-not-be-logged',
        ])));

        $this->assertNotEmpty($captured);
        $this->assertStringNotContainsString(
            'a-value-that-must-not-be-logged',
            json_encode($captured),
            'a user-supplied filter value reached the log'
        );
    }

    // ── when the column lands ────────────────────────────────────────

    public function test_when_the_column_is_available_the_real_query_runs(): void
    {
        // The guard must DELEGATE, not permanently substitute null. Forcing the
        // capability to report available must send both call sites back to the
        // real column — proven by the attempt reaching the database and failing
        // on the genuinely absent column.
        $this->forceAvailable();

        try {
            $this->controller()->listWorkspaces($this->request(['per_page' => 5]));
            $this->fail('listWorkspaces short-circuited despite the column being reported present');
        } catch (QueryException $e) {
            $this->assertStringContainsString('signup_source', $e->getMessage());
        }

        try {
            $this->controller()->listUsers($this->request(['per_page' => 5, 'signup_source' => 'organic']));
            $this->fail('the listUsers filter short-circuited despite the column being reported present');
        } catch (QueryException $e) {
            $this->assertStringContainsString('signup_source', $e->getMessage());
        }
    }

    public function test_nothing_is_reported_missing_when_the_column_is_available(): void
    {
        $this->forceAvailable();

        $captured = $this->captureMarker(function () {
            try { $this->controller()->listWorkspaces($this->request(['per_page' => 5])); } catch (QueryException) {}
        });

        $this->assertSame([], $captured);
    }

    // ── the boundary is unchanged ────────────────────────────────────

    public function test_unauthenticated_requests_remain_denied(): void
    {
        // The guard changes what a permitted caller sees. It must not change
        // who is permitted.
        $this->getJson('/api/admin/workspaces')->assertStatus(401);
        $this->getJson('/api/admin/users')->assertStatus(401);
    }

    public function test_a_non_admin_bearer_is_still_refused(): void
    {
        $customer = DB::table('users')->insertGetId([
            'name' => 'Buyer', 'email' => 'buyer@example.test',
            'password' => bcrypt('irrelevant'), 'is_platform_admin' => false, 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('workspace_users')->insert([
            'workspace_id' => 1, 'user_id' => $customer, 'role' => 'member',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $token = app(\App\Core\Auth\RefreshTokenService::class)
            ->issueAccessToken(\App\Models\User::find($customer), \App\Models\Workspace::find(1), 'password', 1);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/admin/workspaces')
            ->assertForbidden();
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

    private function forceAvailable(): void
    {
        $p = new \ReflectionProperty(SignupSourceSchema::class, 'available');
        $p->setAccessible(true);
        $p->setValue(null, true);
    }

    /** @return array<int,array<string,mixed>> */
    private function captureMarker(callable $work): array
    {
        $captured = [];
        Log::listen(function ($log) use (&$captured) {
            if ($log->message === SignupSourceSchema::MISSING_EVENT) {
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
