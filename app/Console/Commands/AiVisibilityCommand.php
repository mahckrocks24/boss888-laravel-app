<?php

namespace App\Console\Commands;

use App\Core\Business\AiVisibilityService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * K11 (2026-09-25) — report what is actually observable about AI visibility.
 *
 * No score, no ranking, no estimate. Counts of things that happened, and the
 * limits printed beside them every time.
 */
class AiVisibilityCommand extends Command
{
    protected $signature = 'seo:ai-visibility
        {--website= : one website id (default: every published website with traffic)}
        {--days=30 : the window}';

    protected $description = 'K11: observed AI crawler, answer-engine and referral activity, with what it does not prove';

    public function handle(AiVisibilityService $service): int
    {
        $days = max(1, (int) $this->option('days'));

        $ids = $this->option('website')
            ? [(int) $this->option('website')]
            : DB::table('aeo_traffic')->distinct()->pluck('website_id')->filter()->values()->all();

        foreach ($ids as $websiteId) {
            $site = DB::table('websites')->where('id', $websiteId)->first(['name', 'subdomain', 'custom_domain', 'workspace_id']);
            if (! $site) {
                continue;
            }

            $report = $service->forWebsite((int) $websiteId, $days);
            $observed = $report['observed'];

            $fetches = array_sum(array_column($observed['answer_engine_user_fetches'], 'hits'));
            $training = array_sum(array_column($observed['ai_training_crawls'], 'hits'));
            $search = array_sum(array_column($observed['search_crawls'], 'hits'));
            $referrals = array_sum(array_column($observed['answer_engine_referrals'], 'visits'));

            if (($fetches + $training + $search + $referrals) === 0) {
                continue;
            }

            $this->newLine();
            $this->info(($site->name ?: 'Website ' . $websiteId) . ' — ' . ($site->custom_domain ?: $site->subdomain) . " (last {$days} days)");

            $this->line("  Answer engines fetched this site while answering someone : {$fetches}");
            foreach (array_slice($observed['answer_engine_user_fetches'], 0, 4) as $row) {
                $this->line(sprintf('      %-18s %-6s on %s day(s), last %s', $row['source'], $row['hits'], $row['days_seen'], substr((string) $row['last_seen'], 0, 10)));
            }
            $this->line("  AI crawlers read it to build a corpus or index          : {$training}");
            $this->line("  Ordinary search crawling                                : {$search}");
            $this->line("  Visitors who arrived FROM an assistant's answer         : {$referrals}");

            if ($fetches > 0 && $referrals === 0) {
                $this->line('    Fetched but never clicked through. That is normal and is not evidence of absence.');
            }
        }

        $this->newLine();
        $this->warn('What none of this proves:');
        foreach ($service->notObservable() as $limit) {
            $this->line('  - ' . $limit);
        }

        return self::SUCCESS;
    }
}
