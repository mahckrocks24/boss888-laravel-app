<?php

namespace App\Engines\Infrastructure\Observation\Observers;

use App\Engines\Infrastructure\Observation\Custody;
use App\Engines\Infrastructure\Observation\DomainDrift;
use Illuminate\Support\Carbon;

/**
 * INFRA888 · S8 — DNS and WHOIS observation.
 *
 * Both are domain-level public lookups requiring no account access, which is
 * exactly why they are the only way to observe a customer-held domain at all.
 *
 * READ-ONLY. Resolver queries and one optional `whois` process. Nothing is
 * written anywhere, no zone is touched, no registrar is contacted.
 */
class DnsObserver
{
    private const RECORD_TYPES = [
        'A' => DNS_A, 'AAAA' => DNS_AAAA, 'CNAME' => DNS_CNAME,
        'MX' => DNS_MX, 'TXT' => DNS_TXT, 'NS' => DNS_NS, 'CAA' => DNS_CAA,
    ];

    /**
     * @param array<string,mixed> $desired
     * @return array<string,mixed>
     */
    public function observe(string $host, string $custody, array $desired = []): array
    {
        $t0 = microtime(true);
        $records = [];
        $error = null;

        foreach (self::RECORD_TYPES as $label => $const) {
            try {
                $rows = @dns_get_record($host, $const);
            } catch (\Throwable $e) {
                $error = 'DNS_LOOKUP_FAILED';
                $rows = false;
            }

            $records[$label] = $rows === false ? null : $this->flatten($label, (array) $rows);
        }

        // Apex A is the minimum viable signal. Nothing at all means the name
        // does not resolve — which is an outage, not a slow lookup.
        $resolves = ! empty($records['A']) || ! empty($records['AAAA']) || ! empty($records['CNAME']);

        $observed = [
            'records' => $records,
            'resolves' => $resolves,
            'has_mx' => ! empty($records['MX']),
            'nameservers' => $records['NS'] ?? [],
        ];

        return [
            'dimension' => 'dns',
            'provider' => 'dns_resolver',
            'success' => $resolves,
            'confidence' => Custody::confidenceFor($custody, 'dns'),
            'duration_ms' => (int) round((microtime(true) - $t0) * 1000),
            'error_code' => $resolves ? null : ($error ?? 'NXDOMAIN_OR_NO_RECORDS'),
            'error_summary' => $resolves ? null : 'Hostname returned no A, AAAA or CNAME record.',
            'observed' => $observed,
            'desired' => $desired,
            'drift' => $this->drift($observed, $desired, $resolves),
        ];
    }

    /** @return array<int,array<string,mixed>> */
    private function drift(array $obs, array $desired, bool $resolves): array
    {
        $d = [];

        if (! $resolves) {
            $d[] = $this->finding(DomainDrift::UNKNOWN, 'critical',
                'Hostname does not resolve', null, null,
                'DNS returns no address record. The site is unreachable by name.');

            return $d;
        }

        // Nameserver delegation — the hijack signature.
        if (! empty($desired['nameservers']) && ! empty($obs['nameservers'])) {
            $a = array_map('strtolower', $obs['nameservers']);
            $b = array_map('strtolower', $desired['nameservers']);
            sort($a);
            sort($b);

            if ($a !== $b) {
                $d[] = $this->finding(DomainDrift::NAMESERVER_DRIFT, 'high',
                    'Nameservers differ from the recorded delegation', $a, $b,
                    'Delegation changed. Confirm it was authorised; if not, treat as a security incident.');
            }
        }

        // Origin expectation — only asserted when the estate holds one.
        if (! empty($desired['expected_a'])) {
            $expected = (array) $desired['expected_a'];
            $actual = $obs['records']['A'] ?? [];

            if ($actual !== [] && array_intersect($expected, $actual) === []) {
                $d[] = $this->finding(DomainDrift::UNKNOWN, 'high',
                    'Apex A record does not point at the expected origin', $actual, $expected,
                    'Traffic is being sent somewhere we do not serve. Confirm the change was intended.');
            }
        }

        // MX disappearing is an email outage nobody notices until replies stop.
        if (($desired['has_mx'] ?? false) === true && $obs['has_mx'] === false) {
            $d[] = $this->finding(DomainDrift::UNKNOWN, 'critical',
                'MX records have disappeared', [], $desired['mx'] ?? [],
                'Mail for this domain will bounce. Restore MX before anything else.');
        }

        return $d;
    }

    /**
     * WHOIS — the ONLY expiry signal for a domain we do not register.
     *
     * Deliberately low-confidence: WHOIS output is unstandardised across TLDs,
     * frequently rate-limited, and increasingly redacted by privacy services. A
     * parsed expiry is a useful hint, never an authority.
     *
     * @return array<string,mixed>
     */
    public function whois(string $host, string $custody, array $desired = []): array
    {
        $t0 = microtime(true);

        if (! $this->whoisAvailable()) {
            return $this->whoisResult($host, $custody, $t0, false, [],
                'WHOIS_UNAVAILABLE', 'No whois client is installed on this host.', []);
        }

        $out = @shell_exec('timeout 15 whois ' . escapeshellarg($host) . ' 2>/dev/null');

        if (! is_string($out) || trim($out) === '') {
            return $this->whoisResult($host, $custody, $t0, false, [],
                'WHOIS_EMPTY', 'WHOIS returned nothing (rate limited, or TLD not served).', []);
        }

        $observed = [
            'expires_at' => $this->grepDate($out, ['registry expiry date', 'expiry date', 'expiration date', 'paid-till', 'renewal date']),
            'created_at' => $this->grepDate($out, ['creation date', 'created on', 'registered on']),
            'registrar' => $this->grepValue($out, ['registrar:', 'sponsoring registrar:']),
            'status' => $this->grepAll($out, 'domain status:'),
            'nameservers' => array_map('strtolower', $this->grepAll($out, 'name server:')),
            'ownership_visible' => ! preg_match('/redacted|privacy|data protected|not disclosed/i', $out),
        ];

        return $this->whoisResult($host, $custody, $t0, true, $observed, null, null,
            $this->whoisDrift($observed, $desired));
    }

