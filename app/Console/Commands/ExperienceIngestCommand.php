<?php

namespace App\Console\Commands;

use App\Core\Experience888\ExperienceIngestor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * EXPERIENCE888 — scheduled ingestion.
 *
 * Turns authoritative records (tasks, approvals, commitments) into typed
 * experience. All the logic lives in ExperienceIngestor; this is only the
 * operator/scheduler entry point, so there is exactly one ingestion path.
 *
 * Idempotency is the ingestor's, via dedupe_key: a second run over the same
 * rows creates nothing. That is what makes hourly scheduling safe, and what
 * stops one completed task becoming five observations and inventing a pattern.
 *
 * One workspace failing must never stop or corrupt another, so each workspace
 * is isolated in its own try/catch and reported separately.
 */
class ExperienceIngestCommand extends Command
{
    protected $signature = 'experience888:ingest
                            {--workspace= : a single workspace id}
                            {--all : every workspace with recent activity}
                            {--since= : only workspaces with activity since this timestamp}
                            {--limit=500 : max tasks read per workspace}
                            {--dry-run : report what would be ingested, write nothing}';

    protected $description = 'EXPERIENCE888 — ingest authoritative events/outcomes and update patterns (idempotent)';

    public function handle(ExperienceIngestor $ingestor): int
    {
        $dry = (bool) $this->option('dry-run');
        $limit = (int) ($this->option('limit') ?: 500);

        $workspaces = $this->resolveWorkspaces();
        if (!$workspaces) {
            $this->warn('No workspaces selected. Use --workspace=<id> or --all.');
            return self::SUCCESS;
        }

        $this->info(sprintf('Experience888 ingest: %d workspace(s)%s', count($workspaces), $dry ? ' [DRY RUN]' : ''));

        $totals = ['events' => 0, 'outcomes' => 0, 'observations' => 0, 'failed' => 0];

        foreach ($workspaces as $wsId) {
            try {
                if ($dry) {
                    $pending = $this->pendingCount($wsId, $limit);
                    $this->line(sprintf('  ws %-8d would read %d terminal task(s); %d already ingested',
                        $wsId, $pending['terminal'], $pending['already']));
                    continue;
                }

                $before = DB::table('experience_events')->where('workspace_id', $wsId)->count();
                $r = $ingestor->run($wsId);
                $after = DB::table('experience_events')->where('workspace_id', $wsId)->count();

                $new = $after - $before;
                $totals['events'] += $new;
                $totals['outcomes'] += (int) ($r['tasks']['outcomes'] ?? 0);
                $totals['observations'] += (int) ($r['tasks']['pattern_observations'] ?? 0);

                $this->line(sprintf('  ws %-8d +%-4d new event(s)  tasks_read=%-4d  commitments: %s',
                    $wsId, $new, (int) ($r['tasks']['tasks_read'] ?? 0),
                    $this->commitmentSummary($r['commitments'] ?? [])));

                Log::info('[Experience888] ingest complete', ['ws' => $wsId, 'new_events' => $new, 'result' => $r]);
            } catch (\Throwable $e) {
                // Isolated deliberately: a broken workspace must not abort the sweep.
                $totals['failed']++;
                $this->error(sprintf('  ws %-8d FAILED: %s', $wsId, $e->getMessage()));
                Log::error('[Experience888] ingest failed', ['ws' => $wsId, 'error' => $e->getMessage()]);
            }
        }

        $this->info(sprintf('done — %d new event(s), %d outcome(s), %d observation(s), %d workspace failure(s)',
            $totals['events'], $totals['outcomes'], $totals['observations'], $totals['failed']));

        // A workspace failure is reported but does not fail the schedule: the
        // next hourly run retries it, and a non-zero exit would mask the others.
        return self::SUCCESS;
    }

    /** @return int[] */
    private function resolveWorkspaces(): array
    {
        // A single explicit id is a deliberate operator action (forensics,
        // admin, backfill) and bypasses the enrolment policy - but says so.
        if ($this->option('workspace')) {
            $id = (int) $this->option('workspace');
            if (!app(\App\Core\Experience888\ExperienceEligibility::class)->isIngestionEnabled($id)) {
                $this->warn("  ws {$id} is NOT enrolled in Experience888 - proceeding because "
                          . "--workspace was given explicitly.");
                Log::info('[Experience888] manual ingest of a non-enrolled workspace', ['ws' => $id]);
            }
            return [$id];
        }
        if (!$this->option('all')) return [];

        $since = $this->option('since') ?: now()->subDays(7)->toDateTimeString();

        // ENROLMENT GATE. --all means every ENROLLED workspace, never every
        // workspace: real customer tenants must not be learned from until
        // someone deliberately enrols them. See ExperienceEligibility for why
        // is_house_account could not be reused (ws 2 carries it).
        $eligible = app(\App\Core\Experience888\ExperienceEligibility::class)->ingestionEnabledWorkspaceIds();
        if (!$eligible) return [];

        // Only enrolled workspaces with terminal activity in the window:
        // sweeping the rest would spend its time proving nothing changed.
        return DB::table('tasks')
            ->whereIn('workspace_id', $eligible)
            ->whereIn('status', ['completed', 'failed'])
            ->where('updated_at', '>=', $since)
            ->distinct()->pluck('workspace_id')
            ->map(fn($v) => (int) $v)->filter()->values()->all();
    }

    private function pendingCount(int $wsId, int $limit): array
    {
        $terminal = DB::table('tasks')->where('workspace_id', $wsId)
            ->whereIn('status', ['completed', 'failed'])->limit($limit)->count();
        $already = DB::table('experience_events')->where('workspace_id', $wsId)
            ->where('evidence_type', 'tasks')->count();
        return ['terminal' => $terminal, 'already' => $already];
    }

    private function commitmentSummary(array $c): string
    {
        $parts = [];
        foreach ($c as $k => $v) if ($v > 0 && $k !== 'left_open') $parts[] = "{$k}={$v}";
        return $parts ? implode(' ', $parts) : 'none resolved';
    }
}
