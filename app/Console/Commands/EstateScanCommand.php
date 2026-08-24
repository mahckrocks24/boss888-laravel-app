<?php

namespace App\Console\Commands;

use App\Engines\Infrastructure\Observation\Custody;
use App\Engines\Infrastructure\Observation\EstateObservationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * INFRA888 · S8 — customer estate observation.
 *
 * OBSERVATION ONLY. No mutation of any kind, no delivery, no scheduling.
 * Method names are deliberately prefixed to avoid shadowing the Symfony/Laravel
 * base-class methods `run()` and `execute()` — a collision that broke artisan
 * twice in earlier milestones.
 */
class EstateScanCommand extends Command
{
    protected $signature = 'infra:estate-scan
                            {action=scan : scan|subjects|health|dashboard|drift|registry}
                            {--workspace= : limit to one workspace}
                            {--domain= : limit to one hostname}';

    protected $description = 'INFRA888 S8 — observe DNS, certificate, HTTP and WHOIS across the customer estate (read-only)';

    public function handle(EstateObservationService $svc): int
    {
        return match ((string) $this->argument('action')) {
            'scan' => $this->doScan($svc),
            'subjects' => $this->doSubjects(),
            'health' => $this->doHealth($svc),
            'dashboard' => $this->doDashboard($svc),
            'drift' => $this->doDrift(),
            'registry' => $this->doRegistry(),
            default => $this->doInvalid(),
        };
    }

    private function doInvalid(): int
    {
        $this->error('Use: scan|subjects|health|dashboard|drift|registry');

        return self::FAILURE;
    }

    private function ws(): ?int
    {
        return $this->option('workspace') !== null ? (int) $this->option('workspace') : null;
    }

    private function doSubjects(): int
    {
        $this->line('');
        $this->line('INFRA888 S8 — ESTATE SUBJECTS AND CUSTODY');
        $this->line('');
        $this->line(sprintf('  %-34s %-18s %-8s %s', 'SUBJECT', 'CUSTODY', 'WS', 'BASIS'));

        foreach (Custody::estateSubjects($this->ws()) as $s) {
            $this->line(sprintf('  %-34s %-18s %-8s %s',
                $s['subject'], $s['custody'], $s['workspace_id'] ?? '-', $s['reason']));
            $this->line(sprintf('  %-34s observable: %s', '',
                implode(', ', Custody::observableDimensions($s['custody']))));
        }

        $this->line('');

        return self::SUCCESS;
    }

    private function doScan(EstateObservationService $svc): int
    {
        $this->line('');
        $this->line('INFRA888 S8 — ESTATE OBSERVATION  (read-only · no mutation · no delivery)');
        $this->line('');

        $crit = 0;

        foreach ($svc->observeEstate($this->ws(), $this->option('domain')) as $r) {
            $this->line(sprintf('  %s   [%s]', $r['subject'], $r['custody']));

            foreach ($r['dimensions'] as $dim => $d) {
                $this->line(sprintf('    %-12s %-8s conf=%-9s drift=%-2d %s',
                    $dim, $d['success'] ? 'ok' : 'FAILED', $d['confidence'],
                    count($d['drift']), $d['error_code'] ?? ''));

                foreach ($d['drift'] as $x) {
                    $crit += ($x['severity'] ?? '') === 'critical' ? 1 : 0;
                    $this->line(sprintf('        [%-8s] %s', strtoupper($x['severity']), $x['title']));
                    $this->line(sprintf('                   -> %s', $x['recommended_action']));
                }
            }

            $this->line('');
        }

        $this->line("  {$crit} critical finding(s). Nothing was changed.");
        $this->line('');

        return $crit > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function doHealth(EstateObservationService $svc): int
    {
        $this->line('');

        foreach (Custody::estateSubjects($this->ws()) as $s) {
            if ($this->option('domain') !== null && $s['subject'] !== strtolower($this->option('domain'))) {
                continue;
            }

            $h = $svc->health($s['subject']);
            $this->line(sprintf('  %-34s OVERALL=%-12s custody=%s', $h['subject'],
                strtoupper($h['overall']), $h['custody']));

            foreach ($h['dimensions'] as $dim => $d) {
                $this->line(sprintf('      %-12s %-15s conf=%-9s %s',
                    $dim, strtoupper($d['state']), $d['confidence'], $d['reason']));
            }

            $this->line('');
        }

        return self::SUCCESS;
    }

    private function doDashboard(EstateObservationService $svc): int
    {
        $d = $svc->dashboard($this->ws());

        $this->line('');
        $this->line('INFRA888 S8 — CUSTOMER ESTATE DASHBOARD');
        $this->line('');
        $this->line('  subjects   : ' . $d['subjects']);
        $this->line('  by custody : ' . json_encode($d['by_custody']));
        $this->line('  by health  : ' . json_encode($d['by_overall']));
        $this->line('');

        foreach ($d['health'] as $h) {
            $bits = [];

            foreach ($h['dimensions'] as $dim => $x) {
                if ($x['state'] !== 'not_applicable') {
                    $bits[] = substr($dim, 0, 4) . '=' . $x['state'];
                }
            }

            $this->line(sprintf('  %-34s %-10s %s', $h['subject'], strtoupper($h['overall']), implode(' ', $bits)));
        }

        $this->line('');

        return self::SUCCESS;
    }

    private function doDrift(): int
    {
        $this->line('');
        $rows = DB::table('infra_observation_facts')->where('has_drift', true)
            ->orderByDesc('id')->limit(60)->get();

        foreach ($rows as $r) {
            foreach ((array) json_decode((string) $r->drift_json, true) as $x) {
                $this->line(sprintf('  [%-8s] %-30s %-12s %s',
                    strtoupper((string) $x['severity']), $r->subject, $r->dimension, $x['title']));
                $this->line(sprintf('               -> %s', $x['recommended_action'] ?? ''));
            }
        }

        if ($rows->isEmpty()) {
            $this->line('  No drift recorded. Run `scan` first if this looks wrong.');
        }

        $this->line('');

        return self::SUCCESS;
    }

    private function doRegistry(): int
    {
        $this->line('');
        $this->line(sprintf('  %-30s %-12s %-16s %-9s %-10s %-6s %s',
            'SUBJECT', 'DIMENSION', 'CUSTODY', 'SUCCESS', 'CONF', 'MS', 'LAST SUCCESS'));

        foreach (DB::table('infra_observation_facts')->orderByDesc('id')->limit(40)->get() as $r) {
            $this->line(sprintf('  %-30s %-12s %-16s %-9s %-10s %-6s %s',
                substr($r->subject, 0, 29), $r->dimension, $r->custody,
                $r->success ? 'yes' : 'NO', $r->confidence, $r->duration_ms,
                substr((string) $r->last_success_at, 0, 16) ?: 'never'));
        }

        $this->line('');

        return self::SUCCESS;
    }
}
