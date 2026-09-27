<?php

namespace App\Console\Commands;

use App\Core\Search\NewPages;
use App\Core\Search\SearchSites;
use Illuminate\Console\Command;

/**
 * PAGE-ONE-1 (RFC-0020): Sarah's search work on a clock.
 *   search:tick --pages     every 15 min: new URLs → sitemap check, search engines told, on-page pass, owner told
 *   search:tick --inspect   daily: ask Google (Search Console) whether URLs 7+ days old are indexed
 */
class SearchTick extends Command
{
    protected $signature = 'search:tick {--pages} {--inspect} {--website= : one website only} {--workspace= : one workspace only} {--quiet-owner : do not message the owner}';
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
        return self::SUCCESS;
    }
}
