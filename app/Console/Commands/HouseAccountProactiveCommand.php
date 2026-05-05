<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class HouseAccountProactiveCommand extends Command
{
    protected $signature = 'house:weekly-proactive {--force : Run even if not Monday}';
    protected $description = 'Run weekly proactive marketing tasks for house account workspaces';

    public function handle(): int
    {
        if (!$this->option('force') && now()->dayOfWeek !== \Carbon\Carbon::MONDAY) {
            $this->info('House account proactive runs on Mondays. Use --force to run now.');
            return 0;
        }

        $houseAccounts = DB::table('workspaces')
            ->where('is_house_account', true)
            ->get();

        if ($houseAccounts->isEmpty()) {
            $this->info('No house accounts found.');
            return 0;
        }

        foreach ($houseAccounts as $ws) {
            $this->line("\n  Processing: {$ws->name} (WS {$ws->id})");

            // 1. Run SEO audit on the platform website
            try {
                $seo = app(\App\Engines\SEO\Services\SeoService::class);
                $audit = $seo->deepAudit($ws->id, ['url' => 'https://levelupgrowth.io']);
                $this->line("    ✓ SEO audit: score={$audit['score']}/100");
            } catch (\Throwable $e) {
                $this->warn("    ⚠ SEO audit failed: {$e->getMessage()}");
            }

            // 2. Index the main website pages
            $urls = [
                'https://levelupgrowth.io',
                'https://levelupgrowth.io/pricing',
                'https://levelupgrowth.io/features',
            ];
            foreach ($urls as $url) {
                try {
                    $idx = $seo->fetchAndIndexUrl($ws->id, $url);
                    if ($idx['success'] ?? false) {
                        $this->line("    ✓ Indexed: {$url} (score={$idx['score']})");
                    }
                } catch (\Throwable $e) {
                    // Non-critical — URL may not exist yet
                }
            }

            // 3. Check content created last week
            $lastWeek = now()->subWeek();
            $articlesThisWeek = DB::table('seo_activity_log')
                ->where('workspace_id', $ws->id)
                ->where('action', 'like', '%article%')
                ->where('created_at', '>=', $lastWeek)
                ->count();
            $this->line("    Content last week: {$articlesThisWeek} article-related actions");

            // 4. Check keyword rankings
            $keywords = DB::table('seo_keywords')->where('workspace_id', $ws->id)->where('status', 'tracking')->get();
            $ranked = $keywords->whereNotNull('current_rank');
            $this->line("    Keywords: {$keywords->count()} tracked, {$ranked->count()} ranked");

            // 5. Create a proactive task summary
            $weekOf = now()->format('F j, Y');
            try {
                DB::table('seo_activity_log')->insert([
                    'workspace_id' => $ws->id,
                    'user_id' => null,
                    'action' => 'house_weekly_proactive',
                    'object_type' => 'workspace',
                    'object_id' => $ws->id,
                    'meta_json' => json_encode([
                        'week_of' => $weekOf,
                        'seo_audit_score' => $audit['score'] ?? null,
                        'articles_last_week' => $articlesThisWeek,
                        'keywords_tracked' => $keywords->count(),
                        'keywords_ranked' => $ranked->count(),
                    ]),
                    'created_at' => now(),
                ]);
            } catch (\Throwable $e) {
                // Non-critical
            }

            $this->line("    ✓ Weekly proactive check complete for week of {$weekOf}");
        }

        $this->info("\nDone.");
        return 0;
    }
}
