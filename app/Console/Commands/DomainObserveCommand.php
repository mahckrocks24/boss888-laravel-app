<?php

namespace App\Console\Commands;

use App\Engines\Infrastructure\Observation\DomainDrift;
use App\Engines\Infrastructure\Observation\RegistrarObservationService;
use App\Models\CustomerDomain;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * INFRA888 · S6 — registrar observation and estate reconciliation.
 *
 * Every action here is read-only against both the registrar and the estate.
 * There is deliberately NO repair action: this command reports the difference
 * between belief and reality and tells an operator what to do about it.
 */
class DomainObserveCommand extends Command
{
    protected $signature = 'infra:domain-observe
                            {action=report : observe|report|drift|monitor|taxonomy|admin}
                            {--workspace= : limit to one workspace}
                            {--domain= : limit to one domain}';

    protected $description = 'INFRA888 S6 — observe the registrar and reconcile it against the estate (read-only; never repairs)';

    public function handle(RegistrarObservationService $svc): int
    {
        return match ((string) $this->argument('action')) {
            'observe' => $this->observe($svc),
            'report' => $this->report($svc),
            'drift' => $this->drift($svc),
            'monitor' => $this->monitor($svc),
            'taxonomy' => $this->taxonomy(),
            'admin' => $this->admin($svc),
            default => $this->invalid(),
        };
    }

    private function invalid(): int
    {
        $this->error('Unknown action. Use: observe|report|drift|monitor|taxonomy|admin');

        return self::FAILURE;
    }

    private function domains(): \Illuminate\Support\Collection
    {
        return CustomerDomain::query()
            ->when($this->option('workspace') !== null, fn ($q) => $q->where('workspace_id', (int) $this->option('workspace')))
            ->when($this->option('domain') !== null, fn ($q) => $q->where('domain', $this->option('domain')))
            ->orderBy('id')->get();
    }

    private function observe(RegistrarObservationService $svc): int
    {
        $this->line('');
        $this->line('INFRA888 S6 — REGISTRAR OBSERVATION  (read-only · nothing is repaired)');
        $this->line('');

        $crit = 0;

        foreach ($this->domains() as $d) {
            $r = $svc->observe($d);
            $sev = $r['highest_severity'] ?? '-';
            $crit += $sev === DomainDrift::CRITICAL ? 1 : 0;

            $this->line(sprintf('  %-30s reachable=%-5s drift=%-2d %s',
                $d->domain, $r['reachable'] ? 'yes' : 'NO', count($r['drift']), strtoupper((string) $sev)));

            foreach ($r['drift'] as $x) {
                $this->line(sprintf('      [%-8s] %s', strtoupper($x['severity']), $x['title']));
                $this->line(sprintf('                 observed=%s  desired=%s',
                    $this->fmt($x['observed']), $this->fmt($x['desired'])));
                $this->line(sprintf('                 -> %s', $x['recommended_action']));
            }
        }

        $this->line('');
        $this->line('  Observations recorded. No estate value was changed.');
        $this->line('');

        return $crit > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function report(RegistrarObservationService $svc): int
    {
        $latest = $svc->latestPerDomain();

        $this->line('');
        $this->line('INFRA888 S6 — ESTATE vs REGISTRAR');
        $this->line('');
        $this->line(sprintf('  %-30s %-21s %-21s %-9s %s', 'DOMAIN', 'ESTATE BELIEVES', 'REGISTRAR SAYS', 'DRIFT', 'LAST SEEN'));

        foreach ($this->domains() as $d) {
            $o = $latest[$d->id] ?? null;

            $this->line(sprintf('  %-30s %-21s %-21s %-9s %s',
                $d->domain,
                substr((string) $d->expires_at, 0, 10) ?: '-',
                $o ? (substr((string) $o->observed_expires_at, 0, 10) ?: '-') : '(never observed)',
                $o ? ($o->drift_count . ' ' . strtoupper((string) $o->highest_severity)) : '?',
                $o ? substr((string) $o->observed_at, 0, 16) : '-'));
        }

        $this->line('');

        return self::SUCCESS;
    }

    private function drift(RegistrarObservationService $svc): int
    {
        $latest = $svc->latestPerDomain();
        $n = 0;

        $this->line('');

        foreach ($this->domains() as $d) {
            $o = $latest[$d->id] ?? null;

            foreach ((array) json_decode((string) ($o->drift_json ?? '[]'), true) as $x) {
                $n++;
                $this->line(sprintf('  [%-8s] %-30s %s', strtoupper($x['severity']), $d->domain, $x['title']));
                $this->line(sprintf('             observed : %s', $this->fmt($x['observed'] ?? null)));
                $this->line(sprintf('             desired  : %s', $this->fmt($x['desired'] ?? null)));
                $this->line(sprintf('             operator : %s', $x['operator_guidance'] ?? ''));
                $this->line(sprintf('             action   : %s', $x['recommended_action'] ?? ''));
                $this->line('');
            }
        }

        $this->line($n === 0 ? '  No drift recorded. (Run `observe` first if this looks wrong.)' : "  {$n} drift finding(s).");
        $this->line('');

        return self::SUCCESS;
    }

    private function monitor(RegistrarObservationService $svc): int
    {
        $f = $svc->monitor();

        $this->line('');
        $this->line('INFRA888 S6 — RECONCILIATION MONITORING');
        $this->line('');

        foreach ($f as $x) {
            $this->line(sprintf('  [%-8s] %-30s %-30s %s',
                strtoupper($x['severity']), $x['check'], $x['subject'], $x['detail']));
        }

        $crit = count(array_filter($f, fn ($x) => $x['severity'] === DomainDrift::CRITICAL));

        $this->line('');
        $this->line(sprintf('  %d finding(s), %d critical', count($f), $crit));
        $this->line('');

        return $crit > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function taxonomy(): int
    {
        $this->line('');
        $this->line('INFRA888 S6 — DRIFT TAXONOMY');
        $this->line('');

        foreach (DomainDrift::catalogue() as $class => $m) {
            $this->line(sprintf('  [%-8s] %s', strtoupper($m['severity']), $class));
            $this->line('             ' . $m['title']);
            $this->line('             customer: ' . ($m['customer'] ?? '(not shown to customers)'));
            $this->line('             action  : ' . $m['action']);
            $this->line('');
        }

        return self::SUCCESS;
    }

    private function admin(RegistrarObservationService $svc): int
    {
        foreach ($this->domains() as $d) {
            $this->line('');
            $this->line('  ' . $d->domain);
            $this->line('  CUSTOMER: ' . json_encode($svc->customerView($d)));
            $a = $svc->adminView($d, 5);
            $this->line('  ADMIN latest: ' . json_encode($a['latest']['observed'] ?? null));
            $this->line('  ADMIN desired: ' . json_encode($a['latest']['desired'] ?? null));
            $this->line('  ADMIN history: ' . count($a['history']) . ' observation(s)');
        }

        $this->line('');

        return self::SUCCESS;
    }

    private function fmt($v): string
    {
        if (is_array($v)) {
            return implode(', ', array_map(fn ($x) => (string) $x, $v));
        }

        if (is_bool($v)) {
            return $v ? 'true' : 'false';
        }

        return $v === null ? '-' : (string) $v;
    }
}
