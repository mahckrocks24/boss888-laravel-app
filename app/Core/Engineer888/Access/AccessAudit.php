<?php

namespace App\Core\Engineer888\Access;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Every attempt to reach Engineer888, granted or refused.
 *
 * The refusals are the more useful half. A single denied read is somebody
 * mistyping a URL; the same account denied eleven times in a minute across
 * different capabilities is somebody probing, and that pattern only exists if
 * the failures were kept.
 *
 * APPEND-ONLY FROM ENGINEER888'S SIDE. There is no update path and no delete
 * path in this class, and the table is on the protected list, so a reasoning
 * candidate cannot propose one. That is not the same as being immune to
 * anybody with database access — it means the application cannot quietly
 * rewrite its own history.
 *
 * WHAT IS NOT RECORDED: request bodies, candidate contents, file bytes, tokens.
 * An audit that copies the secrets it is auditing has doubled the blast radius
 * of losing it.
 */
final class AccessAudit
{
    /** @param array<string,mixed> $decision the policy's verdict */
    public static function record(Request $request, string $capability, array $decision): void
    {
        try {
            $user = $request->user();

            DB::table('engineering_access_audit')->insert([
                'user_id'    => $user->id ?? null,
                'email'      => $user->email ?? null,
                'capability' => $capability,
                'granted'    => $decision['allowed'] ? 1 : 0,
                'reason'     => $decision['reason'],

                // Enough to tell a mistake from a probe, and no more.
                'route'      => substr($request->path(), 0, 255),
                'method'     => $request->method(),
                'ip'         => substr((string) $request->ip(), 0, 45),
                'user_agent' => substr((string) $request->userAgent(), 0, 255),
                'auth_via'   => (string) ($request->attributes->get('auth_via') ?? 'none'),
                'session_id' => $request->attributes->get('session_id'),
                'device_id'  => $request->header('X-Device-Id') ? substr((string) $request->header('X-Device-Id'), 0, 64) : null,
                'mfa_state'  => json_encode($decision['mfa'] ?? []),
                'policy_version' => Engineer888Access::POLICY_VERSION,
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            // An audit failure must never become an access decision. Refusing
            // the request because the log is full would hand an attacker a
            // denial-of-service, and granting it because the log is full would
            // be worse. The decision already stands; this only records it.
            report($e);
        }
    }

    /** @return array<int,object> */
    public static function recent(int $limit = 50): array
    {
        return DB::table('engineering_access_audit')->orderByDesc('id')->limit($limit)->get()->all();
    }

    public static function deniedSince(\DateTimeInterface $since): int
    {
        return (int) DB::table('engineering_access_audit')
            ->where('granted', 0)->where('created_at', '>=', $since)->count();
    }
}
