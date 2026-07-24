<?php

namespace Tests\Feature\Infrastructure;

use App\Core\Tenancy\WorkspaceContext;
use App\Engines\Infrastructure\Models\InfraHostingAccount;
use App\Engines\Infrastructure\Policies\InfraHostingAccountPolicy;
use App\Engines\Infrastructure\Policies\InfraSubscriptionPolicy;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Permission-denial tests (control C4 / C7 of the 0.1-F design).
 *
 * ⚠️ EXECUTION STATUS: cannot run on the staging install as of 2026-07-18 —
 * Mockery is absent (composer install --no-dev), so RefreshDatabase errors in
 * setUp. Pre-existing condition affecting the whole suite, not INFRA888.
 *
 * These assert the thing the platform currently does NOT do: role-gate engine
 * writes. Phase 0 audit §7 — team.role middleware guards only ~5 team-management
 * routes, so any workspace `member` can execute any engine action their plan
 * allows. Destructive infrastructure operations must be owner-only.
 */
class PermissionDenialTest extends TestCase
{
    use DatabaseTransactions;

    private const WS = 9101;

    /**
     * workspace_users has FKs to BOTH workspaces and users, so the parent rows
     * must exist. Everything here is rolled back by DatabaseTransactions.
     */
    private function ensureWorkspace(int $wsId): void
    {
        if (DB::table('workspaces')->where('id', $wsId)->exists()) {
            return;
        }

        DB::table('users')->insertOrIgnore([
            'id' => 9000 + $wsId % 1000, 'name' => 'probe-creator',
            'email' => "probe-creator-{$wsId}@infra888.invalid", 'password' => 'x',
        ]);

        DB::table('workspaces')->insert([
            'id' => $wsId, 'name' => "INFRA888 probe {$wsId}",
            'slug' => "infra888-probe-{$wsId}", 'created_by' => 9000 + $wsId % 1000,
        ]);
    }

    private function member(int $userId, string $role): object
    {
        $this->ensureWorkspace(self::WS);

        DB::table('users')->insertOrIgnore([
            'id' => $userId, 'name' => "probe-{$role}",
            'email' => "probe-{$userId}@infra888.invalid", 'password' => 'x',
        ]);

        DB::table('workspace_users')->insert([
            'workspace_id' => self::WS,
            'user_id'      => $userId,
            'role'         => $role,
        ]);

        return (object) ['id' => $userId, 'is_platform_admin' => false];
    }

    private function account(): InfraHostingAccount
    {
        $this->ensureWorkspace(self::WS);

        return WorkspaceContext::run(self::WS, fn () => InfraHostingAccount::create([
            'name'  => 'Service',
            'state' => 'active',
        ]));
    }

    protected function tearDown(): void
    {
        WorkspaceContext::reset();
        parent::tearDown();
    }

    public function test_non_member_cannot_view(): void
    {
        $policy = new InfraHostingAccountPolicy();
        $outsider = (object) ['id' => 8888, 'is_platform_admin' => false];

        $this->assertFalse($policy->view($outsider, $this->account()));
        $this->assertFalse($policy->viewAny($outsider, self::WS));
    }

    public function test_viewer_can_view_but_cannot_mutate(): void
    {
        $viewer = $this->member(9110, 'viewer');
        $account = $this->account();
        $policy = new InfraHostingAccountPolicy();

        $this->assertTrue($policy->view($viewer, $account));
        $this->assertFalse($policy->update($viewer, $account));
        $this->assertFalse($policy->suspend($viewer, $account));
        $this->assertFalse($policy->terminate($viewer, $account));
        $this->assertFalse($policy->viewBackups($viewer, $account));
    }

    public function test_member_cannot_perform_destructive_operations(): void
    {
        // This is the platform-wide gap being closed for INFRA888.
        $member = $this->member(9111, 'member');
        $account = $this->account();
        $policy = new InfraHostingAccountPolicy();

        $this->assertTrue($policy->view($member, $account));
        $this->assertTrue($policy->viewBackups($member, $account));
        $this->assertFalse($policy->update($member, $account));
        $this->assertFalse($policy->suspend($member, $account));
        $this->assertFalse($policy->terminate($member, $account));
        $this->assertFalse($policy->restoreBackup($member, $account));
    }

    public function test_admin_can_manage_but_cannot_destroy(): void
    {
        $admin = $this->member(9112, 'admin');
        $account = $this->account();
        $policy = new InfraHostingAccountPolicy();

        $this->assertTrue($policy->update($admin, $account));
        $this->assertTrue($policy->create($admin, self::WS));
        $this->assertFalse($policy->suspend($admin, $account), 'suspend is owner-only');
        $this->assertFalse($policy->terminate($admin, $account), 'terminate is owner-only');
    }

    public function test_owner_can_perform_destructive_operations(): void
    {
        $owner = $this->member(9113, 'owner');
        $account = $this->account();
        $policy = new InfraHostingAccountPolicy();

        $this->assertTrue($policy->suspend($owner, $account));
        $this->assertTrue($policy->terminate($owner, $account));
        $this->assertTrue($policy->restoreBackup($owner, $account));
    }

    public function test_platform_admin_may_override_destructive_operations(): void
    {
        $platformAdmin = (object) ['id' => 9114, 'is_platform_admin' => true];
        $policy = new InfraHostingAccountPolicy();

        $this->assertTrue($policy->terminate($platformAdmin, $this->account()));
    }

    public function test_only_owner_may_purchase_and_cancel_subscriptions(): void
    {
        $policy = new InfraSubscriptionPolicy();

        $this->assertFalse($policy->purchase($this->member(9120, 'admin'), self::WS));
        $this->assertFalse($policy->purchase($this->member(9121, 'member'), self::WS));
        $this->assertTrue($policy->purchase($this->member(9122, 'owner'), self::WS));
    }

    public function test_null_user_is_always_denied(): void
    {
        $policy = new InfraHostingAccountPolicy();

        $this->assertFalse($policy->viewAny(null, self::WS));
        $this->assertFalse($policy->view(null, $this->account()));
        $this->assertFalse($policy->terminate(null, $this->account()));
    }

    public function test_role_in_another_workspace_grants_nothing_here(): void
    {
        $this->ensureWorkspace(9999);
        DB::table('users')->insertOrIgnore([
            'id' => 9130, 'name' => 'probe-outsider',
            'email' => 'probe-9130@infra888.invalid', 'password' => 'x',
        ]);
        DB::table('workspace_users')->insert([
            'workspace_id' => 9999,
            'user_id'      => 9130,
            'role'         => 'owner',
        ]);

        $outsider = (object) ['id' => 9130, 'is_platform_admin' => false];

        $this->assertFalse((new InfraHostingAccountPolicy())->view($outsider, $this->account()));
    }
}
