<?php

namespace App\Engines\Infrastructure\Observation\Observers;

use App\Engines\Infrastructure\Observation\Custody;
use App\Engines\Infrastructure\Observation\DomainDrift;
use Illuminate\Support\Carbon;

/**
 * INFRA888 · S8 — certificate and HTTP observation.
 *
 * Both are measured directly from the public internet, so they are VERIFIED
 * regardless of custody — who holds the registrar does not change what a TLS
 * handshake returns. This is what makes customer-held domains observable at all.
 *
 * READ-ONLY. One TLS handshake and HEAD-style requests. No content is crawled,
 * nothing is submitted, no certificate is issued or renewed.
 */
class EndpointObserver
{
    private const TIMEOUT = 15;

    // ── CERTIFICATE ──────────────────────────────────────────────────────────

    /** @return array<string,mixed> */
    public function certificate(string $host, string $custody, array $desired = []): array
    {
        $t0 = microtime(true);
        $cert = $this->peerCertificate($host);
        $ms = (int) round((microtime(true) - $t0) * 1000);

        if ($cert === null) {
            return [
                'dimension' => 'certificate', 'provider' => 'tls_handshake',
                'success' => false, 'confidence' => Custody::confidenceFor($custody, 'certificate'),
                'duration_ms' => $ms,
                'error_code' => 'TLS_HANDSHAKE_FAILED',
                'error_summary' => 'Could not complete a TLS handshake or read a certificate.',
                'observed' => [], 'desired' => $desired,
                'drift' => [$this->finding(DomainDrift::UNKNOWN, 'critical',
                    'TLS handshake failed — HTTPS is broken for this hostname', null, null,
                    'Visitors see a security warning or cannot connect. Check certificate and listener.')],
            ];
        }

        $drift = [];
        $days = (int) now()->diffInDays(Carbon::parse($cert['expires_at']), false);

        if ($days < 0) {
            $drift[] = $this->finding(DomainDrift::UNKNOWN, 'critical',
                'Certificate has EXPIRED', $cert['expires_at'], null,
                'Every visitor sees a security warning. Reissue immediately.');
        } elseif ($days <= 7) {
            $drift[] = $this->finding(DomainDrift::UNKNOWN, 'critical',
                "Certificate expires in {$days} day(s)", $cert['expires_at'], null,
                'Renewal has almost certainly failed. Investigate the renewal path now.');
        } elseif ($days <= 21) {
            $drift[] = $this->finding(DomainDrift::UNKNOWN, 'high',
                "Certificate expires in {$days} day(s)", $cert['expires_at'], null,
                'Automatic renewal should already have happened. Check the renewal mechanism.');
        }

        // Hostname coverage — a cert can be valid and still not cover this name.
        if (! $this->covers($host, $cert['sans'])) {
            $drift[] = $this->finding(DomainDrift::UNKNOWN, 'critical',
                'Certificate does not cover this hostname', $cert['sans'], [$host],
                'Name mismatch produces a hard browser error. Reissue including this hostname.');
        }

        // www coverage is the single most common real-world gap.
        $bare = preg_replace('/^www\./', '', $host);

        if ($bare === $host && ! $this->covers('www.' . $host, $cert['sans'])) {
            $drift[] = $this->finding(DomainDrift::UNKNOWN, 'medium',
                'Certificate does not cover the www hostname', $cert['sans'], ['www.' . $host],
                'Visitors typing www get a security warning. Include both names when reissuing.');
        }

        if ($cert['self_signed']) {
            $drift[] = $this->finding(DomainDrift::UNKNOWN, 'critical',
                'Certificate is self-signed', $cert['issuer'], null,
                'No browser will trust this. Replace with a publicly trusted certificate.');
        }

        if ($cert['weak_algorithm']) {
            $drift[] = $this->finding(DomainDrift::UNKNOWN, 'medium',
                'Certificate uses a weak signature algorithm: ' . $cert['algorithm'], $cert['algorithm'], 'sha256+',
                'Reissue with a modern signature algorithm.');
        }

        return [
            'dimension' => 'certificate', 'provider' => 'tls_handshake',
            'success' => true, 'confidence' => Custody::confidenceFor($custody, 'certificate'),
            'duration_ms' => $ms, 'error_code' => null, 'error_summary' => null,
            'observed' => $cert + ['days_until_expiry' => $days],
            'desired' => $desired, 'drift' => $drift,
        ];
    }

