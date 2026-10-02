<?php

namespace App\Console\Commands;

use App\Core\OwnerModel\RuntimeMemorySync;
use Illuminate\Console\Command;

/**
 * RFC-0023 P6: hourly safety net behind storage/app/memory1.on - pushes every workspace whose owner memory changed in the
 * last N hours to the runtime. `--all` pushes every workspace with memory; `--workspace=` one; `--show` prints the payload.
 */
class MemorySyncRuntimeCommand extends Command
{
    protected $signature = 'memory:sync-runtime {--all} {--workspace=} {--hours=2} {--show}';
    protected $description = 'Push the owner memory (facts, preferences, debts, lessons) into the runtime workspace memory (RFC-0023 P6)';

    public function handle(RuntimeMemorySync $sync): int
    {
        $ws = $this->option('workspace') ? (int) $this->option('workspace') : null;
        if ($this->option('show')) {
            if (! $ws) { $this->error('--show needs --workspace'); return self::FAILURE; }
            $this->line(json_encode($sync->payload($ws), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            return self::SUCCESS;
        }
        if (! RuntimeMemorySync::enabled()) { $this->warn('disabled (memory1.on missing or RUNTIME_URL empty)'); return self::SUCCESS; }
        $out = $sync->pushChanged($this->option('all') ? null : now()->subHours((int) $this->option('hours')), $ws);
        $this->info('synced: ' . json_encode($out));
        return self::SUCCESS;
    }
}
