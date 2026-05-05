<?php

namespace App\Console\Commands;

use App\Core\Orchestration\ProactiveStrategyEngine;
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

        foreach ($workspaces as $ws) {
            try {
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
