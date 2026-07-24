<?php

namespace App\Core\Publisher;

/**
 * Normalises Meta Graph responses into an outcome the Publisher can act on.
 *
 * THE THREE OUTCOMES THAT MATTER
 * ------------------------------
 *  ok        — the provider confirmed and gave us an id.
 *  failed    — it will never succeed as-is. Do not retry; tell the user why.
 *  retryable — a transient condition. Retry with backoff.
 *  UNCERTAIN — we never learned the result. This is the dangerous one: Meta may
 *              have created the post. Retrying blindly duplicates content, so
 *              this outcome must go to reconciliation, never to a retry.
 *
 * Meta error codes are documented but messy; the mapping below is deliberately
 * conservative — anything unrecognised is treated as permanent rather than
 * retried into a duplicate.
 */
final class MetaErrorMap
{
    public const OK        = 'ok';
    public const FAILED    = 'failed';
    public const RETRYABLE = 'retryable';
    public const UNCERTAIN = 'uncertain';

    /** Token/permission problems — the connection needs user action. */
    private const AUTH_CODES = [190, 102, 458, 459, 463, 464, 467];
    private const PERMISSION_CODES = [10, 200, 803, 3, 279];
    /** Transient throttling / server-side wobble. */
    private const RATE_CODES = [4, 17, 32, 613, 341];
    private const TRANSIENT_CODES = [1, 2, 341368];

    /**
     * @param array $resp transport response
     * @return array{class:string, code:?string, message:string, retryable:bool, needs_reconnect:bool}
     */
    public static function classify(array $resp): array
    {
        $status = (int) ($resp['status'] ?? 0);
        $err    = $resp['error'] ?? null;
        $json   = is_array($resp['json'] ?? null) ? $resp['json'] : [];
        $mErr   = $json['error'] ?? null;
        $code   = is_array($mErr) ? ($mErr['code'] ?? null) : null;
        $sub    = is_array($mErr) ? ($mErr['error_subcode'] ?? null) : null;

        // ── The uncertain case. We sent bytes and never got a verdict.
        if ($err === 'TRANSPORT_TIMEOUT' || ($status === 0 && $err !== null && $mErr === null)) {
            return self::r(self::UNCERTAIN, 'TRANSPORT_TIMEOUT',
                'The request timed out before the provider confirmed the result.', false, false);
        }

        if ($status >= 200 && $status < 300 && $mErr === null) {
            return self::r(self::OK, null, 'ok', false, false);
        }

        // ── Server-side: safe to retry, the request was rejected not applied.
        if ($status >= 500) {
            return self::r(self::RETRYABLE, 'HTTP_' . $status, 'The provider had a temporary error.', true, false);
        }
        if ($status === 429) {
            return self::r(self::RETRYABLE, 'HTTP_429', 'The provider rate-limited this request.', true, false);
        }

        $codeInt = is_numeric($code) ? (int) $code : null;

        if ($codeInt !== null && in_array($codeInt, self::AUTH_CODES, true)) {
            return self::r(self::FAILED, 'META_' . $codeInt,
                'The connection is no longer authorised and must be reconnected.', false, true);
        }
        if ($codeInt !== null && in_array($codeInt, self::PERMISSION_CODES, true)) {
            return self::r(self::FAILED, 'META_' . $codeInt,
                'The connected account is missing a permission required to publish.', false, true);
        }
        if ($codeInt !== null && in_array($codeInt, self::RATE_CODES, true)) {
            return self::r(self::RETRYABLE, 'META_' . $codeInt,
                'The provider is rate-limiting publishing right now.', true, false);
        }
        if ($codeInt !== null && in_array($codeInt, self::TRANSIENT_CODES, true)) {
            return self::r(self::RETRYABLE, 'META_' . $codeInt,
                'The provider reported a temporary problem.', true, false);
        }

        // Everything else: permanent. Better a clear failure than a duplicate.
        $safe = self::sanitize(is_array($mErr) ? (string) ($mErr['message'] ?? '') : (string) ($err ?? ''));
        return self::r(self::FAILED,
            $codeInt !== null ? 'META_' . $codeInt . ($sub ? '_' . $sub : '') : ('HTTP_' . $status),
            $safe !== '' ? $safe : 'The provider rejected this post.', false, false);
    }

    /**
     * Provider messages can echo back request content and, in some error shapes,
     * fragments of the token. Never surface them raw.
     */
    public static function sanitize(string $message): string
    {
        $m = preg_replace('/\b(EAA|EAAB|IGQ)[A-Za-z0-9_\-]{20,}/', '[redacted-token]', $message) ?? $message;
        $m = preg_replace('/access_token=[^&\s]+/i', 'access_token=[redacted]', $m) ?? $m;
        $m = preg_replace('/\s+/', ' ', $m) ?? $m;
        return trim(mb_substr($m, 0, 300));
    }

    private static function r(string $class, ?string $code, string $msg, bool $retryable, bool $reconnect): array
    {
        return ['class' => $class, 'code' => $code, 'message' => $msg,
                'retryable' => $retryable, 'needs_reconnect' => $reconnect];
    }
}
