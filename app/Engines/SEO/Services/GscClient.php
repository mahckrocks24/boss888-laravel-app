<?php

namespace App\Engines\SEO\Services;

use App\Models\GscConnection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Google Search Console OAuth 2.0 client — Laravel port of the proven
 * LUGS_GSC_Client from the LevelUp SEO Suite WP plugin (v5.13.1).
 *
 * Architecture (hands vs brain):
 *   - This class lives in Laravel (the brain): OAuth handshake, encrypted
 *     token custody, auto-refresh, and the authenticated Google fetch.
 *   - It NEVER scores or ranks anything. The raw rows it returns are handed
 *     to the Node runtime (gsc_intelligence tool) for ALL analysis.
 *
 * Credential model B (platform OAuth app):
 *   - ONE LevelUp-owned client_id/secret (config/connectors.php → env) brokers
 *     sign-in for every workspace. Each workspace connects its OWN Google
 *     account + property; only tokens/data are per-workspace (gsc_connections).
 *
 * Tokens are stored encrypted (Crypt::encryptString) and never logged, never
 * returned to the browser, and never sent to the runtime.
 */
class GscClient
{
    private const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    private const API_BASE = 'https://www.googleapis.com/webmasters/v3';
    // Both Search Console AND Analytics (GA4) — one consent grants both, since
    // they share this single Google connection per workspace.
    private const SCOPE = 'https://www.googleapis.com/auth/webmasters.readonly https://www.googleapis.com/auth/analytics.readonly';

    private string $clientId;
    private string $clientSecret;
    private string $redirectUri;
    private int $timeout;
    private int $stateTtl;

    public function __construct()
    {
        $cfg = config('connectors.gsc', []);
        $this->clientId = (string) ($cfg['client_id'] ?? '');
        $this->clientSecret = (string) ($cfg['client_secret'] ?? '');
        $this->redirectUri = (string) ($cfg['redirect_uri'] ?? '');
        $this->timeout = (int) ($cfg['timeout'] ?? 15);
        $this->stateTtl = (int) ($cfg['state_ttl'] ?? 600);
    }

    /** True when the platform OAuth app is configured (creds present). */
    public function isConfigured(): bool
    {
        return $this->clientId !== '' && $this->clientSecret !== '' && $this->redirectUri !== '';
    }

    /*--------------------------------------------------------------
     | Connection state
     *------------------------------------------------------------*/

    public function getConnection(int $workspaceId): ?GscConnection
    {
        return GscConnection::where('workspace_id', $workspaceId)->first();
    }

    public function isConnected(int $workspaceId): bool
    {
        $c = $this->getConnection($workspaceId);
        return $c !== null && (bool) $c->connected && ! empty($c->refresh_token_enc);
    }

    /*--------------------------------------------------------------
     | Signed state (the header-less callback resolves workspace from this)
     *------------------------------------------------------------*/

    public function makeState(int $workspaceId): string
    {
        return Crypt::encryptString(json_encode([
            'ws' => $workspaceId,
            'n'  => Str::random(10),
            't'  => time(),
        ]));
    }

    /** @return int|null workspace_id, or null if invalid/expired/tampered */
    public function decodeState(string $state): ?int
    {
        try {
            $data = json_decode(Crypt::decryptString($state), true);
        } catch (\Throwable $e) {
            return null;
        }
        if (! is_array($data) || empty($data['ws']) || empty($data['t'])) {
            return null;
        }
        if ((time() - (int) $data['t']) > $this->stateTtl) {
            return null; // expired
        }
        return (int) $data['ws'];
    }

    /*--------------------------------------------------------------
     | OAuth 2.0 flow
     *------------------------------------------------------------*/

    public function getAuthUrl(int $workspaceId): string
    {
        return self::AUTH_URL . '?' . http_build_query([
            'client_id'             => $this->clientId,
            'redirect_uri'          => $this->redirectUri,
            'response_type'         => 'code',
            'scope'                 => self::SCOPE,
            'access_type'           => 'offline',
            'prompt'                => 'consent', // force refresh_token every time
            'include_granted_scopes' => 'true',
            'state'                 => $this->makeState($workspaceId),
        ]);
    }

