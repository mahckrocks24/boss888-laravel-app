<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Core\Intelligence\EngineIntelligenceService;

/**
 * Per-engine intelligence wiring health report.
 *
 * This command exists to prevent the "ghost layer" failure mode — where
 * recordToolUsage() calls fire but silently no-op because no blueprints exist.
 *
 * Runs ALWAYS against live DB state and reports:
 *   - Per-engine blueprint/practice/constraint counts
 *   - Per-engine total usage_count (is anything being recorded?)
 *   - Per-engine tools with scored effectiveness (is feedback flowing?)
 *   - Dead engines (blueprints exist but zero usage)
 *   - Sarah's dependency health (does she actually call engineIntel?)
 *
 * Usage:
 *   php artisan intelligence:audit
 *   php artisan intelligence:audit --json
 */
class IntelligenceAuditCommand extends Command
{
    protected $signature = 'intelligence:audit
        {--json : Output raw JSON}';

    protected $description = 'Audit the intelligence layer wiring health for all 11 engines';

    public function handle(EngineIntelligenceService $engineIntel): int
    {
        $engines = EngineIntelligenceService::ENGINES;
        $report = [];
        $totals = [
            'engines' => count($engines),
            'seeded' => 0,
            'with_usage' => 0,
            'with_effectiveness' => 0,
            'dead' => 0,
        ];

        foreach ($engines as $engine) {
            $blueprints = DB::table('engine_intelligence')
                ->where('engine', $engine)
                ->where('knowledge_type', 'tool_blueprint')
                ->count();

            $practices = DB::table('engine_intelligence')
                ->where('engine', $engine)
                ->where('knowledge_type', 'best_practice')
                ->count();

            $constraints = DB::table('engine_intelligence')
                ->where('engine', $engine)
                ->where('knowledge_type', 'constraint')
                ->count();

            $usageSum = (int) DB::table('engine_intelligence')
                ->where('engine', $engine)
                ->where('knowledge_type', 'tool_blueprint')
                ->sum('usage_count');

            $scoredTools = DB::table('engine_intelligence')
                ->where('engine', $engine)
                ->where('knowledge_type', 'tool_blueprint')
                ->whereNotNull('effectiveness_score')
                ->count();

            $status = $this->computeStatus($blueprints, $usageSum, $scoredTools);

            if ($blueprints > 0) $totals['seeded']++;
            if ($usageSum > 0) $totals['with_usage']++;
            if ($scoredTools > 0) $totals['with_effectiveness']++;
            if ($blueprints > 0 && $usageSum === 0) $totals['dead']++;

            $report[$engine] = [
                'blueprints' => $blueprints,
                'practices' => $practices,
                'constraints' => $constraints,
                'total_usage' => $usageSum,
                'scored_tools' => $scoredTools,
                'status' => $status,
            ];
        }

        // Sarah dependency health check
        $sarahHealth = $this->checkSarahHealth();

        if ($this->option('json')) {
            $this->line(json_encode([
                'report' => $report,
                'totals' => $totals,
                'sarah_health' => $sarahHealth,
                'generated_at' => now()->toIso8601String(),
            ], JSON_PRETTY_PRINT));
            return self::SUCCESS;
        }

        $this->info('── BOSS888 Intelligence Layer Audit ──');
        $this->line('');

        // Per-engine table
        $rows = [];
        foreach ($report as $engine => $data) {
            $rows[] = [
                $engine,
                $data['blueprints'],
                $data['practices'],
                $data['constraints'],
                $data['total_usage'],
                $data['scored_tools'],
                $data['status'],
            ];
        }

        $this->table(
            ['Engine', 'Blueprints', 'Practices', 'Constraints', 'Usage', 'Scored', 'Status'],
            $rows
        );

        // Summary
        $this->line('');
        $this->line("Engines seeded:         {$totals['seeded']} / {$totals['engines']}");
        $this->line("Engines with usage:     {$totals['with_usage']} / {$totals['engines']}");
        $this->line("Engines with scores:    {$totals['with_effectiveness']} / {$totals['engines']}");

        if ($totals['dead'] > 0) {
            $this->warn("⚠️  Dead engines (seeded but no usage): {$totals['dead']}");
            $this->line('   These engines have blueprints but recordToolUsage is never firing.');
            $this->line('   Check that writes flow through EngineExecutionService::execute().');
        }

        if ($totals['seeded'] === 0) {
            $this->error('🚨 CRITICAL: Intelligence table is EMPTY. Ghost layer detected.');
            $this->line('   Run: php artisan intelligence:seed');
            $this->line('   All recordToolUsage calls are currently silent no-ops.');
        }

        // Sarah health
        $this->line('');
        $this->info('── Sarah Orchestrator Dependency Health ──');
        $this->line("EngineIntelligenceService injected: " . ($sarahHealth['engineIntel_injected'] ? '✅' : '❌'));
        $this->line("ToolSelectorService injected:       " . ($sarahHealth['toolSelector_injected'] ? '✅' : '❌'));
        $this->line("ToolCostCalculatorService injected: " . ($sarahHealth['costCalc_injected'] ? '✅' : '❌'));
        $this->line("engineIntel actually called:        " . ($sarahHealth['engineIntel_used'] ? '✅ ' . $sarahHealth['engineIntel_call_count'] . ' calls' : '❌ DEAD DEPENDENCY'));
        $this->line("toolSelector actually called:       " . ($sarahHealth['toolSelector_used'] ? '✅ ' . $sarahHealth['toolSelector_call_count'] . ' calls' : '❌ DEAD DEPENDENCY'));

        if (!$sarahHealth['engineIntel_used'] || !$sarahHealth['toolSelector_used']) {
            $this->error('🚨 Sarah has dead intelligence dependencies. She is not thinking.');
        }

        $this->line('');

        // Overall verdict
        if ($totals['seeded'] === $totals['engines']
            && $totals['with_usage'] > 0
            && $sarahHealth['engineIntel_used']
            && $sarahHealth['toolSelector_used']) {
            $this->info('✅ Intelligence layer is HEALTHY and operational.');
        } elseif ($totals['seeded'] === $totals['engines']) {
            $this->warn('🟡 Intelligence layer is SEEDED but not yet producing data. Run real tasks to populate usage.');
        } else {
            $this->error('🔴 Intelligence layer is NOT HEALTHY. See issues above.');
        }

        return self::SUCCESS;
    }

