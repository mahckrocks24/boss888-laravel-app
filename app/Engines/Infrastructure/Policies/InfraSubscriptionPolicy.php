<?php

namespace App\Engines\Infrastructure\Policies;

use App\Engines\Infrastructure\Models\InfraSubscription;

/**
 * Infrastructure subscription authorization.
 *
 * Commercial actions (purchase, cancel, change term) are owner-level: they create
 * or end financial obligations, including multi-year ones.
 */
class InfraSubscriptionPolicy extends InfrastructurePolicy
{
    public function viewAny(?object $user, ?int $workspaceId = null): bool
    {
        return $this->roleIn($user?->id, $workspaceId) !== null;
    }

    public function view(?object $user, InfraSubscription $subscription): bool
    {
        return $this->roleIn($user?->id, (int) $subscription->workspace_id) !== null;
    }

    /** Creates a financial obligation — owner only. */
    public function purchase(?object $user, ?int $workspaceId = null): bool
    {
        return $this->atLeast($this->roleIn($user?->id, $workspaceId), self::ROLE_OWNER);
    }

    public function cancel(?object $user, InfraSubscription $subscription): bool
    {
        if ($this->isPlatformAdmin($user)) {
            return true;
        }

        return $this->atLeast(
            $this->roleIn($user?->id, (int) $subscription->workspace_id),
            self::ROLE_OWNER
        );
    }

    /** Only platform admins may alter pinned commercial terms. */
    public function amendTerms(?object $user, InfraSubscription $subscription): bool
    {
        return $this->isPlatformAdmin($user);
    }
}
