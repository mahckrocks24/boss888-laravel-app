<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\ValidationReportService;

class ValidationReportCommand extends Command
{
    protected $signature = 'boss888:validation-report {--json : Output raw JSON}';

    protected $description = 'Generate and display the Boss888 system validation report';

    public function handle(ValidationReportService $reportService): int
    {
        $report = $reportService->generate();

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return 0;
        }

        $this->info('═══════════════════════════════════════════');
        $this->info('  Boss888 Validation Report');
        $this->info('  ' . $report['generated_at']);
        $this->info('═══════════════════════════════════════════');

        $this->newLine();

        $statusColor = match ($report['system_status']) {
            'stable' => 'info',
            'degraded' => 'warn',
            'critical' => 'error',
            default => 'line',
        };

        $this->{$statusColor}("  System Status: " . strtoupper($report['system_status']));
        $this->info("  Reliability Score: {$report['reliability_score']}%");
        $this->newLine();

        $this->info("  Total Checks: {$report['total_tests']}");
        $this->info("  Passed: {$report['passed']}");

        if ($report['failed'] > 0) {
            $this->error("  Failed: {$report['failed']}");
        } else {
            $this->info("  Failed: 0");
        }

        if ($report['warnings'] > 0) {
            $this->warn("  Warnings: {$report['warnings']}");
        } else {
            $this->info("  Warnings: 0");
        }

        $this->newLine();
        $this->info('── Check Results ──');

        $rows = [];
        foreach ($report['checks'] as $check) {
            $icon = match ($check['status']) {
                'pass' => '✅',
                'warn' => '⚠️',
                'fail' => '❌',
                default => '?',
            };
            $rows[] = [$icon, $check['name'], $check['message']];
        }

        $this->table(['', 'Check', 'Result'], $rows);

        if (! empty($report['failures'])) {
            $this->newLine();
            $this->error('── Failures ──');
            foreach ($report['failures'] as $f) {
                $this->error("  ❌ {$f['name']}: {$f['message']}");
            }
        }

        if (! empty($report['warning_list'])) {
            $this->newLine();
            $this->warn('── Warnings ──');
            foreach ($report['warning_list'] as $w) {
                $this->warn("  ⚠️  {$w['name']}: {$w['message']}");
            }
        }

        $this->newLine();
        $this->info('Report complete.');

        return $report['system_status'] === 'critical' ? 1 : 0;
    }
}
