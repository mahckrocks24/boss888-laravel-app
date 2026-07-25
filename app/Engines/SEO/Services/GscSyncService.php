<?php

namespace App\Engines\SEO\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * GSC data sync — pulls the workspace's Search Console performance rows
 * and persists them RAW into gsc_metrics. No scoring here (that is the
 * runtime's job, Phase 3). Idempotent: re-running for the same snapshot
 * date upserts on (workspace_id, row_hash) so counts never double.
 *
 * Data lag: Search Console finalises data ~2-3 days behind, so the window
 * ends 3 days ago and spans the trailing 28 days. The row `date` is the
 * window end (the snapshot anchor) — re-syncing the same day overwrites,
 * a new day appends a fresh snapshot, giving a daily time series for the
 * runtime to detect trends/decline.
 */
class GscSyncService
{
    public function __construct(private GscClient $client) {}

    private const LAG_DAYS = 3;
    private const WINDOW_DAYS = 28;

    /**
     * Pull + persist for one workspace.
     * Signature matches the Orchestrator dispatch contract ($wsId, $params).
     *
     * @return array{success:bool,rows_synced:int,site:string,window:array,message?:string}
     */
    public function sync(int $workspaceId, array $params = []): array
    {
        $conn = $this->client->getConnection($workspaceId);
        if ($conn === null || ! $conn->connected || empty($conn->site_url)) {
            return [
                'success'     => false,
                'rows_synced' => 0,
                'site'        => (string) ($conn->site_url ?? ''),
                'window'      => [],
                'message'     => 'Connect Google Search Console and choose a property first.',
            ];
        }

        $window = (int) ($params['days'] ?? self::WINDOW_DAYS);
        $window = max(1, min($window, 90));

        $endDate = Carbon::now()->subDays(self::LAG_DAYS);
        $startDate = $endDate->copy()->subDays($window - 1);
        $end = $endDate->toDateString();
        $start = $startDate->toDateString();

        $rows = $this->client->fetchSearchAnalytics(
            $workspaceId, $conn->site_url, $start, $end, (int) ($params['row_limit'] ?? 5000)
        );

        $count = $this->persistRows($workspaceId, $conn->site_url, $rows, $end);
        $this->client->markSynced($workspaceId);

        return [
            'success'     => true,
            'rows_synced' => $count,
            'site'        => $conn->site_url,
            'window'      => ['start' => $start, 'end' => $end],
        ];
    }

    /**
     * Upsert raw GSC rows into gsc_metrics. Rows are the Search Console API
     * shape: each has keys=[page, query] (dimensions order) + clicks /
     * impressions / ctr / position. Returns the number of rows written.
     *
     * Public + decoupled from the live fetch so it is independently testable
     * with synthetic rows (idempotency proof without live credentials).
     */
    public function persistRows(int $workspaceId, string $siteUrl, array $rows, string $snapshotDate): int
    {
        if (empty($rows)) {
            return 0;
        }

        $now = Carbon::now();
        $batch = [];
        foreach ($rows as $row) {
            $keys = $row['keys'] ?? [];
            $page = (string) ($keys[0] ?? ($row['page'] ?? ''));
            $query = (string) ($keys[1] ?? ($row['query'] ?? ''));
            if ($page === '' && $query === '') {
                continue;
            }
            // Trim to column widths (page 768 / query 512) to avoid truncation errors.
            $page = mb_substr($page, 0, 768);
            $query = mb_substr($query, 0, 512);
            $rowHash = md5($siteUrl . '|' . $page . '|' . $query . '|' . $snapshotDate);

            $batch[] = [
                'workspace_id' => $workspaceId,
                'site_url'     => mb_substr($siteUrl, 0, 255),
                'page'         => $page,
                'query'        => $query,
                'row_hash'     => $rowHash,
                'clicks'       => (int) round((float) ($row['clicks'] ?? 0)),
                'impressions'  => (int) round((float) ($row['impressions'] ?? 0)),
                'ctr'          => round((float) ($row['ctr'] ?? 0), 5),
                'position'     => round((float) ($row['position'] ?? 0), 2),
                'date'         => $snapshotDate,
                'synced_at'    => $now,
                'created_at'   => $now,
                'updated_at'   => $now,
            ];
        }
        if (empty($batch)) {
            return 0;
        }

        // Idempotent: conflict on (workspace_id, row_hash) updates the metrics.
        foreach (array_chunk($batch, 500) as $chunk) {
            DB::table('gsc_metrics')->upsert(
                $chunk,
                ['workspace_id', 'row_hash'],
                ['clicks', 'impressions', 'ctr', 'position', 'page', 'query', 'site_url', 'date', 'synced_at', 'updated_at']
            );
        }

        return count($batch);
    }
}
