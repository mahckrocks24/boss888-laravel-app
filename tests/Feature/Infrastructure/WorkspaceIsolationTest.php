<?php

namespace Tests\Feature\Infrastructure;

use App\Core\Tenancy\WorkspaceContext;
use App\Engines\Infrastructure\Models\InfraHostingAccount;
use App\Engines\Infrastructure\Services\HostingService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use RuntimeException;
use Tests\TestCase;

/**
 * Cross-workspace adversarial tests (control C7 of the 0.1-F design).
 *
 * ⚠️ EXECUTION STATUS: these CANNOT run on the staging install as of 2026-07-18.
 * `composer install` was run --no-dev, so Mockery is absent and RefreshDatabase
 * errors during setUp. This is pre-existing — the existing SystemHealthTest fails
 * 6/6 identically. The tests are written and committed so they run the moment dev
 * dependencies are installed. Isolation was instead verified empirically against
 * the real database (see scripts/infra888-verify-isolation.php).
 *
 * Mirrors the live probes that proved the 2026-07-15 IDOR: cross-tenant read,
 * cross-tenant write, and a CONTROL proving legitimate access still works. The
 * control is not optional — a guard that blocks everything is a different outage.
 */
class WorkspaceIsolationTest extends TestCase
{
    use DatabaseTransactions;

    private const WS_A = 9001;
    private const WS_B = 9002;

    protected function tearDown(): void
    {
        WorkspaceContext::reset();
        parent::tearDown();
    }

    private function makeAccount(int $wsId, string $name): InfraHostingAccount
    {
        return WorkspaceContext::run($wsId, fn () => InfraHostingAccount::create([
            'name'  => $name,
            'state' => 'requested',
        ]));
    }

    public function test_queries_are_scoped_to_the_active_workspace(): void
    {
        $this->makeAccount(self::WS_A, 'A-service');
        $this->makeAccount(self::WS_B, 'B-service');

        WorkspaceContext::run(self::WS_A, function () {
            $all = InfraHostingAccount::all();
            $this->assertCount(1, $all);
            $this->assertSame('A-service', $all->first()->name);
        });

        WorkspaceContext::run(self::WS_B, function () {
            $this->assertCount(1, InfraHostingAccount::all());
            $this->assertSame('B-service', InfraHostingAccount::first()->name);
        });
    }

    public function test_cross_workspace_find_returns_not_found(): void
    {
        $bAccount = $this->makeAccount(self::WS_B, 'B-service');

        $this->expectException(ModelNotFoundException::class);
        app(HostingService::class)->find(self::WS_A, $bAccount->id);
    }

    public function test_cross_workspace_account_is_absent_from_list(): void
    {
        $this->makeAccount(self::WS_B, 'B-service');

        $result = app(HostingService::class)->list(self::WS_A);

        $this->assertSame(0, $result['total']);
        $this->assertSame([], $result['items']);
    }

    /** CONTROL — the guard must not over-block legitimate access. */
    public function test_same_workspace_access_succeeds(): void
    {
        $account = $this->makeAccount(self::WS_A, 'A-service');

        $found = app(HostingService::class)->find(self::WS_A, $account->id);
        $this->assertSame($account->id, $found->id);

        $this->assertSame(1, app(HostingService::class)->list(self::WS_A)['total']);
    }

    public function test_workspace_id_is_auto_populated_from_context(): void
    {
        $account = $this->makeAccount(self::WS_A, 'A-service');
        $this->assertSame(self::WS_A, (int) $account->workspace_id);
    }

    public function test_querying_without_a_workspace_context_throws(): void
    {
        // Fails closed. A missing context must never mean "return everything".
        WorkspaceContext::reset();

        $this->expectException(RuntimeException::class);
        InfraHostingAccount::all();
    }

    public function test_workspace_id_cannot_be_reassigned(): void
    {
        $account = $this->makeAccount(self::WS_A, 'A-service');

        $this->expectException(RuntimeException::class);

        WorkspaceContext::run(self::WS_A, function () use ($account) {
            $account->workspace_id = self::WS_B;
            $account->save();
        });
    }

    public function test_context_is_released_even_when_the_callback_throws(): void
    {
        try {
            WorkspaceContext::run(self::WS_A, fn () => throw new RuntimeException('boom'));
        } catch (RuntimeException) {
            // expected
        }

        $this->assertFalse(
            WorkspaceContext::isSet(),
            'WorkspaceContext leaked after an exception — a later query could run in the wrong tenant.'
        );
    }

    public function test_nested_contexts_restore_the_outer_workspace(): void
    {
        WorkspaceContext::run(self::WS_A, function () {
            WorkspaceContext::run(self::WS_B, function () {
                $this->assertSame(self::WS_B, WorkspaceContext::id());
            });

            $this->assertSame(self::WS_A, WorkspaceContext::id());
        });
    }
}
