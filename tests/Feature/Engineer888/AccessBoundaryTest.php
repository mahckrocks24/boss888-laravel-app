<?php

namespace Tests\Feature\Engineer888;

use App\Core\Engineer888\Access\Engineer888Access;
use App\Core\Engineer888\Access\Engineer888Capability;
use App\Core\Engineer888\Migration\MigrationClassifier;
use App\Core\Engineer888\Migration\MigrationRehearsal;
use App\Core\Engineer888\Migration\RehearsalGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The Engineer888 access boundary, and the eight rehearsal refusals.
 *
 * The access tests are almost all negative, and deliberately so: the valuable
 * claim is not "Mark can get in" but "changing one field does not let anybody
 * else in". Each test moves exactly one thing away from the canonical identity
 * and asserts the door stays shut, so a future refactor that collapses two
 * checks into one is caught by the test that stops failing.
 */
class AccessBoundaryTest extends TestCase
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

    // ── the canonical account ───────────────────────────────────────────

    public function test_the_canonical_account_holds_every_capability_except_managing_access(): void
    {
        $request = $this->requestAs();

        foreach (Engineer888Capability::ALL as $capability) {
            $result = $this->access->check($request, $capability);

            if ($capability === Engineer888Capability::MANAGE_ACCESS) {
                $this->assertFalse($result['allowed'],
                    'nobody may change Engineer888 access from inside the application');
                continue;
            }

            $this->assertTrue($result['allowed'], $capability . ': ' . $result['detail']);
        }

        $this->assertFalse($this->access->mayManageAccess($request));
    }

    // ── one field wrong is enough ───────────────────────────────────────

    /** @dataProvider deniedIdentities */
    public function test_a_non_canonical_identity_is_refused(array $overrides, string $expectedReason): void
    {
        $result = $this->access->check($this->requestAs($overrides), Engineer888Capability::DISCOVER);

        $this->assertFalse($result['allowed']);
        $this->assertSame($expectedReason, $result['reason'], $result['detail']);
    }

    public static function deniedIdentities(): array
    {
        return [
            'another platform admin' => [['id' => 2], Engineer888Access::DENY_NOT_CANONICAL_USER],
            'right id, wrong email'  => [['email' => 'someone.else@levelupgrowth.io'], Engineer888Access::DENY_EMAIL_MISMATCH],
            'suspended account'      => [['status' => 'suspended'], Engineer888Access::DENY_INACTIVE],
            'not a platform admin'   => [['is_platform_admin' => false], Engineer888Access::DENY_NOT_PLATFORM_ADMIN],
        ];
    }

    public function test_an_email_match_with_the_wrong_user_id_is_refused(): void
    {
        // The attacker controls the email but not the ID.
        $result = $this->access->check(
            $this->requestAs(['id' => 99, 'email' => Engineer888Access::CANONICAL_EMAIL]),
            Engineer888Capability::VIEW
        );

        $this->assertFalse($result['allowed']);
        $this->assertSame(Engineer888Access::DENY_NOT_CANONICAL_USER, $result['reason']);
    }

    public function test_no_session_is_refused(): void
    {
        $this->assertFalse($this->access->check(null, Engineer888Capability::DISCOVER)['allowed']);

        $bare = Request::create('/api/admin/engineer888/tasks', 'GET');
        $this->assertSame(Engineer888Access::DENY_NO_SESSION,
            $this->access->check($bare, Engineer888Capability::DISCOVER)['reason']);
    }

    /** @dataProvider machinePrincipals */
    public function test_a_machine_principal_is_refused_even_as_the_canonical_user(string $attribute, string $value, string $reason): void
    {
        $request = $this->requestAs();
        $request->attributes->set($attribute, $value);

        $result = $this->access->check($request, Engineer888Capability::DISCOVER);

        $this->assertFalse($result['allowed'],
            'a machine credential has no name to put on an approval');
        $this->assertSame($reason, $result['reason']);
    }

    public static function machinePrincipals(): array
    {
        return [
            'api key'      => ['auth_via', 'api_key', Engineer888Access::DENY_MACHINE],
            'shared token' => ['auth_via_claim', 'shared_admin_token', Engineer888Access::DENY_SHARED_TOKEN],
        ];
    }

    // ── the grant is not implied ────────────────────────────────────────

    public function test_platform_admin_status_alone_confers_nothing(): void
    {
        DB::table('engineering_access_grants')->delete();

        $result = $this->access->check($this->requestAs(), Engineer888Capability::DISCOVER);

        $this->assertFalse($result['allowed']);
        $this->assertSame(Engineer888Access::DENY_NO_GRANT, $result['reason']);
    }

    public function test_a_revoked_grant_is_refused(): void
    {
        DB::table('engineering_access_grants')->update(['revoked_at' => now()->subMinute()]);

        $this->assertSame(Engineer888Access::DENY_GRANT_REVOKED,
            $this->access->check($this->requestAs(), Engineer888Capability::VIEW)['reason']);
    }

    public function test_a_capability_outside_the_grant_is_refused(): void
    {
        DB::table('engineering_access_grants')->update([
            'capabilities' => json_encode([Engineer888Capability::DISCOVER, Engineer888Capability::VIEW]),
        ]);

        $request = $this->requestAs();

        $this->assertTrue($this->access->mayView($request));
        $this->assertFalse($this->access->mayApproveCandidate($request),
            'a grant is a list, not a switch');
    }

    public function test_client_supplied_role_data_grants_nothing(): void
    {
        $request = $this->requestAs(['id' => 7, 'is_platform_admin' => true]);
        $request->merge(['is_platform_admin' => true, 'engineer888' => true, 'role' => 'superadmin']);
        $request->headers->set('X-Engineer888-Access', 'true');

        $this->assertFalse($this->access->mayDiscover($request),
            'authorisation never reads anything the client controls');
    }

    // ── MFA is reported, never faked ────────────────────────────────────

    public function test_the_mfa_gap_is_recorded_rather_than_pretended_away(): void
    {
        config(['engineer888_access.require_mfa' => false]);

        $result = $this->access->check($this->requestAs(), Engineer888Capability::APPROVE_CANDIDATE);

        $this->assertTrue($result['allowed']);
        $this->assertTrue($result['mfa']['high_risk']);
        $this->assertTrue($result['mfa']['gap'], 'an unenrolled high-risk action must declare the gap');
        $this->assertFalse($result['mfa']['enrolled']);
    }

    public function test_when_policy_requires_mfa_an_unenrolled_account_is_blocked_not_waved_through(): void
    {
        config(['engineer888_access.require_mfa' => true]);

        $result = $this->access->check($this->requestAs(), Engineer888Capability::EXECUTE);

        $this->assertFalse($result['allowed']);
        $this->assertSame(Engineer888Access::DENY_MFA, $result['reason']);

        // Reading is unaffected: refusing to let Mark look at a blocked task
        // because he has not enrolled would cost availability and buy nothing.
        $this->assertTrue($this->access->mayView($this->requestAs()));
    }

    // ── the eight rehearsal refusals ────────────────────────────────────

    /** @dataProvider rehearsalRefusals */
    public function test_each_rehearsal_refusal_reason_is_reachable(callable $mutate, string $expected): void
    {
        $path = 'database/migrations/2026_01_01_000000_x.php';
        $bytes = "<?php\n// migration bytes\n";
        $row = $this->rehearsalRow($path, $bytes);

        $mutate($row);
        // __bytes is the fixture's own carrier for the proposed content, not a
        // column. Passing it into an update produced a raw SQL error rather
        // than the refusal the test was written to assert.
        DB::table('engineering_migration_rehearsals')->where('id', $row['id'] ?? 0)->update(
            array_diff_key($row, ['id' => true, '__bytes' => true])
        );

        $files = [$path => $row['__bytes'] ?? $bytes];
        $evaluation = (new RehearsalGate())->evaluate($files, 'cand-1', 'proj-1', 'levelup_e888_test');

        $this->assertFalse($evaluation['permitted']);
        $this->assertSame($expected, $evaluation['refusals'][0]['reason'],
            $evaluation['refusals'][0]['detail']);
    }

    public static function rehearsalRefusals(): array
    {
        $good = fn (array &$r) => null;

        return [
            'failed verdict' => [
                function (array &$r) { $r['status'] = MigrationRehearsal::FAILED_UP; },
                RehearsalGate::REHEARSAL_FAILED,
            ],
            'different project' => [
                function (array &$r) { $r['project_key'] = 'another-project'; },
                RehearsalGate::TARGET_ASSIGNMENT_CHANGED,
            ],
            'different database' => [
                function (array &$r) { $r['database'] = 'levelup_other_test'; },
                RehearsalGate::TARGET_ASSIGNMENT_CHANGED,
            ],
            'unknown classification' => [
                function (array &$r) { $r['classification'] = MigrationClassifier::UNKNOWN; },
                RehearsalGate::CLASSIFICATION_UNKNOWN,
            ],
            'no recovery plan' => [
                function (array &$r) { $r['recovery_plan'] = ''; $r['recovery_plan_fingerprint'] = null; },
                RehearsalGate::RECOVERY_PLAN_MISSING,
            ],
            'empty schema evidence' => [
                function (array &$r) {
                    $r['evidence'] = json_encode(['schema' => ['baseline' => '', 'after_up' => '',
                        'after_down' => '', 'after_second_up' => ''], 'target_proof' => ['proven' => true]]);
                },
                RehearsalGate::EVIDENCE_INCOMPLETE,
            ],
            'stale by age' => [
                function (array &$r) { $r['created_at'] = now()->subHours(48); },
                RehearsalGate::REHEARSAL_STALE,
            ],
        ];
    }

    public function test_a_missing_rehearsal_is_refused(): void
    {
        $evaluation = (new RehearsalGate())->evaluate(
            ['database/migrations/2026_01_01_000000_never_rehearsed.php' => "<?php\n"],
            'cand-1', 'proj-1', 'levelup_e888_test'
        );

        $this->assertSame(RehearsalGate::REHEARSAL_MISSING, $evaluation['refusals'][0]['reason']);
    }

    public function test_changed_migration_bytes_are_refused(): void
    {
        $path = 'database/migrations/2026_01_01_000000_x.php';
        $this->rehearsalRow($path, "<?php\n// original\n", true);

        $evaluation = (new RehearsalGate())->evaluate(
            [$path => "<?php\n// edited after rehearsal\n"], 'cand-1', 'proj-1', 'levelup_e888_test'
        );

        $this->assertSame(RehearsalGate::MIGRATION_BYTES_CHANGED, $evaluation['refusals'][0]['reason']);
    }

    public function test_an_exact_passed_rehearsal_permits_approval(): void
    {
        $path = 'database/migrations/2026_01_01_000000_x.php';
        $bytes = "<?php\n// exactly these bytes\n";
        $this->rehearsalRow($path, $bytes, true);

        $evaluation = (new RehearsalGate())->evaluate([$path => $bytes], 'cand-1', 'proj-1', 'levelup_e888_test');

        $this->assertTrue($evaluation['permitted'], json_encode($evaluation['refusals']));
        $this->assertNotEmpty(RehearsalGate::bindingParts($evaluation),
            'the rehearsal must be bound into the approval fingerprint');
    }

    public function test_a_non_migration_candidate_needs_no_rehearsal(): void
    {
        $evaluation = (new RehearsalGate())->evaluate([], 'cand-1', 'proj-1', 'levelup_e888_test');

        $this->assertFalse($evaluation['required']);
        $this->assertTrue($evaluation['permitted']);
    }

    // ── helpers ─────────────────────────────────────────────────────────

    private function grantCanonical(): void
    {
        // The migration seeds this grant and (user_id, policy_version) is
        // unique, so a bare insert collided and every test died in setUp.
        // Replacing keeps the fixture explicit about what it depends on rather
        // than inheriting it from a seed that may change.
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

    private function requestAs(array $overrides = []): Request
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

    /** @return array<string,mixed> */
    private function rehearsalRow(string $path, string $bytes, bool $insertOnly = false): array
    {
        $schema = ['baseline' => str_repeat('a', 64), 'after_up' => str_repeat('b', 64),
                   'after_down' => str_repeat('a', 64), 'after_second_up' => str_repeat('b', 64)];

        $row = [
            'rehearsal_uuid'   => 'reh-1',
            'candidate_uuid'   => 'cand-1',
            'migration_path'   => $path,
            'migration_hash'   => hash('sha256', $bytes),
            'classification'   => MigrationClassifier::REVERSIBLE_AUTOMATIC,
            'status'           => MigrationRehearsal::PASSED,
            'database'         => 'levelup_e888_test',
            'project_key'      => 'proj-1',
            'phpunit_config'   => 'phpunit.e888.xml',
            'evidence'         => json_encode(['schema' => $schema, 'target_proof' => ['proven' => true]]),
            'evidence_fingerprint' => hash('sha256', 'evidence'),
            'recovery_plan'    => json_encode(['down_command' => 'rollback']),
            'recovery_plan_fingerprint' => hash('sha256', 'plan'),
            'duration_ms'      => 10,
            'created_at'       => now(), 'updated_at' => now(),
        ];

        $id = DB::table('engineering_migration_rehearsals')->insertGetId($row);

        return $insertOnly ? $row : array_merge(['id' => $id, '__bytes' => $bytes], $row);
    }
}
