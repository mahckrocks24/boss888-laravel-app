<?php

namespace App\Console\Commands;

use App\Engines\Infrastructure\Observation\EstateAlertService;
use App\Engines\Infrastructure\Observation\ObservationCadence;
use App\Engines\Infrastructure\Observation\RegistrarObservationService;
use App\Models\CustomerDomain;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * INFRA888 · S7 — continuous observation entry point and alert operations.
 *
 * OBSERVATION ONLY. Nothing here repairs, renews, reconciles automatically, or
 * writes to any provider. `run` is the command a scheduler WOULD call — no
 * schedule is registered.
 */
class EstateObserveCommand extends Command
{
    protected $signature = 'infra:estate-observe
                            {action=dashboard : run|dashboard|health|alerts|ack|suppress|escalate|cadence|admin}
                            {--kind=registrar : observation kind (registrar|dns|certificate)}
                            {--workspace= : limit to one workspace}
                            {--domain= : limit to one domain}
                            {--alert= : alert id for ack/suppress}
                            {--note= : acknowledgement or suppression reason}
                            {--hours=24 : suppression duration}';

    protected $description = 'INFRA888 S7 — continuous estate observation, health and alert lifecycle (observation only)';

    public function handle(RegistrarObservationService $obs, EstateAlertService $alerts): int
    {
        return match ((string) $this->argument('action')) {
            'run' => $this->runCycle($obs, $alerts),
            'dashboard' => $this->dashboard($alerts),
            'health' => $this->health($alerts),
            'alerts' => $this->alerts(),
            'ack' => $this->ack($alerts),
            'suppress' => $this->suppress($alerts),
            'escalate' => $this->escalate($alerts),
            'cadence' => $this->cadence(),
            'admin' => $this->admin($alerts),
            default => $this->invalid(),
        };
    }

    private function invalid(): int
    {
        $this->error('Use: run|dashboard|health|alerts|ack|suppress|escalate|cadence|admin');

        return self::FAILURE;
    }

    private function domains()
    {
        return CustomerDomain::query()
            ->when($this->option('workspace') !== null, fn ($q) => $q->where('workspace_id', (int) $this->option('workspace')))
            ->when($this->option('domain') !== null, fn ($q) => $q->where('domain', $this->option('domain')))
            ->orderBy('id')->get();
    }

    /** One observation cycle: observe, then reconcile alerts against what we saw. */
    private function runCycle(RegistrarObservationService $obs, EstateAlertService $alerts): int
    {
        $kind = (string) $this->option('kind');

        $this->line('');
        $this->line("INFRA888 S7 — OBSERVATION CYCLE [{$kind}]  (observation only · nothing is repaired)");
        $this->line('');

        $tot = ['raised' => 0, 'rearmed' => 0, 'recovered' => 0];

        foreach ($this->domains() as $d) {
            $r = $obs->observe($d);

            $row = DB::table('infra_domain_observations')->where('id', $r['observation_id'])->first();
            $c = $alerts->reconcileFromObservation($row);

            foreach ($tot as $k => $_) {
                $tot[$k] += $c[$k];
            }

            $this->line(sprintf('  %-30s reachable=%-3s drift=%d  raised=%d rearmed=%d recovered=%d',
                $d->domain, $r['reachable'] ? 'yes' : 'NO', count($r['drift']),
                $c['raised'], $c['rearmed'], $c['recovered']));
        }

        $this->line('');
        $this->line(sprintf('  alerts raised=%d rearmed=%d recovered=%d', $tot['raised'], $tot['rearmed'], $tot['recovered']));
        $this->line('  recovery only ever follows a fresh successful observation.');
        $this->line('');

        return self::SUCCESS;
    }

    private function dashboard(EstateAlertService $a): int
    {
        $d = $a->dashboard();

        $this->line('');
        $this->line('INFRA888 S7 — ESTATE DASHBOARD');
        $this->line('');
        $this->line('  domains: ' . $d['domains']);
        $this->line('  health : ' . json_encode($d['health_counts']));
        $this->line('  alerts : ' . json_encode($d['alerts']));
        $this->line('  open by severity: ' . json_encode($d['by_severity']));
        $this->line('');

        foreach ($d['health'] as $h) {
            $this->line(sprintf('  %-30s %-10s age=%s min', $h['domain'], strtoupper($h['state']),
                $h['observation_age_minutes']));

            foreach ($h['reasons'] as $r) {
                $this->line('        · ' . $r);
            }
        }

        $this->line('');

        return self::SUCCESS;
    }

