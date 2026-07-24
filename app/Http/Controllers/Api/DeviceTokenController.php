<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * DeviceTokenController — v1.4.4
 *
 * POST   /api/devices/register   — upsert (user, expo_push_token).
 * DELETE /api/devices/{token}    — unregister on logout.
 *
 * Token uniqueness is enforced by the DB. If the same token re-appears
 * under a different user (phone resold, user switch on same device),
 * the row is rewritten — never duplicated.
 */
class DeviceTokenController
{
    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'expo_push_token' => ['required', 'string', 'max:255'],
            'platform'        => ['required', 'string', 'in:ios,android,web'],
            'device_label'    => ['nullable', 'string', 'max:120'],
        ]);

        $userId      = $request->user()->id;
        $workspaceId = (int) ($request->attributes->get('workspace_id') ?? 0) ?: null;
        // b20 — bind the registration to the sign-in that made it, so logging
        // out on this device revokes push for this device only. Null when the
        // caller still holds a pre-b20 access token (no `sid` claim); those fall
        // back to the user-level signed-in check and are swept by
        // sarah:prune-device-tokens.
        $sessionId   = $request->attributes->get('session_id');

        DB::table('device_tokens')->updateOrInsert(
            ['expo_push_token' => $data['expo_push_token']],
            [
                'user_id'       => $userId,
                'workspace_id'  => $workspaceId,
                'session_id'    => $sessionId,
                'platform'      => $data['platform'],
                'device_label'  => $data['device_label'] ?? null,
                'last_seen_at'  => now(),
                'updated_at'    => now(),
                'created_at'    => now(),
            ],
        );

        return response()->json(['success' => true]);
    }

    public function unregister(Request $request, string $token): JsonResponse
    {
        // Only the owning user can revoke their own device. Silently no-op
        // otherwise so a stale request doesn't reveal whether the token
        // exists for someone else.
        DB::table('device_tokens')
            ->where('expo_push_token', $token)
            ->where('user_id', $request->user()->id)
            ->delete();

        return response()->json(['success' => true]);
    }
}
