<?php

namespace App\Engines\Ads\Services;

use App\Engines\Ads\Support\AdSettings;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * AdTokenService — signed, single-use tokens binding a measurement event to the
 * decision that authorised it.
 *
 * WHY THIS EXISTS
 * The measurement beacon and the click redirect are PUBLIC, UNAUTHENTICATED
 * endpoints. Without a signature, anyone could POST impressions all day and
 * inflate an advertiser's invoice — and the advertiser would be right to refuse
 * to pay it. A token proves the event corresponds to a decision this server
 * actually made, for that creative, on that site, within the last few minutes.
 *
 * PROPERTIES
 *   - HMAC-SHA256 over the decision tuple, keyed on APP_KEY.
 *   - Short TTL (5 min): an impression cannot be replayed tomorrow.
 *   - Single-use per event type: the nonce is burned on first acceptance, so
 *     one decision cannot yield ten impressions.
 *   - Constant-time comparison — never `===` on a signature.
 *
 * The token carries NO personal data. It is (creative, campaign, website, slot,
 * nonce, expiry) — all server-side identifiers.
 */
final class AdTokenService
{
    /** Tokens expire this many seconds after issue. */
    public const TTL_SECONDS = 300;

    private const NONCE_CACHE_PREFIX = 'ads:nonce:';

    /**
     * Effective token lifetime. Operator-configurable via `token_ttl_seconds`;
     * the constant is the shipped default and the fallback when settings cannot
     * be read — failing to a short, safe TTL rather than an unbounded one.
     */
    public static function ttlSeconds(): int
    {
        try {
            $v = app(AdSettingsService::class)->int(AdSettings::TOKEN_TTL_SECONDS);

            return $v > 0 ? $v : self::TTL_SECONDS;
        } catch (Throwable) {
            return self::TTL_SECONDS;
        }
    }

    /**
     * Mint a token for a decision.
     *
     * @return array{token: string, nonce: string, expires_at: int}
     */
    public function issue(int $creativeId, int $campaignId, int $websiteId, int $slotId): array
    {
        $nonce     = bin2hex(random_bytes(16));
        // Laravel's clock, not PHP's time(): it is the same wall clock in
        // production, but it is the one the whole app (and the test suite's
        // time-travel) agrees on. A token TTL you cannot test is a token TTL
        // you do not know works.
        $expiresAt = now()->getTimestamp() + self::ttlSeconds();

        $payload = $this->payload($creativeId, $campaignId, $websiteId, $slotId, $nonce, $expiresAt);
        $token   = $payload . '.' . $this->sign($payload);

        return ['token' => $token, 'nonce' => $nonce, 'expires_at' => $expiresAt];
    }

    /**
     * Verify a token and, for billable events, burn its nonce so it cannot be
     * replayed.
     *
     * @param  string  $event  request|impression|viewable|click
     * @return array{valid: bool, reason: string|null, claims: array<string,mixed>|null}
     */
    public function verify(string $token, string $event, bool $burn = true): array
    {
        try {
            $parts = explode('.', $token);

            if (count($parts) !== 2) {
                return $this->invalid('malformed');
            }

            [$payload, $signature] = $parts;

            // Constant-time. A timing-safe compare is the difference between a
            // signature and a puzzle.
            if (! hash_equals($this->sign($payload), $signature)) {
                return $this->invalid('bad_signature');
            }

            $claims = $this->decode($payload);

            if ($claims === null) {
                return $this->invalid('undecodable');
            }

            // From here the SIGNATURE is proven good, so the claims are ours and
            // can be trusted for attribution even though the event itself is
            // being rejected. That distinction matters: an expired or replayed
            // beacon is a real client we can truthfully attribute to a site,
            // whereas a forged signature tells us nothing we may believe.
            if (($claims['exp'] ?? 0) < now()->getTimestamp()) {
                return $this->invalid('expired', $claims);
            }

            // Burn the nonce for billable events only. A `request` event is
            // informational and may legitimately precede the impression.
            // The nonce is burned PER EVENT TYPE, so one decision can legitimately
            // yield one impression, one viewable, one video_complete and one
            // click — but never two of any of them. That is what lets a video
            // interstitial report its whole funnel from a single token while
            // still being replay-proof on every billable step.
            if ($burn && in_array($event, AdEventRecorder::BILLABLE_EVENTS, true)) {
                $key = self::NONCE_CACHE_PREFIX . $event . ':' . ($claims['n'] ?? '');

                if (Cache::has($key)) {
                    return $this->invalid('replayed', $claims);
                }

                Cache::put($key, 1, self::ttlSeconds() + 60);
            }

            return ['valid' => true, 'reason' => null, 'claims' => $claims];
        } catch (Throwable) {
            return $this->invalid('verify_error');
        }
    }

    // ─────────────────────────────────────────────────────────────────────

    private function payload(int $cr, int $ca, int $w, int $s, string $nonce, int $exp): string
    {
        return rtrim(strtr(base64_encode(json_encode([
            'cr'  => $cr,
            'ca'  => $ca,
            'w'   => $w,
            's'   => $s,
            'n'   => $nonce,
            'exp' => $exp,
        ], JSON_UNESCAPED_SLASHES) ?: '{}'), '+/', '-_'), '=');
    }

    private function decode(string $payload): ?array
    {
        $json = base64_decode(strtr($payload, '-_', '+/'), true);

        if ($json === false) {
            return null;
        }

        $claims = json_decode($json, true);

        return is_array($claims) ? $claims : null;
    }

    private function sign(string $payload): string
    {
        return hash_hmac('sha256', $payload, $this->key());
    }

    private function key(): string
    {
        $key = (string) config('app.key');

        if (str_starts_with($key, 'base64:')) {
            $decoded = base64_decode(substr($key, 7), true);

            if ($decoded !== false) {
                return $decoded;
            }
        }

        return $key;
    }

    /**
     * @param  array<string,mixed>|null  $claims  supplied ONLY when the signature
     *         verified — i.e. when the claims are ours and may be trusted for
     *         attribution. Null means "we cannot believe anything in this token".
     * @return array{valid: false, reason: string, claims: array<string,mixed>|null}
     */
    private function invalid(string $reason, ?array $claims = null): array
    {
        return ['valid' => false, 'reason' => $reason, 'claims' => $claims];
    }
}
