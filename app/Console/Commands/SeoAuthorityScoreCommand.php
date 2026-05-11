<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SeoAuthorityScoreCommand extends Command
{
    protected $signature   = 'seo:authority-score {workspace_id? : Workspace ID (all if omitted)}';
    protected $description = 'Compute PageRank-style authority scores from seo_link_graph and update seo_content_index';

    public function handle(): int
    {
        $wsArg = $this->argument('workspace_id');
        $workspaces = $wsArg
            ? [DB::table('workspaces')->find($wsArg)]
            : DB::table('workspaces')->get()->all();

        foreach ($workspaces as $ws) {
            if (!$ws) { continue; }
            $this->computeForWorkspace((int) $ws->id);
            $this->info("Authority scores computed for workspace {$ws->id}");
        }
        return 0;
    }

    private function computeForWorkspace(int $wsId): void
    {
        $pages = DB::table('seo_content_index')
            ->where('workspace_id', $wsId)
            ->pluck('url')
            ->toArray();
        if (empty($pages)) { return; }

        $total   = count($pages);
        $damping = 0.85;

        // Initial uniform distribution
        $scores = array_fill_keys($pages, 1.0 / $total);

        $links = DB::table('seo_link_graph')
            ->where('workspace_id', $wsId)
            ->where('is_internal', true)
            ->select('source_url', 'target_url')
            ->get();

        $outbound = [];
        foreach ($links as $link) {
            $outbound[$link->source_url] = ($outbound[$link->source_url] ?? 0) + 1;
        }

        // 5 PageRank iterations
        for ($iter = 0; $iter < 5; $iter++) {
            $new = array_fill_keys($pages, (1 - $damping) / $total);
            foreach ($links as $link) {
                if (!isset($scores[$link->source_url]))   { continue; }
                if (!isset($new[$link->target_url]))      { continue; }
                $contribution = $damping * $scores[$link->source_url]
                    / max(1, $outbound[$link->source_url] ?? 1);
                $new[$link->target_url] += $contribution;
            }
            $scores = $new;
        }

        // Normalise 0..1 against the workspace max so the highest-ranked
        // page is always 1.0.
        $max = max(array_merge([0.0001], array_values($scores)));
        foreach ($scores as $url => $score) {
            DB::table('seo_content_index')
                ->where('workspace_id', $wsId)
                ->where('url', $url)
                ->update(['authority_score' => round($score / $max, 4)]);
        }

        // Sync inbound_links count from link graph.
        $inbound = DB::table('seo_link_graph')
            ->where('workspace_id', $wsId)
            ->where('is_internal', true)
            ->select('target_url', DB::raw('COUNT(*) as cnt'))
            ->groupBy('target_url')
            ->get();
        foreach ($inbound as $row) {
            DB::table('seo_content_index')
                ->where('workspace_id', $wsId)
                ->where('url', $row->target_url)
                ->update(['inbound_links' => (int) $row->cnt]);
        }
    }
}
