<?php

namespace App\Core\Awareness;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * MEM-P0 (RFC-0023 P0, 2026-10-02). The ONE writer of the business facts Sarah's prompt treats as ground truth.
 *
 * REPORT-0070: the workspace_memory keys business_name / industry / location / domain had no writer since 2026-05-22 and
 * were injected as "AUTHORITATIVE WORKSPACE FACTS" regardless of age. They are now recomputed from the systems of record
 * (the default business, its websites, the workspace columns the Business model mirrors) on every business save, on every
 * awareness recompute (cron every 10 minutes, connect hooks, the in-turn refresh) and never typed by anyone.
 *
 * Shape: each fact is written as a JSON STRING (the turn keeps scalar values) plus `business_facts_at`, the recompute time,
 * so the prompt can say how fresh the facts are. A fact with no source value is removed, never left stale.
 */
final class BusinessFactsService
{
    public const KEYS = ['business_name', 'industry', 'location', 'domain'];
    public const AT_KEY = 'business_facts_at';

    /** Compute the facts for a workspace from the systems of record. */
    public function facts(int $wsId): array
    {
        $ws = DB::table('workspaces')->where('id', $wsId)->first(['id', 'name', 'business_name', 'industry', 'location']);
        if (! $ws) return [];
        $biz = DB::table('businesses')->where('workspace_id', $wsId)->whereNull('deleted_at')->orderByDesc('is_default')->orderBy('sort_order')->orderBy('id')->first(['id', 'name', 'industry', 'location']);
        $name = trim((string) ($biz->name ?? $ws->business_name ?? ''));
        if ($name === '' || preg_match("/'s Workspace$/", $name)) $name = trim((string) ($ws->business_name ?: ''));
        $industry = trim((string) ($biz->industry ?? $ws->industry ?? ''));
        $industry = $industry !== '' ? trim(preg_replace('/\s+/', ' ', str_replace(['_', '-'], ' ', $industry))) : '';
        $location = trim((string) ($biz->location ?? $ws->location ?? ''));
        $siteQ = DB::table('websites')->where('workspace_id', $wsId)->whereNull('deleted_at');
        if (! empty($biz->id)) { $siteQ = (clone $siteQ)->where('business_id', (int) $biz->id); if (! $siteQ->exists()) $siteQ = DB::table('websites')->where('workspace_id', $wsId)->whereNull('deleted_at'); }
        $site = $siteQ->orderByRaw("CASE WHEN status IN ('published','live') THEN 0 ELSE 1 END")->orderBy('created_at')->first(['custom_domain', 'domain', 'subdomain']);
        $domain = '';
        foreach (['custom_domain', 'domain', 'subdomain'] as $c) { $v = trim((string) ($site->{$c} ?? '')); if ($v !== '') { $domain = preg_replace('#^https?://#', '', $v); break; } }
        return array_filter(['business_name' => $name, 'industry' => $industry, 'location' => $location, 'domain' => $domain], fn ($v) => $v !== '');
    }

    /** Write the facts as JSON strings; remove facts that no longer have a source. Returns what was written. */
    public function recompute(int $wsId): array
    {
        $facts = $this->facts($wsId);
        $now = now();
        foreach (self::KEYS as $k) {
            if (isset($facts[$k])) {
                DB::table('workspace_memory')->updateOrInsert(['workspace_id' => $wsId, 'key' => $k],
                    ['value_json' => json_encode($facts[$k], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'ttl' => null, 'updated_at' => $now, 'created_at' => $now]);
            } else {
                DB::table('workspace_memory')->where('workspace_id', $wsId)->where('key', $k)->delete();
            }
        }
        DB::table('workspace_memory')->updateOrInsert(['workspace_id' => $wsId, 'key' => self::AT_KEY],
            ['value_json' => json_encode($now->toDateTimeString()), 'ttl' => null, 'updated_at' => $now, 'created_at' => $now]);
        return $facts;
    }

    public function recomputeAll(int $limit = 10000): int
    {
        $n = 0;
        foreach (DB::table('workspaces')->orderBy('id')->limit($limit)->pluck('id') as $id) {
            try { $this->recompute((int) $id); $n++; } catch (\Throwable $e) { Log::warning('[MEM-P0] business facts recompute failed', ['workspace_id' => $id, 'error' => $e->getMessage()]); }
        }
        return $n;
    }
}