    /**
     * Exchange an authorization code for tokens.
     * @return array{access_token:string,expires_in?:int,refresh_token?:string}
     */
    public function exchangeCode(string $code): array
    {
        $resp = Http::asForm()->timeout($this->timeout)->post(self::TOKEN_URL, [
            'code'          => $code,
            'client_id'     => $this->clientId,
            'client_secret' => $this->clientSecret,
            'redirect_uri'  => $this->redirectUri,
            'grant_type'    => 'authorization_code',
        ]);

        $body = $resp->json() ?: [];
        if (! $resp->successful() || empty($body['access_token'])) {
            throw new RuntimeException(
                (string) ($body['error_description'] ?? $body['error'] ?? 'Token exchange failed')
            );
        }
        return $body;
    }

    /**
     * Persist tokens for a workspace (upsert). Tokens encrypted at rest.
     */
    public function storeTokens(int $workspaceId, array $tokenData, ?string $email = null): void
    {
        $attrs = [
            'provider'         => 'gsc',
            'access_token_enc' => Crypt::encryptString((string) $tokenData['access_token']),
            'token_expires_at' => Carbon::now()->addSeconds((int) ($tokenData['expires_in'] ?? 3600)),
        ];
        // Google only returns refresh_token on the first consent (or with
        // prompt=consent). Never overwrite a good one with an absent one.
        if (! empty($tokenData['refresh_token'])) {
            $attrs['refresh_token_enc'] = Crypt::encryptString((string) $tokenData['refresh_token']);
        }
        if ($email !== null) {
            $attrs['connected_email'] = $email;
        }

        GscConnection::updateOrCreate(['workspace_id' => $workspaceId], $attrs);
    }

    public function setSite(int $workspaceId, string $siteUrl): void
    {
        GscConnection::where('workspace_id', $workspaceId)->update([
            'site_url'  => $siteUrl,
            'connected' => true,
        ]);
    }

    public function markSynced(int $workspaceId): void
    {
        GscConnection::where('workspace_id', $workspaceId)->update(['last_sync_at' => now()]);
    }

    public function disconnect(int $workspaceId): void
    {
        GscConnection::where('workspace_id', $workspaceId)->update([
            'connected'         => false,
            'site_url'          => null,
            'access_token_enc'  => null,
            'refresh_token_enc' => null,
            'token_expires_at'  => null,
            'last_sync_at'      => null,
            'connected_email'   => null,
        ]);
    }

    /**
     * Return a valid access token for the workspace, refreshing if it is
     * within 5 minutes of expiry.
     */
    public function getAccessToken(int $workspaceId): string
    {
        $c = $this->getConnection($workspaceId);
        if ($c === null || empty($c->access_token_enc)) {
            throw new RuntimeException('Google Search Console is not connected.');
        }

        $expiring = $c->token_expires_at === null
            || $c->token_expires_at->lessThan(Carbon::now()->addMinutes(5));

        if ($expiring) {
            return $this->refreshToken($workspaceId);
        }
        return Crypt::decryptString($c->access_token_enc);
    }

    private function refreshToken(int $workspaceId): string
    {
        $c = $this->getConnection($workspaceId);
        if ($c === null || empty($c->refresh_token_enc)) {
            throw new RuntimeException('Missing refresh token — please reconnect Google Search Console.');
        }
        $refresh = Crypt::decryptString($c->refresh_token_enc);

        $resp = Http::asForm()->timeout($this->timeout)->post(self::TOKEN_URL, [
            'refresh_token' => $refresh,
            'client_id'     => $this->clientId,
            'client_secret' => $this->clientSecret,
            'grant_type'    => 'refresh_token',
        ]);

        $body = $resp->json() ?: [];

        // Refresh token revoked (user removed access in their Google account)
        // → mark disconnected so the UI prompts a clean reconnect.
        if ($resp->status() === 400 && (($body['error'] ?? '') === 'invalid_grant')) {
            $this->disconnect($workspaceId);
            throw new RuntimeException('Google access was revoked — please reconnect Google Search Console.');
        }
        if (! $resp->successful() || empty($body['access_token'])) {
            throw new RuntimeException((string) ($body['error_description'] ?? $body['error'] ?? 'Token refresh failed'));
        }

        GscConnection::where('workspace_id', $workspaceId)->update([
            'access_token_enc' => Crypt::encryptString((string) $body['access_token']),
            'token_expires_at' => Carbon::now()->addSeconds((int) ($body['expires_in'] ?? 3600)),
        ]);

        return (string) $body['access_token'];
    }

