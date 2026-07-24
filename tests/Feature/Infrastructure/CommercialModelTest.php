<?php

namespace Tests\Feature\Infrastructure;

use App\Engines\Infrastructure\States\PlanState;
use App\Engines\Infrastructure\States\ProductState;
use App\Engines\Infrastructure\States\RenewalState;
use App\Engines\Infrastructure\States\SubscriptionState;
use App\Engines\Infrastructure\States\UsageBehavior;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Phase 2A-1 commercial state machines and the usage-behaviour classification.
 *
 * Pure — no application, no database. Runs anywhere.
 */
class CommercialModelTest extends TestCase
{
    // ------------------------------------------------------------- product

    public function test_product_lifecycle_transitions(): void
    {
        $this->assertSame('draft', ProductState::initial());
        $this->assertTrue(ProductState::canTransition('draft', 'active'));
        $this->assertTrue(ProductState::canTransition('active', 'deprecated'));
        $this->assertTrue(ProductState::canTransition('deprecated', 'retired'));

        // A deprecated product may be revived after a pricing review.
        $this->assertTrue(ProductState::canTransition('deprecated', 'active'));
    }

    public function test_retired_product_is_terminal(): void
    {
        $this->assertContains('retired', ProductState::terminal());
        $this->assertFalse(ProductState::canTransition('retired', 'active'));

        $this->expectException(InvalidArgumentException::class);
        ProductState::assertTransition('retired', 'active');
    }

    public function test_deprecated_product_is_not_sellable_but_is_serviceable(): void
    {
        // The whole point of `deprecated`: stop selling, keep serving.
        $this->assertNotContains('deprecated', ProductState::sellable());
        $this->assertContains('deprecated', ProductState::serviceable());
        $this->assertContains('active', ProductState::sellable());
    }

    // ---------------------------------------------------------------- plan

    public function test_plan_versioning_lifecycle(): void
    {
        $this->assertTrue(PlanState::canTransition('draft', 'current'));
        $this->assertTrue(PlanState::canTransition('current', 'superseded'));
        // Rollback of a bad new version.
        $this->assertTrue(PlanState::canTransition('superseded', 'current'));
        $this->assertFalse(PlanState::canTransition('withdrawn', 'current'));
    }

    public function test_superseded_plan_still_resolves_for_grandfathering(): void
    {
        // Grandfathered subscribers sit on superseded versions; entitlement
        // resolution must still work for them.
        $this->assertContains('superseded', PlanState::resolvable());
        $this->assertNotContains('superseded', PlanState::purchasable());
        $this->assertContains('current', PlanState::purchasable());
    }

    // -------------------------------------------------------- subscription

    public function test_subscription_gains_trial_and_archived(): void
    {
        $this->assertContains('trial', SubscriptionState::states());
        $this->assertContains('archived', SubscriptionState::states());
    }

    public function test_trial_cannot_become_active_without_provisioning(): void
    {
        $this->assertFalse(SubscriptionState::canTransition('trial', 'active'));
        $this->assertTrue(SubscriptionState::canTransition('trial', 'pending_provisioning'));
        $this->assertTrue(SubscriptionState::canTransition('trial', 'pending_payment'));
    }

    public function test_mode_a_can_skip_payment_state(): void
    {
        // Manual contract + manual invoice: payment happened off-platform.
        $this->assertTrue(SubscriptionState::canTransition('draft', 'pending_provisioning'));
    }

    public function test_cancelled_leads_to_archived_and_archived_is_terminal(): void
    {
        // cancelled = no longer served; archived = retention elapsed, data gone.
        $this->assertTrue(SubscriptionState::canTransition('cancelled', 'archived'));
        $this->assertContains('archived', SubscriptionState::terminal());
        $this->assertFalse(SubscriptionState::canTransition('archived', 'active'));
    }

    public function test_trial_is_entitled_but_not_revenue_bearing(): void
    {
        $this->assertContains('trial', SubscriptionState::entitled());
        $this->assertNotContains('trial', SubscriptionState::revenueBearing());
        $this->assertContains('active', SubscriptionState::revenueBearing());
    }

    public function test_suspended_is_not_entitled(): void
    {
        $this->assertNotContains('suspended', SubscriptionState::entitled());
        $this->assertNotContains('cancelled', SubscriptionState::entitled());
    }

    // ------------------------------------------------------------- renewal