    /** @return array<string,mixed>|null */
    private function peerCertificate(string $host): ?array
    {
        $ctx = stream_context_create(['ssl' => [
            'capture_peer_cert' => true, 'capture_peer_cert_chain' => true,
            'SNI_enabled' => true, 'peer_name' => $host,
            'verify_peer' => false, 'verify_peer_name' => false,
        ]]);

        $c = @stream_socket_client('ssl://' . $host . ':443', $errno, $errstr,
            self::TIMEOUT, STREAM_CLIENT_CONNECT, $ctx);

        if ($c === false) {
            return null;
        }

        $p = stream_context_get_params($c);
        fclose($c);

        if (! isset($p['options']['ssl']['peer_certificate'])) {
            return null;
        }

        $x = @openssl_x509_parse($p['options']['ssl']['peer_certificate']);

        if (! is_array($x)) {
            return null;
        }

        $sans = [];

        foreach (explode(',', (string) ($x['extensions']['subjectAltName'] ?? '')) as $e) {
            $e = trim($e);

            if (str_starts_with($e, 'DNS:')) {
                $sans[] = strtolower(substr($e, 4));
            }
        }

        $issuerO = $x['issuer']['O'] ?? ($x['issuer']['CN'] ?? 'unknown');
        $subjectCn = $x['subject']['CN'] ?? '';
        $alg = (string) ($x['signatureTypeSN'] ?? 'unknown');
        $chain = $p['options']['ssl']['peer_certificate_chain'] ?? [];

        return [
            'issuer' => (string) $issuerO,
            'issuer_cn' => (string) ($x['issuer']['CN'] ?? ''),
            'subject' => (string) $subjectCn,
            'sans' => $sans,
            'algorithm' => $alg,
            'weak_algorithm' => (bool) preg_match('/md5|sha1/i', $alg),
            'self_signed' => $subjectCn !== '' && $subjectCn === (string) ($x['issuer']['CN'] ?? null),
            'chain_length' => is_array($chain) ? count($chain) : 0,
            'valid_from' => isset($x['validFrom_time_t']) ? date('Y-m-d H:i:s', (int) $x['validFrom_time_t']) : null,
            'expires_at' => isset($x['validTo_time_t']) ? date('Y-m-d H:i:s', (int) $x['validTo_time_t']) : null,
        ];
    }

    /** Wildcard-aware SAN matching. */
    private function covers(string $host, array $sans): bool
    {
        $host = strtolower($host);

        foreach ($sans as $s) {
            if ($s === $host) {
                return true;
            }

            if (str_starts_with($s, '*.')) {
                $suffix = substr($s, 1); // ".example.com"

                if (str_ends_with($host, $suffix)
                    && substr_count($host, '.') === substr_count($s, '.')) {
                    return true;
                }
            }
        }

        return false;
    }

    // ── HTTP ─────────────────────────────────────────────────────────────────

