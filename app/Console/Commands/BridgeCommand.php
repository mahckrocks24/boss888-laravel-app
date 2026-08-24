<?php

namespace App\Console\Commands;

use App\Engines\Infrastructure\Observation\Custody;
use App\Engines\Infrastructure\Observation\ObservationAlertBridge;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * INFRA888 · S8.1 — observation → alert bridge operations.
 *
 * INTEGRATION ONLY. No delivery, no scheduling, no escalation, no mutation.
 * Method names are prefixed to avoid shadowing Command::run()/execute().
 */
class BridgeCommand extends Command
{
    protected $signature = 'infra:estate-bridge
                            {action=bridge : bridge|incidents|health|mapping|alerts}
                            {--workspace= : limit to one workspace}
                            {--domain= : limit to one subject}';

    protected $description = 'INFRA888 S8.1 — resolve observations into canonical alerts and correlated incidents';

    public function handle(ObservationAlertBridge $b): int
    {
        return match ((string) $this->argument('action')) {
            'bridge' => $this->doBridge($b),
            'incidents' => $this->doIncidents($b),
            'health' => $this->doHealth($b),
            'mapping' => $this->doMapping($b),
            'alerts' => $this->doAlerts(),
            default => $this->doInvalid(),
        };
    }

    private function doInvalid(): int
    {
        $this->error('Use: bridge|incidents|health|mapping|alerts');

        return self::FAILURE;
    }

    private function ws(): ?int
    {
        return $this->option('workspace') !== null ? (int) $this->option('workspace') : null;
    }

    private function doBridge(ObservationAlertBridge $b): int
    {
        $this->line('');
        $this->line('INFRA888 S8.1 — OBSERVATION → ALERT BRIDGE  (integration only · no delivery)');
        $this->line('');

        $only = $this->option('domain');

        foreach (Custody::estateSubjects($this->ws()) as $s) {
            if ($only !== null && $s['subject'] !== strtolower($only)) {
                continue;
            }

            $r = $b->bridgeSubject($s['subject']);
            $this->line(sprintf('  %-34s raised=%d updated=%d recovered=%d held=%d',
                $s['subject'], $r['raised'], $r['updated'], $r['recovered'], $r['skipped_unreachable']));
        }

        $this->line('');
        $this->line('  held = alerts NOT recovered because no successful observation covered them.');
        $this->line('');

        return self::SUCCESS;
    }

    private function doIncidents(ObservationAlertBridge $b): int
    {
        $this->line('');
        $this->line('INFRA888 S8.1 — CORRELATED INCIDENTS');
        $this->line('');

        $inc = $b->incidents($this->ws());

        foreach ($inc as $i) {
            $this->line(sprintf('  [%-8s] %-32s %s  (%d alert(s), %d observation(s))',
                strtoupper($i['severity']), $i['subject'], $i['correlation_group'],
                count($i['alerts']), $i['evidence_count']));

            foreach ($i['alerts'] as $a) {
                $this->line(sprintf('        #%-4d %-22s %-9s %-13s occ=%d',
                    $a['id'], $a['issue'], $a['severity'], $a['state'], $a['occurrences']));

                foreach ($a['evidence'] as $e) {
                    $this->line(sprintf('               evidence: %-12s %-14s %-9s %s',
                        $e['dimension'], $e['provider'], $e['severity'], substr((string) $e['title'], 0, 60)));
                }
            }

            $this->line('');
        }

        if ($inc === []) {
            $this->info('  No open incidents.');
        }

        $this->line('');

        return self::SUCCESS;
    }

    private function doHealth(ObservationAlertBridge $b): int
    {
        $this->line('');

        foreach (Custody::estateSubjects($this->ws()) as $s) {
            $h = $b->health($s['subject']);
            $this->line(sprintf('  %-34s %-10s alerts=%-3d %s  [derived_from=%s]',
                $h['subject'], strtoupper($h['state']), $h['alerts'],
                implode('; ', $h['reasons']), $h['derived_from']));
        }

        $this->line('');

        return self::SUCCESS;
    }

    private function doMapping(ObservationAlertBridge $b): int
    {
        $this->line('');
        $this->line('INFRA888 S8.1 — CANONICAL ISSUE CATALOGUE');
        $this->line('');

        foreach (ObservationAlertBridge::catalogue() as $issue => $m) {
            $this->line(sprintf('  %-22s %-14s %s', $issue, $b->correlationGroupOf($issue), $m['title']));
            $this->line('                         customer: ' . ($m['customer'] ?? '(not shown to customers)'));
        }

        $this->line('');

        return self::SUCCESS;
    }

    private function doAlerts(): int
    {
        $this->line('');
        $this->line(sprintf('  %-5s %-9s %-11s %-22s %-32s %s', 'ID', 'SEVERITY', 'STATE', 'CANONICAL ISSUE', 'SUBJECT', 'OCC'));

        foreach (DB::table('infra_estate_alerts')->orderByDesc('id')->limit(40)->get() as $a) {
            $this->line(sprintf('  %-5d %-9s %-11s %-22s %-32s %d',
                $a->id, $a->severity, $a->state, substr($a->drift_class, 0, 21),
                substr($a->domain, 0, 31), $a->occurrence_count));
        }

        $this->line('');

        return self::SUCCESS;
    }
}
