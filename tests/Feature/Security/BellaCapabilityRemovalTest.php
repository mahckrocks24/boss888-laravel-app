<?php

namespace Tests\Feature\Security;

use App\Core\Governance\PermissionRegistry;
use App\Http\Controllers\Api\Admin\BellaController;
use Illuminate\Support\Facades\DB;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

/**
 * ENTERPRISE888 E0.5 — Bella high-risk capability removal.
 *
 * E0 found that `adjust_credits` and `suspend_user` mutated billing and user
 * access with no approval, no governance call, no MFA and no cap, dispatched by
 * regex-scanning the model's own output, while customer-influenceable log
 * content sat in the prompt.
 *
 * These tests pin the removal at every layer. They deliberately assert on
 * ABSENCE as well as refusal, because a capability that is merely hidden from
 * the prompt is not removed.
 */
class BellaCapabilityRemovalTest extends TestCase
{
    private const REMOVED = ['adjust_credits', 'suspend_user'];
    private const SRC = '/var/www/levelup-staging/app/Http/Controllers/Api/Admin/BellaController.php';

    private function source(): string
    {
        return (string) file_get_contents(self::SRC);
    }

    /** Invoke a private method on a controller instance without booting HTTP. */
    private function invokePrivate(string $method, array $args)
    {
        $c = $this->app->make(BellaController::class);
        $m = new ReflectionMethod(BellaController::class, $method);
        $m->setAccessible(true);

        return $m->invokeArgs($c, $args);
    }

    private function allowedActions(): array
    {
        return $this->invokePrivate('allowedActions', []);
    }

    // ── registry ────────────────────────────────────────────────────────────

    public function test_removed_capabilities_are_absent_from_the_action_registry(): void
    {
        $allowed = $this->allowedActions();

        foreach (self::REMOVED as $cap) {
            $this->assertNotContains($cap, $allowed, "{$cap} must not be an allowed Bella action");
        }

        $this->assertCount(9, $allowed, 'Bella should have exactly 9 remaining actions');
    }

    public function test_every_remaining_action_is_read_only_or_own_memory(): void
    {
        $expected = ['list_users', 'get_analytics', 'get_workspace', 'get_queue',
                     'generate_report', 'get_audit_logs', 'get_engine_status',
                     'remember', 'forget'];

        sort($expected);
        $actual = $this->allowedActions();
        sort($actual);

        $this->assertSame($expected, $actual, 'the remaining Bella action set changed unexpectedly');
    }

    public function test_no_handler_methods_remain_for_removed_capabilities(): void
    {
        $methods = array_map(
            fn (ReflectionMethod $m) => $m->getName(),
            (new ReflectionClass(BellaController::class))->getMethods()
        );

        $this->assertNotContains('actionAdjustCredits', $methods);
        $this->assertNotContains('actionSuspendUser', $methods);
    }

    // ── dispatcher gate ─────────────────────────────────────────────────────

    /**
     * @dataProvider removedVariants
     */
    public function test_dispatcher_refuses_removed_capabilities_and_all_variants(string $variant): void
    {
        $result = $this->invokePrivate('executeAction', [$variant, ['workspace_id' => 1, 'amount' => 999, 'user_id' => 2]]);

        $this->assertArrayHasKey('code', $result, "no refusal code for '{$variant}'");
        $this->assertContains(
            $result['code'],
            ['CAPABILITY_DISABLED', 'CAPABILITY_UNKNOWN', 'CAPABILITY_INVALID'],
            "'{$variant}' was not refused"
        );
        $this->assertFalse($result['executed'] ?? true, "'{$variant}' must not report execution");
        $this->assertArrayNotHasKey('success', $result, "'{$variant}' must not report success");
    }

    public static function removedVariants(): array
    {
        return array_map(fn ($v) => [$v], [
            'adjust_credits', 'ADJUST_CREDITS', 'Adjust_Credits', ' adjust_credits ',
            'adjust-credits', 'adjust credits', 'adjustCredits', 'adjust.credits',
            'add_credits', 'deduct_credits', 'modify_balance', 'grant_credits',
            'suspend_user', 'SUSPEND_USER', 'suspend-user', 'suspend user',
            'suspendUser', 'suspend_account', 'disable_user', 'deactivate_user',
            'ban_user', 'block_user',
        ]);
    }

    public function test_unknown_and_malformed_actions_fail_closed(): void
    {
        foreach (['', '   ', 'totally_invented', 'drop_tables', '!!!', '123'] as $bogus) {
            $r = $this->invokePrivate('executeAction', [$bogus, []]);
            $this->assertArrayHasKey('code', $r, "'{$bogus}' should be refused with a code");
            $this->assertFalse($r['executed'] ?? true);
        }
    }

