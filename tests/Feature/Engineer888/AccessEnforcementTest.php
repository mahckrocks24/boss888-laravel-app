<?php

namespace Tests\Feature\Engineer888;

use App\Core\Engineer888\Access\AccessAudit;
use App\Core\Engineer888\Access\Engineer888Access;
use App\Core\Engineer888\Access\Engineer888Capability;
use App\Core\Engineer888\Reasoning\CandidateValidator;
use App\Http\Middleware\DenyApiKeyAuth;
use App\Http\Middleware\RequireEngineer888Access;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Enforcement, not policy.
 *
 * AccessBoundaryTest proves the policy answers correctly. This proves the
 * answer is actually consulted on the way in — that there is one door, that
 * every route uses it, and that everybody who is not the canonical account
 * gets a response indistinguishable from the feature not existing.
 *
 * The distinction matters: Sprint 10.3 shipped a correct policy that nothing
 * called, which is a document, not a control.
 */
class AccessEnforcementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('levelup_e888_test', DB::connection()->getDatabaseName());
    }

    // ── one door ────────────────────────────────────────────────────────

    public function test_every_engineer888_route_is_behind_the_canonical_gate(): void
    {
        $routes = $this->engineerRoutes();
        $this->assertNotEmpty($routes, 'the Engineer888 route module is not registered');

        foreach ($routes as $route) {
            $middleware = $route->gatherMiddleware();

            $this->assertContains('auth.jwt', $middleware, "{$route->uri()} lost auth.jwt");
            $this->assertContains('admin', $middleware, "{$route->uri()} lost the admin gate");
            $this->assertContains(DenyApiKeyAuth::class, $middleware, "{$route->uri()} lost DenyApiKeyAuth");

            $gated = array_filter($middleware,
                fn ($m) => str_starts_with((string) $m, RequireEngineer888Access::class . ':'));

            $this->assertNotEmpty($gated,
                "{$route->uri()} does not pass through RequireEngineer888Access. Every route names the "
                . 'one capability it needs; a route that names none is a bypass.');
        }
    }

    public function test_every_gate_names_a_real_capability(): void
    {
        foreach ($this->engineerRoutes() as $route) {
            foreach ($route->gatherMiddleware() as $middleware) {
                if (! str_starts_with((string) $middleware, RequireEngineer888Access::class . ':')) { continue; }

                $capability = substr((string) $middleware, strlen(RequireEngineer888Access::class) + 1);

                $this->assertContains($capability, Engineer888Capability::ALL,
                    "{$route->uri()} names a capability that does not exist: {$capability}");
            }
        }
    }

    public function test_mutating_routes_never_carry_a_read_only_capability(): void
    {
        $readOnly = [Engineer888Capability::DISCOVER, Engineer888Capability::VIEW];

        foreach ($this->engineerRoutes() as $route) {
            if (! in_array('POST', $route->methods(), true)) { continue; }

            foreach ($route->gatherMiddleware() as $middleware) {
                if (! str_starts_with((string) $middleware, RequireEngineer888Access::class . ':')) { continue; }

                $capability = substr((string) $middleware, strlen(RequireEngineer888Access::class) + 1);

                $this->assertNotContains($capability, $readOnly,
                    "{$route->uri()} mutates but is gated only by {$capability}");
            }
        }
    }

    // ── the door is shut ────────────────────────────────────────────────

    /** @dataProvider unauthorisedIdentities */
    public function test_an_unauthorised_identity_gets_an_undifferentiated_404(string $label, callable $build): void
    {
        $this->grantCanonical();

        $response = $this->passThrough($build($this), Engineer888Capability::VIEW);

        $this->assertSame(404, $response->getStatusCode(), $label . ' reached Engineer888');

        // Compared against a 404 the framework really produced, not against a
        // string this module chose. The literal that used to be asserted here
        // was `{"message":"Not Found"}`, which matched no other 404 on the
        // platform: the status said "nothing here" while the body said "this
        // is the guarded thing", and sweeping /api/admin/engineer888/* told an
        // unauthorised admin exactly which routes existed. The claim worth
        // making is indistinguishability, so indistinguishability is what is
        // measured.
        $this->assertSame($this->genuineMiss()->getContent(), $response->getContent(),
            $label . ' received a body that differs from a route that genuinely does not exist — that is an oracle');
    }

    /**
     * The refusal must be indistinguishable in JSON too.
     *
     * The HTML 404 page carries no path, so an HTML-only comparison would pass
     * even if the JSON bodies disagreed — and JSON is what the console and the
     * companion actually receive. Here the framework's own message names the
     * path, so the genuine miss is fetched for a neighbouring URL and only the
     * path token is substituted.
     */
    public function test_the_refusal_is_indistinguishable_in_json_as_well(): void
    {
        $this->grantCanonical();

        $request = $this->requestAs(['id' => 2, 'email' => 'other.admin@levelupgrowth.io']);
        $request->headers->set('Accept', 'application/json');

        $denied = $this->passThrough($request, Engineer888Capability::VIEW);

        $absentPath = 'api/admin/engineer888/tasks-9f2a-does-not-exist';
        $genuine    = $this->getJson('/' . $absentPath);

        $this->assertSame(404, $denied->getStatusCode());
        $this->assertSame(404, $genuine->getStatusCode());
        $this->assertSame(
            str_replace($absentPath, 'api/admin/engineer888/tasks', $genuine->getContent()),
            $denied->getContent(),
            'the JSON refusal differs from the JSON the framework returns for a route that is not there'
        );
    }

    /** A 404 the framework produced, for a route that genuinely does not exist. */
    private function genuineMiss()
    {
        return $this->get('/api/admin/engineer888/tasks-9f2a-does-not-exist')->baseResponse;
    }

    public static function unauthorisedIdentities(): array
    {
        return [
            'an ordinary platform admin' => ['an ordinary platform admin',
                fn ($t) => $t->requestAs(['id' => 2, 'email' => 'other.admin@levelupgrowth.io'])],
            'a workspace user'  => ['a workspace user',
                fn ($t) => $t->requestAs(['id' => 42, 'email' => 'member@customer.test', 'is_platform_admin' => false])],
            'a customer'        => ['a customer',
                fn ($t) => $t->requestAs(['id' => 900, 'email' => 'buyer@example.test', 'is_platform_admin' => false])],
            'the right email on the wrong id' => ['the right email on the wrong id',
                fn ($t) => $t->requestAs(['id' => 7])],
            'the right id with the wrong email' => ['the right id with the wrong email',
                fn ($t) => $t->requestAs(['email' => 'mark@personal.test'])],
            'a suspended canonical account' => ['a suspended canonical account',
                fn ($t) => $t->requestAs(['status' => 'suspended'])],
            'no session at all' => ['no session at all',
                fn ($t) => Request::create('/api/admin/engineer888/tasks', 'GET')],
        ];
    }

    public function test_an_api_key_cannot_reach_engineer888_even_as_the_canonical_user(): void
    {
        $this->grantCanonical();

        $request = $this->requestAs();
        $request->attributes->set('auth_via', 'api_key');

        $this->assertSame(404, $this->passThrough($request, Engineer888Capability::VIEW)->getStatusCode());
    }

    public function test_the_shared_admin_token_cannot_reach_engineer888(): void
    {
        $this->grantCanonical();

        $request = $this->requestAs();
        $request->attributes->set('auth_via_claim', 'shared_admin_token');

        $this->assertSame(404, $this->passThrough($request, Engineer888Capability::VIEW)->getStatusCode());
    }

    public function test_a_revoked_grant_closes_the_door(): void
    {
        $this->grantCanonical();
        DB::table('engineering_access_grants')->update(['revoked_at' => now()->subMinute()]);

        $this->assertSame(404, $this->passThrough($this->requestAs(), Engineer888Capability::VIEW)->getStatusCode());
    }

    public function test_a_capability_outside_the_grant_is_404_even_for_the_canonical_user(): void
    {
        $this->grantCanonical([Engineer888Capability::DISCOVER, Engineer888Capability::VIEW]);

        $this->assertSame(200, $this->passThrough($this->requestAs(), Engineer888Capability::VIEW)->getStatusCode());
        $this->assertSame(404, $this->passThrough($this->requestAs(), Engineer888Capability::EXECUTE)->getStatusCode());
    }

    public function test_client_supplied_role_data_opens_nothing(): void
    {
        $this->grantCanonical();

        $request = $this->requestAs(['id' => 5, 'email' => 'nobody@levelupgrowth.io']);
        $request->merge(['is_platform_admin' => true, 'user_id' => 1,
                         'email' => Engineer888Access::CANONICAL_EMAIL]);
        $request->headers->set('X-User-Id', '1');
        $request->headers->set('X-Engineer888', 'allow');

        $this->assertSame(404, $this->passThrough($request, Engineer888Capability::VIEW)->getStatusCode());
    }

    // ── the door opens for exactly one account ──────────────────────────

    public function test_the_canonical_account_passes_for_every_granted_capability(): void
    {
        $this->grantCanonical();

        foreach (Engineer888Access::capabilitiesForCanonicalAdmin() as $capability) {
            $this->assertSame(200, $this->passThrough($this->requestAs(), $capability)->getStatusCode(),
                'the canonical account was refused ' . $capability);
        }
    }

    public function test_even_the_canonical_account_cannot_manage_access(): void
    {
        $this->grantCanonical();

        $this->assertSame(404,
            $this->passThrough($this->requestAs(), Engineer888Capability::MANAGE_ACCESS)->getStatusCode(),
            'nobody may change Engineer888 access from inside the application');
    }

    public function test_the_actor_is_attached_for_attribution_not_for_authorisation(): void
    {
        $this->grantCanonical();

        $request = $this->requestAs();
        $this->passThrough($request, Engineer888Capability::VIEW);

        $actor = $request->attributes->get('e888_actor');

        $this->assertSame(1, $actor['user_id']);
        $this->assertSame(Engineer888Access::CANONICAL_EMAIL, $actor['email']);
    }

    // ── audit ───────────────────────────────────────────────────────────

    public function test_a_grant_and_a_denial_are_both_recorded(): void
    {
        $this->grantCanonical();

        $this->passThrough($this->requestAs(), Engineer888Capability::VIEW);
        $this->passThrough($this->requestAs(['id' => 2]), Engineer888Capability::VIEW);

        $rows = AccessAudit::recent(10);
        $this->assertCount(2, $rows);

        $denied = collect($rows)->firstWhere('granted', 0);
        $granted = collect($rows)->firstWhere('granted', 1);

        $this->assertSame(Engineer888Access::DENY_NOT_CANONICAL_USER, $denied->reason,
            'the audit records WHICH check failed, even though the caller is told nothing');
        $this->assertNull($granted->reason);
        $this->assertSame(Engineer888Capability::VIEW, $granted->capability);
        $this->assertNotNull($granted->policy_version);
    }

    public function test_the_audit_carries_no_request_body(): void
    {
        $this->grantCanonical();

        $request = $this->requestAs();
        $request->merge(['secret' => 'do-not-log-me', 'fingerprint' => str_repeat('f', 64)]);
        $this->passThrough($request, Engineer888Capability::VIEW);

        $row = AccessAudit::recent(1)[0];

        foreach ((array) $row as $value) {
            $this->assertStringNotContainsString('do-not-log-me', (string) $value,
                'an audit that copies the secrets it audits doubles the cost of losing it');
        }
    }

    public function test_the_audit_table_has_no_update_path_in_this_code(): void
    {
        $source = file_get_contents(base_path('app/Core/Engineer888/Access/AccessAudit.php'));

        $this->assertStringNotContainsString('->update(', $source);
        $this->assertStringNotContainsString('->delete(', $source);
        $this->assertStringNotContainsString('truncate', $source);
    }

    // ── protected files ─────────────────────────────────────────────────

    /** @dataProvider protectedPaths */
    public function test_a_reasoning_candidate_cannot_rewrite_its_own_controls(string $path): void
    {
        $payload = [
            'problem_understanding' => 'x', 'assumptions' => [], 'unknowns' => [],
            'implementation_strategy' => 'x', 'files_affected' => [],
            'migrations' => ['required' => false, 'detail' => 'none'], 'risks' => [],
            'testing_strategy' => 'x', 'rollback' => 'x', 'confidence' => 'high',
            'file_changes' => [['path' => $path, 'action' => 'update', 'content' => "<?php\n// widened\n"]],
        ];

        $rules = array_column((new CandidateValidator(12, 60000, base_path()))->violations($payload), 'rule');

        $this->assertContains('unsafe_path', $rules, "{$path} must be outside Engineer888's write authority");
    }

    public static function protectedPaths(): array
    {
        return [
            ['app/Core/Engineer888/Access/Engineer888Access.php'],
            ['app/Core/Engineer888/Access/Engineer888Capability.php'],
            ['app/Core/Engineer888/Access/AccessAudit.php'],
            ['app/Http/Middleware/RequireEngineer888Access.php'],
            ['app/Http/Middleware/DenyApiKeyAuth.php'],
            ['app/Core/Engineer888/Approval/ApprovalBinding.php'],
            ['config/engineer888_access.php'],
            ['tests/Feature/Engineer888/AccessBoundaryTest.php'],
            ['tests/Feature/Engineer888/AccessEnforcementTest.php'],
        ];
    }

    // ── helpers ─────────────────────────────────────────────────────────

    /** @return array<int,\Illuminate\Routing\Route> */
    private function engineerRoutes(): array
    {
        return collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($r) => str_starts_with($r->uri(), 'api/admin/engineer888'))
            ->all();
    }

    /** Run the real middleware and return whatever it decided. */
    private function passThrough(Request $request, string $capability)
    {
        return (new RequireEngineer888Access())->handle(
            $request,
            fn () => response()->json(['ok' => true], 200),
            $capability
        );
    }

    public function requestAs(array $overrides = []): Request
    {
        $request = Request::create('/api/admin/engineer888/tasks', 'GET');

        $user = (object) array_merge([
            'id'                => Engineer888Access::CANONICAL_USER_ID,
            'email'             => Engineer888Access::CANONICAL_EMAIL,
            'name'              => 'Mark',
            'status'            => 'active',
            'is_platform_admin' => true,
            'mfa_enabled'       => false,
        ], $overrides);

        $request->setUserResolver(fn () => $user);
        $request->attributes->set('auth_via', 'jwt');

        return $request;
    }

    private function grantCanonical(?array $capabilities = null): void
    {
        DB::table('engineering_access_grants')
            ->where('user_id', Engineer888Access::CANONICAL_USER_ID)->delete();

        DB::table('engineering_access_grants')->insert([
            'user_id'        => Engineer888Access::CANONICAL_USER_ID,
            'email'          => Engineer888Access::CANONICAL_EMAIL,
            'capabilities'   => json_encode($capabilities ?? Engineer888Access::capabilitiesForCanonicalAdmin()),
            'policy_version' => Engineer888Access::POLICY_VERSION,
            'granted_by'     => 'test fixture',
            'granted_at'     => now(),
            'created_at'     => now(), 'updated_at' => now(),
        ]);
    }
}
