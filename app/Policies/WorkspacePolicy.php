<?php

namespace App\Policies;

use App\Core\Governance\GovernanceService;

/**
 * WORKSPACE POLICY — P0-B (2026-07-26).
 *
 * Model-scoped and repeatedly checked, so a Policy is justified here. All
 * authorization delegates to GovernanceService → PermissionRegistry; this class
 * holds no role logic of its own.
 *
 * `delete()` and `transferOwnership()` govern capabilities that DO NOT EXIST in
 * the platform yet — P0-A verified there is no workspace DELETE route and no
 * `deleteWorkspace`/`destroyWorkspace` method anywhere in `app/`. They are
 * defined now so the capability is governed BEFORE it is ever built, which is
 * the cheaper order.
 *
 * ⚠️ P0-B is SHADOW MODE — nothing calls these methods yet.
 */
class WorkspacePolicy
{
    public function __construct(private readonly GovernanceService $governance)
    {
    }

    public function update(mixed $actor, mixed $workspace = null): bool
    {
        return $this->governance->allows(
            'workspace.update',
            $actor,
            $this->workspaceId($workspace),
        );
    }

    public function adjustCredits(mixed $actor, mixed $workspace = null): bool
    {
        return $this->governance->allows(
            'billing.adjust_credits',
            $actor,
            $this->workspaceId($workspace),
        );
    }

    public function assignPlan(mixed $actor, mixed $workspace = null): bool
    {
        return $this->governance->allows(
            'billing.assign_plan',
            $actor,
            $this->workspaceId($workspace),
        );
    }

    /** PLANNED capability — no implementation exists (P0-A §9 N-01). */
    public function delete(mixed $actor, mixed $workspace = null): bool
    {
        return $this->governance->allows(
            'workspace.delete',
            $actor,
            $this->workspaceId($workspace),
        );
    }

    /** PLANNED capability — no implementation exists (P0-A §9 N-02). */
    public function transferOwnership(mixed $actor, mixed $workspace = null): bool
    {
        return $this->governance->allows(
            'workspace.transfer_ownership',
            $actor,
            $this->workspaceId($workspace),
        );
    }

    /** Capability keys this policy governs — used by the test suite. */
    public static function capabilities(): array
    {
        return [
            'workspace.update',
            'billing.adjust_credits',
            'billing.assign_plan',
            'workspace.delete',
            'workspace.transfer_ownership',
        ];
    }

    private function workspaceId(mixed $workspace): ?int
    {
        if (is_object($workspace) && isset($workspace->id)) {
            return (int) $workspace->id;
        }
        if (is_numeric($workspace)) {
            return (int) $workspace;
        }
        return null;
    }
}
