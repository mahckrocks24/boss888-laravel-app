<?php

namespace App\Core\Engineer888;

/**
 * Deterministic recommendation rules.
 *
 * NOT AI. Every recommendation below is a rule with a threshold, evaluated
 * against measured signals, citing the value that triggered it. This is
 * deliberate: the brief must be free to run, must work when the AI runtime is
 * down, and must be re-derivable by hand. A brief whose conclusions cannot be
 * checked is a brief nobody trusts.
 *
 * Each rule names the failure it prevents, per the constitution: a gate without
 * a named failure is overhead.
 *
 * Severity: critical | warn | info
 */
final class Recommendations
{
    /** @return array<int,array{id:string,severity:string,title:string,detail:string,evidence:string,prevents:string}> */
    public static function evaluate(array $signals, array $previous = []): array
    {
        $out = [];
        $git = $signals['git'] ?? [];
        $rt = $signals['runtime'] ?? [];
        $plat = $signals['platform'] ?? [];
        $work = $signals['workload'] ?? [];
        $err = $signals['errors'] ?? [];

        // Trend rules require something to compare against. Firing them on the
        // first brief produces evidence like "529 backup files (was 0)", which
        // is not merely unhelpful — it is false.
        $hasPrevious = $previous !== [];

        // ── delivery path ───────────────────────────────────────────────
        if (($git['available'] ?? false) && ! ($git['has_upstream'] ?? true)) {
            $out[] = [
                'id' => 'GIT-NO-UPSTREAM',
                'severity' => 'critical',
                'title' => 'Production branch has no upstream',
                'detail' => 'Reviewed code cannot reach production through git. Merged pull requests would '
                          . 'change nothing here. Set an upstream and establish a documented pull path.',
                'evidence' => 'branch ' . ($git['branch'] ?? '?') . ' has no tracking ref',
                'prevents' => 'shipping work that never arrives',
            ];
        }

        $appDirty = (int) ($git['uncommitted_app'] ?? 0);
        if ($appDirty >= 20) {
            $out[] = [
                'id' => 'GIT-DIRTY-APP',
                'severity' => $appDirty >= 50 ? 'critical' : 'warn',
                'title' => 'Working tree is unreviewable',
                'detail' => 'With this many uncommitted files under app/, a new change cannot be '
                          . 'attributed or reviewed in isolation, and rollback is guesswork.',
                'evidence' => $appDirty . ' uncommitted files in app/ (' . ($git['uncommitted_total'] ?? '?') . ' total)',
                'prevents' => 'unattributable changes and unusable rollback',
            ];
        }

        // Trend beats level: growth is the actionable part.
        $prevBak = (int) (($previous['git']['backup_files'] ?? 0));
        $bak = (int) ($git['backup_files'] ?? 0);
        if ($hasPrevious && $bak > 100 && $bak > $prevBak) {
            $out[] = [
                'id' => 'GIT-BAK-GROWING',
                'severity' => 'info',
                'title' => 'Backup-file count is growing',
                'detail' => 'These pollute every grep, diff and static analysis pass. Archive them outside the repo.',
                'evidence' => $bak . ' backup files (was ' . $prevBak . ')',
                'prevents' => 'audits and static rules producing false results',
            ];
        }

        // ── runtime ─────────────────────────────────────────────────────
        if (! ($rt['available'] ?? false)) {
            $out[] = [
                'id' => 'RUNTIME-DOWN',
                'severity' => 'critical',
                'title' => 'AI runtime unreachable',
                'detail' => 'All AI execution is unavailable. Customer-facing AI features will be failing now.',
                'evidence' => (string) ($rt['reason'] ?? 'unknown'),
                'prevents' => 'a silent platform-wide AI outage',
            ];
        } else {
            $prevVersion = $previous['runtime']['version'] ?? null;
            if ($prevVersion !== null && $prevVersion !== ($rt['version'] ?? null)) {
                $out[] = [
                    'id' => 'RUNTIME-VERSION-CHANGED',
                    'severity' => 'info',
                    'title' => 'Runtime version string changed',
                    'detail' => 'The reported version is a hardcoded literal, so a change means the runtime '
                              . 'was genuinely redeployed. Verify behaviour rather than trusting the string.',
                    'evidence' => $prevVersion . ' -> ' . ($rt['version'] ?? '?'),
                    'prevents' => 'an undetected runtime deployment',
                ];
            }
            if ((int) ($rt['latency_ms'] ?? 0) > 2000) {
                $out[] = [
                    'id' => 'RUNTIME-SLOW',
                    'severity' => 'warn',
                    'title' => 'Runtime health check is slow',
                    'detail' => 'Elevated health latency usually precedes timeouts on real execution.',
                    'evidence' => $rt['latency_ms'] . 'ms to /health',
                    'prevents' => 'user-visible AI timeouts',
                ];
            }
        }

        // ── workers and queue ───────────────────────────────────────────
        if (! empty($plat['supervisor_down'])) {
            $out[] = [
                'id' => 'WORKERS-DOWN',
                'severity' => 'critical',
                'title' => 'Supervisor program not running',
                'detail' => 'Queued work will not execute. This is the shape of INC-2026-001, where '
                          . 'failures accumulated for 42 hours while failed_jobs stayed empty.',
                'evidence' => implode(', ', (array) $plat['supervisor_down']),
                'prevents' => 'silent accumulation of unprocessed work',
            ];
        }

        $pending = (int) ($plat['queue_pending_total'] ?? 0);
        $hasPrevPending = isset($previous['platform']['queue_pending_total']);
        $prevPending = (int) ($previous['platform']['queue_pending_total'] ?? 0);
        if ($pending > 100 && (! $hasPrevPending || $pending >= $prevPending)) {
            $out[] = [
                'id' => 'QUEUE-BACKLOG',
                'severity' => 'warn',
                'title' => $hasPrevPending ? 'Queue backlog is not draining' : 'Queue backlog is high',
                'detail' => 'Pending work is high' . ($hasPrevPending ? ' and not falling' : '')
                          . '. Check worker count against throughput.',
                'evidence' => $pending . ' pending' . ($hasPrevPending ? ' (was ' . $prevPending . ')' : ' (no prior measurement)'),
                'prevents' => 'unbounded latency on queued work',
            ];
        }

        $hb = $plat['scheduler_heartbeat_age_seconds'] ?? null;
        if ($hb === null && ($plat['available'] ?? false)) {
            $out[] = [
                'id' => 'SCHEDULER-UNKNOWN',
                'severity' => 'warn',
                'title' => 'Scheduler heartbeat cannot be read',
                'detail' => 'Whether scheduled work is running is unverifiable. Every scheduled command, '
                          . 'including this brief, could be silently stopped.',
                'evidence' => (string) ($plat['scheduler_heartbeat_reason'] ?? 'no heartbeat source'),
                'prevents' => 'mistaking an unmeasured scheduler for a working one',
            ];
        } elseif ($hb !== null && $hb > 900) {
            $out[] = [
                'id' => 'SCHEDULER-STALLED',
                'severity' => 'critical',
                'title' => 'Scheduler has produced no output for over 15 minutes',
                'detail' => 'Scheduled work has almost certainly stopped. Nothing else on this platform '
                          . 'reports that, and this brief would stop with it.',
                'evidence' => 'scheduler log last written ' . $hb . 's ago',
                'prevents' => 'every scheduled command failing in silence',
            ];
        }

        if (isset($plat['disk_used_percent']) && $plat['disk_used_percent'] >= 85) {
            $out[] = [
                'id' => 'DISK-LOW',
                'severity' => $plat['disk_used_percent'] >= 92 ? 'critical' : 'warn',
                'title' => 'Disk space low',
                'detail' => 'The database, the queue and the 9.7MB unrotated log all live on this volume.',
                'evidence' => $plat['disk_used_percent'] . '% used, ' . ($plat['disk_free_gb'] ?? '?') . 'GB free',
                'prevents' => 'a write failure taking the platform down',
            ];
        }

        // ── workload ────────────────────────────────────────────────────
        $failed = (int) (($work['tasks_by_status']['failed'] ?? 0));
        if ($failed > 0) {
            $out[] = [
                'id' => 'TASKS-FAILED',
                'severity' => $failed >= 10 ? 'critical' : 'warn',
                'title' => 'Tasks failed in the last 24 hours',
                'detail' => 'Task failures do not surface in failed_jobs on this platform. This is the '
                          . 'only place they are visible.',
                'evidence' => $failed . ' failed tasks (24h window)',
                'prevents' => 'customer-visible failures going unnoticed',
            ];
        }

        $stale = (int) ($work['tasks_stale_running'] ?? 0);
        if ($stale > 0) {
            $out[] = [
                'id' => 'TASKS-STALE',
                'severity' => 'warn',
                'title' => 'Tasks running but untouched for over 2 hours',
                'detail' => 'Either a worker died mid-task or a task has no timeout. Both leave work in '
                          . 'an unknown state, which is the one state the constitution forbids.',
                'evidence' => $stale . ' stale running task(s)',
                'prevents' => 'work stuck in an indeterminate state',
            ];
        }

        // ── the brief's own liveness ─────────────────────────────────────
        // A monitor that stops running reports nothing, which reads exactly like
        // "nothing is wrong". The gap since the previous snapshot is the only
        // evidence that this brief is still being produced on schedule.
        $gapHours = $signals['_meta']['hours_since_previous'] ?? null;
        if ($gapHours !== null && $gapHours > 36) {
            $out[] = [
                'id' => 'BRIEF-GAP',
                'severity' => 'warn',
                'title' => 'No brief was produced for more than a day',
                'detail' => 'This brief is scheduled daily. A gap this large means it did not run, so the '
                          . 'period it covers went unmonitored. Error counts below span that whole gap.',
                'evidence' => round((float) $gapHours, 1) . ' hours since the previous brief',
                'prevents' => 'an absent monitor reading as a clean platform',
            ];
        }

        // ── errors ──────────────────────────────────────────────────────
        $errTotal = (int) ($err['total'] ?? 0);
        $errBaseline = (bool) ($err['baseline'] ?? false);

        if ($errBaseline && $errTotal > 0) {
            // First brief: the scan covers history, not news. Say so plainly
            // rather than inflating a novelty count with old entries.
            $out[] = [
                'id' => 'ERRORS-BASELINE',
                'severity' => 'info',
                'title' => 'Error baseline established',
                'detail' => 'This is the first brief, so there was no offset to count forward from. '
                          . 'The entries below are historical, NOT new. Novelty detection starts with '
                          . 'the next brief.',
                'evidence' => $errTotal . ' historical entries in the last '
                            . number_format((int) ($err['bytes_scanned'] ?? 0)) . ' bytes of log',
                'prevents' => 'a first run reporting old errors as new',
            ];
        }

        if (! $errBaseline && $errTotal > 0) {
            $sev = 'info';
            if (($err['counts']['CRITICAL'] ?? 0) + ($err['counts']['EMERGENCY'] ?? 0) + ($err['counts']['ALERT'] ?? 0) > 0) {
                $sev = 'critical';
            } elseif ($errTotal >= 25) {
                $sev = 'warn';
            }
            $out[] = [
                'id' => 'ERRORS-NEW',
                'severity' => $sev,
                'title' => 'New application errors since the last brief',
                'detail' => 'Counted forward from the previous snapshot offset, so these are genuinely new.',
                'evidence' => $errTotal . ' entries: ' . json_encode($err['counts'] ?? []),
                'prevents' => 'errors accumulating unread in a 9.7MB unrotated log',
            ];
        }

        // Test runs share the production log file. Not a production failure, but
        // not nothing either: it makes the production error count unreadable
        // without this separation, and a test run that writes to the production
        // log can also write to other production-shaped things.
        $foreign = (int) ($err['foreign_total'] ?? 0);
        if ($foreign > 0) {
            $channels = [];
            foreach ((array) ($err['foreign_counts'] ?? []) as $ch => $n) { $channels[] = "{$ch}:{$n}"; }
            $out[] = [
                'id' => 'LOG-FOREIGN-CHANNEL',
                'severity' => 'info',
                'title' => 'Non-production entries are being written to the production log',
                'detail' => 'These are excluded from the error count above so it stays honest. They are '
                          . 'almost certainly test runs, which should log to their own file.',
                'evidence' => $foreign . ' entries from other channels — ' . implode(', ', $channels),
                'prevents' => 'test noise being read as production failures',
            ];
        }

        // Only meaningful once there is a real offset — on a baseline run the
        // 4MB cap is expected, and ERRORS-BASELINE already states the bound.
        if (! $errBaseline && ! empty($err['partial_scan'])) {
            $out[] = [
                'id' => 'ERRORS-PARTIAL',
                'severity' => 'info',
                'title' => 'Error scan was truncated',
                'detail' => 'More than 4MB of log was written since the last brief; only the most recent '
                          . '4MB was scanned. The real error count is higher than reported.',
                'evidence' => number_format((int) ($err['bytes_scanned'] ?? 0)) . ' bytes scanned',
                'prevents' => 'an undercount being read as an accurate count',
            ];
        }

        // Stable ordering: critical first, then by id, so the brief reads the
        // same way every day and diffs cleanly.
        $rank = ['critical' => 0, 'warn' => 1, 'info' => 2];
        usort($out, fn ($a, $b) => [$rank[$a['severity']], $a['id']] <=> [$rank[$b['severity']], $b['id']]);

        return $out;
    }

    public static function worstSeverity(array $recommendations): string
    {
        foreach (['critical', 'warn', 'info'] as $level) {
            foreach ($recommendations as $r) {
                if (($r['severity'] ?? '') === $level) { return $level; }
            }
        }

        return 'ok';
    }
}
