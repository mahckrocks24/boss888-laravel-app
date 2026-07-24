<?php

namespace App\Core\Auth;

use Illuminate\Support\Facades\DB;
use App\Models\User;

/**
 * Single, authoritative resolver for X-API-KEY credentials.
 *
 * WHY THIS EXISTS
 * ---------------
 * Before 2026-07-18, API-key resolution was duplicated in TWO middleware with
 * DIFFERENT rules:
 *
 *   JwtAuthMiddleware.php:39-45  — if the key had no bound user, it selected the
 *       FIRST row in workspace_users ordered by id (typically the workspace
 *       OWNER) and ran the request as that person. An unbound integration key
 *       therefore silently acquired owner-equivalent identity.
 *
 *   ApiKeyAuth.php:51            — set workspace_id but NEVER set a user
 *       resolver, so $request->user() was null downstream. Audit rows written by
 *       API-key traffic had user_id = NULL, and TeamRoleMiddleware 401'd.
 *
 * Both behaviours are removed. Resolution is now explicit and fails closed:
 * a credential must name its principal, and that principal must be a verified
 * member of the credential's workspace. Nothing is inferred from record order.
 *
 * Evidence gathered before the change (staging, 2026-07-18): 3 api_keys rows,
 * all active, ALL with a bound user_id, and every bound user verified to be a
 * member of its key's workspace. The removed fallback had zero live dependents.
 */
class ApiCredentialResolver
{
    public const DENY_INVALID   = 'invalid_api_key';
    public const DENY_EXPIRED   = 'api_key_expired';
    public const DENY_UNBOUND   = 'api_key_unbound';
    public const DENY_NO_USER   = 'api_key_principal_missing';
    public const DENY_NOT_MEMBER= 'api_key_binding_invalid';

    /**
     * @return array{denied:bool, code:?string, message:?string,
     *               user:?User, workspace_id:?int, api_key_id:?int, role:?string}
     */
    public function resolve(?string $key): array
    {
        if (!$key) {
            return $this->deny(self::DENY_INVALID, 'API key required.');
        }

        $record = DB::table('api_keys')
            ->where('key', $key)
            ->where('is_active', true)
            ->first();

        if (!$record) {
            return $this->deny(self::DENY_INVALID, 'Invalid API key.');
        }

        if ($record->expires_at && now()->isAfter($record->expires_at)) {
            return $this->deny(self::DENY_EXPIRED, 'API key has expired.');
        }

        // HARD RULE: no implicit principal. An unbound key is denied outright —
        // it is NOT resolved to "the first workspace member".
        if (!$record->user_id) {
            return $this->deny(
                self::DENY_UNBOUND,
                'This API key is not bound to a user. Re-issue it with an explicit owner.'
            );
        }

        $user = User::find($record->user_id);

        if (!$user) {
            return $this->deny(self::DENY_NO_USER, 'The user bound to this API key no longer exists.');
        }

        // The bound principal must still be a member of the key's workspace.
        // Without this, a key whose user was removed from the workspace — or was
        // never in it — would keep full access.
        $role = DB::table('workspace_users')
            ->where('user_id', $record->user_id)
            ->where('workspace_id', $record->workspace_id)
            ->value('role');

        if (!$role) {
            return $this->deny(
                self::DENY_NOT_MEMBER,
                'This API key is no longer valid for its workspace.'
            );
        }

        return [
            'denied'       => false,
            'code'         => null,
            'message'      => null,
            'user'         => $user,
            'workspace_id' => (int) $record->workspace_id,
            'api_key_id'   => (int) $record->id,
            'role'         => (string) $role,
        ];
    }

    /** Fire-and-forget usage stamp. Never blocks a request. */
    public function touch(int $apiKeyId): void
    {
        try {
            DB::table('api_keys')->where('id', $apiKeyId)->update(['last_used_at' => now()]);
        } catch (\Throwable) {
            // observability handled elsewhere
        }
    }

    private function deny(string $code, string $message): array
    {
        return [
            'denied'       => true,
            'code'         => $code,
            'message'      => $message,
            'user'         => null,
            'workspace_id' => null,
            'api_key_id'   => null,
            'role'         => null,
        ];
    }
}
