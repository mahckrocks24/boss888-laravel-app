<?php

namespace App\Console\Commands;

use App\Core\Search\NewPages;
use App\Core\Search\SearchSites;
use Illuminate\Console\Command;

/**
 * PAGE-ONE-1 (RFC-0020): Sarah's search work on a clock.
 *   search:tick --pages     every 15 min: new URLs → sitemap check, search engines told, on-page pass, owner told
 *   search:tick --inspect   daily: ask Google (Search Console) whether URLs 7+ days old are indexed
 *   search:tick --publish   every 10 min: page-one articles whose date has come go live
 *   search:tick --foundation daily: the keyword research + 12-week Search Roadmap for websites that have none (switch: storage/app/pageone-roadmap.on)
 */
class SearchTick extends Command
{
    protected $signature = 'search:tick {--pages} {--inspect} {--publish} {--foundation} {--website= : one website only} {--workspace= : one workspace only} {--quiet-owner : do not message the owner}';
    protected $description = 'Page One: new pages, indexing, articles, optimization, reports';

    public function handle(NewPages $pages): int
    {
        $sites = SearchSites::managed($this->option('workspace') ? (int) $this->option('workspace') : null);
        if ($this->option('website')) $sites = $sites->where('id', (int) $this->option('website'));
        if ($this->option('pages')) {
            $t = ['sites' => 0, 'new' => 0, 'gone' => 0, 'missing_from_sitemap' => 0];
            foreach ($sites as $w) {
                try { $r = $pages->scan($w, ! $this->option('quiet-owner')); $t['sites']++; foreach (['new', 'gone', 'missing_from_sitemap'] as $k) $t[$k] += $r[$k]; }
                catch (\Throwable $e) { $this->warn("site {$w->id}: " . $e->getMessage()); }
            }
            $this->info('pages: ' . json_encode($t));
        }
        if ($this->option('inspect')) {
            $n = 0;
            foreach ($sites as $w) { try { $n += $pages->inspect($w); } catch (\Throwable $e) { $this->warn("inspect {$w->id}: " . $e->getMessage()); } }
            $this->info("inspected {$n}");
        }
        if ($this->option("publish")) $this->info("published " . app(\App\Core\Search\PageOne::class)->dueTick());
        if ($this->option("foundation")) {
            $one = $this->option("website") || $this->option("workspace");
            if (! $one && ! is_file(storage_path("app/pageone-roadmap.on"))) { $this->info("roadmaps: switched off"); return self::SUCCESS; }
            $built = 0;
            foreach ($sites as $w) {
                if ($built >= 4) break;
                if (! $one && ! $this->eligible($w)) continue;
                try { $r = app(\App\Core\Search\KeywordPlan::class)->build($w, ! $this->option("quiet-owner")); $this->line("site {$w->id}: " . json_encode($r)); if (! empty($r["success"])) $built++; }
                catch (\Throwable $e) { $this->warn("foundation {$w->id}: " . $e->getMessage()); }
            }
            $this->info("roadmaps built {$built}");
        }
        return self::SUCCESS;
    }

    /** A paying workspace in use, a LevelUp-built site, a business Sarah knows, and no roadmap in the last 12 weeks. */
    private function eligible(object $w): bool
    {
        if (\App\Core\Search\SearchSites::isWp($w)) return false;
        $ws = (int) $w->workspace_id;
        if (\Illuminate\Support\Facades\DB::table("search_plans")->where("website_id", $w->id)->where("kind", "roadmap")->where("created_at", ">=", now()->subDays(84))->exists()) return false;
        $paid = \Illuminate\Support\Facades\DB::table("subscriptions as s")->join("plans as p", "p.id", "=", "s.plan_id")->where("s.workspace_id", $ws)->whereIn("s.status", ["active", "trialing"])->where("p.price", ">", 0)->exists();
        if (! $paid) return false;
        $active = \Illuminate\Support\Facades\DB::table("agent_messages")->where("workspace_id", $ws)->where("role", "user")->where("created_at", ">=", now()->subDays(30))->exists();
        if (! $active) return false;
        $biz = \App\Core\Search\SearchSites::business($w);
        return $biz && (trim((string) $biz->industry) !== "" || trim((string) $biz->services_json) !== "");
    }
}
