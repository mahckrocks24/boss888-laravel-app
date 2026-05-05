<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Core\Intelligence\EngineIntelligenceService;

/**
 * Seeds engine intelligence data (blueprints, best practices, constraints) for all 11 engines.
 *
 * Usage:
 *   php artisan intelligence:seed                 # Seed if not already seeded
 *   php artisan intelligence:seed --force         # Re-seed even if already seeded
 *
 * Safe to run repeatedly — uses updateOrInsert internally.
 */
class IntelligenceSeedCommand extends Command
{
    protected $signature = 'intelligence:seed
        {--force : Re-seed even if intelligence data already exists}';

    protected $description = 'Seed engine intelligence blueprints, practices, and constraints for all 11 engines';

    public function handle(EngineIntelligenceService $engineIntel): int
    {
        $this->info('── BOSS888 Intelligence Seeder ──');
        $this->line('');

        if (!$this->option('force') && $engineIntel->hasBeenSeeded()) {
            $this->warn('Intelligence already seeded. Use --force to re-seed.');
            $this->line('Run `php artisan intelligence:audit` to verify current state.');
            return self::SUCCESS;
        }

        $this->line('Seeding intelligence for all ' . count(EngineIntelligenceService::ENGINES) . ' engines...');
        $this->line('');

        $report = $engineIntel->seedAll();

        $rows = [];
        $totals = ['blueprints' => 0, 'practices' => 0, 'constraints' => 0];

        foreach ($report as $engine => $counts) {
            $rows[] = [
                $engine,
                $counts['blueprints'],
                $counts['practices'],
                $counts['constraints'],
                $counts['blueprints'] + $counts['practices'] + $counts['constraints'],
            ];
            $totals['blueprints'] += $counts['blueprints'];
            $totals['practices'] += $counts['practices'];
            $totals['constraints'] += $counts['constraints'];
        }

        $rows[] = ['─────────', '─────', '─────', '─────', '─────'];
        $rows[] = [
            'TOTAL',
            $totals['blueprints'],
            $totals['practices'],
            $totals['constraints'],
            $totals['blueprints'] + $totals['practices'] + $totals['constraints'],
        ];

        $this->table(
            ['Engine', 'Blueprints', 'Practices', 'Constraints', 'Total Rows'],
            $rows
        );

        $this->line('');
        $this->info("✅ Seeded {$totals['blueprints']} blueprints, {$totals['practices']} practices, {$totals['constraints']} constraints across " . count($report) . ' engines.');
        $this->line('');
        $this->line('Run `php artisan intelligence:audit` to verify wiring health.');

        return self::SUCCESS;
    }
}
