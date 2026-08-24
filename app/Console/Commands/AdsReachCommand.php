<?php

namespace App\Console\Commands;

use App\Engines\Ads\Services\AdReachEstimator;
use Illuminate\Console\Command;

/**
 * ADS888 P0 — how much inventory actually matches a targeting spec?
 *
 * Run this BEFORE quoting an advertiser. It refuses to produce a number when
 * matched inventory is below the floor, because "about 300 impressions" for
 * inventory that does not exist loses a customer, whereas "not enough inventory
 * to quote" starts a conversation.
 *
 *   php artisan ads:reach --industry=dental --country=AE
 *   php artisan ads:reach --archetype=hospitality_stay
 *   php artisan ads:reach --interest=food_dining --min-confidence=0.75
 */
class AdsReachCommand extends Command
{
    protected $signature = 'ads:reach
        {--industry=* : industry slug(s)}
        {--archetype=* : archetype code(s)}
        {--country=* : business country ISO-3166-1 alpha-2}
        {--region=* : business region}
        {--interest=* : interest code(s)}
        {--min-confidence= : minimum inventory confidence}
        {--json : raw JSON}';

    protected $description = 'ADS888: estimate reach for a targeting spec before quoting';

    public function handle(AdReachEstimator $estimator): int
    {
        $rules = [];

        foreach ([
            'industry'         => 'industry',
            'archetype'        => 'archetype',
            'country'          => 'business_country',
            'region'           => 'business_region',
            'interest'         => 'interest',
        ] as $option => $dimension) {
            $values = (array) $this->option($option);

            if ($values !== []) {
                $rules[] = ['dimension' => $dimension, 'operator' => 'in', 'values' => $values];
            }
        }

        if ($this->option('min-confidence') !== null) {
            $rules[] = [
                'dimension' => 'min_confidence',
                'operator'  => 'gte',
                'values'    => [(float) $this->option('min-confidence')],
            ];
        }

        $result = $estimator->estimate($rules);

        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->line('');
        $this->info('ADS888 — reach estimate');
        $this->line(str_repeat('─', 72));

        if ($rules === []) {
            $this->comment('  No targeting specified — this is the whole sellable pool.');
        } else {
            foreach ($rules as $rule) {
                $this->line(sprintf('  %-18s %s %s',
                    $rule['dimension'], $rule['operator'], implode(', ', array_map('strval', $rule['values']))));
            }
        }

        $this->line('');
        $this->table(['metric', 'value'], [
            ['matched sites',            $result['matched_sites']],
            ['sellable pool',            $result['sellable_pool']],
            ['excluded (unsellable)',    $result['unsellable_excluded']],
            ["observed impressions ({$result['history_days']}d)", number_format($result['observed_impressions'])],
            ['projected / month',        number_format($result['projected_monthly_impressions'])],
        ]);

        $this->line('');

        if ($result['quotable']) {
            $this->info('  QUOTABLE — there is enough measured inventory to sell against this targeting.');
        } else {
            $this->warn('  NOT QUOTABLE');
            $this->warn('  ' . $result['quote_refusal_reason']);
        }

        if ($result['rejected_by_dimension'] !== []) {
            $this->line('');
            $this->line('  Sites excluded, by the dimension that ruled them out:');
            foreach ($result['rejected_by_dimension'] as $dimension => $count) {
                $this->line(sprintf('    %-20s %d', $dimension, $count));
            }
        }

        if ($result['sites'] !== []) {
            $this->line('');
            $this->table(
                ['site', 'subdomain', 'industry', 'country', 'conf'],
                array_map(static fn ($s) => [
                    $s['website_id'],
                    mb_strimwidth((string) $s['subdomain'], 0, 28, '…'),
                    $s['industry'] ?? '—',
                    $s['country'] ?? '—',
                    number_format($s['confidence'], 2),
                ], $result['sites'])
            );
        }

        $this->line('');

        return self::SUCCESS;
    }
}
