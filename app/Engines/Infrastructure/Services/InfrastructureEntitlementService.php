<?php

namespace App\Engines\Infrastructure\Services;

use App\Engines\Infrastructure\Models\InfraHostingAccount;
use App\Core\Tenancy\WorkspaceContext;
use Illuminate\Support\Facades\DB;

/**
 * Product entitlement for INFRA888 (directive 1C §3).
 *
 * Entitlement is a DISTINCT control from role, membership, approval and frontend
 * visibility. A workspace owner on an unentitled plan must be refused even though
 * they hold the highest role and could approve their own request.
 *
 * Reads `plans.features_json` — the same source `/workspace/status` already
 * exposes to the SPA as `plan.features`, so backend enforcement and frontend
 * visibility cannot disagree. Feature keys are configurable rather than hardcoded
 * so a commercial decision does not require a code deploy.
 *
 * FAILS CLOSED: no active subscription, no plan, missing key, or a non-truthy
 * value all deny. Absence is never treated as permission.
 *
 * NOTE ON MONEY: entitlement is not billing. `credit_cost 0` means infrastructure
 * consumes no AI credits — it does NOT mean the operation is free of commercial
 * price or provider cost (directive §10). Those live in infra_subscriptions and
 * infra_cost_entries.
 */
class InfrastructureEntitlementService
{
    public const DENY_NO_SUBSCRIPTION = 'no_active_subscription';
    public const DENY_NOT_ENTITLED    = 'not_entitled';
    public const DENY_LIMIT_REACHED   = 'allowance_reached';

    /**
     * @return array{allowed:bool, code:?string, message:?string, limit:?int, used:?int}
     */
    public function checkHostingProvision(int $wsId): array
    {
        $plan = $this->activePlan($wsId);

        if (!$plan) {
            return $this->deny(
                self::DENY_NO_SUBSCRIPTION,
                'Managed hosting is not included in your current plan.'
            );
        }

        $features = $this->features($plan);

        // Two gates: the section-level key and the product-level key. A plan may
        // expose Infrastructure (domains/email later) without hosting.
        if (!$this->truthy($features, config('infrastructure.entitlement.access_key', 'infrastructure_access'))) {
            return $this->deny(self::DENY_NOT_ENTITLED, 'Managed hosting is not included in your current plan.');
        }

        if (!$this->truthy($features, config('infrastructure.entitlement.hosting_key', 'hosting_access'))) {
            return $this->deny(self::DENY_NOT_ENTITLED, 'Managed hosting is not included in your current plan.');
        }

        // Allowance. Null / absent means "entitled but unmetered" — an explicit 0
        // means entitled-to-see, not entitled-to-create.
        $limitKey = config('infrastructure.entitlement.sites_limit_key', 'hosting_sites_limit');
        $limit = $features[$limitKey] ?? null;

        if ($limit !== null) {
            $limit = (int) $limit;
            $used = $this->activeHostingCount($wsId);

            if ($used >= $limit) {
                return [
                    'allowed' => false,
                    'code'    => self::DENY_LIMIT_REACHED,
                    'message' => $limit === 0
                        ? 'Managed hosting is not included in your current plan.'
                        : "You've used all {$limit} hosting services included in your plan.",
                    'limit'   => $limit,
                    'used'    => $used,
                ];
            }

            return ['allowed' => true, 'code' => null, 'message' => null, 'limit' => $limit, 'used' => $used];
        }

        return ['allowed' => true, 'code' => null, 'message' => null, 'limit' => null, 'used' => null];
    }

    /** Section-level visibility, used by the read endpoints. */
    public function canAccessInfrastructure(int $wsId): bool
    {
        $plan = $this->activePlan($wsId);

        return $plan
            ? $this->truthy($this->features($plan), config('infrastructure.entitlement.access_key', 'infrastructure_access'))
            : false;
    }

    /** Summary for the SPA so UI copy and backend enforcement agree. */
    public function summary(int $wsId): array
    {
        $check = $this->checkHostingProvision($wsId);

        return [
            'infrastructure_access' => $this->canAccessInfrastructure($wsId),
            'hosting' => [
                'can_provision' => $check['allowed'],
                'reason'        => $check['code'],
                'message'       => $check['message'],
                'limit'         => $check['limit'],
                'used'          => $check['used'],
            ],
            // Honest: these products do not exist yet.
            'domains'        => ['available' => false],
            'email_accounts' => ['available' => false],
        ];
    }

    // ------------------------------------------------------------- internals

    private function activePlan(int $wsId): ?object
    {
        return DB::table('subscriptions')
            ->join('plans', 'plans.id', '=', 'subscriptions.plan_id')
            ->where('subscriptions.workspace_id', $wsId)
            ->whereIn('subscriptions.status', ['active', 'trialing'])
            ->orderByDesc('subscriptions.id')
            ->select('plans.*')
            ->first();
    }

    private function features(object $plan): array
    {
        $raw = $plan->features_json ?? null;

        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }

        return is_array($raw) ? $raw : [];
    }

    private function truthy(array $features, string $key): bool
    {
        return array_key_exists($key, $features) && (bool) $features[$key];
    }

    /** Hosting accounts that consume allowance (terminated ones do not). */
    private function activeHostingCount(int $wsId): int
    {
        return WorkspaceContext::run($wsId, fn () => InfraHostingAccount::query()
            ->whereNotIn('state', ['terminated', 'failed'])
            ->count());
    }

    private function deny(string $code, string $message): array
    {
        return ['allowed' => false, 'code' => $code, 'message' => $message, 'limit' => null, 'used' => null];
    }
}