    private function health(EstateAlertService $a): int
    {
        foreach ($this->domains() as $d) {
            $h = $a->health($d);
            $this->line(sprintf('  %-30s %-10s last_observed=%s', $d->domain, strtoupper($h['state']),
                $h['last_observed_at'] ?? 'never'));

            foreach ($h['reasons'] as $r) {
                $this->line('      reason: ' . $r);
            }
        }

        return self::SUCCESS;
    }

    private function alerts(): int
    {
        $rows = DB::table('infra_estate_alerts')->orderByDesc('id')->get();

        $this->line('');
        $this->line(sprintf('  %-5s %-10s %-9s %-13s %-30s %-6s %s', 'ID', 'SEVERITY', 'STATE', 'CLASS', 'DOMAIN', 'SEEN', 'ESCALATION'));

        foreach ($rows as $r) {
            $this->line(sprintf('  %-5d %-10s %-9s %-13s %-30s %-6d L%d',
                $r->id, $r->severity, $r->state, substr($r->drift_class, 0, 12),
                $r->domain, $r->occurrence_count, $r->escalation_level));
        }

        if ($rows->isEmpty()) {
            $this->line('  (no alerts)');
        }

        $this->line('');

        return self::SUCCESS;
    }

    private function ack(EstateAlertService $a): int
    {
        $id = (int) $this->option('alert');
        $note = (string) ($this->option('note') ?? '');

        if ($id <= 0 || trim($note) === '') {
            $this->error('  --alert and --note are both required. An acknowledgement without a reason is not one.');

            return self::FAILURE;
        }

        $this->line($a->acknowledge($id, null, $note)
            ? "  Alert {$id} acknowledged. The problem is NOT closed — only the escalation clock stopped."
            : "  Alert {$id} could not be acknowledged (missing, or already recovered).");

        return self::SUCCESS;
    }

    private function suppress(EstateAlertService $a): int
    {
        $id = (int) $this->option('alert');
        $note = (string) ($this->option('note') ?? '');

        if ($id <= 0 || trim($note) === '') {
            $this->error('  --alert and --note are both required.');

            return self::FAILURE;
        }

        $h = (int) $this->option('hours');
        $this->line($a->suppress($id, null, $note, $h)
            ? "  Alert {$id} suppressed for {$h}h (max " . EstateAlertService::MAX_SUPPRESSION_HOURS . 'h). It returns automatically.'
            : "  Alert {$id} could not be suppressed.");

        return self::SUCCESS;
    }

    private function escalate(EstateAlertService $a): int
    {
        $n = $a->escalateDue();
        $this->line("  {$n} alert(s) escalated. Nothing raised, nothing closed.");

        return self::SUCCESS;
    }

    private function cadence(): int
    {
        $this->line('');
        $this->line('INFRA888 S7 — OBSERVATION CADENCE  (definitions only; NO schedule registered)');
        $this->line('');

        foreach (ObservationCadence::all() as $kind => $c) {
            $this->line(sprintf('  %-12s every %d min · stale after %d min · %s when stale',
                $kind, $c['interval_minutes'], $c['stale_after_minutes'], strtoupper($c['severity_when_stale'])));
            $this->line('      ' . $c['why']);
            $this->line('');
        }

        $this->line('  PROPOSED registration (inert — requires authorisation):');

        foreach (ObservationCadence::scheduleProposal() as $p) {
            $this->line(sprintf('    %-12s %s   # %s', $p['cron'], $p['command'], $p['note']));
        }

        $this->line('');

        return self::SUCCESS;
    }

    private function admin(EstateAlertService $a): int
    {
        foreach ($this->domains() as $d) {
            $this->line('');
            $this->line('  === ' . $d->domain . ' ===');
            $this->line('  CUSTOMER: ' . json_encode($a->customerView($d)));
            $v = $a->adminView($d);
            $this->line('  HEALTH  : ' . json_encode($v['health']));
            $this->line('  OBSERVATIONS: ' . count($v['observation_history']));
            $this->line('  ALERTS  : ' . count($v['alerts']));

            foreach ($v['alerts'] as $al) {
                $this->line(sprintf('     #%d %s/%s %s occ=%d  -> %s',
                    $al['id'], $al['severity'], $al['state'], $al['drift_class'],
                    $al['occurrences'], substr((string) $al['recommended_action'], 0, 70)));
            }
        }

        $this->line('');

        return self::SUCCESS;
    }
}
