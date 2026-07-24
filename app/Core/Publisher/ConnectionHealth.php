<?php

namespace App\Core\Publisher;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Connection usability, and the vocabulary to describe it honestly.
 *
 * "Facebook is not connected" is the wrong thing to say when the truth is "your
 * token was revoked" or "you removed the publishing permission" — it sends the
 * user to Connect when they need Reconnect, or to Settings when they need to
 * re-grant a scope. Each state below implies a different next action.
 */
final class ConnectionHealth
{
    public const CONNECTED     = 'connected';
    public const EXPIRING      = 'expiring';                // usable, but re-auth soon
    public const EXPIRED       = 'expired';
    public const REVOKED       = 'revoked';
    public const INSUFFICIENT  = 'insufficient_permissions';
    public const DISCONNECTED  = 'disconnected';

    /** States in which publishing must not even be attempted. */
    public const UNUSABLE = [self::EXPIRED, self::REVOKED, self::INSUFFICIENT, self::DISCONNECTED];

    public const EXPIRING_WINDOW_DAYS = 7;

    /** Scopes each platform needs before a publish is possible. */
    public const REQUIRED_SCOPES = [
        'facebook'  => ['pages_show_list', 'pages_read_engagement', 'pages_manage_posts'],
        'instagram' => ['pages_show_list', 'instagram_basic', 'instagram_content_publish'],
    ];

    /**
     * Is this connection usable right now?
     *
     * @return array{ok:bool, state:string, reason:?string, message:string}
     */
    public static function assess(object $account): array
    {
        $state = (string) ($account->health_state ?? self::CONNECTED);
        $platform = strtolower((string) ($account->platform ?? ''));

        if (strtolower((string) ($account->status ?? '')) !== 'connected') {
            return self::no(self::DISCONNECTED, 'ACCOUNT_NOT_CONNECTED',
                'This account is disconnected. Connect it again to publish.');
        }
        if (in_array($state, self::UNUSABLE, true)) {
            return self::no($state, 'CONNECTION_' . strtoupper($state), self::messageFor($state));
        }

        // Token lifetime.
        if (!empty($account->token_expires_at)) {
            $exp = strtotime((string) $account->token_expires_at);
            if ($exp !== false) {
                if ($exp <= time()) {
                    return self::no(self::EXPIRED, 'TOKEN_EXPIRED', self::messageFor(self::EXPIRED));
                }
                if ($exp - time() <= self::EXPIRING_WINDOW_DAYS * 86400) {
                    return ['ok' => true, 'state' => self::EXPIRING, 'reason' => null,
                            'message' => 'This connection expires soon. Reconnect to avoid interruption.'];
                }
            }
        }

        // Scopes actually granted, where we recorded them.
        $granted = json_decode((string) ($account->granted_scopes_json ?? 'null'), true);
        if (is_array($granted) && isset(self::REQUIRED_SCOPES[$platform])) {
            $missing = array_values(array_diff(self::REQUIRED_SCOPES[$platform], $granted));
            if ($missing !== []) {
                return self::no(self::INSUFFICIENT, 'MISSING_SCOPES:' . implode(',', $missing),
                    'This connection is missing permissions required to publish ('
                    . implode(', ', $missing) . '). Reconnect and approve them.');
            }
        }

        return ['ok' => true, 'state' => self::CONNECTED, 'reason' => null, 'message' => 'Connected.'];
    }

    /** Record a health transition. Never writes credentials. */
    public static function mark(int $accountId, string $state, ?string $detail = null): void
    {
        try {
            DB::table('social_accounts')->where('id', $accountId)->update([
                'health_state'      => $state,
                'health_detail'     => $detail !== null ? mb_substr($detail, 0, 190) : null,
                'health_checked_at' => now(),
                'updated_at'        => now(),
            ]);
            Log::info('[Publisher] connection health changed', [
                'account_id' => $accountId, 'state' => $state, 'detail' => $detail,
            ]);
        } catch (\Throwable $e) {
            Log::warning('[Publisher] health mark failed', ['error' => $e->getMessage()]);
        }
    }

    public static function messageFor(string $state): string
    {
        return match ($state) {
            self::EXPIRED      => 'The connection expired. Reconnect the account to publish.',
            self::REVOKED      => 'Access was revoked at the provider. Reconnect the account to publish.',
            self::INSUFFICIENT => 'The connection is missing a permission required to publish. Reconnect and approve it.',
            self::DISCONNECTED => 'This account is disconnected. Connect it again to publish.',
            self::EXPIRING     => 'This connection expires soon. Reconnect to avoid interruption.',
            default            => 'Connected.',
        };
    }

    // ── Credential envelope ─────────────────────────────────────────────
    // Secrets live encrypted at rest and are decrypted only for the duration of
    // a provider call. They never enter logs, audit rows, notifications or API
    // responses — see the assertions in the test suite.

    public static function storeCredentials(int $accountId, array $creds): void
    {
        DB::table('social_accounts')->where('id', $accountId)->update([
            'credentials_encrypted' => Crypt::encryptString(json_encode($creds)),
            // Legacy plaintext column is explicitly emptied for new connections.
            'credentials_json'      => json_encode(['_' => 'encrypted']),
            'updated_at'            => now(),
        ]);
    }

    /** @return array decrypted credentials, or [] */
    public static function readCredentials(object $account): array
    {
        if (!empty($account->credentials_encrypted)) {
            try {
                $d = json_decode(Crypt::decryptString($account->credentials_encrypted), true);
                return is_array($d) ? $d : [];
            } catch (\Throwable $e) {
                Log::warning('[Publisher] credential decrypt failed', ['account_id' => $account->id ?? null]);
                return [];
            }
        }
        // Historical rows only.
        $d = json_decode((string) ($account->credentials_json ?? '{}'), true);
        return is_array($d) ? $d : [];
    }

    private static function no(string $state, string $reason, string $message): array
    {
        return ['ok' => false, 'state' => $state, 'reason' => $reason, 'message' => $message];
    }
}
