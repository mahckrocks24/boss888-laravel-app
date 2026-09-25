<?php

namespace App\Console\Commands;

use App\Core\Awareness\ConnectionFactsService;
use Illuminate\Console\Command;

/** SARAH-AWARE-1: keep the channel-status fact Sarah reads true for every workspace (every ten minutes; also on connect). */
class AwarenessRecomputeCommand extends Command
{
    protected $signature = 'awareness:recompute {--workspace= : one workspace id} {--quiet-changes : do not write notifications}';
    protected $description = 'Recompute the live channel status (social, email, Search Console) that Sarah reads as ground truth';

    public function handle(ConnectionFactsService $svc): int
    {
        $ws = (int) $this->option('workspace');
        if ($ws > 0) {
            $r = $svc->recompute($ws, ! $this->option('quiet-changes'));
            $this->line($r['sentence']);
            $this->line('changes: ' . json_encode($r['changes']));
            return self::SUCCESS;
        }
        $n = $svc->recomputeAll();
        $this->line("recomputed {$n} workspaces");
        return self::SUCCESS;
    }
}
