<?php

namespace App\Core\Auth;

use App\Models\User;

class WorkspaceSwitchService
{
    public function __construct(
        private RefreshTokenService $refreshTokenService,
    ) {}

    public function switchWorkspace(User $user, int $workspaceId): array
    {
        $workspace = $user->workspaces()->where('workspaces.id', $workspaceId)->first();

        if (! $workspace) {
            abort(403, 'Not a member of this workspace');
        }

        // INC-0006: an archived failed build is dead — it produced no website and is not a business, so
        // nothing may open it as a working context. A QA fixture is different: it is hidden from the
        // customer list but must stay reachable for the people who maintain it, and membership is that
        // authorisation. Deleting neither, hiding both, opening only the one that still has a purpose.
        if ($workspace->lifecycle_state === \App\Models\Workspace::STATE_ARCHIVED) {
            abort(403, 'This workspace was archived as a failed build and can no longer be opened.');
        }

        $tokens = $this->refreshTokenService->issueTokenPair($user, $workspace);

        $workspaces = $user->workspaces()->customerVisible()->with('subscription.plan')->get();

        return [
            'access_token' => $tokens['access_token'],
            'refresh_token' => $tokens['refresh_token'],
            'user' => [
                'id' => $user->id,
                'email' => $user->email,
                'name' => $user->name,
            ],
            'workspaces' => $workspaces->map(fn ($ws) => [
                'id' => $ws->id,
                'name' => $ws->name,
                'role' => $ws->pivot->role,
                'plan' => $ws->subscription?->plan?->slug ?? 'free',
            ])->toArray(),
            'current_workspace_id' => $workspace->id,
        ];
    }
}
