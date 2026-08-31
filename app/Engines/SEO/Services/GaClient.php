<?php

namespace App\Engines\SEO\Services;

use App\Models\GscConnection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Google Analytics (GA4) client.
 *
 * Shares the SAME Google connection as Search Console (same OAuth tokens,
 * stored on gsc_connections) — it just needs the extra analytics.readonly
 * scope (added to GscClient::SCOPE) and the Analytics Data + Admin APIs
 * enabled on the project. Token custody/refresh is delegated to GscClient.
 *
 *   - Admin API  (analyticsadmin) → list the account's GA4 properties
 *   - Data API   (analyticsdata)  → runReport / batchRunReports for metrics
 *
 * Like GSC: Laravel fetches the raw numbers; any heavier "why did traffic
 * move" intelligence belongs in the runtime (future pass). The plain
 * visitor report below is direct metrics, no scoring.
 */
class GaClient
{
    private const ADMIN_BASE = 'https://analyticsadmin.googleapis.com/v1beta';
    private const DATA_BASE = 'https://analyticsdata.googleapis.com/v1beta';
    private int $timeout;

    public function __construct(private GscClient $gsc)
    {
        $this->timeout = (int) (config('connectors.gsc.timeout', 15));
    }

    private function token(int $workspaceId): string
    {
        return $this->gsc->getAccessToken($workspaceId); // shared connection + auto-refresh
    }

    public function getConnection(int $workspaceId): ?GscConnection
    {
        return $this->gsc->getConnection($workspaceId);
    }

    public function isConnected(int $workspaceId): bool
    {
        $c = $this->getConnection($workspaceId);
        return $c !== null && ! empty($c->ga_property_id) && ! empty($c->refresh_token_enc);
    }

    /**
     * List the GA4 properties this account can read.
     * @return array<int,array{property:string,id:string,name:string,account:string}>
     */
    public function listProperties(int $workspaceId): array
    {
        $resp = Http::withToken($this->token($workspaceId))
            ->timeout($this->timeout)
            ->get(self::ADMIN_BASE . '/accountSummaries', ['pageSize' => 200]);

        if (! $resp->successful()) {
            throw new RuntimeException('Could not list Analytics properties (HTTP ' . $resp->status() . ').');
        }

        $out = [];
        foreach ($resp->json('accountSummaries') ?: [] as $acct) {
            foreach ($acct['propertySummaries'] ?? [] as $p) {
                $prop = (string) ($p['property'] ?? ''); // "properties/123456789"
                if ($prop === '') {
                    continue;
                }
                $out[] = [
                    'property' => $prop,
                    'id'       => str_replace('properties/', '', $prop),
                    'name'     => (string) ($p['displayName'] ?? $prop),
                    'account'  => (string) ($acct['displayName'] ?? ''),
                ];
            }
        }
        return $out;
    }

    /** Reduce a GSC site_url / any URL to a bare host (no scheme/www/path). */
    public static function bareDomain(?string $s): ?string
    {
        if (! $s) {
            return null;
        }
        $s = preg_replace('#^sc-domain:#', '', trim($s));
        $s = preg_replace('#^https?://#', '', (string) $s);
        $s = preg_replace('#/.*$#', '', (string) $s);
        $s = preg_replace('#^www\.#', '', strtolower((string) $s));
        return $s ?: null;
    }

    /** The website domain a GA4 property tracks (first web data stream). */
    public function propertyDomain(int $workspaceId, string $propertyId): ?string
    {
        try {
            $r = Http::withToken($this->token($workspaceId))->timeout($this->timeout)
                ->get(self::ADMIN_BASE . '/properties/' . $propertyId . '/dataStreams');
            foreach ($r->json('dataStreams') ?: [] as $ds) {
                $uri = $ds['webStreamData']['defaultUri'] ?? null;
                if ($uri) {
                    return self::bareDomain($uri);
                }
            }
        } catch (\Throwable $e) {
            // ignore — property just won't get a domain
        }
        return null;
    }

    /**
     * Properties that track THIS workspace's own site (domain match against
     * the connected GSC site). Returns ['matched' => [...], 'all' => [...],
     * 'workspace_domain' => string|null] so the UI can show the relevant one
     * and still offer a manual override if nothing matches.
     */
    public function scopedProperties(int $workspaceId): array
    {
        $conn = $this->getConnection($workspaceId);
        $wsDomain = self::bareDomain($conn->site_url ?? null);
        $all = $this->listProperties($workspaceId);
        foreach ($all as &$p) {
            $p['domain'] = $this->propertyDomain($workspaceId, $p['id']);
        }
        unset($p);

        $matched = [];
        if ($wsDomain) {
            $matched = array_values(array_filter($all, function ($p) use ($wsDomain) {
                $d = $p['domain'] ?? null;
                return $d && ($d === $wsDomain || str_contains($d, $wsDomain) || str_contains($wsDomain, $d));
            }));
        }
        return ['matched' => $matched, 'all' => $all, 'workspace_domain' => $wsDomain];
    }

