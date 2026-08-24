<?php

namespace App\Policies;

use App\Core\Governance\GovernanceService;
use App\Core\Governance\PermissionRegistry;

/**
 * USER POLICY — P0-B (2026-07-26).
 *
 * Created because user mutation is model-scoped and repeatedly checked, which
 * is exactly where a Policy earns its keep. It contains NO authorization logic
 * of its own — every method delegates to GovernanceService → PermissionRegistry,
 * satisfying the "zero duplicated authorization logic" success criterion.
 *
 * ⚠️ P0-B is SHADOW MODE. Nothing calls these methods yet; no controller was
 * modified. They resolve and are exercised only by the governance test suite.
 * Wiring `$this->authorize(...)` into controllers is P0-D work.
 *
 * PO-1 (Platform Owner invariant) is enforced here as defence in depth: a
 * platform admin may never delete or suspend a platform admin, regardless of
 * what the registry says.
 */
class UserPolicy
{
    public function __construct(private readonly GovernanceService $governance)
    {
    }

    public function viewAny(mixed $actor): bool
    {
        return $this->governance->allows('admin.user_create', $actor);
    }

    public function create(mixed $actor): bool
    {
        return $this->governance->allows('admin.user_create', $actor);
    }

    public function update(mixed $actor, mixed $target = null): bool
    {
        return $this->governance->allows('admin.user_update', $actor);
    }

    /**
     * PO-1: the Platform Owner cannot be deleted by any other principal.
     * Enforced before the registry is consulted.
     */
    public function delete(mixed $actor, mixed $target = null): bool
    {
        if ($this->targetIsPlatformAdmin($target) && ! $this->isSelf($actor, $target)) {
            return false;
        }
        return $this->governance->allows('admin.user_delete', $actor);
    }

    /** PO-1: platform admins cannot be suspended. Mirrors BellaController. */
    public function suspend(mixed $actor, mixed $target = null): bool
    {
        if ($this->targetIsPlatformAdmin($target)) {
            return false;
        }
        return $this->governance->allows('admin.user_suspend', $actor);
    }

    public function revokeSession(mixed $actor, mixed $target = null): bool
    {
        return $this->governance->allows('admin.session_revoke', $actor);
    }

    /** Capability keys this policy governs — used by the test suite. */
    public static function capabilities(): array
    {
        return [
            'admin.user_create',
            'admin.user_update',
            'admin.user_delete',
            'admin.user_suspend',
            'admin.session_revoke',
        ];
    }

    private function targetIsPlatformAdmin(mixed $target): bool
    {
        return is_object($target) && ! empty($target->is_platform_admin);
    }

    private function isSelf(mixed $actor, mixed $target): bool
    {
        return is_object($actor) && is_object($target)
            && isset($actor->id, $target->id)
            && (int) $actor->id === (int) $target->id;
    }
}
