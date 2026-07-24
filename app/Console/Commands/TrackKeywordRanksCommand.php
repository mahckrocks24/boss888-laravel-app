<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TrackKeywordRanksCommand extends Command
{
    protected $signature = 'seo:track-ranks {--workspace= : Track ranks for a specific workspace only} {--force : Run even if not Monday}';
    protected $description = 'Check SERP rankings for all tracked keywords via DataForSEO';

    public function handle(): int
    {
        $connector = app(\App\Connectors\DataForSeoConnector::class);

        if (!$connector->isConfigured()) {
            $this->error('DataForSEO credentials not configured. Set DATAFORSEO_LOGIN and DATAFORSEO_PASSWORD in .env');
            return 1;
        }

        $wsFilter = $this->option('workspace');
        $forceRun = $this->option('force');

        // Weekly schedule: only run on Mondays (unless --force)
        if (!$forceRun && now()->dayOfWeek !== \Carbon\Carbon::MONDAY) {
            $nextMonday = now()->next(\Carbon\Carbon::MONDAY)->format('l, F j, Y');
            $this->info("Rank tracking runs weekly on Mondays. Next scan: {$nextMonday}. Use --force to run now.");
            return 0;
        }

        // Get workspaces with tracked keywords ON PAID PLANS with keyword tracking
        if ($wsFilter) {
            // Manual run for specific workspace — skip plan check
            $workspaces = collect([(int) $wsFilter]);
        } else {
            // Cron run — track ranks for active-subscription workspaces UNLESS the
            // plan explicitly opts out with rank_check_frequency = 'never'.
            // 2026-07-01 FIX #1: the old `!= 'never'` filter returned NULL (→ row
            // excluded) for any plan whose features_json lacks the key — silently
            // dropping paid workspaces (e.g. Pro) from all rank tracking. Treat a
            // MISSING key as eligible; only 'never' opts out.
            $workspaces = DB::table('seo_keywords')
                ->where('seo_keywords.status', 'tracking')
                ->join('subscriptions', 'seo_keywords.workspace_id', '=', 'subscriptions.workspace_id')
                ->join('plans', 'subscriptions.plan_id', '=', 'plans.id')
                ->where('subscriptions.status', 'active')
                ->whereRaw("(JSON_EXTRACT(plans.features_json, '$.rank_check_frequency') IS NULL OR JSON_UNQUOTE(JSON_EXTRACT(plans.features_json, '$.rank_check_frequency')) <> 'never')")
                ->select('seo_keywords.workspace_id')
                ->distinct()
                ->pluck('workspace_id');
        }

        if ($workspaces->isEmpty()) {
            $this->info('No workspaces with tracked keywords on eligible plans.');
            return 0;
        }

        $this->info("Tracking ranks for {$workspaces->count()} workspace(s)...");

        $totalChecked = 0;
        $totalFound = 0;
        $totalImproved = 0;
        $totalDropped = 0;
        $totalCost = 0;

        foreach ($workspaces as $wsId) {
            $keywords = DB::table('seo_keywords')
                ->where('workspace_id', $wsId)
                ->where('status', 'tracking')
                ->get();

            if ($keywords->isEmpty()) continue;

            // Get workspace domain for rank matching.
            // 2026-07-01 FIX #2: prefer the LIVE custom_domain over the
            // *.levelupgrowth.io subdomain — SERP results list the real domain
            // (e.g. chefredraymundo.com), so matching the subdomain never hit.
            $domain = null;
            $publishedSite = DB::table('websites')
                ->where('workspace_id', $wsId)
                ->where('status', 'published')
                ->where(function ($q) {
                    $q->whereNotNull('custom_domain')->orWhereNotNull('subdomain');
                })
                ->first();

            if ($publishedSite) {
                $raw = $publishedSite->custom_domain ?: $publishedSite->subdomain;
                if ($raw) {
                    // Normalise to a bare host whether stored bare or as a URL.
                    $domain = parse_url(str_contains($raw, '//') ? $raw : '//' . $raw, PHP_URL_HOST) ?: $raw;
                }
            }

            // 2026-07-01 FIX #3: derive the SERP country from the workspace's
            // location instead of the hardcoded UAE (which was wrong for every
            // non-UAE workspace). workspaces.location is free-text.
            $wsLocation = DB::table('workspaces')->where('id', $wsId)->value('location');
            $locationCode = \App\Connectors\DataForSeoConnector::locationCodeFromText($wsLocation);

            $this->line("  WS {$wsId}: {$keywords->count()} keywords" . ($domain ? " (domain: {$domain})" : ' (no published domain)') . " (loc: {$locationCode})");

            foreach ($keywords as $kw) {
                // Determine domain to check — keyword's target_url domain, or workspace published domain
                $checkDomain = $domain;
                if (!empty($kw->target_url)) {
                    $parsed = parse_url($kw->target_url, PHP_URL_HOST);
                    if ($parsed) $checkDomain = $parsed;
                }

                if (!$checkDomain) {
                    $this->line("    [{$kw->keyword}] — skipped (no domain to check against)");
                    continue;
                }

                // DFS-F1 (2026-06-21) — scheduled rank tracking uses depth 20
                // (was default 100). Top-20 is sufficient for SMB rank position;
                // manual "refresh now" may use deeper. ~5x cheaper on the SERP call.
                $result = $connector->trackKeywordRank(
                    $kw->keyword,
                    $checkDomain,
                    $locationCode,
                    20
                );
                $totalChecked++;
                $totalCost += $result['cost'] ?? 0;

                if (!$result['success']) {
                    $this->warn("    [{$kw->keyword}] — API error: {$result['error']}");
                    Log::warning('seo:track-ranks API error', ['keyword' => $kw->keyword, 'error' => $result['error']]);
                    continue;
                }

                $newRank = $result['position']; // null if not in top N
                $previousRank = $kw->current_rank;
                $rankChange = null;

                if ($newRank !== null && $previousRank !== null) {
                    // Positive change = improved (rank number decreased)
                    $rankChange = $previousRank - $newRank;
                }

                // 2026-07-01 precedence — GSC wins where present. If this keyword's
                // rank came from GSC and is still fresh (GSC syncs daily), DataForSEO
                // does NOT overwrite it; DataForSEO only fills keywords GSC can't see.
                $gscFresh = (($kw->rank_source ?? null) === 'gsc')
                    && !empty($kw->last_rank_check)
                    && Carbon::parse($kw->last_rank_check)->gt(now()->subDays(3));
                if ($gscFresh) {
                    $this->line("    [{$kw->keyword}] — kept fresh GSC rank #{$previousRank} (DataForSEO deferred)");
                    continue;
                }

                // Update keyword (tag the source so GSC precedence holds next run)
                DB::table('seo_keywords')->where('id', $kw->id)->update([
                    'previous_rank' => $previousRank,
                    'current_rank' => $newRank,
                    'rank_change' => $rankChange,
                    'rank_source' => 'dataforseo',
                    'last_rank_check' => now(),
                    'rank_url' => $result['url'],
                    'updated_at' => now(),
                ]);

                $posStr = $newRank !== null ? "#{$newRank}" : 'not found';
                $changeStr = '';
                if ($rankChange !== null && $rankChange !== 0) {
                    if ($rankChange > 0) {
                        $changeStr = " (↑{$rankChange})";
                        $totalImproved++;
                    } else {
                        $changeStr = " (↓" . abs($rankChange) . ")";
                        $totalDropped++;
                    }
                }
                if ($newRank !== null) $totalFound++;

                $this->line("    [{$kw->keyword}] → {$posStr}{$changeStr}");

                // Log significant changes
                if ($rankChange !== null && abs($rankChange) >= 3) {
                    try {
                        DB::table('seo_activity_log')->insert([
                            'workspace_id' => $wsId,
                            'user_id' => null,
                            'action' => $rankChange > 0 ? 'rank_improved' : 'rank_dropped',
                            'object_type' => 'keyword',
                            'object_id' => $kw->id,
                            'meta_json' => json_encode([
                                'keyword' => $kw->keyword,
                                'previous_rank' => $previousRank,
                                'new_rank' => $newRank,
                                'change' => $rankChange,
                                'url' => $result['url'],
                            ]),
                            'created_at' => now(),
                        ]);
                    } catch (\Throwable $e) {
                        // Non-critical
                    }
                }

                // Brief pause to avoid API rate limits
                usleep(200000); // 200ms between calls
            }
        }

        $this->newLine();
        $this->info("Done. Checked: {$totalChecked} | Found: {$totalFound} | Improved: {$totalImproved} | Dropped: {$totalDropped} | Cost: \${$totalCost}");

        return 0;
    }
}
