<?php

namespace App\Engines\SEO\Services;

use Illuminate\Support\Facades\DB;

/**
 * SeoDataService — single source of truth for tab data shared between
 * /api/seo/* and /api/connector/* routes. Prevents schema drift.
 *
 * 2026-05-12 Phase 0: created so both route groups delegate here.
 */
class SeoDataService
{
    public function quickWins(int $wsId, ?string $url = null): array
    {
        $items = DB::table('seo_audit_items')
            ->join('seo_audits', 'seo_audit_items.audit_id', '=', 'seo_audits.id')
            ->where('seo_audits.workspace_id', $wsId)
            ->whereIn('seo_audit_items.status', ['error', 'warning'])
            ->when($url, fn ($q) => $q->where('seo_audit_items.url', $url))
            ->orderByRaw("CASE seo_audit_items.status WHEN 'error' THEN 1 ELSE 2 END")
            ->orderBy('seo_audit_items.score')
            ->limit(20)
            ->select(
                'seo_audit_items.check_name AS title',
                'seo_audit_items.status     AS severity',
                'seo_audit_items.details    AS description',
                'seo_audit_items.url',
                'seo_audit_items.score'
            )
            ->get();

        $rankWins = DB::table('seo_keywords')
            ->where('workspace_id', $wsId)
            ->whereBetween('current_rank', [11, 20])
            ->limit(10)
            ->get(['keyword', 'current_rank', 'volume', 'target_url'])
            ->map(fn ($k) => [
                'title'       => "Rank boost: \"{$k->keyword}\" (pos #{$k->current_rank})",
                'severity'    => 'opportunity',
                'description' => "Volume: {$k->volume}. Push from #{$k->current_rank} to top 10.",
                'url'         => $k->target_url,
                'score'       => null,
            ]);

        $all = array_merge($items->toArray(), $rankWins->toArray());
        return ['success' => true, 'quick_wins' => $all, 'total' => count($all)];
    }

    public function competitors(int $wsId): array
    {
        $comps = DB::table('seo_serp_results')
            ->where('workspace_id', $wsId)
            ->whereNotNull('domain')
            ->where('domain', '!=', '')
            ->select(
                'domain',
                DB::raw('COUNT(*) AS appearances'),
                DB::raw('ROUND(AVG(position), 1) AS avg_position'),
                DB::raw('MAX(created_at) AS last_seen')
            )
            ->groupBy('domain')
            ->orderByDesc('appearances')
            ->limit(20)
            ->get();
        return ['success' => true, 'competitors' => $comps];
    }

    public function linkOpportunities(int $wsId, string $srcUrl = ''): array
    {
        $svc = app(SeoService::class);
        $result = $svc->generateLinkSuggestions($wsId, ['source_url' => $srcUrl]);
        return [
            'success'     => true,
            'suggestions' => $result['suggestions'] ?? $result,
        ];
    }

    public function indexedContent(int $wsId, array $filters = []): array
    {
        $perPage = min(100, max(1, (int) ($filters['per_page'] ?? 25)));
        $filter  = $filters['filter'] ?? '';
        $q       = $filters['q'] ?? '';

        $query = DB::table('seo_content_index')->where('workspace_id', $wsId);
        if ($filter === 'low_score')    { $query->where('content_score', '<', 50); }
        if ($filter === 'missing_meta') { $query->whereNull('meta_description'); }
        if ($filter === 'thin_content') { $query->where('word_count', '<', 300); }
        if ($filter === 'no_h1')        { $query->whereNull('h1'); }
        if ($q !== '') {
            $query->where(function ($x) use ($q) {
                $x->where('url',   'like', "%{$q}%")
                  ->orWhere('title','like', "%{$q}%");
            });
        }
        $pages = $query->orderBy('content_score')->paginate($perPage);
        return [
            'success' => true,
            'items'   => $pages->items(),
            'total'   => $pages->total(),
            'page'    => $pages->currentPage(),
        ];
    }
}
