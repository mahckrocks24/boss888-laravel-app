<?php

namespace App\Console\Commands;

use App\Core\PlatformEvents\DeliveryWorker;
use App\Core\PlatformEvents\EventFanOut;
use App\Core\PlatformEvents\ChainVerifier;
use App\Core\PlatformEvents\EventSystemHealth;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The single scheduled entry point for platform event processing.
 *
 *   php artisan platform-events:process
 *   php artisan platform-events:process --health   (report only, no work)
 *
 * Does three things in order: reap stale claims, fan out, deliver. One command,
 * one lock, one log line — not three schedule entries racing each other.
 *
 * SAFETY BY CONSTRUCTION
 *  - flags off  → both stages are no-ops and return zero counts
 *  - no events  → nothing claimed, nothing written
 *  - no active subscriber → fan-out creates nothing, delivery claims nothing
 *  - overlapping runs → the second exits immediately without work
 *  - cache/Redis unavailable → the lock cannot be taken, so the run ABORTS
 *    rather than proceeding unlocked. Work is not lost; it is picked up next run.
 *
 * Fan-out and delivery are pure database operations. Neither touches Redis, the
 * queue, a provider, Stripe or a notification channel.
 */
class PlatformEventsProcessCommand extends Command
{
    protected $signature = 'platform-events:process
                            {--health : print the health report and exit without processing}
                            {--batch= : override the batch size for this run}
                            {--ack-failures : clear the recorded processing-failure state and exit}';

    protected $description = 'Fan out recorded platform events and deliver them to active subscribers';

    private const LOCK_KEY = 'platform-events:process';

    /**
     * Where an unexpected processing failure is recorded so it survives for an
     * operator to see.
     *
     * A non-zero exit and two log lines already existed when the 10:20:05 failure
     * happened — but `--health` reported OK immediately afterwards, so the failure
     * was invisible the moment anyone looked. A SUCCESSFUL run deliberately does NOT
     * clear this: otherwise the next five-minute cycle erases the evidence.
     * Clearing is an explicit operator action, `--ack-failures`.
     */
    public const FAILURE_STATE_KEY = 'platform-events:processing-failure';

    public function handle(): int
    {
        if ($this->option('health')) {
            return $this->printHealth();
        }

        if ($this->option('ack-failures')) {
            return $this->acknowledgeFailures();
        }

        $lockSeconds = (int) config('platform_events.processing.lock_seconds', 240);

        // Overlap prevention. A cache lock rather than a database flag, so a
        // killed process releases it by TTL instead of stranding the schedule.
        try {
            $lock = Cache::lock(self::LOCK_KEY, $lockSeconds);
        } catch (Throwable $e) {
            // Cache backend unavailable. Fail CLOSED: running two overlapping
            // passes is worse than skipping one. Nothing is lost — un-fanned
            // events and pending deliveries are durable in the database.
            $this->warn('Cache unavailable; skipping this run to avoid an unlocked pass.');
            Log::warning('[PlatformEvents] processing skipped — cache lock unavailable', [
                'error' => $e->getMessage(),
            ]);

            return self::SUCCESS;
        }

        if (! $lock->get()) {
            $this->line('Another processing run holds the lock; exiting.');

            return self::SUCCESS;
        }

        try {
            // ── PHASE 1E DEPLOYMENT GATE ──
            //
            // There is no deployment pipeline on this host: changes are applied in
            // place. So the gate lives at the boundary that actually matters — a
            // release must not begin PROCESSING scheduled events until the chain has
            // been verified. Verification runs whenever the chain's fingerprint
            // differs from the last one that passed, i.e. exactly once after any
            // change, and never on an unchanged system.
            //
            // On failure the pass processes NOTHING and returns non-zero. There is no
            // --skip flag and no `|| true`: a broken chain cannot opt out.
            if (! $this->verifyChainBeforeProcessing()) {
                return self::FAILURE;
            }

            $batch = $this->option('batch') !== null ? (int) $this->option('batch') : null;

            $worker = new DeliveryWorker();
            $reaped = $worker->reapStaleClaims();

            $fan = (new EventFanOut())->run($batch);
            $del = $worker->run(null, $batch);

            $did = $reaped > 0 || array_sum($fan) > 0 || array_sum($del) > 0;

            if ($did) {
                Log::info('[PlatformEvents] processing pass', [
                    'reaped' => $reaped, 'fanout' => $fan, 'delivery' => $del,
                ]);
            }

            $this->line(sprintf(
                'reaped=%d | fanout claimed=%d created=%d skipped=%d no_subs=%d | delivery claimed=%d delivered=%d retried=%d failed=%d',
                $reaped,
                $fan['claimed'], $fan['created'], $fan['skipped'], $fan['no_subscribers'],
                $del['claimed'], $del['delivered'], $del['retried'], $del['failed']
            ));

            // A permanent failure is the one outcome that always needs a human.
            if ($del['failed'] > 0) {
                Log::error('[PlatformEvents] permanent delivery failure(s) in this pass', [
                    'failed' => $del['failed'],
                ]);
            }

            return self::SUCCESS;
        } catch (Throwable $e) {
            // Containment is preserved — one bad pass must not take the scheduler
            // down — but the failure is recorded so it cannot be normalised into a
            // clean-looking system by the next successful cycle.
            Log::error('[PlatformEvents] processing pass threw', [
                'error' => $e->getMessage(),
                'exception' => $e::class,
            ]);
            $this->recordFailure($e);
            $this->error('Processing failed: ' . $e->getMessage());

            return self::FAILURE;
        } finally {
            $lock->release();
        }
    }

