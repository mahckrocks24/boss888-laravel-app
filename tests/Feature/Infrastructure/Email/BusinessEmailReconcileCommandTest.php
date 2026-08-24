<?php

namespace Tests\Feature\Infrastructure\Email;

use App\Engines\Infrastructure\Email\Console\ReconcileBusinessEmail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * INFRA888 — the scheduled Business Email reconciler.
 *
 * `EmailReconciliationService` has existed since E2 and is well behaved. What
 * did not exist was anything that CALLED it on a schedule: its only caller was
 * an admin endpoint someone had to remember to press. E8 recorded recovery as
 * PARTIAL for exactly this reason.
 *
 * The assertions that matter here are about honesty under failure, not about
 * the happy path. A reconciler that cannot reach the provider and says "0
 * drifted" is worse than no reconciler, because it manufactures the reassurance
 * an operator would otherwise go looking for.
 */
class BusinessEmailReconcileCommandTest extends TestCase
{
    use RefreshDatabase;

    private function workspace(int $id): void
    {
        $user = User::create([
            'name'     => 'infra-recon',
            'email'    => 'infra-recon-' . Str::random(8) . '@test.local',
            'password' => Hash::make(Str::random(32)),
            'is_admin' => 0,
        ]);

        DB::table('workspaces')->insertOrIgnore([
            'id' => $id, 'name' => 'recon-ws-' . $id,
            'slug' => 'recon-ws-' . $id . '-' . Str::random(6),
            'created_by' => $user->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function domain(int $wsId, string $domain): int
    {
        return (int) DB::table('email_domains')->insertGetId([
            'workspace_id' => $wsId,
            'domain'       => $domain,
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);
    }

    /** @test */
    public function it_is_registered_and_runnable(): void
    {
        $this->artisan('infra:reconcile-business-email --dry-run')->assertSuccessful();
    }

    /** @test */
    public function it_reports_plainly_when_there_is_nothing_to_reconcile(): void
    {
        $this->artisan('infra:reconcile-business-email')
            ->expectsOutputToContain('no email domains to reconcile')
            ->assertSuccessful();
    }

    /**
     * @test
     *
     * The load-bearing one. With the provider gates shut the reconciler cannot
     * see anything — and it must SAY that, not report a clean estate.
     */
    public function an_unreachable_provider_never_produces_a_clean_result(): void
    {
        $this->workspace(991000);
        $this->domain(991000, 'recon-canary.test');

        $this->artisan('infra:reconcile-business-email --dry-run')
            ->doesntExpectOutputToContain('clean (')
            ->expectsOutputToContain('INCONCLUSIVE')
            ->assertSuccessful();
    }

    /**
     * @test
     *
     * A pass in which every domain was inconclusive has confirmed nothing. That
     * is legitimate while the gates are closed, and it is also exactly what a
     * silently expired credential looks like — so it must be stated, not left
     * to be inferred from a reassuring "0 drifted".
     */
    public function a_pass_that_checked_nothing_says_so_out_loud(): void
    {
        $this->workspace(991001);
        $this->domain(991001, 'recon-canary-2.test');

        $this->artisan('infra:reconcile-business-email --dry-run')
            ->expectsOutputToContain('NOTHING WAS ACTUALLY CHECKED')
            ->assertSuccessful();
    }

    /**
     * @test
     *
     * One domain must never be able to end the pass for the others. A scheduled
     * reconciler's whole value is that it covers everything every time.
     */
    public function every_domain_is_attempted_even_when_earlier_ones_fail(): void
    {
        $this->workspace(991002);
        $this->domain(991002, 'first.test');
        $this->domain(991002, 'second.test');
        $this->domain(991002, 'third.test');

        $this->artisan('infra:reconcile-business-email --dry-run')
            ->expectsOutputToContain('reconciling 3 domain(s)')
            ->expectsOutputToContain('first.test')
            ->expectsOutputToContain('second.test')
            ->expectsOutputToContain('third.test')
            ->assertSuccessful();
    }

    /** @test */
    public function a_dry_run_records_no_observation_facts(): void
    {
        $this->workspace(991003);
        $this->domain(991003, 'dry.test');

        $before = DB::table('infra_observation_facts')->count();

        $this->artisan('infra:reconcile-business-email --dry-run')->assertSuccessful();

        $this->assertSame($before, DB::table('infra_observation_facts')->count(),
            'A dry run persisted observations. --dry-run must be safe to run anywhere.');
    }

    /** @test */
    public function a_single_domain_can_be_targeted(): void
    {
        $this->workspace(991004);
        $this->domain(991004, 'alpha.test');
        $only = $this->domain(991004, 'beta.test');

        $this->artisan("infra:reconcile-business-email --domain={$only} --dry-run")
            ->expectsOutputToContain('reconciling 1 domain(s)')
            ->expectsOutputToContain('beta.test')
            ->doesntExpectOutputToContain('alpha.test')
            ->assertSuccessful();
    }

    /** @test */
    public function it_never_mutates_desired_state(): void
    {
        $this->workspace(991005);
        $id = $this->domain(991005, 'immutable.test');

        $before = DB::table('email_domains')->where('id', $id)->first();

        $this->artisan('infra:reconcile-business-email')->assertSuccessful();

        $after = DB::table('email_domains')->where('id', $id)->first();

        // Reconciliation observes. A reconciler that repairs is one whose
        // reports you cannot trust, because it has already changed the thing
        // it is describing.
        $this->assertEquals(
            (array) $before,
            (array) $after,
            'Reconciliation modified desired state. It may only record observations.',
        );
    }

    /** @test */
    public function the_command_is_scheduled_hourly_without_overlapping(): void
    {
        $schedule = app(\Illuminate\Console\Scheduling\Schedule::class);

        $event = collect($schedule->events())->first(
            fn ($e) => str_contains($e->command ?? '', 'infra:reconcile-business-email')
        );

        $this->assertNotNull($event, 'The reconciler is not scheduled — the E8 gap is still open.');
        $this->assertSame('0 * * * *', $event->expression);
        $this->assertNotEmpty($event->withoutOverlapping,
            'A slow provider must not stack reconciliation passes.');
    }
}