    /*--------------------------------------------------------------
     | Search Console API
     *------------------------------------------------------------*/

    /**
     * List the Search Console properties the connected account can access.
     * @return array<int,array{siteUrl:string,permissionLevel:string}>
     */
    public function listSites(int $workspaceId): array
    {
        $token = $this->getAccessToken($workspaceId);
        $resp = Http::withToken($token)->timeout($this->timeout)->get(self::API_BASE . '/sites');

        if (! $resp->successful()) {
            throw new RuntimeException('Could not list Search Console properties (HTTP ' . $resp->status() . ').');
        }
        $entries = $resp->json('siteEntry') ?: [];
        return array_map(static fn ($e) => [
            'siteUrl'         => (string) ($e['siteUrl'] ?? ''),
            'permissionLevel' => (string) ($e['permissionLevel'] ?? ''),
        ], $entries);
    }

    /**
     * Pull search-analytics rows (page + query) for a date window.
     * Returns RAW rows — the runtime does all analysis.
     *
     * @return array<int,array{keys:array,clicks:float,impressions:float,ctr:float,position:float}>
     */
    public function fetchSearchAnalytics(int $workspaceId, string $siteUrl, string $startDate, string $endDate, int $rowLimit = 5000): array
    {
        $token = $this->getAccessToken($workspaceId);
        $endpoint = self::API_BASE . '/sites/' . rawurlencode($siteUrl) . '/searchAnalytics/query';

        $resp = Http::withToken($token)->timeout($this->timeout)->post($endpoint, [
            'startDate'  => $startDate,
            'endDate'    => $endDate,
            'dimensions' => ['page', 'query'],
            'rowLimit'   => max(1, min($rowLimit, 25000)),
        ]);

        if (! $resp->successful()) {
            $err = $resp->json('error.message') ?? ('HTTP ' . $resp->status());
            throw new RuntimeException('Search Console query failed: ' . $err);
        }
        return $resp->json('rows') ?: [];
    }

