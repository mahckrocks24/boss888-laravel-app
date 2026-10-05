<?php

namespace App\Core\Managed;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * MANAGED-1 (RFC-0029, DEC-0086) — the one rule for "managed" workspaces.
 *
 * A managed workspace belongs to a client whose website we host and run (PTAA first). Its people use the managed
 * portal (/portal): Email, Billing, Account and a read-only status line. Everything else in the product is refused
 * to them on the server, whatever the browser asks for.
 *
 * guard() is called from JwtAuthMiddleware — the one place every signed-in API request passes through, including
 * the sub-requests of /api/batch — after the workspace is known. For every standard workspace it is one indexed
 * lookup, memoised per request, and returns null (no change in behaviour).
 */
final class ManagedWorkspaces
{
    public const TYPE = 'managed';

    /**
     * API paths (after the api/ or exec-api/ prefix) a managed workspace may reach. Prefix match on whole
     * segments. Keep it short: each line is a door.
     */
    private const ALLOWED = [
        'managed/',                         // the portal's own endpoints
        'infrastructure/business-email/',   // the Email page
        'auth/me', 'auth/logout', 'auth/password', 'auth/profile', 'auth/switch-workspace',
        'user/preferences',                 // theme (light/dark) follows the person between the app and the portal
        'workspace/status',                 // read once by the app shell before it hands over to the portal
        'batch',                            // its sub-requests pass through here again, one by one
    ];

    /** @var array<int,bool> */
    private static array $memo = [];

    public static function isManaged(?int $workspaceId): bool
    {
        if (! $workspaceId) {
            return false;
        }
        if (! array_key_exists($workspaceId, self::$memo)) {
            self::$memo[$workspaceId] = DB::table('workspaces')->where('id', $workspaceId)->value('workspace_type') === self::TYPE;
        }

        return self::$memo[$workspaceId];
    }

    public static function forget(int $workspaceId): void
    {
        unset(self::$memo[$workspaceId]);
    }

    /** Path as the guard sees it: no leading slash, no api/ or exec-api/ prefix. */
    public static function normalisedPath(Request $request): string
    {
        $p = ltrim($request->path(), '/');

        return preg_replace('#^(api|exec-api)/#', '', $p) ?? $p;
    }

    public static function pathAllowed(string $path): bool
    {
        foreach (self::ALLOWED as $prefix) {
            if (str_ends_with($prefix, '/') ? str_starts_with($path . '/', $prefix) : ($path === $prefix || str_starts_with($path, $prefix . '/'))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Null = carry on. A response = refuse. Platform admins are never refused (staff look after these clients).
     * $viaApiKey: machine credentials are never allowed into a managed workspace.
     */
    public static function guard(Request $request, $user, ?int $workspaceId, bool $viaApiKey = false)
    {
        if (! self::isManaged($workspaceId)) {
            return null;
        }
        if ($user && ! empty($user->is_platform_admin)) {
            return null;
        }
        if (! $viaApiKey && self::pathAllowed(self::normalisedPath($request))) {
            return null;
        }

        return response()->json([
            'success' => false,
            'error'   => 'This part of LevelUpGrowth is not part of your package.',
            'code'    => 'managed_workspace',
            'portal'  => '/portal',
        ], 403);
    }
}
