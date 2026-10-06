<?php

namespace App\Console\Commands;

use App\Engines\CRM\Services\LeadsAssistant;
use Illuminate\Console\Command;

/**
 * LEADS-W1 (DEC-0089 wave 1): Sarah chases the owner about enquiries nobody answered (1 h in-app, 2 h push, 24 h email)
 * and, once a week, brings back the old ones for a decision. Switch: storage/app/leads2.on (workspace ids or "*").
 *   --dry-run          print what would be sent, change nothing   --everyone   with --dry-run: every onboarded workspace
 *   --workspace=ID     one workspace                               --any-hour   ignore 08:00-21:00 local
 *   --old              the weekly old-enquiry card (Mondays 09:00 local); --force sends it now
 *   --rate=LEAD_ID     read one enquiry now
 */
class LeadsTick extends Command
{
    protected $signature = 'leads:tick {--dry-run} {--everyone} {--workspace=} {--any-hour} {--old} {--force} {--rate=}';
    protected $description = 'Sarah chases unanswered enquiries and rates new ones (LEADS-W1)';

    public function handle(LeadsAssistant $la): int
    {
        $ws = $this->option('workspace') ? (int) $this->option('workspace') : null;
        $dry = (bool) $this->option('dry-run');
        if ($this->option('rate')) {
            $id = (int) $this->option('rate');
            $wsId = (int) \Illuminate\Support\Facades\DB::table('leads')->where('id', $id)->value('workspace_id');
            $r = $la->rate($wsId, $id, true);
            $this->line(json_encode($r, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            return self::SUCCESS;
        }
        if ($this->option('old')) {
            $n = $la->old($ws, $dry, (bool) $this->option('force'));
            foreach ($la->report as $l) $this->line($l);
            $this->info(($dry ? '[dry run] ' : '') . "old-enquiry cards: {$n}");
            return self::SUCCESS;
        }
        $r = $la->tick($ws, $dry, (bool) $this->option('any-hour'), (bool) $this->option('everyone'));
        foreach ($la->report as $l) $this->line($l);
        $this->info(($dry ? '[dry run] ' : '') . json_encode($r));
        return self::SUCCESS;
    }
}
