<?php

namespace App\Console\Commands;

use App\Core\Agents\AgentCompletionReportService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * AGENT VOICE (2026-07-19) — let the delegated agents report their own
 * completed work.
 *
 * Before this, 17 of 20 agents had never posted a single message in any
 * workspace, while `tasks.assigned_agents_json` showed priya alone owning 900
 * tasks in ws2. See AgentCompletionReportService for the full forensic.
 *
 * Completions only · every agent except Sarah · always batched, framed as work
 * Sarah delegated.
 */
class AgentReportCompletionsCommand extends Command
{
    protected $signature = 'agents:report-completions
        {--workspace= : limit to a single workspace id}
        {--dry-run : show what WOULD be posted without posting or marking}';

    protected $description = 'Post batched completion updates from each delegated agent (all agents except Sarah)';

    public function handle(AgentCompletionReportService $svc): int
    {
        $dry     = (bool) $this->option('dry-run');
        $wsParam = $this->option('workspace');

        $workspaceIds = $wsParam
            ? [(int) $wsParam]
            : DB::table('tasks')
                ->where('status', 'completed')
                ->whereNull('agent_reported_at')
                ->distinct()
                ->pluck('workspace_id')
                ->all();

        if (empty($workspaceIds)) {
            $this->line('agents:report-completions — nothing to report.');
            return self::SUCCESS;
        }

        $this->line('agents:report-completions — ' . count($workspaceIds) . ' workspace(s)' . ($dry ? ' [DRY-RUN]' : ''));

        $totalAgents = 0;
        $totalTasks  = 0;

        foreach ($workspaceIds as $wsId) {
            try {
                $posted = $svc->reportForWorkspace((int) $wsId, $dry);
            } catch (\Throwable $e) {
                Log::error('[AgentVoice] workspace failed', ['workspace_id' => $wsId, 'error' => $e->getMessage()]);
                $this->error("  ws {$wsId}: {$e->getMessage()}");
                continue;
            }

            if (empty($posted)) {
                continue;
            }

            foreach ($posted as $slug => $n) {
                $this->line("  ws {$wsId}: {$slug} reported {$n} completion(s)" . ($dry ? ' [DRY]' : ''));
                $totalAgents++;
                $totalTasks += $n;
            }
        }

        $this->info("Done — {$totalAgents} agent message(s), {$totalTasks} completion(s)" . ($dry ? ' [DRY-RUN, nothing written]' : ''));
        return self::SUCCESS;
    }
}
