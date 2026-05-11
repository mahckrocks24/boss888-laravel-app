<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SeoInsightWatcherCommand extends Command
{
    protected $signature   = 'seo:insights {workspace_id? : Workspace ID (all if omitted)}';
    protected $description = 'Generate proactive SEO insights for workspaces (run daily).';

    public function handle(): int
    {
        $wsArg = $this->argument('workspace_id');
        $workspaces = $wsArg
            ? [DB::table('workspaces')->find($wsArg)]
            : DB::table('workspaces')->get()->all();

        foreach ($workspaces as $ws) {
            if (!$ws) { continue; }
            $this->generate((int) $ws->id);
        }
        return 0;
    }

    private function generate(int $wsId): void
    {
        $rows = [];
        $now  = now();

        // 1. Score drop between last two audits
        $audits = DB::table('seo_audits')
            ->where('workspace_id', $wsId)
            ->orderByDesc('created_at')
            ->limit(2)
            ->get();
        if ($audits->count() === 2) {
            $drop = (int) (($audits[1]->score ?? 0) - ($audits[0]->score ?? 0));
            if ($drop > 5) {
                $rows[] = $this->row($wsId, 'score_drop', 'critical',
                    'SEO Score Dropped',
                    "Your SEO score dropped {$drop} points since the last audit.",
                    ['drop' => $drop], $now);
            }
        }

        // 2. Orphan pages (no inbound links)
        $orphans = DB::table('seo_content_index')
            ->where('workspace_id', $wsId)
            ->where('inbound_links', 0)
            ->where('word_count', '>', 100)
            ->count();
        if ($orphans > 0) {
            $rows[] = $this->row($wsId, 'orphan_pages', 'warning',
                "{$orphans} Orphan Pages Found",
                "These pages have no internal links pointing to them and may not be discovered by search engines.",
                ['count' => $orphans], $now);
        }

        // 3. Thin content
        $thin = DB::table('seo_content_index')
            ->where('workspace_id', $wsId)
            ->where('word_count', '<', 300)
            ->where('word_count', '>', 0)
            ->count();
        if ($thin > 0) {
            $rows[] = $this->row($wsId, 'thin_content', 'warning',
                "{$thin} Pages With Thin Content",
                "Pages under 300 words may rank poorly. Add more content or consolidate.",
                ['count' => $thin], $now);
        }

        // 4. Missing meta descriptions
        $missingMeta = DB::table('seo_content_index')
            ->where('workspace_id', $wsId)
            ->whereNull('meta_description')
            ->count();
        if ($missingMeta > 0) {
            $rows[] = $this->row($wsId, 'missing_meta', 'opportunity',
                "{$missingMeta} Pages Missing Meta Descriptions",
                "Meta descriptions improve click-through rates from search results.",
                ['count' => $missingMeta], $now);
        }

        // 5. Keyword rank drops
        $rankDrops = DB::table('seo_keywords')
            ->where('workspace_id', $wsId)
            ->whereNotNull('rank_change')
            ->where('rank_change', '<', -3)
            ->count();
        if ($rankDrops > 0) {
            $rows[] = $this->row($wsId, 'keyword_rank_drop', 'warning',
                "{$rankDrops} Keywords Dropped in Ranking",
                "Some tracked keywords dropped more than 3 positions. Review content for those pages.",
                ['count' => $rankDrops], $now);
        }

        // 6. Broken outbound links
        if (DB::getSchemaBuilder()->hasTable('seo_outbound_links')) {
            $broken = DB::table('seo_outbound_links')
                ->where('workspace_id', $wsId)
                ->where('http_status', '>=', 400)
                ->count();
            if ($broken > 0) {
                $rows[] = $this->row($wsId, 'broken_outbound', 'critical',
                    "{$broken} Broken Outbound Links",
                    "Broken links damage user experience and SEO. Replace or remove them.",
                    ['count' => $broken], $now);
            }
        }

        // 7. Image alt-text issues
        if (DB::getSchemaBuilder()->hasTable('seo_images')) {
            $missingAlt = DB::table('seo_images')
                ->where('workspace_id', $wsId)
                ->where(fn ($q) => $q->where('missing_alt', true)->orWhere('empty_alt', true))
                ->count();
            if ($missingAlt > 0) {
                $rows[] = $this->row($wsId, 'missing_alt', 'opportunity',
                    "{$missingAlt} Images Missing Alt Text",
                    "Alt text helps search engines understand images and improves accessibility.",
                    ['count' => $missingAlt], $now);
            }
        }

        // Replace prior insights for this workspace (only un-dismissed ones, to
        // preserve dismissed history). Then insert fresh.
        DB::table('seo_insights')
            ->where('workspace_id', $wsId)
            ->whereNull('dismissed_at')
            ->delete();
        if (!empty($rows)) {
            DB::table('seo_insights')->insert($rows);
        }
        $this->info("Generated " . count($rows) . " insights for workspace {$wsId}");
    }

    private function row(int $wsId, string $type, string $priority, string $title,
                         string $description, array $data, $now): array
    {
        return [
            'workspace_id' => $wsId,
            'type'         => $type,
            'priority'     => $priority,
            'title'        => $title,
            'description'  => $description,
            'data_json'    => json_encode($data),
            'created_at'   => $now,
            'updated_at'   => $now,
        ];
    }
}
