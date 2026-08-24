<?php

namespace App\Console\Commands;

use App\Engines\Infrastructure\Observation\AlertEligibility;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * INFRA888 · S8.3 — alert eligibility policy.
 *
 * EVALUATION ONLY. Delivers nothing, schedules nothing, mutates no asset, and
 * never changes alert state. It answers "may this alert leave INFRA888?" and
 * shows the operator exactly which rule decided and why.
 */
class EligibilityCommand extends Command
{
    protected $signature = 'infra:alert-eligibility
                            {action=evaluate : evaluate|explain|rules|audit}
                            {--workspace= : limit to one workspace}
                            {--alert= : explain one alert id}
                            {--dry : evaluate without recording an audit row}';

    protected $description = 'INFRA888 S8.3 — decide whether alerts are eligible to leave INFRA888 (evaluation only)';

    public function handle(AlertEligibility $e): int
    {
        return match ((string) $this->argument('action')) {
            'evaluate' => $this->doEvaluate($e),
            'explain' => $this->doExplain($e),
            'rules' => $this->doRules(),
            'audit' => $this->doAudit(),
            default => $this->doInvalid(),
        };
    }

    private function doInvalid(): int
    {
        $this->error('Use: evaluate|explain|rules|audit');

        return self::FAILURE;
    }

    private function doEvaluate(AlertEligibility $e): int
    {
        $ws = $this->option('workspace') !== null ? (int) $this->option('workspace') : null;
        $decisions = $e->evaluateAll($ws, ! $this->option('dry'));

        $this->line('');
        $this->line('INFRA888 S8.3 — ALERT ELIGIBILITY  (evaluation only · nothing delivered)');
        $this->line('');
        $this->line(sprintf('  %-5s %-32s %-9s %-15s %-24s %s', 'ID', 'SUBJECT', 'SEVERITY', 'VERDICT', 'DECIDING RULE', 'REASON'));

        foreach ($decisions as $d) {
            $this->line(sprintf('  %-5d %-32s %-9s %-15s %-24s %s',
                $d['alert_id'], substr($d['subject'], 0, 31), $d['severity'],
                strtoupper($d['verdict']), (string) $d['deciding_rule'], substr($d['reason'], 0, 60)));
        }

        if ($decisions === []) {
            $this->line('  (no alerts to evaluate)');
        }

        $this->line('');
        $this->line('  ' . json_encode($e->summary($decisions)));
        $this->line('');

        return self::SUCCESS;
    }

    private function doExplain(AlertEligibility $e): int
    {
        $id = (int) $this->option('alert');
        $a = DB::table('infra_estate_alerts')->where('id', $id)->first();

        if ($a === null) {
            $this->error('  No such alert.');

            return self::FAILURE;
        }

        $d = $e->evaluate($a, false);

        $this->line('');
        $this->line("  ALERT #{$id}  {$a->domain}  [{$a->drift_class} / {$a->severity} / {$a->state}]");
        $this->line("  VERDICT: " . strtoupper($d['verdict']) . '  (' . (string) $d['deciding_rule'] . ')');
        $this->line("  REASON : {$d['reason']}");
        $this->line('');
        $this->line('  RULE TRAIL:');

        foreach ($d['trail'] as $t) {
            $this->line(sprintf('    %-24s %-15s %s', $t['rule'], strtoupper($t['result']), $t['reason']));
        }

        $this->line('');

        return self::SUCCESS;
    }

    private function doRules(): int
    {
        $this->line('');
        $this->line('INFRA888 S8.3 — RULE PIPELINE (first non-eligible verdict wins)');
        $this->line('');

        foreach (AlertEligibility::rulePipeline() as $i => $r) {
            $this->line(sprintf('  %d. %s', $i + 1, $r));
        }

        $this->line('');
        $this->line('  interrupt threshold : ' . implode(', ', AlertEligibility::DELIVERABLE_SEVERITIES));
        $this->line('  redelivery cooldown : ' . AlertEligibility::REDELIVERY_COOLDOWN_MINUTES . ' min');
        $this->line('');

        return self::SUCCESS;
    }

    private function doAudit(): int
    {
        $this->line('');
        $this->line(sprintf('  %-5s %-32s %-15s %-24s %s', 'ALERT', 'SUBJECT', 'VERDICT', 'RULE', 'DECIDED'));

        foreach (DB::table('infra_alert_eligibility_decisions')->orderByDesc('id')->limit(30)->get() as $d) {
            $this->line(sprintf('  %-5d %-32s %-15s %-24s %s',
                $d->alert_id, substr($d->subject, 0, 31), strtoupper($d->verdict),
                (string) $d->deciding_rule, substr((string) $d->decided_at, 0, 16)));
        }

        $this->line('');

        return self::SUCCESS;
    }
}
