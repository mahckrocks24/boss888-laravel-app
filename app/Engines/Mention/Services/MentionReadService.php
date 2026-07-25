<?php

namespace App\Engines\Mention\Services;

use Illuminate\Support\Facades\DB;

/**
 * MentionReadService — read-only views on the mention graph.
 *
 *   list()             — paged inbox with status / sentiment / source_type /
 *                        priority / source_domain / watchlist_id / q filters
 *   single()           — one mention + the watchlist label that found it
 *   listWatchlist()    — workspace's watchlist rows + per-row mention counts
 *   singleWatchlist()  — one watchlist row + recent scan history (last 5 runs)
 *   listScanRuns()     — audit log of scan executions (paginated)
 *   stats()            — dashboard counts + last-7d daily trend
 */
class MentionReadService
{
    public const ALLOWED_SORTS = [
        'discovered_at', 'published_at', 'sentiment_confidence',
        'priority', 'status', 'source_domain', 'created_at',
    ];

    public const ALLOWED_STATUSES = ['new', 'triaged', 'responded', 'spam', 'ignored', 'resolved'];

    // ── Inbox list ─────────────────────────────────────────────────────
    public function list(int $wsId, array $opts = []): array
    {
        $q = DB::table('brand_mentions')->where('workspace_id', $wsId);

        if (!empty($opts['status'])
            && in_array($opts['status'], self::ALLOWED_STATUSES, true)) {
            $q->where('status', $opts['status']);
        }
        if (!empty($opts['sentiment'])) {
            $q->where('sentiment', $opts['sentiment']);
        }
        if (!empty($opts['priority'])) {
            $q->where('priority', $opts['priority']);
        }
        if (!empty($opts['source_type'])) {
            $q->where('source_type', $opts['source_type']);
        }
        if (!empty($opts['source_domain'])) {
            $q->where('source_domain', $opts['source_domain']);
        }
        if (!empty($opts['watchlist_id'])) {
            $q->where('watchlist_id', (int) $opts['watchlist_id']);
        }
        if (!empty($opts['q'])) {
            $needle = '%' . str_replace(['%','_'], ['\%','\_'], (string) $opts['q']) . '%';
            $q->where(function ($w) use ($needle) {
                $w->where('source_title', 'like', $needle)
                  ->orWhere('excerpt', 'like', $needle)
                  ->orWhere('source_url', 'like', $needle);
            });
        }
        if (!empty($opts['since'])) {
            $q->where('discovered_at', '>=', $opts['since']);
        }

        $sort = in_array($opts['sort'] ?? '', self::ALLOWED_SORTS, true)
            ? $opts['sort'] : 'discovered_at';
        $dir  = (($opts['dir'] ?? 'desc') === 'asc') ? 'asc' : 'desc';

        // Default sort: priority high→low first, then discovered_at desc
        // (so the user sees the most urgent rows at the top of the inbox).
        if (!isset($opts['sort'])) {
            $q->orderByRaw("FIELD(priority,'high','normal','low')")
              ->orderBy('discovered_at', 'desc');
        } else {
            $q->orderBy($sort, $dir);
        }

        $total   = (clone $q)->count();
        $perPage = max(1, min(100, (int) ($opts['per_page'] ?? 25)));
        $page    = max(1, (int) ($opts['page'] ?? 1));
        $offset  = ($page - 1) * $perPage;

        $rows = $q->offset($offset)->limit($perPage)->get([
            'id','watchlist_id','term_matched','source_url','source_title',
            'source_domain','source_type','excerpt','published_at','discovered_at',
            'sentiment','sentiment_confidence','priority','status',
            'triage_notes','triaged_by','triaged_at','created_at',
        ]);

        // Attach watchlist label in one batched lookup (avoid N+1)
        $watchlistIds = $rows->pluck('watchlist_id')->unique()->filter()->toArray();
        $labels = !empty($watchlistIds)
            ? DB::table('brand_watchlist')->where('workspace_id', $wsId)
                ->whereIn('id', $watchlistIds)->pluck('label', 'id')->toArray()
            : [];

        $hydrated = $rows->map(function ($r) use ($labels) {
            $arr = (array) $r;
            $arr['watchlist_label'] = $labels[$r->watchlist_id] ?? null;
            if ($arr['sentiment_confidence'] !== null) {
                $arr['sentiment_confidence'] = (float) $arr['sentiment_confidence'];
            }
            return $arr;
        });

        return [
            'success' => true,
            'data'    => $hydrated->toArray(),
            'paging'  => [
                'page'      => $page,
                'per_page'  => $perPage,
                'total'     => $total,
                'last_page' => max(1, (int) ceil($total / $perPage)),
            ],
            'filters_applied' => array_filter([
                'status'        => $opts['status']        ?? null,
                'sentiment'     => $opts['sentiment']     ?? null,
                'priority'      => $opts['priority']      ?? null,
                'source_type'   => $opts['source_type']   ?? null,
                'source_domain' => $opts['source_domain'] ?? null,
                'watchlist_id'  => $opts['watchlist_id']  ?? null,
                'q'             => $opts['q']             ?? null,
                'since'         => $opts['since']         ?? null,
            ], fn($v) => $v !== null && $v !== ''),
            'sort' => $sort,
            'dir'  => $dir,
        ];
    }

