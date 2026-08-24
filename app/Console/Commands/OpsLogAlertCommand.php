<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * MISSION-018 WS-2 (2026-08-24). The operational tripwire this deployment
 * never had: 37 MB of laravel.log existed because nothing read it. Every
 * 10 minutes this scans the recent log window and the failed_jobs table and
 * emails ops when something crosses a threshold:
 *
 *   - application ERROR rate in the window
 *   - "Stripe webhook not handled" warnings — the wrong-webhook-secret
 *     failure mode discards every billing event silently; one of these is
 *     worth a human's eyes
 *   - new failed_jobs rows
 *
 * A 60-minute per-reason cooldown (cache) keeps a sustained incident from
 * becoming an inbox flood: first crossing alerts, repeats wait out the
 * cooldown. The command NEVER throws — an alerting failure must not fail
 * the scheduler run — but it logs its own send failures, so a broken alert
 * channel is at least visible in the log it watches.
 */
class OpsLogAlertCommand extends Command
{
    protected $signature = 'ops:log-alert
                            {--window=10 : minutes of log to scan}
                            {--error-threshold=10 : ERROR lines in window that trigger}
                            {--webhook-threshold=1 : unhandled-webhook warnings that trigger}
                            {--stub-threshold=25 : STUB_UNIMPLEMENTED hits in window that trigger}
                            {--to= : override recipient (default ops address)}';

    protected $description = 'Scan recent log + failed_jobs and email ops on threshold crossings (cooldown-limited)';

    private const RECIPIENT = 'admin@levelupgrowth.io';
    private const COOLDOWN_MINUTES = 60;

    public function handle(): int
    {
        try {
            $window = max(1, (int) $this->option('window'));
            $since = now()->subMinutes($window);
            $alerts = [];

            // ── log window scan ──────────────────────────────────────────
            $path = storage_path('logs/laravel.log');
            $errorCount = 0;
            $stubCount = 0;
            $webhookCount = 0;
            if (is_readable($path)) {
                // Rotation keeps this file small (daily x14, WS-0); a bounded
                // tail is belt-and-braces against a mid-day flood.
                $lines = $this->tailLines($path, 20000);
                foreach ($lines as $line) {
                    $ts = $this->lineTimestamp($line);
                    if ($ts !== null && $ts < $since->getTimestamp()) {
                        continue;
                    }
                    if (str_contains($line, '.ERROR:')) {
                        $errorCount++;
                    }
                    if (str_contains($line, 'Stripe webhook not handled')) {
                        $webhookCount++;
                    }
                    if (str_contains($line, 'STUB_UNIMPLEMENTED')) {
                        $stubCount++;
                    }
                }
            }

            if ($errorCount >= (int) $this->option('error-threshold')) {
                $alerts['error_rate'] = "{$errorCount} application ERROR lines in the last {$window} minutes.";
            }
            if ($webhookCount >= (int) $this->option('webhook-threshold')) {
                $alerts['webhook_unhandled'] = "{$webhookCount} 'Stripe webhook not handled' warning(s) in the last {$window} minutes — check STRIPE_WEBHOOK_SECRET and the event types Stripe is sending.";
            }
            if ($stubCount >= (int) $this->option('stub-threshold')) {
                $alerts['unimplemented_stub'] = "{$stubCount} STUB_UNIMPLEMENTED hit(s) in the last {$window} minutes — the Runtime is calling internal bridges that are not implemented; a capability may be reaching customers as a silent no-op.";
            }

            // ── failed jobs ──────────────────────────────────────────────
            try {
                $failed = DB::table('failed_jobs')->where('failed_at', '>=', $since)->count();
                if ($failed > 0) {
                    $alerts['failed_jobs'] = "{$failed} job(s) landed in failed_jobs in the last {$window} minutes.";
                }
            } catch (\Throwable $e) {
                // table may not exist on some installs; that fact is itself loggable once
                Log::info('ops:log-alert failed_jobs check skipped', ['error' => $e->getMessage()]);
            }

            if ($alerts === []) {
                $this->info('ops:log-alert — quiet window, nothing to report.');
                return self::SUCCESS;
            }

            // ── cooldown + send ─────────────────────────────────────────
            $due = array_filter($alerts, function ($msg, $reason) {
                return Cache::add('ops-alert-cooldown:' . $reason, 1, now()->addMinutes(self::COOLDOWN_MINUTES));
            }, ARRAY_FILTER_USE_BOTH);

            if ($due === []) {
                $this->info('ops:log-alert — alerts active but all within cooldown.');
                return self::SUCCESS;
            }

            $to = (string) ($this->option('to') ?: self::RECIPIENT);
            $bodyLines = [];
            foreach ($due as $reason => $msg) {
                $bodyLines[] = strtoupper($reason) . ': ' . $msg;
            }
            $body = "Level Up Growth ops alert — " . now()->toDateTimeString() . "\n\n"
                . implode("\n", $bodyLines)
                . "\n\nHost: " . gethostname()
                . "\nWindow: last {$window} minutes. Repeats of the same reason are suppressed for "
                . self::COOLDOWN_MINUTES . " minutes.";

            try {
                Mail::raw($body, function ($m) use ($to, $due) {
                    $m->to($to)->subject('[LUG OPS] ' . implode(' + ', array_keys($due)));
                });
                $this->info('ops:log-alert — sent: ' . implode(', ', array_keys($due)));
                Log::warning('ops:log-alert sent', ['reasons' => array_keys($due), 'to' => $to]);
            } catch (\Throwable $e) {
                Log::error('ops:log-alert SEND FAILED — the alert channel itself is broken', [
                    'error' => $e->getMessage(), 'reasons' => array_keys($due),
                ]);
                $this->error('send failed: ' . $e->getMessage());
                return self::FAILURE;
            }

            return self::SUCCESS;
        } catch (\Throwable $e) {
            Log::error('ops:log-alert crashed', ['error' => $e->getMessage()]);
            return self::FAILURE;
        }
    }

    /** Bounded tail without loading the whole file. */
    private function tailLines(string $path, int $max): array
    {
        $size = (int) filesize($path);
        $chunk = min($size, 4 * 1024 * 1024); // 4 MB is plenty for a 10-minute window
        $fh = fopen($path, 'rb');
        fseek($fh, -$chunk, SEEK_END);
        $data = (string) fread($fh, $chunk);
        fclose($fh);
        $lines = explode("\n", $data);
        return array_slice($lines, -$max);
    }

    /** Parse the leading [Y-m-d H:i:s] timestamp; null for continuation lines. */
    private function lineTimestamp(string $line): ?int
    {
        if (preg_match('/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\]/', $line, $m)) {
            $t = strtotime($m[1]);
            return $t === false ? null : $t;
        }
        return null;
    }
}
