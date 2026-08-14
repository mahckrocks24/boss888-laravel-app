<?php

namespace Tests\Feature\Engineer888;

use App\Core\Engineer888\Access\Engineer888Access;
use App\Core\Engineer888\Access\Engineer888Capability;
use App\Core\Platform\Admin\AdminAccess;
use App\Core\Platform\Admin\AdminRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Engineer888 is private to one account, and everybody else is told nothing.
 *
 * AccessBoundaryTest already proves the door is shut. This file proves the two
 * things that are not the same as the door being shut:
 *
 *   1. A credential type nobody has classified is refused. The policy used to
 *      refuse `api_key` and admit everything else, which meant the NEXT
 *      authentication path anyone adds would have arrived pre-authorised.
 *   2. A platform admin who is not the canonical account is not told the
 *      module exists — the nav, the client-side slug map and the URL all agree
 *      that there is nothing there, and the refusal is byte-identical to a
 *      genuine typo.
 *
 * Both are omission properties, and omission is what a denial test cannot see:
 * a 403 passes "is it denied?" and fails "does it exist?".
 */
class SingleAccountOmissionTest extends TestCase
{
    use RefreshDatabase;

    private Engineer888Access $access;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('levelup_e888_test', DB::connection()->getDatabaseName());
        $this->access = new Engineer888Access();
        $this->grantCanonical();
    }

    // ── 1. the credential type is allowlisted, not denylisted ───────────

    /** @dataProvider unclassifiedCredentials */
    public function test_an_unclassified_credential_type_is_refused(mixed $via, string $why): void
    {
        $request = $this->canonicalRequest();
        $request->attributes->set('auth_via', $via);

        $result = $this->access->check($request, Engineer888Capability::DISCOVER);

        $this->assertFalse($result['allowed'], $why);
        $this->assertSame(Engineer888Access::DENY_MACHINE, $result['reason']);
    }

    public static function unclassifiedCredentials(): array
    {
        return [
            // The exact case that exposed this: a principal type the policy had
            // never heard of was admitted, because it was not the one value on
            // the denylist.
            'a machine principal that is not an api key' => ['machine',
                'an unrecognised credential must not inherit human authority'],
            'an authentication path added later'         => ['saml_sso',
                'a new sign-in path arrives unclassified and must fail closed'],
            'a service account'                          => ['service_account',
                'a service account has no name to attribute an approval to'],
            'no credential type recorded at all'         => [null,
                'a request that never passed a classifying middleware is not a human session'],
            'an empty credential type'                   => ['',
                'an empty marker is an absent marker'],
        ];
    }

    /** @dataProvider humanCredentials */
    public function test_the_two_real_human_sign_ins_still_work(string $via): void
    {
        $request = $this->canonicalRequest();
        $request->attributes->set('auth_via', $via);

        $this->assertTrue(
            $this->access->check($request, Engineer888Capability::DISCOVER)['allowed'],
            "{$via} is how a real person signs in; tightening the rule must not lock Mark out"
        );
    }

    public static function humanCredentials(): array
    {
        return [['jwt'], ['jwt_cookie']];
    }

    /**
     * The coupling test.
     *
     * The allowlist is only correct while it agrees with the middleware that
     * populates it. This reads the values the application actually sets and
     * asserts each one has been classified as human or machine — so adding a
     * new authentication path fails here, at the policy, rather than silently
     * granting Engineer888 to whoever arrives through it.
     */
    public function test_every_credential_type_the_platform_sets_has_been_classified(): void
    {
        $middleware = glob(app_path('Http/Middleware/*.php')) ?: [];
        $found = [];

        foreach ($middleware as $file) {
            preg_match_all(
                "/attributes->set\(\s*'auth_via'\s*,\s*'([a-z0-9_]+)'/i",
                (string) file_get_contents($file),
                $matches
            );
            foreach ($matches[1] as $value) {
                $found[$value] = basename($file);
            }
        }

        $this->assertNotEmpty($found, 'no middleware sets auth_via — this test is not reading the real source');

        $machine = ['api_key'];

        foreach ($found as $value => $file) {
            $this->assertTrue(
                in_array($value, Engineer888Access::HUMAN_CREDENTIALS, true)
                    || in_array($value, $machine, true),
                "{$file} sets auth_via='{$value}', which Engineer888Access has never classified. "
                . 'Decide whether it names a person (add it to HUMAN_CREDENTIALS) or a machine '
                . '(leave it out) — do not leave it unclassified.'
            );
        }
    }

    public function test_the_allowlist_does_not_quietly_contain_a_machine(): void
    {
        $this->assertNotContains('api_key', Engineer888Access::HUMAN_CREDENTIALS);
        $this->assertSame(['jwt', 'jwt_cookie'], Engineer888Access::HUMAN_CREDENTIALS);
    }

    // ── 2. omission, not refusal ────────────────────────────────────────

    public function test_a_second_platform_admin_is_not_told_the_module_exists(): void
    {
        $registry = AdminRegistry::make();
        $request  = $this->requestAsUser($this->otherPlatformAdmin());

        $visible = $registry->visibleFor($request);
        $slugMap = $registry->slugMapFor($request);

        $this->assertNotEmpty($visible, 'the other admin is a real admin and must still see the console');

        $surface = strtolower(json_encode([$visible, $slugMap]) ?: '');

        $this->assertStringNotContainsString('engineer888', $surface,
            'the nav, the titles and window.ADMIN_PAGES are one list; the module must be absent from all of it');

        foreach (self::MODULE_SLUGS as $slug) {
            $this->assertNull($registry->keyForSlug($request, $slug),
                "/admin/{$slug} must be indistinguishable from a typo for this admin");
        }
    }

    /**
     * Every page the module publishes. Named, not counted.
     *
     * A bare count told us a page had been added but not which one, and it
     * would have passed just as happily if a page had been added AND another
     * removed. The omission test above walks this same list, so a new
     * Engineer888 surface cannot ship without something asserting that the
     * other administrator cannot reach it either.
     *
     * `engineer888/decisions` joined on 2026-08-14.
     */
    private const MODULE_SLUGS = [
        'engineer888',
        'engineer888/chat',
        'engineer888/decisions',
        'engineer888/projects',
    ];

    public function test_the_canonical_admin_does_see_the_module(): void
    {
        $registry = AdminRegistry::make();
        $request  = $this->requestAsUser($this->canonicalUser());

        $slugs = $registry->slugMapFor($request);
        $e888  = array_values(array_filter($slugs, fn ($s) => str_starts_with((string) $s, 'engineer888')));

        sort($e888);
        $expected = self::MODULE_SLUGS;
        sort($expected);

        $this->assertSame($expected, $e888,
            'this test is the control: if the module is hidden from Mark too, the omission tests prove '
            . 'nothing. It names the pages rather than counting them, so adding one and dropping another '
            . 'cannot cancel out.');
    }

    /**
     * The registry hides pages a module refuses, and Engineer888 is the module
     * that refuses. If AdminAccess ever answered from a copy of the rules
     * instead of asking Engineer888Access, revoking the grant would stop
     * hiding the page — so the revocation is what is asserted, not the wiring.
     */
    public function test_revoking_the_grant_removes_the_pages_from_the_canonical_admin_too(): void
    {
        DB::table('engineering_access_grants')
            ->where('user_id', Engineer888Access::CANONICAL_USER_ID)
            ->update(['revoked_at' => now()]);

        $request = $this->requestAsUser($this->canonicalUser());
        $surface = strtolower(json_encode(AdminRegistry::make()->slugMapFor($request)) ?: '');

        $this->assertStringNotContainsString('engineer888', $surface,
            'the admin shell must read the module policy live, not a cached copy of it');
    }

    public function test_a_capability_prefix_nobody_implements_is_refused_rather_than_shown(): void
    {
        $access = new AdminAccess();

        $this->assertFalse(
            $access->allows($this->requestAsUser($this->canonicalUser()), 'somemodule.view'),
            'an unroutable capability is a defect, and a defect that grants access is the expensive kind'
        );
    }

    public function test_a_normal_user_sees_no_admin_console_at_all(): void
    {
        $request = $this->requestAsUser((object) [
            'id' => 2, 'email' => 'red@levelupgrowth.io', 'name' => 'Red',
            'status' => 'active', 'is_platform_admin' => false,
        ]);

        $this->assertSame([], AdminRegistry::make()->visibleFor($request));
    }

    // ── helpers ─────────────────────────────────────────────────────────

    private function canonicalUser(): object
    {
        return (object) [
            'id'                => Engineer888Access::CANONICAL_USER_ID,
            'email'             => Engineer888Access::CANONICAL_EMAIL,
            'name'              => 'Mark',
            'status'            => 'active',
            'is_platform_admin' => true,
            'mfa_enabled'       => false,
        ];
    }

    /** A real second platform admin exists on staging; this mirrors it. */
    private function otherPlatformAdmin(): object
    {
        return (object) [
            'id'                => 990016,
            'email'             => 'infra-approver-staging@levelupgrowth.io',
            'name'              => 'Infra Approver',
            'status'            => 'active',
            'is_platform_admin' => true,
            'mfa_enabled'       => false,
        ];
    }

    private function canonicalRequest(): Request
    {
        return $this->requestAsUser($this->canonicalUser());
    }

    private function requestAsUser(object $user): Request
    {
        $request = Request::create('/admin/engineer888/chat', 'GET');
        $request->setUserResolver(fn () => $user);
        $request->attributes->set('auth_via', 'jwt_cookie');

        return $request;
    }

    private function grantCanonical(): void
    {
        DB::table('engineering_access_grants')
            ->where('user_id', Engineer888Access::CANONICAL_USER_ID)->delete();

        DB::table('engineering_access_grants')->insert([
            'user_id'        => Engineer888Access::CANONICAL_USER_ID,
            'email'          => Engineer888Access::CANONICAL_EMAIL,
            'capabilities'   => json_encode(Engineer888Access::capabilitiesForCanonicalAdmin()),
            'policy_version' => Engineer888Access::POLICY_VERSION,
            'granted_by'     => 'test fixture',
            'granted_at'     => now(),
            'created_at'     => now(), 'updated_at' => now(),
        ]);
    }
}