    // ── Single mention ─────────────────────────────────────────────────
    public function single(int $wsId, int $id): array
    {
        $row = DB::table('brand_mentions')
            ->where('id', $id)->where('workspace_id', $wsId)->first();
        if (!$row) return ['success' => false, 'error' => 'mention not found'];

        $arr = (array) $row;
        $arr['metadata'] = json_decode($arr['metadata_json'] ?? '{}', true) ?: [];
        unset($arr['metadata_json']);
        if ($arr['sentiment_confidence'] !== null) {
            $arr['sentiment_confidence'] = (float) $arr['sentiment_confidence'];
        }

        $wl = DB::table('brand_watchlist')
            ->where('id', $row->watchlist_id)->where('workspace_id', $wsId)
            ->first(['id', 'label', 'term', 'scope', 'priority']);
        $arr['watchlist'] = $wl ? (array) $wl : null;

        return ['success' => true, 'data' => $arr];
    }

    // ── Watchlist list with mention counts ─────────────────────────────
    public function listWatchlist(int $wsId, array $opts = []): array
    {
        $q = DB::table('brand_watchlist')->where('workspace_id', $wsId);
        if (isset($opts['is_active'])) $q->where('is_active', (bool) $opts['is_active']);
        if (!empty($opts['scope']))    $q->where('scope', $opts['scope']);
        if (!empty($opts['q'])) {
            $needle = '%' . str_replace(['%','_'], ['\%','\_'], (string) $opts['q']) . '%';
            $q->where(function ($w) use ($needle) {
                $w->where('label', 'like', $needle)->orWhere('term', 'like', $needle);
            });
        }

        $rows = $q->orderByDesc('id')->get();
        $ids  = $rows->pluck('id')->toArray();

        // Batched mention counts per watchlist
        $counts = !empty($ids)
            ? DB::table('brand_mentions')
                ->where('workspace_id', $wsId)
                ->whereIn('watchlist_id', $ids)
                ->selectRaw('watchlist_id,
                    COUNT(*) AS total,
                    SUM(CASE WHEN status = "new" THEN 1 ELSE 0 END) AS new_count,
                    SUM(CASE WHEN sentiment = "negative" THEN 1 ELSE 0 END) AS negative_count')
                ->groupBy('watchlist_id')->get()->keyBy('watchlist_id')->toArray()
            : [];

        $hydrated = $rows->map(function ($r) use ($counts) {
            $arr = (array) $r;
            $arr['variants']          = json_decode($arr['variants_json']          ?? '[]', true) ?: [];
            $arr['negative_keywords'] = json_decode($arr['negative_keywords_json'] ?? '[]', true) ?: [];
            $arr['metadata']          = json_decode($arr['metadata_json']          ?? '{}', true) ?: [];
            unset($arr['variants_json'], $arr['negative_keywords_json'], $arr['metadata_json']);
            $arr['is_active'] = (bool) $arr['is_active'];

            $c = $counts[$r->id] ?? null;
            $arr['mention_counts'] = [
                'total'    => $c ? (int) $c->total          : 0,
                'new'      => $c ? (int) $c->new_count      : 0,
                'negative' => $c ? (int) $c->negative_count : 0,
            ];
            return $arr;
        });

        return [
            'success' => true,
            'count'   => $rows->count(),
            'data'    => $hydrated->toArray(),
        ];
    }

    // ── Single watchlist + recent scan history ─────────────────────────
    public function singleWatchlist(int $wsId, int $id): array
    {
        $wl = DB::table('brand_watchlist')
            ->where('id', $id)->where('workspace_id', $wsId)->first();
        if (!$wl) return ['success' => false, 'error' => 'watchlist row not found'];

        $arr = (array) $wl;
        $arr['variants']          = json_decode($arr['variants_json']          ?? '[]', true) ?: [];
        $arr['negative_keywords'] = json_decode($arr['negative_keywords_json'] ?? '[]', true) ?: [];
        $arr['metadata']          = json_decode($arr['metadata_json']          ?? '{}', true) ?: [];
        unset($arr['variants_json'], $arr['negative_keywords_json'], $arr['metadata_json']);
        $arr['is_active'] = (bool) $arr['is_active'];

        // Recent scan history (last 5)
        $arr['recent_scans'] = DB::table('brand_mention_scan_runs')
            ->where('workspace_id', $wsId)
            ->where('watchlist_id', $id)
            ->orderByDesc('id')
            ->limit(5)
            ->get(['id','scan_type','started_at','finished_at','results_found',
                   'results_new','credits_charged','backend_used','error'])
            ->toArray();

        // Mention counts
        $counts = DB::table('brand_mentions')
            ->where('workspace_id', $wsId)->where('watchlist_id', $id)
            ->selectRaw('COUNT(*) AS total,
                SUM(CASE WHEN status = "new" THEN 1 ELSE 0 END) AS new_count,
                SUM(CASE WHEN sentiment = "negative" THEN 1 ELSE 0 END) AS negative_count')
            ->first();
        $arr['mention_counts'] = [
            'total'    => $counts ? (int) $counts->total          : 0,
            'new'      => $counts ? (int) $counts->new_count      : 0,
            'negative' => $counts ? (int) $counts->negative_count : 0,
        ];

        return ['success' => true, 'data' => $arr];
    }

    // ── Scan-runs audit log ────────────────────────────────────────────
    public function listScanRuns(int $wsId, array $opts = []): array
    {
        $q = DB::table('brand_mention_scan_runs')->where('workspace_id', $wsId);

        if (!empty($opts['watchlist_id'])) {
            $q->where('watchlist_id', (int) $opts['watchlist_id']);
        }
        if (!empty($opts['scan_type'])) {
            $q->where('scan_type', $opts['scan_type']);
        }
        if (!empty($opts['since'])) {
            $q->where('started_at', '>=', $opts['since']);
        }

        $total   = (clone $q)->count();
        $perPage = max(1, min(100, (int) ($opts['per_page'] ?? 25)));
        $page    = max(1, (int) ($opts['page'] ?? 1));
        $offset  = ($page - 1) * $perPage;

        $rows = $q->orderByDesc('id')->offset($offset)->limit($perPage)->get([
            'id','watchlist_id','scan_type','started_at','finished_at',
            'results_found','results_new','credits_charged','backend_used','error',
        ]);

        // Batched watchlist label lookup
        $watchlistIds = $rows->pluck('watchlist_id')->unique()->filter()->toArray();
        $labels = !empty($watchlistIds)
            ? DB::table('brand_watchlist')->where('workspace_id', $wsId)
                ->whereIn('id', $watchlistIds)->pluck('label', 'id')->toArray()
            : [];

        $hydrated = $rows->map(function ($r) use ($labels) {
            $arr = (array) $r;
            $arr['watchlist_label'] = $labels[$r->watchlist_id] ?? null;
            return $arr;
        });

        return [
            'success' => true,
            'data'    => $hydrated->toArray(),
            'paging'  => [
                'page'      => $page,
                'per_page'  => $perPage,
                'total'     => $total,
                'last_page' => max(1, (int) ceil($total / $perPage)),
            ],
        ];
    }

    // ── Dashboard stats ────────────────────────────────────────────────
    public function stats(int $wsId, array $opts = []): array
    {
        $days = max(1, min(90, (int) ($opts['trend_days'] ?? 7)));
        $since = now()->subDays($days)->startOfDay();

        $byStatus = DB::table('brand_mentions')
            ->where('workspace_id', $wsId)
            ->selectRaw('status, COUNT(*) AS c')
            ->groupBy('status')->pluck('c', 'status')->toArray();

        $bySentiment = DB::table('brand_mentions')
            ->where('workspace_id', $wsId)
            ->selectRaw('sentiment, COUNT(*) AS c')
            ->groupBy('sentiment')->pluck('c', 'sentiment')->toArray();

        $bySourceType = DB::table('brand_mentions')
            ->where('workspace_id', $wsId)
            ->selectRaw('source_type, COUNT(*) AS c')
            ->groupBy('source_type')->pluck('c', 'source_type')->toArray();

        $byPriority = DB::table('brand_mentions')
            ->where('workspace_id', $wsId)
            ->selectRaw('priority, COUNT(*) AS c')
            ->groupBy('priority')->pluck('c', 'priority')->toArray();

        // Daily trend: count per day for the last $days
        $trend = DB::table('brand_mentions')
            ->where('workspace_id', $wsId)
            ->where('discovered_at', '>=', $since)
            ->selectRaw('DATE(discovered_at) AS d, COUNT(*) AS c,
                SUM(CASE WHEN sentiment = "negative" THEN 1 ELSE 0 END) AS negatives')
            ->groupBy('d')
            ->orderBy('d')
            ->get()
            ->map(fn($r) => [
                'date'      => $r->d,
                'count'     => (int) $r->c,
                'negatives' => (int) $r->negatives,
            ])->toArray();

        // Top source domains (most mentions in the trend window)
        $topDomains = DB::table('brand_mentions')
            ->where('workspace_id', $wsId)
            ->where('discovered_at', '>=', $since)
            ->selectRaw('source_domain, COUNT(*) AS c')
            ->groupBy('source_domain')
            ->orderByDesc('c')
            ->limit(10)
            ->get()
            ->map(fn($r) => ['domain' => $r->source_domain, 'count' => (int) $r->c])
            ->toArray();

        return [
            'success' => true,
            'data' => [
                'total_mentions'   => (int) array_sum($byStatus),
                'by_status'        => array_map('intval', $byStatus),
                'by_sentiment'     => array_map('intval', $bySentiment),
                'by_source_type'   => array_map('intval', $bySourceType),
                'by_priority'      => array_map('intval', $byPriority),
                'trend'            => $trend,
                'top_domains'      => $topDomains,
                'trend_days'       => $days,
                'watchlist_active' => DB::table('brand_watchlist')
                    ->where('workspace_id', $wsId)->where('is_active', true)->count(),
            ],
        ];
    }
}