<?php

namespace App\Engines\Infrastructure\Policies;

use App\Engines\Infrastructure\Models\InfraHostingAccount;

/**
 * Hosting authorization. Implements the 0.1-F §5 permission matrix.
 *
 * Note the asymmetry between view/create and suspend/terminate: destructive
 * infrastructure operations are owner-or-platform-admin ONLY, in addition to the
 * `protected` approval gate applied upstream. Defence in depth — the approval gate
 * asks "did a human agree?", the policy asks "is this human allowed to ask?".
 *
 * This closes, for INFRA888, the platform-wide gap where any workspace `member`
 * can execute any engine action their plan allows (Phase 0 audit §7 — team.role
 * middleware guards only ~5 team-management routes).
 */
class InfraHostingAccountPolicy extends InfrastructurePolicy
{
    public function viewAny(?object $user, ?int $workspaceId = null): bool
    {
        return $this->roleIn($user?->id, $workspaceId) !== null;
    }

    public function view(?object $user, InfraHostingAccount $account): bool
    {
        return $this->roleIn($user?->id, (int) $account->workspace_id) !== null;
    }

    public function create(?object $user, ?int $workspaceId = null): bool
    {
        return $this->atLeast($this->roleIn($user?->id, $workspaceId), self::ROLE_ADMIN);
    }

    public function update(?object $user, InfraHostingAccount $account): bool
    {
        return $this->atLeast(
            $this->roleIn($user?->id, (int) $account->workspace_id),
            self::ROLE_ADMIN
        );
    }

    /** Destructive — owner only. */
    public function suspend(?object $user, InfraHostingAccount $account): bool
    {
        if ($this->isPlatformAdmin($user)) {
            return true;
        }

        return $this->atLeast(
            $this->roleIn($user?->id, (int) $account->workspace_id),
            self::ROLE_OWNER
        );
    }

    /** Destructive and irreversible — owner only. */
    public function terminate(?object $user, InfraHostingAccount $account): bool
    {
        if ($this->isPlatformAdmin($user)) {
            return true;
        }

        return $this->atLeast(
            $this->roleIn($user?->id, (int) $account->workspace_id),
            self::ROLE_OWNER
        );
    }

    /** Restores overwrite live data — owner only. */
    public function restoreBackup(?object $user, InfraHostingAccount $account): bool
    {
        if ($this->isPlatformAdmin($user)) {
            return true;
        }

        return $this->atLeast(
            $this->roleIn($user?->id, (int) $account->workspace_id),
            self::ROLE_OWNER
        );
    }

    /** Viewers may not see backup inventory. */
    public function viewBackups(?object $user, InfraHostingAccount $account): bool
    {
        return $this->atLeast(
            $this->roleIn($user?->id, (int) $account->workspace_id),
            self::ROLE_MEMBER
        );
    }
}