    public function test_renewal_lifecycle(): void
    {
        $this->assertSame('scheduled', RenewalState::initial());
        $this->assertTrue(RenewalState::canTransition('scheduled', 'due'));
        $this->assertTrue(RenewalState::canTransition('due', 'attempting'));
        $this->assertTrue(RenewalState::canTransition('attempting', 'renewed'));
        $this->assertTrue(RenewalState::canTransition('attempting', 'failed'));
    }

    public function test_failed_renewal_enters_dunning_not_terminal_failure(): void
    {
        // A failed attempt is recoverable; dunning is the recovery process.
        $this->assertTrue(RenewalState::canTransition('failed', 'dunning'));
        $this->assertTrue(RenewalState::canTransition('dunning', 'renewed'));
        $this->assertTrue(RenewalState::canTransition('dunning', 'abandoned'));
        $this->assertContains('abandoned', RenewalState::terminal());
    }

    public function test_renewed_renewal_cannot_be_reused(): void
    {
        $this->assertContains('renewed', RenewalState::terminal());
        $this->assertFalse(RenewalState::canTransition('renewed', 'attempting'));
    }

    public function test_scheduler_actionable_states(): void
    {
        $this->assertEqualsCanonicalizing(
            ['scheduled', 'due', 'dunning'],
            RenewalState::actionable()
        );
    }

    // ------------------------------------------------------ usage behaviour

    public function test_limits_do_not_all_behave_the_same(): void
    {
        // The central commercial insight of the usage model.
        $this->assertSame(UsageBehavior::HARD,     UsageBehavior::defaultForMetric('site_count'));
        $this->assertSame(UsageBehavior::HARD,     UsageBehavior::defaultForMetric('mailbox_count'));
        $this->assertSame(UsageBehavior::BILLABLE, UsageBehavior::defaultForMetric('storage_mb'));
        $this->assertSame(UsageBehavior::SOFT,     UsageBehavior::defaultForMetric('bandwidth_mb'));
        $this->assertSame(UsageBehavior::ADMINISTRATIVE, UsageBehavior::defaultForMetric('backup_retention_days'));
    }

    public function test_storage_never_hard_blocks(): void
    {
        // Refusing writes at 100.1% of quota breaks live sites and drives churn.
        $this->assertNotSame(UsageBehavior::HARD, UsageBehavior::defaultForMetric('storage_mb'));
        $this->assertContains(UsageBehavior::defaultForMetric('storage_mb'), UsageBehavior::chargeable());
    }

    public function test_only_hard_limits_block(): void
    {
        $this->assertSame([UsageBehavior::HARD], UsageBehavior::blocking());
        $this->assertNotContains(UsageBehavior::SOFT, UsageBehavior::blocking());
        $this->assertNotContains(UsageBehavior::BILLABLE, UsageBehavior::blocking());
    }

    public function test_administrative_limits_are_never_customer_facing(): void
    {
        $this->assertContains(UsageBehavior::ADMINISTRATIVE, UsageBehavior::internalOnly());
        $this->assertNotContains(UsageBehavior::ADMINISTRATIVE, UsageBehavior::notifying());
    }

    public function test_unknown_behaviour_is_rejected(): void
    {
        $this->assertFalse(UsageBehavior::isValid('whatever'));
        $this->expectException(InvalidArgumentException::class);
        UsageBehavior::assertValid('whatever');
    }

    public function test_unknown_metric_defaults_to_unmetered(): void
    {
        $this->assertSame(UsageBehavior::NONE, UsageBehavior::defaultForMetric('not_a_metric'));
    }

    // ------------------------------------------------------------ integrity

    public function test_every_commercial_machine_has_reachable_states(): void
    {
        foreach ([ProductState::class, PlanState::class, SubscriptionState::class, RenewalState::class] as $machine) {
            $states = $machine::states();
            $terminal = $machine::terminal();

            foreach ($machine::transitions() as $from => $targets) {
                $this->assertContains($from, $states, "{$machine}: '{$from}' undeclared");
                foreach ($targets as $to) {
                    $this->assertContains($to, $states, "{$machine}: '{$to}' undeclared");
                }
            }

            foreach ($states as $s) {
                if (!in_array($s, $terminal, true)) {
                    $this->assertNotEmpty($machine::transitions()[$s] ?? [], "{$machine}: '{$s}' is a dead end");
                }
            }
        }
    }
}
