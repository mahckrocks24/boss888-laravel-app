<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\PerformanceCollector;
use App\Services\ValidationReportService;
use App\Services\ConnectorCircuitBreakerService;
use App\Services\QueueControlService;

class InfraReportCommand extends Command
{
    protected $signature = 'boss888:infra-report
        {--json : Output raw JSON}
        {--since=1h : Time window (e.g. 1h, 24h, 7d)}';

    protected $description = 'Phase 5: Generate comprehensive infrastructure validation report';

    public function handle(
        PerformanceCollector $perf,
        ValidationReportService $validation,
        ConnectorCircuitBreakerService $cb,
        QueueControlService $qc,
    ): int {
        $sinceStr = $this->option('since');
        $since = $this->parseSince($sinceStr);

        $perfMetrics = $perf->collect($since);
        $validationReport = $validation->generate();

        $report = [
            'generated_at' => now()->toIso8601String(),
            'period' => $perfMetrics['period'],
            'reliability_score' => $validationReport['reliability_score'],
            'infra_status' => $this->determineInfraStatus($perfMetrics, $validationReport),
            'system_status' => $validationReport['system_status'],

            'infrastructure' => $perfMetrics['infrastructure'],

            'queue_health' => $perfMetrics['queue_metrics'],

            'task_performance' => $perfMetrics['task_metrics'],

            'connector_health' => $perfMetrics['connector_metrics'],

            'credit_integrity' => $perfMetrics['credit_metrics'],

            'idempotency' => $perfMetrics['idempotency_metrics'],

            'validation_checks' => $validationReport['checks'],

            'failure_analysis' => $validationReport['failures'],

            'recommendations' => $this->generateRecommendations($perfMetrics, $validationReport),
        ];

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return 0;
        }

        // Formatted output
        $this->info('═══════════════════════════════════════════════════════');
        $this->info('  Boss888 Infrastructure Validation Report');
        $this->info('  ' . $report['generated_at']);
        $this->info('═══════════════════════════════════════════════════════');

        $this->newLine();
        $statusColor = match ($report['infra_status']) {
            'stable' => 'info',
            'warning' => 'warn',
            'critical' => 'error',
            default => 'line',
        };
        $this->{$statusColor}("  Infrastructure: " . strtoupper($report['infra_status']));
        $this->info("  Reliability Score: {$report['reliability_score']}%");

        // Infrastructure
        $this->newLine();
        $this->info('── Infrastructure ──');
        $infra = $report['infrastructure'];
        $this->table(['Component', 'Connected', 'Latency'], [
            ['Database', $infra['database']['connected'] ? '✅' : '❌', $infra['database']['latency_ms'] . 'ms'],
            ['Redis', $infra['redis']['connected'] ? '✅' : '❌', $infra['redis']['latency_ms'] . 'ms'],
            ['Queue Driver', $infra['queue_driver'], '—'],
            ['Cache Driver', $infra['cache_driver'], '—'],
        ]);

        // Task Performance
        $this->info('── Task Performance ──');
        $t = $report['task_performance'];
        $this->table(['Metric', 'Value'], [
            ['Total', $t['total']],
            ['Completed', $t['completed']],
            ['Failed', $t['failed']],
            ['Failure Rate', $t['failure_rate_percent'] . '%'],
            ['Avg Execution', $t['avg_execution_time_seconds'] . 's'],
            ['Avg Queue Wait', $t['avg_queue_wait_seconds'] . 's'],
            ['Total Retries', $t['total_retries']],
        ]);

        // Queue Health
        $this->info('── Queue Health ──');
        $q = $report['queue_health'];
        $this->table(['Metric', 'Value'], [
            ['Pending', $q['pending_tasks']],
            ['Running', $q['running_tasks']],
            ['Stale', $q['stale_tasks']],
        ]);

        // Connector Health
        $this->info('── Connector Reliability ──');
        $connRows = [];
        foreach ($report['connector_health'] as $name => $c) {
            $connRows[] = [
                $name,
                $c['executions'],
                $c['verification_failure_rate'] . '%',
                $c['circuit_state'],
                $c['circuit_failures'],
            ];
        }
        $this->table(['Connector', 'Executions', 'Verify Fail%', 'Circuit', 'Failures'], $connRows);

