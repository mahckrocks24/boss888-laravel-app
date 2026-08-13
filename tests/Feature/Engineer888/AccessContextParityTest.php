<?php

namespace Tests\Feature\Engineer888;

use App\Core\Engineer888\Access\Engineer888Access;
use App\Core\Engineer888\Access\Engineer888AccessContext as Ctx;
use App\Core\Engineer888\Access\Engineer888Capability as Cap;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * One policy, two callers.
 *
 * Engineer888Access used to read `auth_via` straight out of Request::attributes,
 * which meant the policy could only be evaluated inside the HTTP pipeline that
 * populated it. Anything else - a projection, a console command, a test -
 * arrived with no credential provenance and was refused MACHINE_PRINCIPAL. That
 * refusal was CORRECT; an unclassified credential must fail closed. What was
 * wrong is that there was no way to state provenance honestly from outside.
 *
 * Engineer888AccessContext is that statement. These tests exist to prove the
 * refactor moved the plumbing and not the rule: for every identity and every
 * credential shape, the HTTP path and the explicit-context path must reach the
 * same verdict AND the same named reason. A refactor that quietly widened
 * access would otherwise look exactly like a refactor that did not.
 */
class AccessContextParityTest extends TestCase
{
    use RefreshDatabase;

    private Engineer888Access $access;

    protected function setUp(): void
    {
        parent::setUp();
        $this->access = new Engineer888Access();
    }

    /**
     * Every case runs down both paths and the two must agree exactly.
     *
     * @dataProvider identities
     */
    public function test_http_and_context_paths_agree(
        string $who, ?string $via, ?string $claim, bool $expectAllowed, ?string $expectReason
    ): void {
        $user = $this->userFor($who);

        $request = Request::create('/api/admin/engineer888/x', 'GET');
        $request->setUserResolver(fn () => $user);
        if ($via !== null) { $request->attributes->set('auth_via', $via); }
        if ($claim !== null) { $request->attributes->set('auth_via_claim', $claim); }

        $http = $this->access->check($request, Cap::REVIEW_CANDIDATE);
        $ctx = $this->access->checkContext(new Ctx($user, $via, $claim), Cap::REVIEW_CANDIDATE);

        $this->assertSame($http['allowed'], $ctx['allowed'],
            "HTTP and context disagree on ALLOW for [{$who}/{$via}]");
        $this->assertSame($http['reason'], $ctx['reason'],
            "HTTP and context disagree on REASON for [{$who}/{$via}]");

        $this->assertSame($expectAllowed, $ctx['allowed'], "unexpected verdict for [{$who}/{$via}]");
        $this->assertSame($expectReason, $ctx['reason'], "unexpected reason for [{$who}/{$via}]");
    }

    public static function identities(): array
    {
        return [
            // who, auth_via, auth_via_claim, allowed, reason
            'canonical + jwt'                 => ['canonical', 'jwt', null, true, null],
            'canonical + jwt_cookie'          => ['canonical', 'jwt_cookie', null, true, null],
            'canonical + api_key'             => ['canonical', 'api_key', null, false, Engineer888Access::DENY_MACHINE],
            'canonical + unclassified'        => ['canonical', 'a_future_scheme', null, false, Engineer888Access::DENY_MACHINE],
            'canonical + no provenance'       => ['canonical', null, null, false, Engineer888Access::DENY_MACHINE],
            'canonical + shared admin token'  => ['canonical', 'jwt', 'shared_admin_token', false, Engineer888Access::DENY_SHARED_TOKEN],
            'second platform admin'           => ['second_admin', 'jwt', null, false, Engineer888Access::DENY_NOT_CANONICAL_USER],
            'ordinary user'                   => ['ordinary', 'jwt', null, false, Engineer888Access::DENY_NOT_CANONICAL_USER],
            'canonical id, wrong email'       => ['wrong_email', 'jwt', null, false, Engineer888Access::DENY_EMAIL_MISMATCH],
            'suspended canonical'             => ['suspended', 'jwt', null, false, Engineer888Access::DENY_INACTIVE],
            'canonical without grant'         => ['no_grant', 'jwt', null, false, Engineer888Access::DENY_NO_GRANT],
            'nobody'                          => ['none', 'jwt', null, false, Engineer888Access::DENY_NO_SESSION],
        ];
    }

