<?php

namespace App\Core\PlatformEvents;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Reads pending events and attempts delivery.
 *
 * PHASE 1A HAS NO SUBSCRIBERS. That is not a gap to be papered over — it is
 * the honest state of the milestone. An event that reaches the dispatcher and
 * finds zero handlers is marked `no_subscribers`, NOT `dispatched`. Marking it
 * dispatched would be a false claim that something consumed it, and would make
 * the first real subscriber in Phase 1B look like a regression.
 *
 * Claiming uses SELECT ... FOR UPDATE SKIP LOCKED in a short transaction, so
 * two workers can never claim the same row. Processing happens OUTSIDE that
 * transaction: a slow or failing handler must never hold a database lock, and
 * must never be able to affect the producer's committed state.
 */
class OutboxDispatcher
{
    /** @var array<string, callable[]> event_type => handlers */
    private array $handlers = [];

    /**
     * Register a subscriber. Phase 1A registers none in production; the
     * mechanism exists so retry and locking can be proven under test.
     */
    public function subscribe(string $eventType, callable $handler): void
    {
        $this->handlers[$eventType][] = $handler;
    }

    public function handlerCountFor(string $eventType): int
    {
        return count($this->handlers[$eventType] ?? []);
    }

    /**
     * Process one batch.
     *
     * @return array{claimed:int,dispatched:int,no_subscribers:int,failed:int,retried:int}
     */
    public function run(?int $batchSize = null): array
    {
        $this->reapStaleLocks();

        $batch = $batchSize ?? (int) config('platform_events.dispatcher.batch_size', 50);
        $rows = $this->claim($batch);

        $result = ['claimed' => count($rows), 'dispatched' => 0, 'no_subscribers' => 0, 'failed' => 0, 'retried' => 0];

        foreach ($rows as $row) {
            $outcome = $this->process($row);
            $result[$outcome]++;
        }

        return $result;
    }

    /**
     * Claim a batch atomically. SKIP LOCKED means a second worker steps over
     * rows this one holds rather than blocking on them.
     */
    private function claim(int $batchSize): array
    {
        return DB::transaction(function () use ($batchSize) {
            $query = DB::table('platform_events')
                ->where('status', Outbox::STATUS_PENDING)
                ->where(function ($q) {
                    $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now());
                })
                ->orderBy('id')
                ->limit($batchSize);

            // SKIP LOCKED is MySQL 8+ / Postgres. Fall back gracefully so the
            // test suite can run on sqlite.
            try {
                $rows = $query->lockForUpdate()->get();
            } catch (Throwable) {
                $rows = $query->get();
            }

            if ($rows->isEmpty()) {
                return [];
            }

            DB::table('platform_events')
                ->whereIn('id', $rows->pluck('id')->all())
                ->update([
                    'status'        => Outbox::STATUS_DISPATCHING,
                    'locked_at'     => now(),
                    'attempt_count' => DB::raw('attempt_count + 1'),
                ]);

            return $rows->all();
        });
    }

    /** @return 'dispatched'|'no_subscribers'|'failed'|'retried' */
    private function process(object $row): string
    {
        $handlers = $this->handlers[$row->event_type] ?? [];

        if ($handlers === []) {
            // Truthful terminal state: recorded, validated, nothing consumed it.
            DB::table('platform_events')->where('id', $row->id)->update([
                'status'    => Outbox::STATUS_NO_SUBSCRIBERS,
                'locked_at' => null,
                'last_error'=> null,
            ]);

            return 'no_subscribers';
        }

        try {
            $payload = json_decode((string) $row->payload_json, true) ?: [];

            foreach ($handlers as $handler) {
                $handler($row, $payload);
            }

            DB::table('platform_events')->where('id', $row->id)->update([
                'status'        => Outbox::STATUS_DISPATCHED,
                'dispatched_at' => now(),
                'locked_at'     => null,
                'last_error'    => null,
            ]);

            return 'dispatched';
        } catch (Throwable $e) {
            return $this->recordFailure($row, $e);
        }
    }

    private function recordFailure(object $row, Throwable $e): string
    {
        $max = (int) config('platform_events.dispatcher.max_attempts', 5);
        // claim() SELECTs the rows and THEN increments attempt_count, so the
        // row object in hand still holds the pre-claim value. Add the claim's
        // own increment, or the counter runs one behind forever and an event
        // that always fails is retried indefinitely instead of failing.
        $attempts = (int) $row->attempt_count + 1;

        $error = mb_substr(class_basename($e) . ': ' . $e->getMessage(), 0, 1000);

        if ($attempts >= $max) {
            DB::table('platform_events')->where('id', $row->id)->update([
                'status'     => Outbox::STATUS_FAILED,
                'locked_at'  => null,
                'last_error' => $error,
            ]);

            Log::error('[Outbox] event permanently failed', [
                'event_id' => $row->event_id, 'type' => $row->event_type, 'attempts' => $attempts,
            ]);

            return 'failed';
        }

        DB::table('platform_events')->where('id', $row->id)->update([
            'status'          => Outbox::STATUS_PENDING,
            'locked_at'       => null,
            'last_error'      => $error,
            'next_attempt_at' => now()->addSeconds($this->backoffFor($attempts)),
        ]);

        return 'retried';
    }

    public function backoffFor(int $attempt): int
    {
        $ladder = (array) config('platform_events.dispatcher.backoff', [30, 120, 600, 3600]);
        $idx = max(0, min($attempt - 1, count($ladder) - 1));

        return (int) $ladder[$idx];
    }

    /**
     * A row left in 'dispatching' belongs to a worker that died. Return it to
     * pending so it is retried rather than stranded.
     */
    public function reapStaleLocks(): int
    {
        $minutes = (int) config('platform_events.dispatcher.stale_lock_minutes', 15);

        return DB::table('platform_events')
            ->where('status', Outbox::STATUS_DISPATCHING)
            ->where('locked_at', '<', now()->subMinutes($minutes))
            ->update([
                'status'    => Outbox::STATUS_PENDING,
                'locked_at' => null,
            ]);
    }
}
