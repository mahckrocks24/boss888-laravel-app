<?php

namespace App\Core\Engineer888\Signals;

use Illuminate\Support\Facades\Redis;

/**
 * Is the box healthy — workers, queue depth, disk.
 *
 * Queue depth is read from REDIS, not the jobs table. QUEUE_CONNECTION=redis,
 * so `jobs` sits permanently at 0 rows and reading it would produce a
 * confidently wrong "queue is empty" every single day. Measuring the wrong
 * store is worse than not measuring.
 */
final class PlatformSignal implements Signal
{
    public function __construct(
        private string $schedulerLogPath = '/var/log/laravel-scheduler.log',
    ) {}

    public function key(): string { return 'platform'; }
    public function label(): string { return 'Platform'; }

    public function collect(): array
    {
        $out = ['available' => true];

        // ── scheduler heartbeat ─────────────────────────────────────────
        // The scheduler is in www-data's crontab, not root's, which is why it
        // reads as absent from the obvious place. If it stops, every scheduled
        // command stops with it — including this brief — and nothing says so.
        // That is the INC-2026-001 shape: silence indistinguishable from health.
        //
        // WHAT THIS ACTUALLY MEASURES: the mtime of the scheduler's output log.
        // It only advances because at least one per-minute command produces
        // output. If every per-minute command were unscheduled, this would
        // report stale while cron was fine. The check is worth having anyway —
        // that arrangement is itself worth being told about — but the reading is
        // a heartbeat, not proof of cron.
        if (is_file($this->schedulerLogPath)) {
            $mtime = @filemtime($this->schedulerLogPath);
            $out['scheduler_heartbeat_age_seconds'] = $mtime ? max(0, time() - $mtime) : null;
        } else {
            $out['scheduler_heartbeat_age_seconds'] = null;
            $out['scheduler_heartbeat_reason'] = 'scheduler log not found at ' . $this->schedulerLogPath;
        }

        // ── workers ─────────────────────────────────────────────────────
        $sup = Shell::run('bash', ['-c', 'sudo -n supervisorctl status 2>/dev/null']);
        if ($sup['out'] !== '') {
            $lines = array_values(array_filter(explode("\n", trim($sup['out']))));
            $running = 0;
            $down = [];
            foreach ($lines as $line) {
                if (preg_match('/^(\S+)\s+(\S+)/', $line, $m)) {
                    if ($m[2] === 'RUNNING') { $running++; } else { $down[] = $m[1] . '=' . $m[2]; }
                }
            }
            $out['supervisor_programs'] = count($lines);
            $out['supervisor_running']  = $running;
            $out['supervisor_down']     = $down;
        } else {
            $out['supervisor_available'] = false;
        }

        $procs = Shell::run('bash', ['-c', "ps ax | grep '[q]ueue:work' | wc -l"]);
        $out['queue_worker_processes'] = (int) trim($procs['out']);

        // ── queue depth (redis) ─────────────────────────────────────────
        try {
            $keys = Redis::connection()->keys('*queues:*');
            $depth = [];
            foreach ((array) $keys as $key) {
                // Skip the reserved/delayed zsets — only pending lists count.
                if (str_contains((string) $key, ':reserved') || str_contains((string) $key, ':delayed')) {
                    continue;
                }
                $len = Redis::connection()->llen($key);
                if ($len > 0) { $depth[(string) $key] = (int) $len; }
            }
            $out['queue_depth'] = $depth;
            $out['queue_pending_total'] = array_sum($depth);
        } catch (\Throwable $e) {
            $out['queue_depth_available'] = false;
            $out['queue_depth_reason'] = substr($e->getMessage(), 0, 120);
        }

        // ── disk ────────────────────────────────────────────────────────
        $df = Shell::run('bash', ['-c', "df -P / | tail -1"]);
        if (preg_match('/(\d+)\s+(\d+)\s+(\d+)\s+(\d+)%/', $df['out'], $m)) {
            $out['disk_used_percent'] = (int) $m[4];
            $out['disk_free_gb'] = round(((int) $m[3]) / 1048576, 1);
        }

        return $out;
    }
}
