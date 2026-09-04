<?php

namespace App\Engines\SEO\Support;

/**
 * SEO SSRF guard (SEO-P1-3 / SEO-P2-4, 2026-09-04). The SEO crawlers fetch arbitrary URLs;
 * a private/internal/loopback target must be refused before any fetch. isPublicHttp() returns
 * false for non-http(s) schemes and for any URL whose resolved IP(s) fall in private/reserved
 * ranges (127/10/172.16/192.168/169.254/::1 …), using PHP's public-range filter.
 */
final class UrlGuard
{
    public static function isPublicHttp(?string $url): bool
    {
        $host   = strtolower((string) parse_url((string) $url, PHP_URL_HOST));
        $scheme = strtolower((string) parse_url((string) $url, PHP_URL_SCHEME));
        if ($host === '' || ! in_array($scheme, ['http', 'https'], true)) {
            return false;
        }
        $ips = [];
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $ips = [$host];
        } else {
            foreach ((array) @dns_get_record($host, DNS_A + DNS_AAAA) as $rec) {
                if (! empty($rec['ip']))   { $ips[] = $rec['ip']; }
                if (! empty($rec['ipv6'])) { $ips[] = $rec['ipv6']; }
            }
            if ($ips === []) { $r = @gethostbyname($host); if ($r && $r !== $host) { $ips[] = $r; } }
        }
        if ($ips === []) {
            return false;
        }
        foreach ($ips as $ip) {
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return false;
            }
        }
        return true;
    }
}
