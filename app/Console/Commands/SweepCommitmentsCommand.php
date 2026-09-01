<?php

namespace App\Console\Commands;

use App\Core\Sarah888\CommitmentLifecycle;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Close the commitments that can be closed, so Sarah stops reading a permanent backlog as a live one.
 *
 * Dry run by default: it prints what it would close and why, and writes nothing.
 */
class SweepCommitmentsCommand extends Command
{
    protected $signature = 'sarah:sweep-commitments
                            {--apply : actually close them (default is a dry run)}
                            {--workspace= : limit to one workspace}';

    protected $description = 'Complete satisfied commitments and expire stale ones (dry run by default)';

    public function handle(CommitmentLifecycle $lifecycle): int
    {
        $apply = (bool) $this->option('apply');
        $only = $this->option('workspace') !== null ? (int) $this->option('workspace') : null;

        $workspaces = DB::table('sarah_commitments')
            ->whereIn('status', \App\Core\Sarah888\CommitmentStore::LIVE)
            ->when($only !== null, fn ($q) => $q->where('workspace_id', $only))
            ->distinct()->pluck('workspace_id');

        $this->line('');
        $this->info(sprintf('Commitment sweep — %s', $apply ? 'APPLYING' : 'DRY RUN (nothing will be written)'));
        $this->line(sprintf('  workspaces with live commitments: %d', $workspaces->count()));
        $this->line('');

        $totalSat = 0;
        $totalExp = 0;

        foreach ($workspaces as $wsId) {
            $r = $lifecycle->sweep((int) $wsId, $apply);
            if (! $r['satisfied'] && ! $r['expired']) {
                continue;
            }

            $totalSat += $r['satisfied'];
            $totalExp += $r['expired'];

            $this->line(sprintf('  ws %-8d completed %d · expired %d', $wsId, $r['satisfied'], $r['expired']));
            foreach (array_slice($r['detail'], 0, 6) as $d) {
                $this->line(sprintf('      %-9s #%-6d %-36s %s',
                    $d['outcome'], $d['id'], mb_strimwidth($d['title'], 0, 36, '…'), $d['why']));
            }
            if (count($r['detail']) > 6) {
                $this->line(sprintf('      … and %d more', count($r['detail']) - 6));
            }
        }

        $this->line('');
        $this->info(sprintf('%s %d as completed and %d as expired.',
            $apply ? 'Closed' : 'Would close', $totalSat, $totalExp));

        if (! $apply) {
            $this->comment('Dry run. Re-run with --apply. Nothing is ever reported as completed without evidence on the row.');
        }

        return self::SUCCESS;
    }
}
