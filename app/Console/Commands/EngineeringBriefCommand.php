<?php

namespace App\Console\Commands;

use App\Core\Engineer888\EngineeringBrief;
use Illuminate\Console\Command;

/**
 * Engineer888 — the daily engineering brief.
 *
 * PURPOSE
 * Replaces work that was, until now, performed by hand: checking git state,
 * runtime health, worker status, queue depth, task failures and the error log,
 * one probe at a time, and only when someone remembered to look. During the
 * 2026-07-29 audits those same probes were run manually more than a dozen
 * times. This command runs them in one second and reports what CHANGED.
 *
 * Exit codes are chosen so this is usable as a monitor, not only as a report:
 *   0  nothing needing attention
 *   1  warnings present
 *   2  critical findings present
 *
 * Usage:
 *   php artisan engineering:brief              human-readable
 *   php artisan engineering:brief --json       machine-readable
 *   php artisan engineering:brief --no-store   do not persist a snapshot
 *   php artisan engineering:brief --quiet-ok   print nothing when clean (cron)
 */
class EngineeringBriefCommand extends Command
{
    protected $signature = 'engineering:brief
        {--json : output machine-readable JSON}
        {--no-store : do not persist a snapshot}
        {--quiet-ok : print nothing when there is nothing to report}';

    protected $description = 'Engineer888 daily engineering brief — platform state, change since last run, and deterministic recommendations';

