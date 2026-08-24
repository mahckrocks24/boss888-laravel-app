<?php

namespace App\Console\Commands;

use App\Engines\Ads\Services\AdStatsRollupService;
use Illuminate\Console\Command;

/**
 * ADS888 P0 — roll raw events into the daily table, and prune what has aged out.
 *
 * Idempotent: re-running a date rewrites it from source rather than adding to
 * it. You WILL re-run a date — to fix a bug, or to answer an advertiser
 * dispute — so this had to be safe by construction rather than by discipline.
 *
 *   php artisan ads:rollup                      # yesterday
 *   php artisan ads:rollup --date=2026-07-27
 *   php artisan ads:rollup --backfill=30        # last 30 days
 *   php artisan ads:rollup --prune              # drop events past retention
 *   php artisan ads:rollup --reconcile=2026-07-27
 *
 * NOT SCHEDULED YET — deliberate. There is no delivery plane, so there are no
 * events to roll up. Schedule it nightly at the same time the delivery plane
 * goes live, not before.
 */
class AdsRollupCommand extends Command
{
    protected $signature = 'ads:rollup
        {--date= : Roll up a specific date (Y-m-d), default yesterday}
        {--backfill= : Roll up the last N days}
        {--prune : Delete raw events past the retention horizon}
        {--reconcile= : Compare raw events against the rollup for a date}';

    protected $description = 'ADS888: roll raw ad events into ad_stats_daily';

    public function handle(AdStatsRollupService $rollup): int
    {
        $this->line('');
        $this->info('ADS888 — stats rollup');
        $this->line(str_repeat('─', 72));

        if ($date = $this->option('reconcile')) {
            $result = $rollup->reconcile((string) $date);

            foreach ($result['compare'] as $metric => [$raw, $rolled]) {
                $this->line(sprintf('  %-12s raw=%-8s rolled=%-8s %s',
                    $metric, $raw, $rolled, $raw === $rolled ? 'ok' : 'MISMATCH'));
            }

            $this->line('');
            $result['balanced']
                ? $this->info('  Balanced.')
                : $this->error('  MISMATCH — do not bill from this date until resolved.');

            return $result['balanced'] ? self::SUCCESS : self::FAILURE;
        }

        if ($this->option('prune')) {
            $deleted = $rollup->prune();
            $this->info("  Pruned {$deleted} raw event(s) past the retention horizon.");

            return self::SUCCESS;
        }

        $dates = [];

        if ($backfill = $this->option('backfill')) {
            for ($i = (int) $backfill; $i >= 1; $i--) {
                $dates[] = now()->subDays($i)->toDateString();
            }
        } else {
            $dates[] = (string) ($this->option('date') ?: now()->subDay()->toDateString());
        }

        $grand = ['rows' => 0, 'impressions' => 0, 'viewable' => 0, 'clicks' => 0, 'invalid' => 0, 'revenue' => 0];

        foreach ($dates as $date) {
            $result = $rollup->rollup($date);

            $grand['rows']        += $result['rows'];
            $grand['impressions'] += $result['impressions'];
            $grand['viewable']    += $result['viewable'];
            $grand['clicks']      += $result['clicks'];
            $grand['invalid']     += $result['invalid'];
            $grand['revenue']     += $result['revenue_micros'];

            if (count($dates) === 1 || $result['rows'] > 0) {
                $this->line(sprintf(
                    '  %s  rows=%-4d impr=%-6d viewable=%-6d clicks=%-5d invalid=%-5d revenue=$%s',
                    $date, $result['rows'], $result['impressions'], $result['viewable'],
                    $result['clicks'], $result['invalid'],
                    number_format($result['revenue_micros'] / 1_000_000, 2)
                ));
            }
        }

        if (count($dates) > 1) {
            $this->line('');
            $this->info(sprintf(
                '  %d date(s): rows=%d impr=%d viewable=%d clicks=%d invalid=%d revenue=$%s',
                count($dates), $grand['rows'], $grand['impressions'], $grand['viewable'],
                $grand['clicks'], $grand['invalid'],
                number_format($grand['revenue'] / 1_000_000, 2)
            ));
        }

        if ($grand['rows'] === 0) {
            $this->line('');
            $this->comment('  No events to roll up. Expected: the delivery plane is not built,');
            $this->comment('  so nothing generates events yet.');
        }

        $this->line('');

        return self::SUCCESS;
    }
}
