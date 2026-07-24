<?php

namespace App\Engines\Infrastructure\Services;

use App\Engines\Infrastructure\Models\InfraPlan;
use App\Engines\Infrastructure\Models\InfraSubscription;
use App\Engines\Infrastructure\Models\InfraSubscriptionMigration;
use App\Engines\Infrastructure\States\PlanState;
use App\Engines\Infrastructure\States\SubscriptionState;
use InvalidArgumentException;
use RuntimeException;

/**
 * Plans the movement of subscribers between plan versions.
 *
 * PHASE 2A-2 PLANS AND APPROVES. IT DOES NOT EXECUTE.
 *
 * That boundary is deliberate. Executing a migration changes the commercial
 * terms of live customers — price, allowances, possibly support level. It needs
 * its own proven workflow with per-subscription idempotency, rollback and
 * customer notification. Planning it, costing it and having a human approve it
 * is genuinely useful on its own, and is where the risk is actually assessed.
 *
 * The planner's job is to make the blast radius visible BEFORE anyone agrees to
 * it: how many customers, whether anyone pays more, whether anyone loses
 * allowance.
 */
class SubscriberMigrationPlanner
{
    /**
     * Assess a migration WITHOUT creating anything. Safe to call repeatedly.
     *
     * @return array{
     *   from:array, to:array, affected_count:int, price_increases:bool,
     *   allowances_decrease:bool, adverse:bool, warnings:array<int,string>,
     *   allowance_deltas:array<string,array{from:?int,to:?int}>
     * }
     */
    public function assess(int $fromPlanId, int $toPlanId): array
    {
        if ($fromPlanId === $toPlanId) {
            throw new InvalidArgumentException('Source and target plan must differ.');
        }

        $from = $this->findPlan($fromPlanId);
        $to   = $this->findPlan($toPlanId);

        if (!in_array($to->lifecycle_status, PlanState::resolvable(), true)) {
            throw new RuntimeException('The target plan must be current or superseded — never a draft or withdrawn plan.');
        }

        $affected = InfraSubscription::withoutWorkspaceScope()
            ->where('plan_id', $from->id)
            ->whereIn('state', SubscriptionState::entitled())
            ->count();

        $warnings = [];

        // Currency changes are not a migration; they are a different product.
        if ($from->currency !== $to->currency) {
            $warnings[] = 'Currency differs between plans. Subscribers cannot be migrated across currencies.';
        }

        if ($from->product_id !== $to->product_id) {
            $warnings[] = 'Plans belong to different products. Confirm this is a product migration, not a version bump.';
        }

        $fromAmount = (int) $from->recurring_amount_minor;
        $toAmount   = (int) $to->recurring_amount_minor;
        $priceUp    = $toAmount > $fromAmount;

        if ($priceUp) {
            $warnings[] = sprintf(
                'Price increases by %d minor units (%s). Existing subscribers may be contractually price-locked.',
                $toAmount - $fromAmount,
                $to->currency
            );
        }

        $deltas = $this->allowanceDeltas($from, $to);
        $decrease = false;

        foreach ($deltas as $metric => $d) {
            // NULL means unlimited. Going from unlimited to a number is a decrease.
            if ($d['from'] === null && $d['to'] !== null) {
                $decrease = true;
                $warnings[] = "Allowance '{$metric}' changes from unlimited to {$d['to']}.";
                continue;
            }

            if ($d['from'] !== null && $d['to'] !== null && $d['to'] < $d['from']) {
                $decrease = true;
                $warnings[] = "Allowance '{$metric}' decreases from {$d['from']} to {$d['to']}.";
            }
        }

        if ($affected === 0) {
            $warnings[] = 'No active subscribers on the source plan — this migration would affect nobody.';
        }

        return [
            'from' => $this->planSummary($from),
            'to'   => $this->planSummary($to),
            'affected_count'      => $affected,
            'price_increases'     => $priceUp,
            'allowances_decrease' => $decrease,
            'adverse'             => $priceUp || $decrease,
            'allowance_deltas'    => $deltas,
            'warnings'            => $warnings,
        ];
    }