    public function handle(): int
    {
        $brief = (new EngineeringBrief(base_path(), storage_path('logs/laravel.log')))
            ->generate(persist: ! $this->option('no-store'));

        if ($this->option('json')) {
            $this->line(json_encode($brief, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $this->exitCode($brief['worst']);
        }

        if ($this->option('quiet-ok') && $brief['worst'] === 'ok') {
            return 0;
        }

        $this->render($brief);

        return $this->exitCode($brief['worst']);
    }

    private function exitCode(string $worst): int
    {
        return match ($worst) {
            'critical' => 2,
            'warn'     => 1,
            default    => 0,
        };
    }

    private function render(array $brief): void
    {
        $s = $brief['signals'];
        $prev = $brief['previous'] ?? [];

        $this->newLine();
        $this->line('  <options=bold>ENGINEER888 — DAILY ENGINEERING BRIEF</>');
        $this->line('  ' . now()->toDayDateTimeString()
            . '   ·   ' . ($brief['previous_at']
                ? 'compared with ' . $brief['previous_at']
                : 'first brief — no comparison available'));
        $this->line('  ' . str_repeat('─', 74));

        // ── repository ──────────────────────────────────────────────────
        $this->section('Repository');
        if ($s['git']['available'] ?? false) {
            $g = $s['git'];
            $this->kv('branch', $g['branch'] . ' @ ' . $g['commit']);
            $this->kv('upstream', $g['has_upstream'] ? $g['upstream'] : '<fg=red>NONE — merged work cannot reach production</>');
            // 2026-07-30: the count moved from git-status entries to expanded
            // files, which is a change of METRIC, not a change in the repository.
            // Rendering it as +233 would report a two-hundred-file jump that did
            // not happen. Snapshots taken before the change have no
            // uncommitted_entries key, and that absence is how we know.
            $methodChanged = isset($prev['git']) && ! array_key_exists('uncommitted_entries', $prev['git']);
            if ($methodChanged) {
                $this->kv('uncommitted', $g['uncommitted_total'] . '  <fg=gray>(counting method changed — '
                    . 'now every untracked file, was every git-status entry; not comparable to the previous brief)</>');
                $this->kv('', 'git status alone would report ' . ($g['uncommitted_entries'] ?? '?') . ' entries');
            } else {
                $this->kv('uncommitted', $this->delta($g['uncommitted_total'], $prev['git']['uncommitted_total'] ?? null)
                    . '  (app/: ' . $this->delta($g['uncommitted_app'], $prev['git']['uncommitted_app'] ?? null) . ')');
            }
            if (! empty($g['uncommitted_by_area'])) {
                $areas = [];
                foreach ($g['uncommitted_by_area'] as $area => $n) { $areas[] = "{$area}:{$n}"; }
                $this->kv('dirty areas', implode('  ', $areas));
            }
            $this->kv('backup files', $this->delta($g['backup_files'], $prev['git']['backup_files'] ?? null));
            $this->kv('last commit', (string) ($g['last_commit_at'] ?? 'unknown'));
        } else {
            $this->kv('unavailable', (string) ($s['git']['reason'] ?? 'unknown'));
        }

        // ── runtime ─────────────────────────────────────────────────────
        $this->section('AI Runtime');
        if ($s['runtime']['available'] ?? false) {
            $r = $s['runtime'];
            $this->kv('status', $r['status'] . '  (' . $r['latency_ms'] . 'ms)');
            $this->kv('version', $r['version'] . '  <fg=gray>[hardcoded literal — not deploy evidence]</>');
            $this->kv('surface', ($r['agents'] ?? '?') . ' agents · ' . ($r['tools'] ?? '?') . ' tools · ' . ($r['ai_run_tasks'] ?? '?') . ' tasks');
        } else {
            $this->line('    <fg=red>UNREACHABLE</> — ' . ($s['runtime']['reason'] ?? 'unknown'));
        }

        // ── platform ────────────────────────────────────────────────────
        $this->section('Platform');
        $p = $s['platform'];
        if (isset($p['supervisor_running'])) {
            $down = empty($p['supervisor_down']) ? '' : '  <fg=red>DOWN: ' . implode(', ', $p['supervisor_down']) . '</>';
            $this->kv('supervisor', $p['supervisor_running'] . '/' . $p['supervisor_programs'] . ' running' . $down);
        }
        $this->kv('queue workers', (string) ($p['queue_worker_processes'] ?? '?'));
        if (isset($p['queue_pending_total'])) {
            $this->kv('queue pending', $this->delta($p['queue_pending_total'], $prev['platform']['queue_pending_total'] ?? null));
        } else {
            $this->kv('queue pending', '<fg=yellow>unavailable</> — ' . ($p['queue_depth_reason'] ?? 'redis unreadable'));
        }
        if (isset($p['disk_used_percent'])) {
            $this->kv('disk', $p['disk_used_percent'] . '% used · ' . $p['disk_free_gb'] . 'GB free');
        }
        $hb = $p['scheduler_heartbeat_age_seconds'] ?? null;
        $this->kv('scheduler', $hb === null
            ? '<fg=yellow>unverifiable</> — ' . ($p['scheduler_heartbeat_reason'] ?? 'no heartbeat source')
            : 'last output ' . $hb . 's ago');

        // ── workload ────────────────────────────────────────────────────
        $this->section('Workload (24h)');
        if ($s['workload']['available'] ?? false) {
            $w = $s['workload'];
            $byStatus = [];
            foreach (($w['tasks_by_status'] ?? []) as $status => $n) { $byStatus[] = "{$status}:{$n}"; }
            $this->kv('tasks', implode('  ', $byStatus) ?: 'none created');
            if (isset($w['tasks_stale_running'])) {
                $this->kv('stale running', (string) $w['tasks_stale_running']);
            }
            $this->kv('totals', 'tasks ' . $this->delta($w['tasks_total'] ?? 0, $prev['workload']['tasks_total'] ?? null)
                . ' · events ' . $this->delta($w['task_events_total'] ?? 0, $prev['workload']['task_events_total'] ?? null)
                . ' · ai calls ' . $this->delta($w['api_usage_logs_total'] ?? 0, $prev['workload']['api_usage_logs_total'] ?? null));
        }

        // ── errors ──────────────────────────────────────────────────────
        $baseline = (bool) ($s['errors']['baseline'] ?? false);
        $this->section($baseline ? 'Errors (baseline — historical, not new)' : 'Errors since last brief');
        if ($s['errors']['available'] ?? false) {
            $e = $s['errors'];
            $this->kv($baseline ? 'historical' : 'new entries',
                $e['total'] . '  ' . json_encode($e['counts'])
                . '  <fg=gray>[channel ' . ($e['channel'] ?? '?') . ']</>');
            $this->kv('scanned', number_format($e['bytes_scanned']) . ' bytes'
                . (! empty($e['partial_scan']) ? '  <fg=yellow>(truncated)</>' : '')
                . (! empty($e['rotated']) ? '  <fg=yellow>(log rotated)</>' : ''));
            foreach (array_slice($e['samples'] ?? [], 0, 3) as $sample) {
                $this->line('      <fg=gray>' . $sample . '</>');
            }
            if (($e['foreign_total'] ?? 0) > 0) {
                $this->kv('excluded', $e['foreign_total'] . ' from other channels '
                    . json_encode($e['foreign_counts']) . '  <fg=gray>(not production errors)</>');
            }
        } else {
            $this->kv('unavailable', (string) ($s['errors']['reason'] ?? 'unknown'));
        }

        // ── recommendations ─────────────────────────────────────────────
        $this->newLine();
        $this->line('  ' . str_repeat('─', 74));
        $recs = $brief['recommendations'];

        if (empty($recs)) {
            $this->line('  <fg=green;options=bold>NOTHING REQUIRING ATTENTION</>');
        } else {
            $this->line('  <options=bold>RECOMMENDATIONS</> <fg=gray>(deterministic rules, not AI)</>');
            $this->newLine();
            foreach ($recs as $r) {
                $tag = match ($r['severity']) {
                    'critical' => '<fg=red;options=bold>CRITICAL</>',
                    'warn'     => '<fg=yellow;options=bold>WARN    </>',
                    default    => '<fg=blue>INFO    </>',
                };
                $this->line('  ' . $tag . '  <options=bold>' . $r['title'] . '</>  <fg=gray>[' . $r['id'] . ']</>');
                $this->line('            ' . $r['detail']);
                $this->line('            <fg=gray>evidence: ' . $r['evidence'] . '</>');
                $this->line('            <fg=gray>prevents: ' . $r['prevents'] . '</>');
                $this->newLine();
            }
        }

        $this->line('  ' . str_repeat('─', 74));
        $this->line('  <fg=gray>collected in ' . $brief['duration_ms'] . 'ms'
            . ($this->option('no-store') ? ' · snapshot NOT stored' : ' · snapshot stored') . '</>');
        $this->newLine();
    }

    private function section(string $title): void
    {
        $this->newLine();
        $this->line('  <options=bold>' . $title . '</>');
    }

    private function kv(string $k, string $v): void
    {
        $this->line('    ' . str_pad($k, 16) . $v);
    }

    /** Render a value with its change since the previous brief. */
    private function delta(int|float|null $now, int|float|null $then): string
    {
        $now ??= 0;
        if ($then === null) {
            return (string) $now;
        }
        $d = $now - $then;
        if ($d === 0) {
            return $now . ' <fg=gray>(no change)</>';
        }

        return $now . ($d > 0 ? ' <fg=yellow>(+' . $d . ')</>' : ' <fg=green>(' . $d . ')</>');
    }
}
