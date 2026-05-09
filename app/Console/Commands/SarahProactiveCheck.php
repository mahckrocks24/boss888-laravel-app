<?php

namespace App\Console\Commands;

use App\Core\Orchestration\ProactiveStrategyEngine;
use App\Core\Orchestration\SarahReadBackService;
use App\Models\Workspace;
use Illuminate\Console\Command;

class SarahProactiveCheck extends Command
{
    protected $signature = 'sarah:proactive {--type=daily : Check type: daily, weekly, monthly}';
    protected $description = 'Run Sarah\'s proactive strategy checks across all onboarded workspaces';

    public function handle(ProactiveStrategyEngine $proactive): int
    {
        $type = $this->option('type');

        // Only run for workspaces with proactive enabled
        // For weekly checks, also filter by proactive_frequency
        $query = Workspace::where('onboarded', true)
            ->where('proactive_enabled', true);

        if ($type === 'weekly') {
            $query->whereIn('proactive_frequency', ['daily', 'weekly']);
        }

        $workspaces = $query->get();

        $this->info("Running {$type} proactive check for {$workspaces->count()} workspace(s)...");

        $success = 0;
        $failed  = 0;

        // PATCH (Phase 2 — read-back loop, 2026-05-10) — Sarah reads
        // unread completed-task results before each proactive run. The
        // interpretations are written into ProactiveStrategyEngine via the
        // standard recordEvent path through SarahReadBackService::checkCompletedTasks
        // which also stamps sarah_read_at on each task. We log them here so
        // operators can see the read-back firing in the supervisor log; the
        // chat handler is what surfaces them to end users.
        $readBack = app(SarahReadBackService::class);

        foreach ($workspaces as $ws) {
            try {
                $insights = $readBack->checkCompletedTasks($ws->id, 5);
                if (!empty($insights)) {
                    $this->line("  ↳ Sarah read {$ws->business_name}: " . count($insights) . ' completed task(s)');
                    foreach ($insights as $i) {
                        $this->line("      task #{$i['task_id']} {$i['engine']}/{$i['action']}: {$i['interpretation']}");
                    }
                }

                match ($type) {
                    'daily'   => $proactive->dailyCheck($ws->id),
                    'weekly'  => $proactive->weeklyReview($ws->id),
                    'monthly' => $proactive->monthlyStrategy($ws->id, $ws->owner_id ?? 1),
                    default   => $proactive->dailyCheck($ws->id),
                };
                $this->line("  ✓ {$ws->business_name} ({$ws->id})");
                $success++;
            } catch (\Throwable $e) {
                $this->error("  ✗ {$ws->business_name}: {$e->getMessage()}");
                $failed++;
            }
        }

        $this->info("Done. Success: {$success} | Failed: {$failed}");
        return $failed > 0 ? 1 : 0;
    }
}
