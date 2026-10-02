<?php

namespace App\Console\Commands;

use App\Core\OutcomeLedger\OutcomeLedgerService;
use Illuminate\Console\Command;

/**
 * RFC-0023 P2: hourly, the Outcome Ledger measures what delivered work produced at 24 h / 7 d / 30 d and records declined
 * campaigns. Scheduled behind storage/app/memory1.on. `--backfill=N` records the last N completed tasks that have no row.
 */
class OutcomesMeasureCommand extends Command
{
    protected $signature = 'outcomes:measure {--backfill=0} {--workspace=}';
    protected $description = 'Measure delivered work at 24h / 7d / 30d and write lessons (RFC-0023 Outcome Ledger)';

    public function handle(OutcomeLedgerService $svc): int
    {
        $n = (int) $this->option('backfill');
        if ($n > 0) {
            $q = \Illuminate\Support\Facades\DB::table('tasks')->whereIn('status', ['completed', 'failed'])->whereNotNull('completed_at')->orderByDesc('id')->limit($n);
            if ($this->option('workspace')) $q->where('workspace_id', (int) $this->option('workspace'));
            $done = 0;
            foreach ($q->get() as $t) {
                if (\Illuminate\Support\Facades\DB::table('outcome_ledger')->where('ref', 'task:' . $t->id)->exists()) continue;
                $res = is_array($t->result_json ?? null) ? $t->result_json : (json_decode((string) ($t->result_json ?? ''), true) ?: null);
                if ($svc->recordTask($t, $t->status === 'failed' ? 'failed' : 'delivered', $res, $t->error_text ?? null)) $done++;
            }
            $this->info("backfilled {$done} task rows");
        }
        $out = $svc->measureDue();
        $this->info('measured: ' . json_encode($out));
        return self::SUCCESS;
    }
}