    /** @return array<int,array<string,mixed>> */
    private function whoisDrift(array $obs, array $desired): array
    {
        $d = [];

        if (! empty($obs['expires_at'])) {
            $exp = Carbon::parse($obs['expires_at']);
            $days = (int) now()->diffInDays($exp, false);

            if ($days < 0) {
                $d[] = $this->finding(DomainDrift::EXPIRY_EARLIER, 'critical',
                    'WHOIS reports this domain as already expired', $exp->toDateString(), null,
                    'The customer must renew with their own registrar immediately. We cannot renew it for them.');
            } elseif ($days <= 30) {
                $d[] = $this->finding(DomainDrift::EXPIRY_EARLIER, 'high',
                    "Domain expires in {$days} day(s) and is not renewable by us",
                    $exp->toDateString(), null,
                    'Customer-held domain. Warn the customer now — we have no ability to renew it.');
            }

            if (! empty($desired['expires_at'])) {
                $w = Carbon::parse($desired['expires_at']);

                if (! $exp->isSameDay($w)) {
                    $d[] = $this->finding(DomainDrift::EXPIRY_LATER, 'medium',
                        'WHOIS expiry differs from the recorded expiry',
                        $exp->toDateString(), $w->toDateString(),
                        'Update the recorded expiry deliberately after confirming the WHOIS reading.');
                }
            }
        }

        foreach ((array) $obs['status'] as $s) {
            if (preg_match('/clienthold|serverhold|pendingdelete|redemption/i', $s)) {
                $d[] = $this->finding(DomainDrift::STATUS_DRIFT, 'critical',
                    'Registrar reports a hold or deletion status: ' . $s, $s, 'ok',
                    'The domain is suspended or being deleted. Contact the customer urgently.');
            }
        }

        return $d;
    }

    private function whoisAvailable(): bool
    {
        $p = @shell_exec('command -v whois 2>/dev/null');

        return is_string($p) && trim($p) !== '';
    }

    /** @return array<string,mixed> */
    private function whoisResult(string $host, string $custody, float $t0, bool $ok, array $obs, ?string $code, ?string $msg, array $drift): array
    {
        return [
            'dimension' => 'whois',
            'provider' => 'whois_cli',
            'success' => $ok,
            'confidence' => Custody::confidenceFor($custody, 'whois'),
            'duration_ms' => (int) round((microtime(true) - $t0) * 1000),
            'error_code' => $code,
            'error_summary' => $msg,
            'observed' => $obs,
            'desired' => [],
            'drift' => $drift,
        ];
    }

    // ── parsing helpers ──────────────────────────────────────────────────────

    private function flatten(string $type, array $rows): array
    {
        $out = [];

        foreach ($rows as $r) {
            $out[] = match ($type) {
                'A' => $r['ip'] ?? null,
                'AAAA' => $r['ipv6'] ?? null,
                'CNAME', 'NS' => $r['target'] ?? null,
                'MX' => ($r['pri'] ?? '') . ' ' . ($r['target'] ?? ''),
                'TXT' => $r['txt'] ?? null,
                'CAA' => ($r['tag'] ?? '') . ' ' . ($r['value'] ?? ''),
                default => null,
            };
        }

        return array_values(array_filter($out, fn ($v) => $v !== null && $v !== ''));
    }

    private function grepDate(string $body, array $labels): ?string
    {
        foreach ($labels as $l) {
            if (preg_match('/^\s*' . preg_quote($l, '/') . '\s*:?\s*(.+)$/im', $body, $m)) {
                try {
                    return Carbon::parse(trim($m[1]))->toDateTimeString();
                } catch (\Throwable) {
                    continue;
                }
            }
        }

        return null;
    }

    private function grepValue(string $body, array $labels): ?string
    {
        foreach ($labels as $l) {
            if (preg_match('/^\s*' . preg_quote($l, '/') . '\s*(.+)$/im', $body, $m)) {
                return trim($m[1]);
            }
        }

        return null;
    }

    private function grepAll(string $body, string $label): array
    {
        preg_match_all('/^\s*' . preg_quote($label, '/') . '\s*(.+)$/im', $body, $m);

        return array_values(array_unique(array_map('trim', $m[1] ?? [])));
    }

    private function finding(string $class, string $severity, string $title, $observed, $desired, string $action): array
    {
        return [
            'class' => $class, 'severity' => $severity, 'title' => $title,
            'observed' => $observed, 'desired' => $desired, 'recommended_action' => $action,
        ];
    }
}
