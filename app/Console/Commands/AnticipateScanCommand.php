<?php

namespace App\Console\Commands;

use App\Core\Anticipation\AnticipationEngine;
use Illuminate\Console\Command;

/**
 * RFC-0023 P5: hourly scan - one proposal card per workspace at most every 48 h, in local daytime, behind storage/app/anticipate1.on.
 * `--workspace=` limits to one; `--force` ignores the pacing rules (QA only); `--dry` prints the ranked candidates without posting.
 */
class AnticipateScanCommand extends Command
{
    protected $signature = 'anticipate:scan {--workspace=} {--force} {--dry}';
    protected $description = 'Sarah proposes before being asked: calendar, opportunity, risk, readiness, question (RFC-0023 P5)';

    public function handle(AnticipationEngine $engine): int
    {
        $ws = $this->option('workspace') ? (int) $this->option('workspace') : null;
        if ($this->option('dry')) {
            if (! $ws) { $this->error('--dry needs --workspace'); return self::FAILURE; }
            $bizIds = \Illuminate\Support\Facades\DB::table('businesses')->where('workspace_id', $ws)->whereNull('deleted_at')->orderByDesc('is_default')->orderBy('id')->limit(6)->pluck('id')->all() ?: [null];
            foreach ($bizIds as $b) foreach ($engine->candidates($ws, $b ? (int) $b : null) as $c) $this->line(sprintf('biz %-5s %-12s %.2f %3dcr  %s  [%s]', $b ?? '-', $c['kind'], $c['confidence'], $c['cost'], $c['title'], $c['key']));
            $pick = $engine->pick($ws, null);
            $this->info('pick: ' . ($pick ? $pick['key'] : 'none'));
            return self::SUCCESS;
        }
        $out = $engine->scan($ws, (bool) $this->option('force'));
        $this->info('proposed: ' . json_encode($out));
        return self::SUCCESS;
    }
}
