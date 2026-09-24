<?php

namespace App\Console\Commands;

use App\Core\Business\FactUsageScanner;
use App\Models\Business;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * K8 (2026-09-25) — answer "where is this fact represented publicly?" and
 * "what is stale?" for a business.
 *
 * Scanning is explicit, never a side effect of rendering a page.
 */
class FactUsageCommand extends Command
{
    protected $signature = 'business:fact-usage
        {--business= : one business id (default: every business owning a published website)}
        {--scan : rebuild the usage record before reporting}
        {--stale : report only what has drifted since it was published}';

    protected $description = 'K8: where each business fact is published, and which pages are now stale';

    public function handle(FactUsageScanner $scanner): int
    {
        $ids = $this->option('business')
            ? [(int) $this->option('business')]
            : DB::table('websites')->whereNull('deleted_at')->where('status', 'published')
                ->whereNotNull('business_id')->distinct()->pluck('business_id')->all();

        $scanned = 0;
        $reported = 0;

        foreach ($ids as $id) {
            $business = Business::find($id);
            if (! $business) {
                continue;
            }

            if ($this->option('scan')) {
                $scanned += $scanner->scanBusiness((int) $id);
            }

            if ($this->option('stale')) {
                $stale = $scanner->stale((int) $id);
                if (! $stale) {
                    continue;
                }
                $this->newLine();
                $this->warn("{$business->name} — " . count($stale) . ' stale representation(s)');
                foreach (array_slice($stale, 0, 12) as $row) {
                    $this->line(sprintf('    %-52s %-22s %s', substr($row['page_url'], 0, 51), $row['property'], $row['reason']));
                    $reported++;
                }
                continue;
            }

            $rows = DB::table('fact_usages')->where('business_id', $id)
                ->orderBy('fact_type')->orderBy('fact_key')->orderBy('page_url')->get();
            if ($rows->isEmpty()) {
                continue;
            }

            $this->newLine();
            $this->info("{$business->name} — " . $rows->count() . ' published representation(s)');
            $grouped = [];
            foreach ($rows as $row) {
                $grouped[$row->fact_type . ' ' . $row->fact_key][] = $row;
            }
            foreach ($grouped as $fact => $uses) {
                $this->line(sprintf('  %-28s %s as %s', $fact, count($uses) . ' page(s)', $uses[0]->property));
                foreach (array_slice($uses, 0, 3) as $use) {
                    $this->line('      ' . $use->page_url);
                }
                if (count($uses) > 3) {
                    $this->line('      ... ' . (count($uses) - 3) . ' more');
                }
                $reported++;
            }
        }

        $this->newLine();
        if ($this->option('scan')) {
            $this->info("scanned {$scanned} representation(s) across " . count($ids) . ' business(es).');
        }
        if ($reported === 0) {
            $this->line($this->option('stale') ? 'Nothing is stale.' : 'No recorded representations. Run with --scan first.');
        }

        return self::SUCCESS;
    }
}