    private function computeStatus(int $blueprints, int $usage, int $scored): string
    {
        if ($blueprints === 0) return '❌ not seeded';
        if ($usage === 0) return '🟡 seeded, no usage';
        if ($scored === 0) return '🟡 usage, no scores';
        return '✅ learning';
    }

    /**
     * Parse SarahOrchestrator.php source to verify dependency injection AND usage.
     * This catches the "dead dependency" anti-pattern at audit time.
     */
    private function checkSarahHealth(): array
    {
        $path = app_path('Core/Orchestration/SarahOrchestrator.php');
        if (!file_exists($path)) {
            return [
                'engineIntel_injected' => false,
                'toolSelector_injected' => false,
                'costCalc_injected' => false,
                'engineIntel_used' => false,
                'toolSelector_used' => false,
                'engineIntel_call_count' => 0,
                'toolSelector_call_count' => 0,
            ];
        }

        $source = file_get_contents($path);

        return [
            'engineIntel_injected' => str_contains($source, 'EngineIntelligenceService $engineIntel'),
            'toolSelector_injected' => str_contains($source, 'ToolSelectorService $toolSelector'),
            'costCalc_injected' => str_contains($source, 'ToolCostCalculatorService $costCalc'),
            'engineIntel_used' => substr_count($source, '$this->engineIntel->') > 0,
            'toolSelector_used' => substr_count($source, '$this->toolSelector->') > 0,
            'engineIntel_call_count' => substr_count($source, '$this->engineIntel->'),
            'toolSelector_call_count' => substr_count($source, '$this->toolSelector->'),
        ];
    }
}
