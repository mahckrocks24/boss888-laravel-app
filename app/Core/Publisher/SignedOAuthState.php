<?php

namespace App\Core\Publisher;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Signed, single-use OAuth state for Publisher provider connections.
 *
 * WHY THIS REPLACES THE EXISTING FLOW
 * -----------------------------------
 * SocialConnector builds state as:
 *
 *     $state = "{$workspaceId}_{$nonce}";
 *
 * The workspace id therefore travels as an UNSIGNED value inside a query
 * parameter that the user's browser — and anyone who can influence the redirect
 * — controls. `handleCallback($code, $state, $workspaceId)` then takes the
 * workspace from the caller while the cache key derives from the attacker-
 * supplied state string. Nothing cryptographically ties the returned state to
 * the workspace, the user, or the provider that the flow started for.
 *
 * Here the state is an HMAC-signed envelope. Its payload is authoritative, so
 * the callback never has to trust a caller-supplied workspace id, and a state
 * minted for one workspace/user/provider cannot be replayed into another.
 *
 * Single use is enforced by an atomic cache pull: the first callback consumes
 * the record; a replay finds nothing and is rejected.
 */
final class SignedOAuthState
{
    private const VERSION   = 'v1';
    private const TTL       = 600;   // 10 minutes — an OAuth round trip is seconds
    private const CACHE_KEY = 'publisher_oauth_state:';

    /** @return array{state:string, nonce:string} */
    public static function issue(int $workspaceId, int $userId, string $provider, array $extra = []): array
    {
        $nonce   = Str::random(40);
        $payload = [
            'ws'   => $workspaceId,
            'uid'  => $userId,
            'prov' => strtolower($provider),
            'n'    => $nonce,
            'iat'  => time(),
            'ver'  => self::VERSION,
        ];

        $body  = self::b64(json_encode($payload, JSON_UNESCAPED_SLASHES));
        $state = $body . '.' . self::b64(hash_hmac('sha256', $body, self::key(), true));

        // Server-side record makes the state single-use and lets us carry
        // provider extras (e.g. PKCE verifier) without putting them in the URL.
        Cache::put(self::CACHE_KEY . hash('sha256', $state), [
            'ws' => $workspaceId, 'uid' => $userId, 'prov' => strtolower($provider),
            'extra' => $extra, 'iat' => time(),
        ], self::TTL);

        return ['state' => $state, 'nonce' => $nonce];
    }

    /**
     * Verify and CONSUME. Returns the authoritative payload or null.
     *
     * @param string $expectedProvider the provider whose callback is executing
     */
    public static function consume(string $state, string $expectedProvider): ?array
    {
        if ($state === '' || substr_count($state, '.') !== 1) {
            return self::deny('malformed');
        }

        [$body, $sig] = explode('.', $state, 2);
        if (!hash_equals(self::b64(hash_hmac('sha256', $body, self::key(), true)), $sig)) {
            return self::deny('bad_signature');
        }

        $payload = json_decode(self::unb64($body) ?: '', true);
        if (!is_array($payload))                        return self::deny('bad_payload');
        if (($payload['ver'] ?? null) !== self::VERSION) return self::deny('bad_version');
        if (time() - (int) ($payload['iat'] ?? 0) > self::TTL) return self::deny('expired');

        // The provider in the signed payload must match the callback that ran.
        // Stops a state issued for one provider being replayed into another.
        if (strtolower($expectedProvider) !== ($payload['prov'] ?? '')) {
            return self::deny('provider_mismatch');
        }

        // Atomic single use. A replayed state finds nothing here.
        $record = Cache::pull(self::CACHE_KEY . hash('sha256', $state));
        if (!is_array($record)) return self::deny('replayed_or_unknown');

        // Defence in depth: the signed payload and the server record must agree.
        if ((int) $record['ws'] !== (int) $payload['ws']
            || (int) $record['uid'] !== (int) $payload['uid']
            || $record['prov'] !== $payload['prov']) {
            return self::deny('record_mismatch');
        }

        return [
            'workspace_id' => (int) $payload['ws'],
            'user_id'      => (int) $payload['uid'],
            'provider'     => (string) $payload['prov'],
            'extra'        => $record['extra'] ?? [],
        ];
    }

    private static function key(): string
    {
        $k = (string) config('app.key');
        if ($k === '') throw new \RuntimeException('SignedOAuthState: APP_KEY is not set');
        return $k;
    }

    private static function deny(string $why): ?array
    {
        // Never log the state itself — it is a bearer value until consumed.
        Log::info('[Publisher] oauth state rejected', ['reason' => $why]);
        return null;
    }

    private static function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private static function unb64(string $s): string
    {
        return base64_decode(strtr($s, '-_', '+/')) ?: '';
    }
}
