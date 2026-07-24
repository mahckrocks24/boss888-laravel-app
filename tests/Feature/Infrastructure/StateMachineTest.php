<?php

namespace Tests\Feature\Infrastructure;

use App\Engines\Infrastructure\States\HostingState;
use App\Engines\Infrastructure\States\OperationState;
use App\Engines\Infrastructure\States\SubscriptionState;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * State-transition unit tests.
 *
 * Extends PHPUnit's TestCase directly rather than Tests\TestCase: these are pure
 * and need no application, no database, and no Mockery — so they run on this
 * install today, where RefreshDatabase-based tests cannot (dev dependencies are
 * absent because composer install was run --no-dev).
 */
class StateMachineTest extends TestCase
{
    public function test_subscription_initial_state_is_draft(): void
    {
        $this->assertSame('draft', SubscriptionState::initial());
    }

    public function test_subscription_allows_declared_transitions(): void
    {
        $this->assertTrue(SubscriptionState::canTransition('draft', 'pending_payment'));
        $this->assertTrue(SubscriptionState::canTransition('provisioning', 'active'));
        $this->assertTrue(SubscriptionState::canTransition('active', 'past_due'));
        $this->assertTrue(SubscriptionState::canTransition('past_due', 'grace_period'));
        $this->assertTrue(SubscriptionState::canTransition('suspended', 'active'));
    }

    public function test_subscription_rejects_illegal_jumps(): void
    {
        // The whole point of the machine: no skipping provisioning.
        $this->assertFalse(SubscriptionState::canTransition('draft', 'active'));
        $this->assertFalse(SubscriptionState::canTransition('pending_payment', 'active'));
        $this->assertFalse(SubscriptionState::canTransition('cancelled', 'active'));
    }

    public function test_cancelled_leads_to_archived_and_archived_is_terminal(): void
    {
        // Phase 2A-1 changed this DELIBERATELY. `cancelled` is no longer
        // terminal: once retention elapses the subscription becomes `archived`
        // (data removed). Conflating the two is how a host either deletes a
        // customer's data early or keeps it for years and inherits a compliance
        // problem.
        $this->assertNotContains('cancelled', SubscriptionState::terminal());
        $this->assertContains('archived', SubscriptionState::terminal());
        $this->assertTrue(SubscriptionState::canTransition('cancelled', 'archived'));

        // Cancelled still cannot jump back into service.
        $this->assertFalse(SubscriptionState::canTransition('cancelled', 'draft'));
        $this->assertFalse(SubscriptionState::canTransition('cancelled', 'active'));

        $this->expectException(InvalidArgumentException::class);
        SubscriptionState::assertTransition('archived', 'active');
    }

    public function test_assert_transition_rejects_unknown_states(): void
    {
        $this->expectException(InvalidArgumentException::class);
        SubscriptionState::assertTransition('draft', 'not_a_real_state');
    }

    public function test_failed_subscription_is_recoverable(): void
    {
        // An operator must be able to retry a failed provision.
        $this->assertTrue(SubscriptionState::canTransition('failed', 'pending_provisioning'));
    }

    public function test_entitled_states_exclude_suspended_and_cancelled(): void
    {
        $entitled = SubscriptionState::entitled();

        $this->assertContains('active', $entitled);
        $this->assertContains('grace_period', $entitled);
        $this->assertNotContains('suspended', $entitled);
        $this->assertNotContains('cancelled', $entitled);
        $this->assertNotContains('draft', $entitled);
    }

    public function test_hosting_requires_full_provisioning_chain(): void
    {
        $this->assertTrue(HostingState::canTransition('requested', 'approved'));
        $this->assertTrue(HostingState::canTransition('provisioning', 'configuring'));
        $this->assertTrue(HostingState::canTransition('configuring', 'active'));

        // Cannot leap from request straight to live.
        $this->assertFalse(HostingState::canTransition('requested', 'active'));
        $this->assertFalse(HostingState::canTransition('queued', 'active'));
    }

    public function test_hosting_degraded_is_distinct_from_failed_and_active(): void
    {
        // A paid, provisioned service failing health checks is neither "active"
        // nor "failed". Collapsing that into a boolean is how false success
        // reporting happens.
        $this->assertTrue(HostingState::canTransition('active', 'degraded'));
        $this->assertTrue(HostingState::canTransition('degraded', 'active'));
        $this->assertContains('degraded', HostingState::operational());
        $this->assertContains('active', HostingState::operational());
    }

    public function test_hosting_terminated_is_terminal(): void
    {
        $this->assertContains('terminated', HostingState::terminal());
        $this->assertFalse(HostingState::canTransition('terminated', 'active'));
    }

    public function test_operation_timeout_is_distinct_from_failure(): void
    {
        // A timeout means we do NOT know whether the provider applied the change,
        // so it must be its own state with its own recovery path. Phase 1B split
        // `failed` into failed_retryable (reaper may re-queue) and failed_terminal
        // (it must not).
        $this->assertTrue(OperationState::canTransition('running', 'timed_out'));
        $this->assertTrue(OperationState::canTransition('running', 'failed_retryable'));
        $this->assertTrue(OperationState::canTransition('running', 'failed_terminal'));
        $this->assertNotSame(OperationState::TIMED_OUT, OperationState::FAILED_TERMINAL);
        $this->assertNotSame(OperationState::FAILED_RETRYABLE, OperationState::FAILED_TERMINAL);

        // A timeout must NOT go straight back to the queue - provider state is
        // unknown, so it reconciles first.
        $this->assertFalse(OperationState::canTransition('timed_out', 'queued'));
        $this->assertTrue(OperationState::canTransition('timed_out', 'compensation_pending'));
        $this->assertContains('queued', OperationState::recoverable());
    }

    public function test_operation_succeeded_is_terminal(): void
    {
        $this->assertFalse(OperationState::canTransition('succeeded', 'running'));
        $this->assertContains('succeeded', OperationState::terminal());
    }

    public function test_every_transition_target_is_a_declared_state(): void
    {
        // Guards against typos silently creating unreachable states.
        foreach ([SubscriptionState::class, HostingState::class, OperationState::class] as $machine) {
            $states = $machine::states();

            foreach ($machine::transitions() as $from => $targets) {
                $this->assertContains($from, $states, "{$machine}: '{$from}' is not a declared state");

                foreach ($targets as $to) {
                    $this->assertContains($to, $states, "{$machine}: '{$to}' is not a declared state");
                }
            }
        }
    }

    public function test_every_non_terminal_state_has_an_exit(): void
    {
        foreach ([SubscriptionState::class, HostingState::class, OperationState::class] as $machine) {
            $terminal = $machine::terminal();

            foreach ($machine::states() as $state) {
                if (in_array($state, $terminal, true)) {
                    continue;
                }

                $this->assertNotEmpty(
                    $machine::transitions()[$state] ?? [],
                    "{$machine}: non-terminal state '{$state}' is a dead end"
                );
            }
        }
    }
}
