<?php

namespace App\Console\Commands;

use App\Engines\Ads\Services\AdReportService;
use App\Engines\Ads\Services\AdStatsRollupService;
use Illuminate\Console\Command;

/**
 * ADS888 P0 — the admin reporting surfaces on the command line.
 *
 * These are the same service methods the admin console will call when its HTTP
 * surface is built; exposing them here means the reporting logic is exercised
 * and reviewable now, without touching a contended routes file.
 *
 *   php artisan ads:report dashboard
 *   php artisan ads:report inventory
 *   php artisan ads:report invalid
 *   php artisan ads:report revenue
 *   php artisan ads:report campaign --id=4
 *   php artisan ads:report advertiser --id=1
 *   php artisan ads:report reconcile --date=2026-07-27
 */
class AdsReportCommand extends Command
{
    protected $signature = 'ads:report
        {surface=dashboard : dashboard|campaign|advertiser|inventory|invalid|revenue|reconcile}
        {--id= : campaign or advertiser id}
        {--days=30 : reporting window}
        {--date= : date for reconcile}
        {--json : raw JSON instead of tables}';

    protected $description = 'ADS888: admin reporting surfaces';

    public function handle(AdReportService $reports, AdStatsRollupService $rollup): int
    {
        $surface = (string) $this->argument('surface');
        $days    = (int) $this->option('days');

        $data = match ($surface) {
            'dashboard'  => $reports->dashboard($days),
            'campaign'   => $reports->campaign((int) $this->option('id'), $days),
            'advertiser' => $reports->advertiser((int) $this->option('id'), $days),
            'inventory'  => $reports->inventory($days),
            'invalid'    => $reports->invalidTraffic($days),
            'revenue'    => $reports->revenue($days),
            'reconcile'  => $rollup->reconcile((string) ($this->option('date') ?: now()->subDay()->toDateString())),
            default      => null,
        };

        if ($data === null) {
            $this->error("Unknown surface: {$surface}");

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->line('');
        $this->info('ADS888 — ' . strtoupper($surface));
        $this->line(str_repeat('─', 78));

        // Guarantee 1 + 2: freshness and timezone are shown on EVERY surface.
        if (isset($data['freshness'])) {
            $this->comment('  ' . $data['freshness']['note']);
            $this->comment('  All dates ' . ($data['timezone'] ?? 'UTC') . '.');
            $this->line('');
        }

        match ($surface) {
            'dashboard'  => $this->renderDashboard($data),
            'inventory'  => $this->renderInventory($data),
            'invalid'    => $this->renderInvalid($data),
            'revenue'    => $this->renderRevenue($data),
            'reconcile'  => $this->renderReconcile($data),
            default      => $this->line(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)),
        };

        $this->line('');

        return self::SUCCESS;
    }

    private function renderDashboard(array $d): void
    {
        $t = $d['totals'];

        // Guarantee 3: gross / invalid / net always shown as three figures.
        $this->table(['metric', 'value'], [
            ['requests',              number_format($t['requests'])],
            ['impressions (gross)',   number_format($t['impressions_gross'])],
            ['impressions (invalid)', number_format($t['impressions_invalid'])],
            ['impressions (net)',     number_format($t['impressions_net'])],
            ['viewable',              number_format($t['viewable'])],
            ['clicks',                number_format($t['clicks'])],
            ['CTR',                   $this->pct($t['ctr'])],
            ['viewability',           $this->pct($t['viewability_rate'])],
            ['invalid rate',          $this->pct($t['invalid_rate'])],
            ['revenue',               $this->money($t['revenue_micros'])],
            ['— fill split —',        ''],
            ['paid impressions',      number_format($d['fill']['paid_impressions'])],
            ['house impressions',     number_format($d['fill']['house_impressions'])],
            ['paid share',            $this->pct($d['fill']['paid_share'])],
        ]);

        if ($d['alerts'] !== []) {
            $this->line('');
            $this->line('  Alerts:');
            foreach ($d['alerts'] as $alert) {
                $line = sprintf('    [%s] %s', strtoupper($alert['level']), $alert['message']);
                $alert['level'] === 'action' ? $this->warn($line) : $this->line($line);
            }
        }
    }

    private function renderInventory(array $d): void
    {
        $this->line("  sites profiled : {$d['site_count']}");
        $this->line("  sellable       : {$d['sellable']}");
        $this->line('');

        if ($d['sites'] !== []) {
            $this->table(
                ['site', 'subdomain', 'industry', 'country', 'conf', 'quality', 'sellable', 'impr'],
                array_map(static fn ($s) => [
                    $s['website_id'],
                    mb_strimwidth((string) $s['subdomain'], 0, 24, '…'),
                    $s['industry'] ?? '—',
                    $s['country'] ?? '—',
                    number_format($s['confidence'], 2),
                    number_format($s['quality'], 2),
                    $s['sellable'] ? 'yes' : 'NO',
                    number_format($s['impressions']),
                ], array_slice($d['sites'], 0, 30))
            );
        }

        foreach (['by_archetype' => 'Archetype', 'by_country' => 'Country', 'by_industry' => 'Industry'] as $key => $label) {
            if (($d[$key] ?? []) === []) {
                continue;
            }
            $this->line('');
            $this->line("  By {$label}:");
            foreach ($d[$key] as $code => $stats) {
                $this->line(sprintf('    %-24s %2d site(s)  %s impressions',
                    $code, $stats['sites'], number_format($stats['impressions'])));
            }
        }

        if (($d['unclassified'] ?? []) !== []) {
            $this->line('');
            $this->warn('  ' . count($d['unclassified']) . ' unclassified site(s) — house ads only, never sold.');
        }
    }

    private function renderInvalid(array $d): void
    {
        $this->line("  total events  : " . number_format($d['total_events']));
        $this->line("  invalid       : " . number_format($d['invalid_total']));
        $this->line("  invalid rate  : " . $this->pct($d['invalid_rate']));
        $this->line('');

        if ($d['by_reason'] === []) {
            $this->line('  No invalid traffic recorded in this window.');

            return;
        }

        $this->table(['reason', 'events'], array_map(
            static fn ($reason, $count) => [$reason ?? '(unspecified)', number_format($count)],
            array_keys($d['by_reason']), array_values($d['by_reason'])
        ));
    }

    private function renderRevenue(array $d): void
    {
        $this->table(['metric', 'value'], [
            ['booked',    $this->money($d['booked_micros'])],
            ['delivered', $this->money($d['delivered_micros'])],
            ['deferred',  $this->money($d['deferred_micros'])],
        ]);

        if ($d['by_advertiser'] !== []) {
            $this->line('');
            $this->table(['advertiser', 'delivered'], array_map(
                fn ($a) => [$a['name'], $this->money($a['delivered_micros'])],
                $d['by_advertiser']
            ));
        }
    }

    private function renderReconcile(array $d): void
    {
        $rows = [];
        foreach ($d['compare'] as $metric => [$raw, $rolled]) {
            $rows[] = [$metric, number_format($raw), number_format($rolled), $raw === $rolled ? 'ok' : 'MISMATCH'];
        }

        $this->table(['metric', 'raw events', 'rolled up', 'status'], $rows);
        $this->line('');

        if ($d['balanced']) {
            $this->info('  Balanced — the rollup is neither dropping nor double-counting.');
        } else {
            $this->error('  MISMATCH — investigate before billing anyone from this date.');
        }
    }

    private function pct(float $value): string
    {
        return number_format($value * 100, 2) . '%';
    }

    private function money(int $micros): string
    {
        return '$' . number_format($micros / 1_000_000, 2);
    }
}