    public function test_multiple_action_blocks_in_one_response_are_refused(): void
    {
        $content = '```json
{"action":"get_queue"}
```
and also {"action":"adjust_credits","params":{"workspace_id":1,"amount":9999}}';

        $this->assertNull(
            $this->invokePrivate('extractActionBlock', [$content]),
            'an ambiguous multi-action response must be refused, not guessed'
        );
    }

    public function test_action_embedded_in_prose_cannot_smuggle_a_removed_capability(): void
    {
        $content = 'I will now run {"action": "adjust_credits", "params": {"workspace_id": 1, "amount": 5000}}';
        $block = $this->invokePrivate('extractActionBlock', [$content]);

        // Extraction may find it — the GATE is what must refuse it.
        if ($block !== null) {
            $r = $this->invokePrivate('executeAction', [$block['action'], $block['params'] ?? []]);
            $this->assertSame('CAPABILITY_DISABLED', $r['code'] ?? null);
            $this->assertFalse($r['executed'] ?? true);
        }
        $this->assertTrue(true);
    }

    // ── no mutation reaches the database ────────────────────────────────────

    public function test_refused_capabilities_change_no_credit_or_user_state(): void
    {
        $creditsBefore = (string) DB::table('credits')->sum('balance');
        $txBefore      = DB::table('credit_transactions')->count();
        $suspBefore    = DB::table('users')->where('status', 'suspended')->count();

        foreach (self::removedVariants() as [$variant]) {
            $this->invokePrivate('executeAction', [$variant, [
                'workspace_id' => 1, 'amount' => 100000, 'user_id' => 1, 'reason' => 'test',
            ]]);
        }

        $this->assertSame($creditsBefore, (string) DB::table('credits')->sum('balance'),
            'credit balances must be unchanged');
        $this->assertSame($txBefore, DB::table('credit_transactions')->count(),
            'no credit ledger entry may be created');
        $this->assertSame($suspBefore, DB::table('users')->where('status', 'suspended')->count(),
            'no user may be suspended');
    }

    public function test_controller_contains_no_write_to_credits_or_users(): void
    {
        $src = $this->source();

        // Strip the REMOVED_CAPABILITIES list and docblocks so alias names there
        // do not create false positives.
        $body = preg_replace('/private const REMOVED_CAPABILITIES = \[.*?\];/s', '', $src);
        $body = preg_replace('#/\*\*.*?\*/#s', '', (string) $body);

        /*
         * The assertion is about WRITES, not mentions. Bella legitimately READS
         * credits for reporting (`actionGetWorkspace`, platform context), so a
         * blanket ban on the string "table('credits')" would be wrong and would
         * fail for the right code — which is worse than no test.
         *
         * Every mutating call is therefore enumerated and checked against the
         * tables that matter.
         */
        preg_match_all(
            '/DB::table\(\s*.([a-z_]+).\s*\)((?!;).)*?->(update|insert|delete|updateOrInsert|increment|decrement)\(/s',
            (string) $body,
            $m
        );
        $mutatedTables = array_unique($m[1] ?? []);

        foreach (['credits', 'credit_transactions', 'users'] as $forbidden) {
            $this->assertNotContains(
                $forbidden,
                $mutatedTables,
                "BellaController must never write to `{$forbidden}`. Mutated tables found: "
                . implode(', ', $mutatedTables)
            );
        }

        // Whatever it does mutate must be its own surface plus the audit trail.
        foreach ($mutatedTables as $tbl) {
            $this->assertContains(
                $tbl,
                ['bella_conversations', 'bella_memory', 'audit_logs'],
                "unexpected mutating write to `{$tbl}` in BellaController"
            );
        }

        $this->assertStringNotContainsString("'suspended'", (string) $body,
            'BellaController must not set any status to suspended');
    }

    // ── prompt ──────────────────────────────────────────────────────────────

    public function test_prompt_does_not_advertise_removed_capabilities(): void
    {
        $prompt = $this->invokePrivate('buildSystemPrompt', [[
            'users_total' => 1, 'users_recent' => 0, 'workspaces_total' => 1,
            'active_sessions' => 0, 'tasks_pending' => 0, 'tasks_running' => 0,
            'tasks_completed' => 0, 'tasks_failed' => 0, 'credits_total_balance' => 0,
            'queue_pending_jobs' => 0, 'queue_failed_jobs' => 0, 'recent_audit_logs' => [],
            'engines_active' => 0, 'engines_total' => 0, 'subscriptions' => [],
        ], null, [], '']);

        $this->assertStringNotContainsString('**adjust_credits**', $prompt);
        $this->assertStringNotContainsString('**suspend_user**', $prompt);
        $this->assertStringNotContainsString('Suspend a user account', $prompt);
        $this->assertStringNotContainsString('Adjust workspace credits', $prompt);
    }

    // ── governance registry ─────────────────────────────────────────────────

    public function test_bella_capabilities_are_removed_from_the_permission_registry(): void
    {
        $keys = PermissionRegistry::keys();

        $this->assertNotContains('bella.adjust_credits', $keys,
            'removed under E0.5 — an unknown capability falls through to STRICT_DEFAULT deny');
        $this->assertNotContains('bella.suspend_user', $keys);
        $this->assertNotContains('bella.query_database', $keys, 'removed under GD-002');
    }

    public function test_human_admin_capabilities_are_untouched(): void
    {
        $keys = PermissionRegistry::keys();

        // E0.5 must NOT change legitimate admin capability declarations.
        $this->assertContains('billing.adjust_credits', $keys,
            'the human admin credit capability must remain — it is out of E0.5 scope');
    }

    public function test_admin_controller_equivalents_still_exist(): void
    {
        $admin = '/var/www/levelup-staging/app/Http/Controllers/Api/Admin/AdminController.php';
        $src = (string) file_get_contents($admin);

        $this->assertStringContainsString('function adjustCredits', $src,
            'the legitimate admin credit endpoint must be untouched by E0.5');
        $this->assertStringContainsString('function suspendUser', $src,
            'the legitimate admin suspension endpoint must be untouched by E0.5');
    }
}