    /**
     * The GA4 Measurement ID (G-XXXX) of the property that tracks this
     * workspace's site — read straight from Google, so the user never has to
     * copy/paste it. Null if no matching property or no web stream.
     */
    public function detectedMeasurementId(int $workspaceId): ?string
    {
        $sp = $this->scopedProperties($workspaceId);
        $prop = $sp['matched'][0] ?? null;
        if (! $prop) {
            return null;
        }
        try {
            $r = Http::withToken($this->token($workspaceId))->timeout($this->timeout)
                ->get(self::ADMIN_BASE . '/properties/' . $prop['id'] . '/dataStreams');
            foreach ($r->json('dataStreams') ?: [] as $ds) {
                $mid = $ds['webStreamData']['measurementId'] ?? null;
                if ($mid) {
                    return $mid;
                }
            }
        } catch (\Throwable $e) {
            // ignore
        }
        return null;
    }

    /**
     * INC-0006 - an Analytics property measures ONE website. This used to update every row for the
     * workspace, so a business with two sites had the second choice overwrite the first. The choice
     * now lands on the named website, or on the business-wide default when none is named.
     */
    public function setProperty(int $workspaceId, string $propertyId, ?string $name = null, ?int $websiteId = null): void
    {
        $id = str_replace('properties/', '', $propertyId);
        $target = ($websiteId !== null && $websiteId > 0)
            ? $websiteId
            : \App\Core\Tenancy\WebsiteScope::BUSINESS_DEFAULT;

        GscConnection::updateOrCreate(
            ['workspace_id' => $workspaceId, 'website_id' => $target],
            ['ga_property_id' => $id, 'ga_property_name' => $name],
        );
    }

