<?php

namespace App\Engines\Infrastructure\Policies;

use Illuminate\Support\Facades\DB;

/**
 * Shared authorization primitives for INFRA888 (control C4 of the 0.1-F design).
 *
 * The platform has NO policy layer at all — `app/Policies` does not exist and there
 * is no Gate/authorize() infrastructure anywhere (Phase 0 audit §7). Isolation is
 * per-handler $wsId threading, which is why the 2026-07-15 IDOR sweep needed seven
 * patch rounds across nine engines.
 *
 * Roles come from the existing workspace_users.role enum
 * (owner|admin|member|viewer). No new role system is introduced.
 *
 * Deliberate: cross-workspace access returns FALSE, and controllers map that to
 * 404 rather than 403. A 403 confirms the resource exists, which is itself a small
 * cross-tenant information leak. This matches the behaviour verified correct on
 * 2026-07-15 (cross-tenant GET returned 404).
 */
abstract class InfrastructurePolicy
{
    public const ROLE_OWNER  = 'owner';
    public const ROLE_ADMIN  = 'admin';
    public const ROLE_MEMBER = 'member';
    public const ROLE_VIEWER = 'viewer';

    /** Descending authority. Index 0 is the most privileged. */
    protected const HIERARCHY = [
        self::ROLE_OWNER,
        self::ROLE_ADMIN,
        self::ROLE_MEMBER,
        self::ROLE_VIEWER,
    ];

    /**
     * Resolve the authenticated user's role in a workspace.
     * Returns null when the user is NOT a member — membership is verified against
     * the pivot, never inferred from a caller-supplied workspace id (control C6).
     */
    protected function roleIn(?int $userId, ?int $workspaceId): ?string
    {
        if (!$userId || !$workspaceId) {
            return null;
        }

        $role = DB::table('workspace_users')
            ->where('user_id', $userId)
            ->where('workspace_id', $workspaceId)
            ->value('role');

        return $role ? (string) $role : null;
    }

    /** True when the user's role is at least $minimum in the hierarchy. */
    protected function atLeast(?string $role, string $minimum): bool
    {
        if ($role === null) {
            return false;
        }

        $have = array_search($role, self::HIERARCHY, true);
        $need = array_search($minimum, self::HIERARCHY, true);

        if ($have === false || $need === false) {
            return false;
        }

        return $have <= $need;
    }

    protected function isPlatformAdmin(?object $user): bool
    {
        return (bool) ($user->is_platform_admin ?? false);
    }
}
