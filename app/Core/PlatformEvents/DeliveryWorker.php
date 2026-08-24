<?php

namespace App\Core\PlatformEvents;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Executes one subscriber against one event, once.
 *
 * THE CRASH-WINDOW ANSWER
 * The subscriber's output and the delivery's 'delivered' status are written in
 * ONE transaction. There is therefore no window between "audit row inserted" and
 * "delivery marked delivered" in which a crash can leave them disagreeing:
 * either both are committed or neither is.
 *
 * A crash after the claim but before that transaction commits leaves the row in
 * 'processing'. reapStaleClaims() returns it to pending, and the retry re-runs
 * the subscriber cleanly — the previous attempt's output was rolled back, so no
 * duplicate is possible.
 *
 * The claim itself is a single atomic compare-and-swap, so two workers can never
 * both execute the same delivery.
 *
 * NEITHER the source platform event NOR the originating domain order is ever
 * modified here.
 */
class DeliveryWorker
{
    public const STATUS_PENDING            = 'pending';
    public const STATUS_PROCESSING         = 'processing';
    public const STATUS_DELIVERED          = 'delivered';
    public const STATUS_RETRYABLE_FAILURE  = 'retryable_failure';
    public const STATUS_PERMANENTLY_FAILED = 'permanently_failed';
    public const STATUS_SKIPPED            = 'skipped';

    public function enabled(): bool
    {
        return config('platform_events.delivery_worker.enabled') === true;
    }

    /**
     * @return array{claimed:int,delivered:int,retried:int,failed:int}
     */
    public function run(?string $subscriberKey = null, ?int $batchSize = null): array
    {
        $result = ['claimed' => 0, 'delivered' => 0, 'retried' => 0, 'failed' => 0];

        if (! $this->enabled()) {
            return $result;
        }

        $this->reapStaleClaims();

        $batch = $batchSize ?? (int) config('platform_events.delivery_worker.batch_size', 50);
        // EXECUTABLE, not eligible: this is the operational gate. A paused
        // subscriber's pending rows are left exactly where they are.
        $active = SubscriberRegistry::executable();

        if ($active === []) {
            return $result;
        }

        $keys = $subscriberKey !== null
            ? array_intersect(array_keys($active), [$subscriberKey])
            : array_keys($active);

        $due = DB::table('platform_event_deliveries')
            ->whereIn('subscriber_key', $keys)
            ->whereIn('status', [self::STATUS_PENDING, self::STATUS_RETRYABLE_FAILURE])
            ->where(function ($q) {
                $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now());
            })
            ->orderBy('id')
            ->limit($batch)
            ->get();

        foreach ($due as $row) {
            if (! $this->claim($row)) {
                continue;   // another worker took it
            }

            $result['claimed']++;
            $result[$this->execute((int) $row->id)]++;
        }