    /**
     * The website-visitors report: totals, daily trend, top pages, channels,
     * devices, and countries over the trailing window. One batched call.
     */
    /**
     * Light GA totals (single runReport) — for the agent read-tool, where a
     * full report would be too slow. Null if no property selected.
     */
    public function quickTotals(int $workspaceId, int $days = 28): ?array
    {
        $c = $this->getConnection($workspaceId);
        if ($c === null || empty($c->ga_property_id)) {
            return null;
        }
        $end = Carbon::now()->subDay()->toDateString();
        $start = Carbon::now()->subDays($days)->toDateString();
        try {
            $resp = Http::withToken($this->token($workspaceId))->timeout($this->timeout)
                ->post(self::DATA_BASE . '/properties/' . $c->ga_property_id . ':runReport', [
                    'dateRanges' => [['startDate' => $start, 'endDate' => $end]],
                    'metrics' => [['name' => 'totalUsers'], ['name' => 'sessions'], ['name' => 'screenPageViews'], ['name' => 'engagementRate']],
                ]);
            if (! $resp->successful()) {
                return null;
            }
            $row = $resp->json('rows.0.metricValues') ?: [];
            $n = fn ($i) => isset($row[$i]['value']) ? (float) $row[$i]['value'] : 0.0;
            return [
                'users'      => (int) round($n(0)),
                'sessions'   => (int) round($n(1)),
                'pageviews'  => (int) round($n(2)),
                'engagement' => round($n(3) * 100, 1),
                'property'   => $c->ga_property_id,
                'name'       => $c->ga_property_name,
            ];
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Comprehensive GA4 report — totals + every breakdown the dashboard shows
     * (acquisition, content, tech, geography, demographics, events). GA4 caps
     * batchRunReports at 5 requests, so the ~17 reports run in chunks of 5;
     * each chunk is resilient (a failing chunk just omits those sections —
     * e.g. demographics need Google Signals — instead of failing the whole
     * report).
     */
    public function visitorReport(int $workspaceId, int $days = 28): array
    {
        $c = $this->getConnection($workspaceId);
        if ($c === null || empty($c->ga_property_id)) {
            throw new RuntimeException('No Google Analytics property selected.');
        }

        $end = Carbon::now()->subDay()->toDateString();        // GA finalises ~1 day back
        $start = Carbon::now()->subDays($days)->toDateString();
        $range = [['startDate' => $start, 'endDate' => $end]];

        // key => [dimensions[], metrics[], orderByMetric|'date'|null, limit]
        $defs = [
            'totals'        => [[], ['totalUsers', 'newUsers', 'sessions', 'screenPageViews', 'averageSessionDuration', 'engagementRate', 'bounceRate', 'eventCount'], null, 1],
            'trend'         => [['date'], ['totalUsers', 'sessions'], 'date', 0],
            'channels'      => [['sessionDefaultChannelGroup'], ['sessions'], 'sessions', 10],
            'sources'       => [['sessionSourceMedium'], ['sessions'], 'sessions', 10],
            'top_pages'     => [['pagePath'], ['screenPageViews'], 'screenPageViews', 12],
            'landing_pages' => [['landingPage'], ['sessions'], 'sessions', 10],
            'devices'       => [['deviceCategory'], ['totalUsers'], 'totalUsers', 6],
            'browsers'      => [['browser'], ['totalUsers'], 'totalUsers', 8],
            'os'            => [['operatingSystem'], ['totalUsers'], 'totalUsers', 8],
            'countries'     => [['country'], ['totalUsers'], 'totalUsers', 10],
            'cities'        => [['city'], ['totalUsers'], 'totalUsers', 10],
            'languages'     => [['language'], ['totalUsers'], 'totalUsers', 8],
            'age'           => [['userAgeBracket'], ['totalUsers'], 'totalUsers', 10],
            'gender'        => [['userGender'], ['totalUsers'], 'totalUsers', 10],
            'new_returning' => [['newVsReturning'], ['totalUsers'], 'totalUsers', 5],
            'events'        => [['eventName'], ['eventCount'], 'eventCount', 12],
        ];

        $keys = array_keys($defs);
        $requests = [];
        foreach ($defs as $k => [$dims, $mets, $order, $limit]) {
            $req = ['dateRanges' => $range, 'metrics' => array_map(fn ($m) => ['name' => $m], $mets)];
            if ($dims) {
                $req['dimensions'] = array_map(fn ($d) => ['name' => $d], $dims);
            }
            if ($order === 'date') {
                $req['orderBys'] = [['dimension' => ['dimensionName' => 'date']]];
            } elseif ($order) {
                $req['orderBys'] = [['metric' => ['metricName' => $order], 'desc' => true]];
            }
            if ($limit) {
                $req['limit'] = $limit;
            }
            $requests[] = $req;
        }

        // Run in chunks of 5 (GA4 batch limit). Preserve indices to map results.
        $reports = [];
        foreach (array_chunk($requests, 5, true) as $chunk) {
            try {
                $resp = Http::withToken($this->token($workspaceId))->timeout(max($this->timeout, 45))
                    ->post(self::DATA_BASE . '/properties/' . $c->ga_property_id . ':batchRunReports', [
                        'requests' => array_values($chunk),
                    ]);
                if (! $resp->successful()) {
                    continue; // skip this chunk's sections, keep the rest
                }
                $chunkKeys = array_keys($chunk);
                foreach ($resp->json('reports') ?: [] as $i => $rep) {
                    if (isset($chunkKeys[$i])) {
                        $reports[$keys[$chunkKeys[$i]]] = $rep;
                    }
                }
            } catch (\Throwable $e) {
                // network/timeout on one chunk — keep whatever else succeeded
            }
        }

        $list = function (?array $rep) {
            $out = [];
            foreach ($rep['rows'] ?? [] as $row) {
                $label = (string) ($row['dimensionValues'][0]['value'] ?? '');
                if ($label === '' || $label === '(not set)') {
                    $label = $label === '' ? '(unknown)' : $label;
                }
                $out[] = ['label' => $label, 'value' => (int) round((float) ($row['metricValues'][0]['value'] ?? 0))];
            }
            return $out;
        };

        $totalsRow = $reports['totals']['rows'][0]['metricValues'] ?? [];
        $n = fn ($i) => isset($totalsRow[$i]['value']) ? (float) $totalsRow[$i]['value'] : 0.0;

        $topPages = [];
        foreach ($reports['top_pages']['rows'] ?? [] as $row) {
            $topPages[] = [
                'label' => (string) ($row['dimensionValues'][0]['value'] ?? ''),
                'value' => (int) round((float) ($row['metricValues'][0]['value'] ?? 0)),
            ];
        }

        return [
            'property' => $c->ga_property_id,
            'name'     => $c->ga_property_name,
            'window'   => ['start' => $start, 'end' => $end],
            'totals'   => [
                'users'         => (int) round($n(0)),
                'new_users'     => (int) round($n(1)),
                'sessions'      => (int) round($n(2)),
                'pageviews'     => (int) round($n(3)),
                'avg_session_s' => round($n(4), 1),
                'engagement'    => round($n(5) * 100, 1),
                'bounce'        => round($n(6) * 100, 1),
                'events'        => (int) round($n(7)),
            ],
            'trend'         => array_map(function ($row) {
                $d = (string) ($row['dimensionValues'][0]['value'] ?? '');
                return [
                    'date'     => strlen($d) === 8 ? substr($d, 0, 4) . '-' . substr($d, 4, 2) . '-' . substr($d, 6, 2) : $d,
                    'users'    => (int) round((float) ($row['metricValues'][0]['value'] ?? 0)),
                    'sessions' => (int) round((float) ($row['metricValues'][1]['value'] ?? 0)),
                ];
            }, $reports['trend']['rows'] ?? []),
            'channels'      => $list($reports['channels'] ?? null),
            'sources'       => $list($reports['sources'] ?? null),
            'top_pages'     => $topPages,
            'landing_pages' => $list($reports['landing_pages'] ?? null),
            'devices'       => $list($reports['devices'] ?? null),
            'browsers'      => $list($reports['browsers'] ?? null),
            'os'            => $list($reports['os'] ?? null),
            'countries'     => $list($reports['countries'] ?? null),
            'cities'        => $list($reports['cities'] ?? null),
            'languages'     => $list($reports['languages'] ?? null),
            'age'           => $list($reports['age'] ?? null),
            'gender'        => $list($reports['gender'] ?? null),
            'new_returning' => $list($reports['new_returning'] ?? null),
            'events'        => $list($reports['events'] ?? null),
        ];
    }
}