    /**
     * Light Search Console totals (single dimensionless call) — for fast
     * agent tools (seo_health, search_performance). Null if not connected.
     */
    public function quickTotals(int $workspaceId, int $days = 28): ?array
    {
        $c = $this->getConnection($workspaceId);
        if ($c === null || ! $c->connected || empty($c->site_url)) {
            return null;
        }
        try {
            $token = $this->getAccessToken($workspaceId);
            $end = Carbon::now()->subDays(3)->toDateString();
            $start = Carbon::now()->subDays($days + 3)->toDateString();
            $resp = Http::withToken($token)->timeout($this->timeout)
                ->post(self::API_BASE . '/sites/' . rawurlencode($c->site_url) . '/searchAnalytics/query', [
                    'startDate' => $start, 'endDate' => $end, 'dimensions' => [],
                ]);
            if (! $resp->successful()) {
                return null;
            }
            $row = ($resp->json('rows') ?: [])[0] ?? [];
            return [
                'clicks'      => (int) round((float) ($row['clicks'] ?? 0)),
                'impressions' => (int) round((float) ($row['impressions'] ?? 0)),
                'ctr'         => round((float) ($row['ctr'] ?? 0) * 100, 2),
                'position'    => round((float) ($row['position'] ?? 0), 1),
                'site'        => $c->site_url,
            ];
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Comprehensive Search Console report for the visual dashboard: daily
     * trend (clicks/impressions/position), top queries + pages, device and
     * country splits, and a position-distribution histogram. Each dimension
     * is a separate searchAnalytics call (GSC has no batch). Raw data only —
     * no scoring. ~3-day lag is baked into the window.
     */
    public function report(int $workspaceId, int $days = 28): array
    {
        $c = $this->getConnection($workspaceId);
        if ($c === null || ! $c->connected || empty($c->site_url)) {
            throw new RuntimeException('Search Console is not connected.');
        }
        $site = $c->site_url;
        $token = $this->getAccessToken($workspaceId);
        $end = Carbon::now()->subDays(3)->toDateString();        // GSC finalises ~3 days back
        $start = Carbon::now()->subDays($days + 3)->toDateString();

        $q = function (array $dimensions, int $rowLimit) use ($token, $site, $start, $end) {
            try {
                $resp = Http::withToken($token)->timeout($this->timeout)
                    ->post(self::API_BASE . '/sites/' . rawurlencode($site) . '/searchAnalytics/query', [
                        'startDate' => $start, 'endDate' => $end,
                        'dimensions' => $dimensions, 'rowLimit' => $rowLimit,
                    ]);
                return $resp->successful() ? ($resp->json('rows') ?: []) : [];
            } catch (\Throwable $e) {
                return [];
            }
        };

        $dateRows = $q(['date'], 1000);
        $queryRows = $q(['query'], 1000);
        $pageRows = $q(['page'], 1000);
        $deviceRows = $q(['device'], 10);
        $countryRows = $q(['country'], 25);

        $trend = array_map(fn ($r) => [
            'date'        => (string) ($r['keys'][0] ?? ''),
            'clicks'      => (int) round((float) ($r['clicks'] ?? 0)),
            'impressions' => (int) round((float) ($r['impressions'] ?? 0)),
            'position'    => round((float) ($r['position'] ?? 0), 1),
        ], $dateRows);

        $topList = function (array $rows, int $n) {
            usort($rows, fn ($a, $b) => ((float) ($b['clicks'] ?? 0)) <=> ((float) ($a['clicks'] ?? 0)));
            return array_map(fn ($r) => [
                'label'       => (string) ($r['keys'][0] ?? ''),
                'clicks'      => (int) round((float) ($r['clicks'] ?? 0)),
                'impressions' => (int) round((float) ($r['impressions'] ?? 0)),
                'ctr'         => round((float) ($r['ctr'] ?? 0) * 100, 2),
                'position'    => round((float) ($r['position'] ?? 0), 1),
            ], array_slice($rows, 0, $n));
        };

        $clicksList = fn (array $rows) => array_map(fn ($r) => [
            'label' => (string) ($r['keys'][0] ?? ''),
            'value' => (int) round((float) ($r['clicks'] ?? 0)),
        ], $rows);

        // Position distribution (impressions weighted) from the query rows.
        $b = ['1-3' => 0, '4-10' => 0, '11-20' => 0, '21+' => 0];
        foreach ($queryRows as $r) {
            $p = (float) ($r['position'] ?? 0);
            $imp = (int) round((float) ($r['impressions'] ?? 0));
            if ($p <= 3) { $b['1-3'] += $imp; }
            elseif ($p <= 10) { $b['4-10'] += $imp; }
            elseif ($p <= 20) { $b['11-20'] += $imp; }
            else { $b['21+'] += $imp; }
        }

        $totClicks = array_sum(array_map(fn ($r) => (float) ($r['clicks'] ?? 0), $dateRows));
        $totImpr = array_sum(array_map(fn ($r) => (float) ($r['impressions'] ?? 0), $dateRows));
        $posW = array_sum(array_map(fn ($r) => ((float) ($r['position'] ?? 0)) * ((float) ($r['impressions'] ?? 0)), $dateRows));

        return [
            'site'   => $site,
            'window' => ['start' => $start, 'end' => $end],
            'totals' => [
                'clicks'      => (int) round($totClicks),
                'impressions' => (int) round($totImpr),
                'ctr'         => $totImpr > 0 ? round($totClicks / $totImpr * 100, 2) : 0,
                'position'    => $totImpr > 0 ? round($posW / $totImpr, 1) : 0,
            ],
            'trend'            => $trend,
            'top_queries'      => $topList($queryRows, 12),
            'top_pages'        => $topList($pageRows, 12),
            'devices'          => $clicksList($deviceRows),
            'countries'        => $clicksList(array_slice($countryRows, 0, 12)),
            'position_buckets' => [
                ['label' => '1–3', 'value' => $b['1-3']],
                ['label' => '4–10', 'value' => $b['4-10']],
                ['label' => '11–20', 'value' => $b['11-20']],
                ['label' => '21+', 'value' => $b['21+']],
            ],
        ];
    }
}
