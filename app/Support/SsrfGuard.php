<?php

namespace App\Support;

/**
 * RISK-0120 — canonical SSRF guard for server-side URL fetches. Blocks non-http(s) schemes and
 * internal/reserved hosts (cloud metadata 169.254.169.254, localhost, RFC1918, IPv6 link-local /
 * loopback / IPv4-mapped), INCLUDING hostnames that resolve to them (gethostbyname + private/
 * reserved-range filter; safe-fail on resolution failure). Single source of truth so every
 * server-side fetch of a possibly-attacker-influenced URL can share one proven implementation.
 */
final class SsrfGuard
{
    /** @return bool true = the URL must NOT be fetched (internal/reserved/non-http). */
    public static function isBlockedUrl(string $url): bool
    {
        $host   = strtolower((string) (parse_url($url, PHP_URL_HOST) ?: ''));
        $scheme = strtolower((string) (parse_url($url, PHP_URL_SCHEME) ?: ''));
        if ($host === '' || ! in_array($scheme, ['http', 'https'], true)) {
            return true;
        }
        if ($host === 'localhost'
            || preg_match('/^(127\.|10\.|192\.168\.|169\.254\.|::1|172\.(1[6-9]|2[0-9]|3[0-1])\.)/', $host)) {
            return true;
        }
        // Resolve the host and block private/reserved IPs (catches hostnames pointing at internal
        // addresses and IPv4-mapped IPv6). gethostbyname returns the input unchanged on failure ->
        // treated as non-public -> blocked (safe-fail).
        $ip = filter_var($host, FILTER_VALIDATE_IP) ? $host : @gethostbyname($host);
        if (! $ip || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return true;
        }
        return false;
    }
}