    /**
     * Verify the chain if anything it depends on has changed since the last pass.
     *
     * @return bool true when it is safe to process
     */
    private function verifyChainBeforeProcessing(): bool
    {
        try {
            $fingerprint = ChainVerifier::fingerprint();
            $verified = Cache::get(ChainVerifier::VERIFIED_FINGERPRINT_KEY);
        } catch (Throwable $e) {
            // Cannot read the cache. The lock was obtained, so the cache is
            // reachable; treat an unexpected failure here as a reason to verify
            // rather than a reason to skip.
            $fingerprint = null;
            $verified = null;
        }

        if ($fingerprint !== null && $fingerprint === $verified) {
            return true;   // unchanged and previously verified
        }

        $result = app(ChainVerifier::class)->verify();

        if (! $result['passed']) {
            $failed = implode(', ', $result['summary']['failed_names']);

            Log::error('[PlatformEvents] DEPLOYMENT GATE — refusing to process an unverified chain', [
                'failed_checks' => $result['summary']['failed_names'],
                'failed_count' => $result['summary']['failed'],
                'total_checks' => $result['summary']['total'],
            ]);

            $this->error('Chain verification FAILED (' . $failed . '). Nothing was processed.');
            $this->line('Run `php artisan platform-events:verify` for detail.');

            $this->recordFailure(new \RuntimeException(
                'chain verification failed before processing: ' . $failed
            ));

            return false;
        }

        if ($fingerprint !== null) {
            try {
                Cache::forever(ChainVerifier::VERIFIED_FINGERPRINT_KEY, $fingerprint);
            } catch (Throwable) {
                // Not fatal: the next pass simply verifies again.
            }
        }

        Log::info('[PlatformEvents] chain verified before processing', [
            'checks' => $result['summary']['total'],
        ]);

        return true;
    }

    /**
     * Persist the failure for EventSystemHealth to surface.
     *
     * Never throws: bookkeeping about a failure must not be able to mask it. The
     * non-zero exit status and the log line are already in place by this point.
     */
    private function recordFailure(Throwable $e): void
    {
        try {
            $existing = Cache::get(self::FAILURE_STATE_KEY);
            $now = now()->toDateTimeString();

            Cache::put(self::FAILURE_STATE_KEY, [
                'count' => (int) ($existing['count'] ?? 0) + 1,
                'first_at' => $existing['first_at'] ?? $now,
                'last_at' => $now,
                // Class and message only. No payload, no stack trace, no secret.
                'last_error' => mb_substr($e::class . ': ' . $e->getMessage(), 0, 400),
            ], now()->addDays(7));
        } catch (Throwable $inner) {
            Log::error('[PlatformEvents] could not record the processing failure', [
                'error' => $inner->getMessage(),
            ]);
        }
    }

    private function acknowledgeFailures(): int
    {
        $existing = null;

        try {
            $existing = Cache::get(self::FAILURE_STATE_KEY);
            Cache::forget(self::FAILURE_STATE_KEY);
        } catch (Throwable $e) {
            $this->error('Could not clear the failure state: ' . $e->getMessage());

            return self::FAILURE;
        }

        if ($existing === null) {
            $this->info('No recorded processing failure to acknowledge.');

            return self::SUCCESS;
        }

        Log::warning('[PlatformEvents] processing failure state acknowledged and cleared', [
            'count' => $existing['count'] ?? null,
            'first_at' => $existing['first_at'] ?? null,
            'last_at' => $existing['last_at'] ?? null,
        ]);

        $this->info(sprintf('Acknowledged %d recorded failure(s), last at %s.',
            (int) ($existing['count'] ?? 0), $existing['last_at'] ?? 'unknown'));

        return self::SUCCESS;
    }

    private function printHealth(): int
    {
        $h = app(EventSystemHealth::class)->report();

        $this->line('severity: ' . strtoupper($h['severity']));
        $this->newLine();

        $this->line('flags:');
        foreach ($h['flags'] as $k => $v) {
            $this->line(sprintf('  %-30s %s', $k, is_array($v) ? ('[' . implode(',', $v) . ']') : var_export($v, true)));
        }

        $this->newLine();
        $this->line('backlog:');
        foreach ($h['backlog'] as $k => $v) {
            $this->line(sprintf('  %-38s %s', $k, $v === null ? '-' : $v));
        }

        $this->newLine();
        $this->line('timestamps:');
        foreach ($h['timestamps'] as $k => $v) {
            $this->line(sprintf('  %-30s %s', $k, $v ?? '-'));
        }

        $this->newLine();
        $this->line('infrastructure:');
        foreach ($h['infrastructure'] as $k => $v) {
            $this->line(sprintf('  %-30s %s', $k, is_bool($v) || $v === null ? var_export($v, true) : $v));
        }

        $this->newLine();
        $this->line('domain payment webhook:');
        foreach ($h['domain_payment_webhook'] ?? [] as $k => $v) {
            $this->line(sprintf('  %-30s %s', $k,
                is_array($v) ? json_encode($v) : (is_bool($v) || $v === null ? var_export($v, true) : $v)));
        }

        $this->newLine();
        $this->line('processing failures:');
        foreach ($h['processing_failures'] ?? [] as $k => $v) {
            $this->line(sprintf('  %-30s %s', $k, $v ?? '-'));
        }

        if ($h['findings'] !== []) {
            $this->newLine();
            $this->line('findings:');
            foreach ($h['findings'] as $f) {
                $this->line(sprintf('  [%-8s] %s', strtoupper($f['severity']), $f['message']));
            }
        }

        return self::SUCCESS;
    }
}