    /**
     * A context cannot be talked into conferring access.
     *
     * forHuman() takes provenance as a required argument precisely so a caller
     * cannot omit it and inherit "human" by accident. Passing a machine value
     * produces a context the policy refuses, which is the whole design: the
     * constructor carries a claim, it does not grant anything.
     */
    public function test_a_context_carries_a_claim_not_a_permission(): void
    {
        $user = $this->userFor('canonical');
        $this->grant($user->id);

        $this->assertTrue(
            $this->access->allowsContext(Ctx::forHuman($user, 'jwt'), Cap::REVIEW_CANDIDATE)
        );

        foreach (['api_key', 'service_account', 'cron', '', 'JWT'] as $machine) {
            $this->assertFalse(
                $this->access->allowsContext(Ctx::forHuman($user, $machine), Cap::REVIEW_CANDIDATE),
                "credential '{$machine}' must not be treated as a human sign-in"
            );
        }

        $this->assertFalse($this->access->allowsContext(Ctx::none(), Cap::REVIEW_CANDIDATE));
    }

    /** The capability is still checked per capability, not once per session. */
    public function test_the_grant_is_read_per_capability(): void
    {
        $user = $this->userFor('canonical');
        $this->grant($user->id, ['engineer888.review_candidate']);

        $ctx = Ctx::forHuman($user, 'jwt');

        $this->assertTrue($this->access->allowsContext($ctx, Cap::REVIEW_CANDIDATE));
        $this->assertFalse($this->access->allowsContext($ctx, Cap::EXECUTE),
            'a grant that omits a capability must not confer it');
    }

    // ── fixtures ─────────────────────────────────────────────────────

    private function userFor(string $who): ?object
    {
        if ($who === 'none') { return null; }

        $canonicalId = Engineer888Access::CANONICAL_USER_ID;
        $canonicalEmail = Engineer888Access::CANONICAL_EMAIL;

        $spec = match ($who) {
            'canonical'    => [$canonicalId, $canonicalEmail, 'active', true, true],
            'no_grant'     => [$canonicalId, $canonicalEmail, 'active', true, false],
            'suspended'    => [$canonicalId, $canonicalEmail, 'suspended', true, true],
            'wrong_email'  => [$canonicalId, 'someone.else@levelupgrowth.io', 'active', true, true],
            'second_admin' => [990016, 'infra-approver-staging@levelupgrowth.io', 'active', true, true],
            'ordinary'     => [777, 'someone@example.com', 'active', false, false],
        };

        [$id, $email, $status, $isAdmin, $withGrant] = $spec;

        DB::table('users')->insertOrIgnore([
            'id' => $id, 'name' => 'Test', 'email' => $email,
            'password' => bcrypt('x'), 'status' => $status,
            'is_platform_admin' => $isAdmin, 'is_admin' => $isAdmin,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // The canonical grant is created by migration, so "no grant" is not
        // reachable by merely declining to insert one - it has to be removed.
        // Worth knowing: a fresh environment already trusts user 1, and only an
        // explicit revocation takes that away.
        if ($withGrant) {
            $this->grant($id);
        } else {
            DB::table('engineering_access_grants')->where('user_id', $id)->delete();
        }

        return \App\Models\User::find($id);
    }

    private function grant(int $userId, ?array $capabilities = null): void
    {
        DB::table('engineering_access_grants')->updateOrInsert(
            ['user_id' => $userId],
            [
                'email' => DB::table('users')->where('id', $userId)->value('email'),
                'capabilities' => json_encode($capabilities ?? [
                    'engineer888.discover', 'engineer888.view', 'engineer888.message',
                    'engineer888.create_task', 'engineer888.review_candidate',
                    'engineer888.approve_candidate', 'engineer888.execute',
                    'engineer888.approve_recovery', 'engineer888.approve_migration',
                ]),
                'policy_version' => Engineer888Access::POLICY_VERSION,
                'granted_by' => 'parity test',
                'granted_at' => now(),
                'revoked_at' => null,
                'created_at' => now(), 'updated_at' => now(),
            ]
        );
    }
}
