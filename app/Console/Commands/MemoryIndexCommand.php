<?php

namespace App\Console\Commands;

use App\Core\Recall\MemoryIndexer;
use Illuminate\Console\Command;

/**
 * RFC-0023 P4: keeps the recall index current. Hourly (last 2 h, every workspace) behind storage/app/recall1.on;
 * `--all` backfills every workspace from the beginning; `--workspace=` limits to one.
 */
class MemoryIndexCommand extends Command
{
    protected $signature = 'memory:index {--all} {--workspace=} {--hours=2}';
    protected $description = 'Index owner messages, Sarah replies, journal, outcomes and facts for recall (RFC-0023 P4)';

    public function handle(MemoryIndexer $idx): int
    {
        $since = $this->option('all') ? null : now()->subHours((int) $this->option('hours'));
        $only = $this->option('workspace') ? (int) $this->option('workspace') : null;
        $out = $idx->indexAll($since, $only);
        $this->info('indexed: ' . json_encode($out));
        return self::SUCCESS;
    }
}