    /**
     * Record a migration PLAN. Still does not move anybody — this creates the
     * record an approver acts on, with the impact snapshot attached.
     */
    public function plan(
        int $fromPlanId,
        int $toPlanId,
        string $strategy = InfraSubscriptionMigration::STRATEGY_AT_RENEWAL,
        ?string $reason = null,
        ?int $actorUserId = null
    ): InfraSubscriptionMigration {
        if (!in_array($strategy, InfraSubscriptionMigration::strategies(), true)) {
            throw new InvalidArgumentException('Unknown migration strategy.');
        }

        $impact = $this->assess($fromPlanId, $toPlanId);

        // A currency mismatch is not something an approver should be able to
        // wave through — it is structurally invalid.
        $from = $this->findPlan($fromPlanId);
        $to   = $this->findPlan($toPlanId);

        if ($from->currency !== $to->currency) {
            throw new RuntimeException('Subscribers cannot be migrated between plans in different currencies.');
        }

        // Moving customers mid-term when they are worse off is refused outright;
        // adverse changes must wait for renewal, when the customer can decide.
        if ($impact['adverse'] && $strategy === InfraSubscriptionMigration::STRATEGY_IMMEDIATE) {
            throw new RuntimeException(
                'An adverse migration (price increase or reduced allowances) cannot be applied immediately. '
                . 'Use at_renewal or at_term_end so customers are not changed mid-term.'
            );
        }

        return InfraSubscriptionMigration::create([
            'from_plan_id'                => $fromPlanId,
            'to_plan_id'                  => $toPlanId,
            'state'                       => InfraSubscriptionMigration::STATE_PLANNED,
            'strategy'                    => $strategy,
            'affected_subscription_count' => $impact['affected_count'],
            'impact_json'                 => $impact,
            'price_increases'             => $impact['price_increases'],
            'allowances_decrease'         => $impact['allowances_decrease'],
            'reason'                      => $reason,
            'planned_by'                  => $actorUserId,
        ]);
    }

    /**
     * Mark a plan approved. Execution is NOT implemented in this phase — the
     * record stops here, deliberately.
     */
    public function markApproved(int $migrationId, ?int $approverUserId, ?int $approvalId = null): InfraSubscriptionMigration
    {
        $m = InfraSubscriptionMigration::find($migrationId);

        if (!$m) {
            throw new RuntimeException('Migration plan not found.');
        }

        if ($m->state !== InfraSubscriptionMigration::STATE_PLANNED) {
            throw new RuntimeException("Only a planned migration can be approved (state: {$m->state}).");
        }

        $m->previous_state = $m->state;
        $m->state          = InfraSubscriptionMigration::STATE_APPROVED;
        $m->approved_by    = $approverUserId;
        $m->approved_at    = now();
        $m->approval_id    = $approvalId;
        $m->save();

        return $m;
    }

    private function allowanceDeltas(InfraPlan $from, InfraPlan $to): array
    {
        $fields = [
            'included_sites', 'included_storage_mb', 'included_bandwidth_mb',
            'included_mailboxes', 'included_domains', 'backup_retention_days',
            'included_staging_environments', 'included_restores_per_month', 'included_migrations',
        ];

        $out = [];

        foreach ($fields as $f) {
            $a = $from->{$f} === null ? null : (int) $from->{$f};
            $b = $to->{$f} === null ? null : (int) $to->{$f};

            if ($a !== $b) {
                $out[$f] = ['from' => $a, 'to' => $b];
            }
        }

        return $out;
    }

    private function planSummary(InfraPlan $p): array
    {
        return [
            'id'               => $p->id,
            'slug'             => $p->slug,
            'name'             => $p->name,
            'version'          => (int) $p->version,
            'lifecycle_status' => $p->lifecycle_status,
            'currency'         => $p->currency,
            'amount_minor'     => (int) $p->recurring_amount_minor,
        ];
    }

    private function findPlan(int $id): InfraPlan
    {
        $p = InfraPlan::find($id);

        if (!$p) {
            throw new RuntimeException('Plan not found.');
        }

        return $p;
    }
}