        // Credit Integrity
        $this->info('── Credit Integrity ──');
        $cr = $report['credit_integrity'];
        $this->table(['Check', 'Value'], [
            ['Pending Reservations', $cr['pending_reservations']],
            ['Committed', $cr['committed']],
            ['Released', $cr['released']],
            ['Orphaned', $cr['orphaned_reservations']],
            ['Integrity', $cr['integrity']],
        ]);

        // Idempotency
        $this->info('── Idempotency ──');
        $id = $report['idempotency'];
        $this->table(['Metric', 'Value'], [
            ['Duplicate Skips', $id['duplicate_skips']],
            ['Lock Blocks', $id['lock_blocks']],
            ['Collision Rate', $id['collision_rate']],
        ]);

        // Recommendations
        if (! empty($report['recommendations'])) {
            $this->newLine();
            $this->warn('── Recommendations ──');
            foreach ($report['recommendations'] as $rec) {
                $this->warn("  • {$rec}");
            }
        }

        // Failures
        if (! empty($report['failure_analysis'])) {
            $this->newLine();
            $this->error('── Failures ──');
            foreach ($report['failure_analysis'] as $f) {
                $this->error("  ❌ {$f['name']}: {$f['message']}");
            }
        }

        $this->newLine();
        $this->info('Report complete.');

        return $report['infra_status'] === 'critical' ? 1 : 0;
    }

    private function determineInfraStatus(array $perf, array $validation): string
    {
        $infra = $perf['infrastructure'];
        $credits = $perf['credit_metrics'];
        $tasks = $perf['task_metrics'];

        if (! $infra['database']['connected'] || ! $infra['redis']['connected']) {
            return 'critical';
        }

        if ($credits['orphaned_reservations'] > 0 || $tasks['failure_rate_percent'] > 20) {
            return 'critical';
        }

        if ($perf['queue_metrics']['stale_tasks'] > 0 || $tasks['failure_rate_percent'] > 5) {
            return 'warning';
        }

        if ($validation['system_status'] !== 'stable') {
            return 'warning';
        }

        return 'stable';
    }

    private function generateRecommendations(array $perf, array $validation): array
    {
        $recs = [];

        $infra = $perf['infrastructure'];
        if ($infra['database']['latency_ms'] > 50) {
            $recs[] = "Database latency is {$infra['database']['latency_ms']}ms — consider query optimization or connection pooling";
        }
        if ($infra['redis']['latency_ms'] > 10) {
            $recs[] = "Redis latency is {$infra['redis']['latency_ms']}ms — check network or consider local Redis";
        }
        if ($infra['queue_driver'] === 'sync') {
            $recs[] = 'CRITICAL: Queue driver is sync — switch to redis for production';
        }
        if ($infra['cache_driver'] === 'array') {
            $recs[] = 'CRITICAL: Cache driver is array — switch to redis for production';
        }

        $tasks = $perf['task_metrics'];
        if ($tasks['failure_rate_percent'] > 10) {
            $recs[] = "Task failure rate is {$tasks['failure_rate_percent']}% — investigate connector health and error logs";
        }
        if ($tasks['avg_queue_wait_seconds'] > 30) {
            $recs[] = "Average queue wait is {$tasks['avg_queue_wait_seconds']}s — consider adding more workers";
        }

        if ($perf['credit_metrics']['orphaned_reservations'] > 0) {
            $recs[] = "Found {$perf['credit_metrics']['orphaned_reservations']} orphaned credit reservations — run boss888:recover-stale";
        }

        if ($perf['queue_metrics']['stale_tasks'] > 0) {
            $recs[] = "Found {$perf['queue_metrics']['stale_tasks']} stale tasks — run boss888:recover-stale";
        }

        foreach ($perf['connector_metrics'] as $name => $c) {
            if ($c['circuit_state'] !== 'closed') {
                $recs[] = "Connector {$name} circuit is {$c['circuit_state']} — check connector health";
            }
            if ($c['verification_failure_rate'] > 10) {
                $recs[] = "Connector {$name} has {$c['verification_failure_rate']}% verification failures — investigate response format";
            }
        }

        if (empty($recs)) {
            $recs[] = 'System looks healthy — no action required';
        }

        return $recs;
    }

    private function parseSince(string $str): \DateTimeInterface
    {
        if (preg_match('/^(\d+)(h|d|m)$/', $str, $m)) {
            $n = (int) $m[1];
            return match ($m[2]) {
                'h' => now()->subHours($n),
                'd' => now()->subDays($n),
                'm' => now()->subMinutes($n),
                default => now()->subHour(),
            };
        }
        return now()->subHour();
    }
}
