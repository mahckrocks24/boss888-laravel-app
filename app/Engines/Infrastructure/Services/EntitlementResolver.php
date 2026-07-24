<?php

namespace App\Engines\Infrastructure\Services;

use App\Core\Tenancy\WorkspaceContext;
use App\Engines\Infrastructure\Models\InfraPlan;
use App\Engines\Infrastructure\Models\InfraSubscription;
use App\Engines\Infrastructure\States\SubscriptionState;
use App\Engines\Infrastructure\States\UsageBehavior;

/**
 * Resolves the EFFECTIVE entitlements of a subscription.
 *
 * Two sources, deliberately separate:
 *
 *   1. TYPED ALLOWANCES on the plan (sites, storage, bandwidth, mailboxes,
 *      domains, staging, restores, migrations, retention). Hot path, indexable,
 *      checked on nearly every provisioning request.
 *
 *   2. SUPPLEMENTAL ENTITLEMENTS from infra_plan_entitlements — the optional,
 *      commercially-variable extras that product can add without a migration.
 *
 * GRANDFATHERING: entitlements resolve from the plan version the subscription
 * was PURCHASED on, not the current one. A superseded plan must still resolve,
 * which is why PlanState::resolvable() includes it.
 *
 * This phase RESOLVES entitlements. It does not enforce them — enforcement
 * against live usage is Phase 2A-4.
 */
class EntitlementResolver
{
    /**
     * @return array{
     *   subscription_id:int, plan_id:int, plan_version:int, entitled:bool,
     *   allowances:array<string,array{limit:?int,unit:string,behavior:string}>,
     *   features:array<string,array{value:bool|int|string|null,behavior:string,addon:bool}>,
     *   support_tier:?string, compute_class:?string
     * }
     */
    public function forSubscription(int $wsId, int $subscriptionId): array
    {
        return WorkspaceContext::run($wsId, function () use ($subscriptionId) {
            /** @var InfraSubscription|null $sub */
            $sub = InfraSubscription::query()->find($subscriptionId);

            if (!$sub) {
                throw new \RuntimeException('Subscription not found in this workspace.');
            }

            // Grandfathering: the plan the customer BOUGHT, not the current one.
            $plan = InfraPlan::query()
                ->with(['entitlements.definition'])
                ->find($sub->plan_id);

            if (!$plan) {
                throw new \RuntimeException('The plan for this subscription no longer resolves.');
            }

            return [
                'subscription_id' => $sub->id,
                'plan_id'         => $plan->id,
                'plan_version'    => (int) ($sub->plan_version_at_purchase ?? $plan->version),
                'entitled'        => in_array($sub->state, SubscriptionState::entitled(), true),
                'allowances'      => $this->allowances($plan),
                'features'        => $this->features($plan),
                'support_tier'    => $sub->support_tier ?: $plan->support_tier,
                'compute_class'   => $plan->compute_class,
            ];
        });
    }

    /** Typed numeric allowances, each carrying its limit behaviour. */
    public function allowances(InfraPlan $plan): array
    {
        $map = [
            'site_count'            => ['limit' => $plan->included_sites,                  'unit' => 'count'],
            'storage_mb'            => ['limit' => $plan->included_storage_mb,             'unit' => 'mb'],
            'bandwidth_mb'          => ['limit' => $plan->included_bandwidth_mb,           'unit' => 'mb'],
            'mailbox_count'         => ['limit' => $plan->included_mailboxes,              'unit' => 'count'],
            'domain_count'          => ['limit' => $plan->included_domains,                'unit' => 'count'],
            'staging_count'         => ['limit' => $plan->included_staging_environments,   'unit' => 'count'],
            'restore_count'         => ['limit' => $plan->included_restores_per_month,     'unit' => 'count'],
            'migration_count'       => ['limit' => $plan->included_migrations,             'unit' => 'count'],
            'backup_retention_days' => ['limit' => $plan->backup_retention_days,           'unit' => 'days'],
        ];

        $out = [];
        foreach ($map as $metric => $spec) {
            $out[$metric] = [
                'limit'    => $spec['limit'] === null ? null : (int) $spec['limit'],
                'unit'     => $spec['unit'],
                'behavior' => UsageBehavior::defaultForMetric($metric),
            ];
        }

        return $out;
    }

    /** Supplemental entitlements from the normalized tables. */
    public function features(InfraPlan $plan): array
    {
        $out = [];

        foreach ($plan->entitlements as $pe) {
            $def = $pe->definition;
            if (!$def || !$def->is_active) {
                continue;
            }

            $out[$def->key] = [
                'value'    => $pe->value(),
                'behavior' => $pe->effectiveBehavior(),
                'addon'    => $pe->isAddon(),
                'unit'     => $def->unit,
            ];
        }

        return $out;
    }

    /**
     * Convenience: does this subscription hold a boolean feature?
     * Returns false when the feature is unknown — fails closed.
     */
    public function hasFeature(int $wsId, int $subscriptionId, string $key): bool
    {
        $resolved = $this->forSubscription($wsId, $subscriptionId);

        if (!$resolved['entitled']) {
            return false;
        }

        return (bool) ($resolved['features'][$key]['value'] ?? false);
    }
}
