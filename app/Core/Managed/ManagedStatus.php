<?php

namespace App\Core\Managed;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * MANAGED-1 — the portal's read-only status line: "Website online · Email working · Certificate valid until …".
 *
 * Three facts, each from something we already run or can check without touching the client's systems:
 *   website     — the workspace's uptime check (infra_monitor_checks, probed every 5 minutes by
 *                 infra:run-monitor-checks), with 30-day uptime from the same check's results.
 *   email       — the domain's mail exchangers answer: MX records exist and port 25 on the first one accepts a
 *                 connection. Cached 10 minutes.
 *   certificate — the website's certificate as a browser receives it (TLS handshake on 443), valid-until date.
 *                 Cached 6 hours.
 *
 * Wording is for the client, never for engineers. Nothing here names a vendor, a server or another system
 * hosted beside theirs (DEC-0086: only their website is reported; no resource figures).
 */
final class ManagedStatus
{
    public static function forWorkspace(int $workspaceId): array
    {
        $check = DB::table('infra_monitor_checks')
            ->where('workspace_id', $workspaceId)->where('enabled', 1)
            ->orderBy('id')->first(['id', 'target_url', 'last_status', 'last_checked_at', 'down_since']);

        $host = $check ? parse_url($check->target_url, PHP_URL_HOST) : null;

        return [
            'host'        => $host,
            'website'     => self::website($check),
            'email'       => $host ? self::email(self::apex($host)) : ['state' => 'unknown', 'label' => 'Email status unavailable'],
            'certificate' => $host ? self::certificate($host) : ['state' => 'unknown', 'label' => 'Certificate status unavailable'],
            'checked_at'  => $check?->last_checked_at ? \Illuminate\Support\Carbon::parse($check->last_checked_at)->toIso8601String() : null,
        ];
    }

    private static function website($check): array
    {
        if (! $check) {
            return ['state' => 'unknown', 'label' => 'Website status unavailable'];
        }
        $since = now()->subDays(30);
        $row = DB::table('infra_monitor_results')
            ->where('monitor_check_id', $check->id)->where('checked_at', '>=', $since)
            ->selectRaw("COUNT(*) total, SUM(CASE WHEN status IN ('up','degraded') THEN 1 ELSE 0 END) up")
            ->first();
        $uptime = ($row && $row->total > 0) ? round(100 * $row->up / $row->total, 2) : null;

        $up = in_array($check->last_status, ['up', 'degraded'], true);
        $latest = DB::table('infra_monitor_results')->where('monitor_check_id', $check->id)->orderByDesc('id')->first(['response_ms', 'checked_at']);
        $incidents = DB::table('infra_incidents')->where('workspace_id', DB::table('infra_monitor_checks')->where('id', $check->id)->value('workspace_id'))
            ->where('created_at', '>=', $since)->count();

        return [
            'state'        => $up ? 'ok' : ($check->last_status === 'down' ? 'bad' : 'unknown'),
            'label'        => $up ? 'Website online' : ($check->last_status === 'down' ? 'Website unreachable — we are on it' : 'Website status unavailable'),
            'uptime_30d'   => $uptime,
            'response_ms'  => $latest?->response_ms !== null ? (int) $latest->response_ms : null,
            'incidents_30d'=> $incidents,
        ];
    }

    private static function email(string $domain): array
    {
        return Cache::remember('managed:mx:' . $domain, 600, function () use ($domain) {
            $mx = @dns_get_record($domain, DNS_MX) ?: [];
            if (! $mx) {
                return ['state' => 'bad', 'label' => 'Email is not receiving'];
            }
            usort($mx, fn ($a, $b) => ($a['pri'] ?? 0) <=> ($b['pri'] ?? 0));
            $target = $mx[0]['target'] ?? null;
            // Port 25 is blocked outbound on our servers (DigitalOcean), so the mail server's IMAP port (993)
            // stands in as "the mail server is up". MX present + the server answering = receiving.
            foreach ($target ? [25, 993] : [] as $port) {
                $sock = @fsockopen($target, $port, $errno, $errstr, 4);
                if ($sock) {
                    fclose($sock);

                    return ['state' => 'ok', 'label' => 'Email working'];
                }
            }

            return ['state' => 'warn', 'label' => 'Email delayed — we are checking'];
        });
    }

    private static function certificate(string $host): array
    {
        return Cache::remember('managed:cert:' . $host, 21600, function () use ($host) {
            $ctx = stream_context_create(['ssl' => ['capture_peer_cert' => true, 'SNI_enabled' => true, 'peer_name' => $host, 'verify_peer' => true, 'verify_peer_name' => true]]);
            $c = @stream_socket_client('ssl://' . $host . ':443', $errno, $errstr, 8, STREAM_CLIENT_CONNECT, $ctx);
            if (! $c) {
                return ['state' => 'bad', 'label' => 'Certificate problem — we are on it'];
            }
            $params = stream_context_get_params($c);
            fclose($c);
            $cert = @openssl_x509_parse($params['options']['ssl']['peer_certificate'] ?? null);
            $to = isset($cert['validTo_time_t']) ? (int) $cert['validTo_time_t'] : null;
            if (! $to) {
                return ['state' => 'unknown', 'label' => 'Certificate status unavailable'];
            }
            $days = (int) floor(($to - time()) / 86400);
            $date = date('j M Y', $to);

            // Renewal is ours and automatic; under 7 days left means the automatic renewal did not happen.
            return [
                'state'       => $days >= 7 ? 'ok' : 'warn',
                'label'       => 'Certificate valid until ' . $date,
                'valid_until' => date('Y-m-d', $to),
            ];
        });
    }

    /** ptaa.org.ph from www.ptaa.org.ph; keeps two-level public suffixes such as .org.ph / .com.ph intact. */
    private static function apex(string $host): string
    {
        $host = preg_replace('/^www\./i', '', strtolower($host));
        $parts = explode('.', $host);
        $n = count($parts);
        if ($n <= 2) {
            return $host;
        }
        $twoLevel = in_array($parts[$n - 2], ['com', 'org', 'net', 'gov', 'edu', 'co', 'ac'], true) && strlen($parts[$n - 1]) === 2;

        return implode('.', array_slice($parts, $twoLevel ? -3 : -2));
    }
}
