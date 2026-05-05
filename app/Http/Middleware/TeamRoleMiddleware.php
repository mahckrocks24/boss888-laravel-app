<?php

namespace App\Http\Middleware;

use App\Core\Workspaces\TeamService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * TeamRoleMiddleware
 *
 * Enforces workspace role requirements on specific route groups.
 *
 * Usage:
 *   'team.role:owner'       — workspace owner only
 *   'team.role:admin'       — owner or admin
 *   'team.role:member'      — any member (owner, admin, member, viewer)
 *
 * Applied to:
 *   Settings, billing, team management, API key management → owner or admin only
 *   Member-level routes → any authenticated workspace member
 *
 * This middleware assumes JwtAuthMiddleware has already run and set
 * $request->user() and the workspace_id attribute.
 */
class TeamRoleMiddleware
{
    public function __construct(
        private TeamService $team,
    ) {}

    public function handle(Request $request, Closure $next, string $required = 'member'): Response
    {
        $user = $request->user();
        $wsId = (int) $request->attributes->get('workspace_id');

        if (!$user || !$wsId) {
            return response()->json(['error' => 'Authentication required'], 401);
        }

        $role = $this->team->getUserRole($wsId, $user->id);

        if (!$role) {
            return response()->json([
                'error' => 'You are not a member of this workspace',
                'code'  => 'NOT_A_MEMBER',
            ], 403);
        }

        $allowed = match ($required) {
            'owner'  => $role === 'owner',
            'admin'  => in_array($role, ['owner', 'admin']),
            'member' => in_array($role, ['owner', 'admin', 'member']),
            default  => true,
        };

        if (!$allowed) {
            $requiredLabel = match ($required) {
                'owner' => 'workspace owner',
                'admin' => 'workspace admin or owner',
                default => 'workspace member',
            };

            return response()->json([
                'error'       => "This action requires {$requiredLabel} access",
                'code'        => 'INSUFFICIENT_ROLE',
                'your_role'   => $role,
                'required'    => $required,
            ], 403);
        }

        // Inject role into request attributes for downstream use
        $request->attributes->set('workspace_role', $role);

        return $next($request);
    }
}
