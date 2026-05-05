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

        $tokens = $this->refreshTokenService->issueTokenPair($user, $workspace);

        $workspaces = $user->workspaces()->with('subscription.plan')->get();

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
