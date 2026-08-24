<?php

namespace App\Console\Commands;

use App\Engines\Infrastructure\Services\DomainRenewalService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * INFRA888 · S3 — operator entry point for the domain renewal lifecycle.
 *
 * `execute` is the only action that spends money, and it is gated three times:
 * a config flag, an explicit renewal id (never a bulk sweep), and a confirmation
 * token derived from the plan.
 */
class DomainRenewalsCommand extends Command
{
    protected $signature = 'infra:domain-renewals
                            {action=status : status|eligible|schedule|due|execute|verify|monitor}
                            {--workspace= : limit to one workspace}
                            {--id= : renewal id, for execute/verify}
                            {--confirm= : token printed by the dry run}
                            {--force : bypass the execution flag (still requires --confirm)}';

    protected $description = 'INFRA888 S3 — domain renewal orchestration: eligibility, scheduling, execution, verification, monitoring';

    public function handle(DomainRenewalService $svc): int
    {
        $ws = $this->option('workspace') !== null ? (int) $this->option('workspace') : null;

        return match ((string) $this->argument('action')) {
            'status' => $this->status(),
            'eligible' => $this->eligible($svc, $ws),
            'schedule' => $this->schedule($svc, $ws),
            'due' => $this->due($svc),
            'execute' => $this->runExecute($svc),
            'verify' => $this->verify($svc),
            'monitor' => $this->monitor($svc),
            default => $this->invalid(),
        };
    }

    private function invalid(): int
    {
        $this->error('Unknown action. Use: status|eligible|schedule|due|execute|verify|monitor');

        return self::FAILURE;
    }

    private function status(): int
    {
        $this->line('');
        $this->line('INFRA888 S3 — DOMAIN RENEWAL LEDGER');
        $this->line('');

        $rows = DB::table('infra_domain_renewals')->orderBy('id')->get();

        if ($rows->isEmpty()) {
            $this->warn('  No renewal records. Run `eligible`, then `schedule`.');
        }

        foreach ($rows as $r) {
            $this->line(sprintf('  #%-4d %-30s %-13s attempts=%d/%d verify=%d  %s',
                $r->id, $r->domain, $r->state, $r->attempt_count,
                DomainRenewalService::MAX_ATTEMPTS, $r->verify_attempts,
                $r->failure_code ? "[{$r->failure_class}: {$r->failure_code}]" : ''));
        }

        $this->line('');
        $this->line('  execution gate: ' . (config('infrastructure.renewals.execution_enabled', false) ? 'OPEN' : 'CLOSED'));
        $this->line('');

        return self::SUCCESS;
    }

    private function eligible(DomainRenewalService $svc, ?int $ws): int
    {
        $r = $svc->findEligible($ws);

        $this->line('');
        $this->line('ELIGIBLE FOR RENEWAL (within ' . DomainRenewalService::REMINDER_DAYS . ' days)');
        $this->line('');

        foreach ($r['eligible'] as $e) {
            $this->line(sprintf('  %-30s expires %s  in %d days  mode=%s',
                $e['domain'], $e['expires_at'], $e['days_until_expiry'], $e['mode']));
        }

        if ($r['eligible'] === []) {
            $this->line('  (none)');
        }

        $this->line('');
        $this->line('  NOT eligible:');

        foreach ($r['skipped'] as $s) {
            $this->line(sprintf('    %-30s %s', $s['domain'], $s['reason']));
        }

        $this->line('');

        return self::SUCCESS;
    }

    private function schedule(DomainRenewalService $svc, ?int $ws): int
    {
        $plan = $svc->schedule($ws, true);
        $token = substr(hash('sha256', 'schedule|' . $plan['eligible'] . '|' . date('Y-m-d')), 0, 20);

        $this->line('');
        $this->line(sprintf('  eligible: %d   would create: %d   skipped: %d',
            $plan['eligible'], $plan['eligible'], $plan['skipped']));

        if ($plan['eligible'] === 0) {
            $this->info('  Nothing to schedule.');

            return self::SUCCESS;
        }

        if (! hash_equals($token, (string) ($this->option('confirm') ?? ''))) {
            $this->line('');
            $this->info("  DRY RUN — nothing written. Confirm with --confirm={$token}");

            return self::SUCCESS;
        }

        $done = $svc->schedule($ws, false);
        $this->info(sprintf('  Scheduled %d renewal(s). No provider call was made.', $done['created']));

        return self::SUCCESS;
    }

    private function due(DomainRenewalService $svc): int
    {
        $n = $svc->markDue();
        $this->info("  {$n} renewal(s) moved to `due`.");

        return self::SUCCESS;
    }

    private function runExecute(DomainRenewalService $svc): int
    {
        $id = (int) $this->option('id');

        if ($id <= 0) {
            $this->error('  --id is required. Renewals are executed one at a time, never in bulk: each one spends money.');

            return self::FAILURE;
        }

        $r = DB::table('infra_domain_renewals')->where('id', $id)->first();

        if ($r === null) {
            $this->error('  No such renewal.');

            return self::FAILURE;
        }

        $token = substr(hash('sha256', 'execute|' . $r->id . '|' . $r->idempotency_key), 0, 20);

        $this->line('');
        $this->line("  BILLABLE ACTION — renew {$r->domain} for {$r->years} year(s)");
        $this->line("  state={$r->state}  attempts={$r->attempt_count}  expiry_before={$r->expiry_before}");
        $this->line('');

        if (! hash_equals($token, (string) ($this->option('confirm') ?? ''))) {
            $this->warn("  NOT EXECUTED. This spends money. Confirm with --confirm={$token}");

            return self::SUCCESS;
        }

        $out = $svc->execute($id, (bool) $this->option('force'));
        $this->line(sprintf('  outcome: %s — %s', $out['outcome'], $out['message']));

        return self::SUCCESS;
    }

    private function verify(DomainRenewalService $svc): int
    {
        $id = (int) $this->option('id');

        if ($id > 0) {
            $out = $svc->verify($id);
            $this->line(sprintf('  #%d %s — %s', $id, $out['outcome'], $out['message']));

            return self::SUCCESS;
        }

        // Verification is read-only, so sweeping it is safe.
        $rows = DB::table('infra_domain_renewals')->where('state', 'verifying')->get();

        foreach ($rows as $r) {
            $out = $svc->verify((int) $r->id);
            $this->line(sprintf('  #%d %-28s %s — %s', $r->id, $r->domain, $out['outcome'], $out['message']));
        }

        if ($rows->isEmpty()) {
            $this->line('  Nothing awaiting verification.');
        }

        return self::SUCCESS;
    }

    private function monitor(DomainRenewalService $svc): int
    {
        $f = $svc->monitor();

        $this->line('');
        $this->line('INFRA888 S3 — RENEWAL MONITORING');
        $this->line('');

        foreach ($f as $x) {
            $this->line(sprintf('  [%-8s] %-28s %-34s %s',
                strtoupper($x['severity']), $x['check'], (string) $x['subject'], $x['detail']));
        }

        if ($f === []) {
            $this->info('  No findings.');
        }

        $crit = count(array_filter($f, fn ($x) => $x['severity'] === 'critical'));
        $this->line('');
        $this->line(sprintf('  %d finding(s), %d critical', count($f), $crit));
        $this->line('');

        return $crit > 0 ? self::FAILURE : self::SUCCESS;
    }
}