    /** @return array<string,mixed> */
    public function http(string $host, string $custody, array $desired = []): array
    {
        $t0 = microtime(true);

        $https = $this->probe('https://' . $host . '/');
        $http = $this->probe('http://' . $host . '/');

        $ms = (int) round((microtime(true) - $t0) * 1000);
        $drift = [];

        $observed = [
            'https_status' => $https['status'],
            'https_final_url' => $https['final_url'],
            'https_redirects' => $https['redirects'],
            'https_time_ms' => $https['time_ms'],
            'https_error' => $https['error'],
            'http_status' => $http['status'],
            'http_final_url' => $http['final_url'],
            'http_redirects_to_https' => $http['final_url'] !== null && str_starts_with((string) $http['final_url'], 'https://'),
            'available' => $https['status'] !== null && $https['status'] < 400,
        ];

        if ($https['status'] === null) {
            $drift[] = $this->finding(DomainDrift::UNKNOWN, 'critical',
                'HTTPS is not answering', $https['error'], '2xx/3xx',
                'The site is down over HTTPS. Check origin, listener and certificate.');
        } elseif ($https['status'] >= 500) {
            $drift[] = $this->finding(DomainDrift::UNKNOWN, 'critical',
                'HTTPS returns a server error: ' . $https['status'], $https['status'], '200',
                'The origin is failing. Check application and server logs.');
        } elseif ($https['status'] >= 400) {
            $drift[] = $this->finding(DomainDrift::UNKNOWN, 'high',
                'HTTPS returns ' . $https['status'], $https['status'], '200',
                'The hostname resolves and connects but serves an error. Check routing and vhost.');
        }

        // Plain HTTP that never reaches HTTPS leaves visitors unencrypted.
        if ($http['status'] !== null && ! $observed['http_redirects_to_https']) {
            $drift[] = $this->finding(DomainDrift::UNKNOWN, 'medium',
                'HTTP does not redirect to HTTPS', $http['final_url'], 'https://' . $host . '/',
                'Traffic can stay unencrypted. Add a permanent redirect to HTTPS.');
        }

        if ($https['time_ms'] !== null && $https['time_ms'] > 5000) {
            $drift[] = $this->finding(DomainDrift::UNKNOWN, 'medium',
                'HTTPS response is very slow (' . $https['time_ms'] . ' ms)', $https['time_ms'], '<5000',
                'Investigate origin performance before customers report it.');
        }

        if ($https['redirects'] > 4) {
            $drift[] = $this->finding(DomainDrift::UNKNOWN, 'medium',
                'Excessive redirect chain (' . $https['redirects'] . ' hops)', $https['redirects'], '<=2',
                'Long redirect chains slow every request and can loop. Flatten them.');
        }

        return [
            'dimension' => 'http', 'provider' => 'http_probe',
            'success' => $observed['available'],
            'confidence' => Custody::confidenceFor($custody, 'http'),
            'duration_ms' => $ms,
            'error_code' => $observed['available'] ? null : 'HTTPS_UNAVAILABLE',
            'error_summary' => $observed['available'] ? null : (string) $https['error'],
            'observed' => $observed, 'desired' => $desired, 'drift' => $drift,
        ];
    }

    /**
     * One request. No body is read beyond headers — this is availability
     * observation, not crawling.
     *
     * @return array<string,mixed>
     */
    private function probe(string $url): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_NOBODY => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 8,
            CURLOPT_TIMEOUT => self::TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERAGENT => 'INFRA888-Observer/1.0 (+availability probe)',
        ]);

        $ok = curl_exec($ch);
        $err = curl_error($ch);
        $info = curl_getinfo($ch);
        curl_close($ch);

        return [
            'status' => $ok === false || ($info['http_code'] ?? 0) === 0 ? null : (int) $info['http_code'],
            'final_url' => $info['url'] ?? null,
            'redirects' => (int) ($info['redirect_count'] ?? 0),
            'time_ms' => isset($info['total_time']) ? (int) round($info['total_time'] * 1000) : null,
            'error' => $err !== '' ? $err : null,
        ];
    }

    private function finding(string $class, string $severity, string $title, $observed, $desired, string $action): array
    {
        return [
            'class' => $class, 'severity' => $severity, 'title' => $title,
            'observed' => $observed, 'desired' => $desired, 'recommended_action' => $action,
        ];
    }
}