        return $result;
    }

    /**
     * Atomic compare-and-swap. Only the worker whose UPDATE affects a row
     * proceeds; a concurrent worker sees 0 affected and steps over it.
     */
    private function claim(object $row): bool
    {
        $affected = DB::table('platform_event_deliveries')
            ->where('id', $row->id)
            ->whereIn('status', [self::STATUS_PENDING, self::STATUS_RETRYABLE_FAILURE])
            ->update([
                'status' => self::STATUS_PROCESSING,
                'claimed_at' => now(),
                'attempt_count' => DB::raw('attempt_count + 1'),
                'updated_at' => now(),
            ]);

        return $affected === 1;
    }

    /** @return 'delivered'|'retried'|'failed' */
    private function execute(int $deliveryId): string
    {
        // Re-read post-claim so attempt_count is the real, incremented value.
        $delivery = DB::table('platform_event_deliveries')->where('id', $deliveryId)->first();

        if ($delivery === null) {
            return 'failed';
        }

        $event = DB::table('platform_events')->where('event_id', $delivery->event_id)->first();

        if ($event === null) {
            return $this->settleFailure($delivery, 'source event not found', terminal: true);
        }

        $decl = SubscriberRegistry::declaration($delivery->subscriber_key);

        // Tenancy, checked again at execution time. Fail closed rather than
        // deliver an event to a subscriber under the wrong tenant.
        if ((int) $event->workspace_id !== (int) $delivery->workspace_id) {
            return $this->settleFailure(
                $delivery,
                'tenancy mismatch: event workspace ' . $event->workspace_id
                    . ' != delivery workspace ' . $delivery->workspace_id,
                terminal: true
            );
        }

        if (! $decl->maySee((string) $event->sensitivity)) {
            return $this->settleFailure(
                $delivery,
                'sensitivity ' . $event->sensitivity . ' exceeds subscriber allowance',
                terminal: true
            );
        }

        $payload = json_decode((string) $event->payload_json, true);

        if (! is_array($payload)) {
            return $this->settleFailure($delivery, 'payload is not decodable JSON', terminal: true);
        }

        try {
            // ── the atomic pair ────────────────────────────────────────────
            DB::transaction(function () use ($decl, $event, $payload, $delivery) {
                // The CONFIGURED callable, not a hardcoded ->handle(), so the
                // verification gate checks the same thing this line executes.
                [$handler, $method] = SubscriberRegistry::handlerCallable($decl->key);
                $handler->{$method}($event, $payload);

                DB::table('platform_event_deliveries')->where('id', $delivery->id)->update([
                    'status' => self::STATUS_DELIVERED,
                    'delivered_at' => now(),
                    'last_error' => null,
                    'updated_at' => now(),
                ]);
            });

            return 'delivered';
        } catch (Throwable $e) {
            // FAILURE CLASSIFICATION.
            //
            // An integrity-constraint violation is PERMANENT: the referenced row
            // will not materialise because we retried. audit_logs carries foreign
            // keys to users.id and workspaces.id, so an event whose workspace was
            // deleted after the event was recorded lands here — and must surface
            // for investigation rather than consuming its whole retry budget.
            $terminal = $this->isPermanentFailure($e)
                || (int) $delivery->attempt_count >= $decl->maxAttempts;

            return $this->settleFailure(
                $delivery,
                class_basename($e) . ': ' . $e->getMessage(),
                terminal: $terminal,
                backoffSeconds: $terminal ? null : $decl->backoffFor((int) $delivery->attempt_count)
            );
        }
    }

    /**
     * A failure that retrying cannot fix.
     *
     * Integrity violations (SQLSTATE 23000) mean a referenced row is absent;
     * trying again does not create it. Data-shape errors are the same class.
     */
    private function isPermanentFailure(Throwable $e): bool
    {
        // The subscriber knows its own logic better than the worker does: only it
        // can say "this will never succeed for this event".
        if ($e instanceof PermanentSubscriberFailure) {
            return true;
        }

        if ($e instanceof \Illuminate\Database\UniqueConstraintViolationException) {
            return true;
        }

        if ($e instanceof \Illuminate\Database\QueryException) {
            $sqlState = (string) ($e->errorInfo[0] ?? '');

            // 23000 integrity constraint, 22032 invalid JSON, 22001 data too long
            return in_array($sqlState, ['23000', '22032', '22001'], true);
        }

        return false;
    }

    /** @return 'retried'|'failed' */
    private function settleFailure(object $delivery, string $error, bool $terminal, ?int $backoffSeconds = null): string
    {
        $error = mb_substr($error, 0, 1000);

        if ($terminal) {
            DB::table('platform_event_deliveries')->where('id', $delivery->id)->update([
                'status' => self::STATUS_PERMANENTLY_FAILED,
                'failed_at' => now(),
                'last_error' => $error,
                'updated_at' => now(),
            ]);

            Log::error('[Delivery] permanently failed', [
                'delivery_id' => $delivery->id,
                'subscriber' => $delivery->subscriber_key,
                'event_id' => $delivery->event_id,
                'attempts' => $delivery->attempt_count,
            ]);

            return 'failed';
        }

        DB::table('platform_event_deliveries')->where('id', $delivery->id)->update([
            'status' => self::STATUS_RETRYABLE_FAILURE,
            'next_attempt_at' => now()->addSeconds($backoffSeconds ?? 60),
            'last_error' => $error,
            'updated_at' => now(),
        ]);

        return 'retried';
    }

    /**
     * A row left in 'processing' belongs to a worker that died. Return it to
     * pending; the retry is safe because its transaction rolled back.
     */
    public function reapStaleClaims(): int
    {
        $minutes = (int) config('platform_events.delivery_worker.stale_claim_minutes', 15);

        return DB::table('platform_event_deliveries')
            ->where('status', self::STATUS_PROCESSING)
            ->where('claimed_at', '<', now()->subMinutes($minutes))
            ->update([
                'status' => self::STATUS_PENDING,
                'claimed_at' => null,
                'next_attempt_at' => now(),
                'updated_at' => now(),
            ]);
    }
}
