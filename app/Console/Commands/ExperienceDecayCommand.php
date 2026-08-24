<?php

namespace App\Console\Commands;

use App\Core\Experience888\PatternEngine;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * EXPERIENCE888 — scheduled decay / re-evaluation.
 *
 * Patterns are recomputed from their evidence, so ageing takes effect and a
 * pattern nobody has seen for months stops being asserted as if it were current.
 * Nothing is deleted: confidence and status change, the evidence stays, and the
 * change is logged so a demotion can always be explained afterwards.
 *
 * Deterministic and rerunnable — running it twice in a row changes nothing the
 * second time, because the verdict is a pure function of evidence and dates.
 */
class ExperienceDecayCommand extends Command
{
    protected $signature = 'experience888:decay
                            {--workspace= : a single workspace id}
                            {--all : every workspace holding patterns}
                            {--dry-run : report changes without writing}';

    protected $description = 'EXPERIENCE888 — re-evaluate pattern confidence, apply staleness, log demotions';

    public function handle(PatternEngine $patterns): int
    {
        $dry = (bool) $this->option('dry-run');
        $workspaces = $this->resolveWorkspaces();

        if (!$workspaces) {
            $this->warn('No workspaces selected. Use --workspace=<id> or --all.');
            return self::SUCCESS;
        }

        $this->info(sprintf('Experience888 decay: %d workspace(s)%s', count($workspaces), $dry ? ' [DRY RUN]' : ''));
        $changedTotal = 0; $failed = 0;

        foreach ($workspaces as $wsId) {
            try {
                if ($dry) {
                    $n = DB::table('experience_patterns')->where('workspace_id', $wsId)->count();
                    $this->line("  ws {$wsId} would re-evaluate {$n} pattern(s)");
                    continue;
                }

                $changed = $patterns->decaySweep($wsId);
                $changedTotal += count($changed);

                if ($changed) {
                    foreach ($changed as $pid => $transition) {
                        $p = DB::table('experience_patterns')->where('id', $pid)
                            ->first(['subject', 'sample_size', 'last_observed_at']);
                        $this->line(sprintf('  ws %-8d pattern %-5d %-34s %s',
                            $wsId, $pid, $p->subject ?? '?', $transition));
                        Log::info('[Experience888] pattern confidence changed', [
                            'ws' => $wsId, 'pattern_id' => $pid, 'transition' => $transition,
                            'subject' => $p->subject ?? null, 'sample_size' => $p->sample_size ?? null,
                            'last_observed_at' => $p->last_observed_at ?? null,
                        ]);
                    }
                } else {
                    $this->line("  ws {$wsId} no confidence changes");
                }
            } catch (\Throwable $e) {
                $failed++;
                $this->error("  ws {$wsId} FAILED: " . $e->getMessage());
                Log::error('[Experience888] decay failed', ['ws' => $wsId, 'error' => $e->getMessage()]);
            }
        }

        $this->info("done — {$changedTotal} pattern(s) changed, {$failed} workspace failure(s)");
        return self::SUCCESS;
    }

    /** @return int[] */
    private function resolveWorkspaces(): array
    {
        if ($this->option('workspace')) return [(int) $this->option('workspace')];
        if (!$this->option('all')) return [];

        // Same enrolment gate as ingestion: a workspace nobody enrolled should
        // not be swept, even to re-evaluate patterns it should not have.
        $eligible = app(\App\Core\Experience888\ExperienceEligibility::class)->ingestionEnabledWorkspaceIds();
        if (!$eligible) return [];

        return DB::table('experience_patterns')
            ->whereIn('workspace_id', $eligible)
            ->distinct()->pluck('workspace_id')
            ->map(fn($v) => (int) $v)->filter()->values()->all();
    }
}
