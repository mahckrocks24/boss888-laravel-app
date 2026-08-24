<?php

namespace App\Core\PlatformEvents;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Turns recorded events into per-subscriber delivery rows.
 *
 * The producer never learns which subscribers exist — that separation is the
 * point. Fan-out resolves active declarations against eligible events.
 *
 * IDEMPOTENT by construction: delivery rows are inserted with the unique
 * identity (event_id, subscriber_key, subscriber_version), and a collision is
 * swallowed. Running fan-out twice over the same event produces one delivery
 * row per subscriber, not two.
 *
 * PROSPECTIVE by construction: an event recorded before a subscriber's
 * activation timestamp gets no delivery row. Historical events are reached only
 * by an explicit, approved replay.
 */
class EventFanOut
{
    public function enabled(): bool
    {
        return config('platform_events.fanout.enabled') === true;
    }

    /**
     * Fan out one batch of not-yet-fanned-out events.
     *
     * @return array{claimed:int,created:int,skipped:int,no_subscribers:int}
     */
    public function run(?int $batchSize = null): array
    {
        $result = ['claimed' => 0, 'created' => 0, 'skipped' => 0, 'no_subscribers' => 0];

        if (! $this->enabled()) {
            return $result;
        }

        $batch = $batchSize ?? (int) config('platform_events.fanout.batch_size', 100);
        // ELIGIBLE, not executable: a paused subscriber must still receive its
        // pending delivery row, or the obligation is lost the moment we stamp
        // fanned_out_at. This is the Phase 1C.1 correction.
        $subscribers = SubscriberRegistry::eligible();

        $events = DB::table('platform_events')
            ->whereNull('fanned_out_at')
            ->orderBy('id')
            ->limit($batch)
            ->get();

        $result['claimed'] = $events->count();

        foreach ($events as $event) {
            $outcome = $this->fanOutOne($event, $subscribers);
            $result['created'] += $outcome['created'];
            $result['skipped'] += $outcome['skipped'];

            if ($outcome['created'] === 0 && $outcome['skipped'] === 0) {
                $result['no_subscribers']++;
            }
        }

        return $result;
    }

    /**
     * @param array<string, SubscriberDeclaration> $subscribers
     * @return array{created:int,skipped:int}
     */
    private function fanOutOne(object $event, array $subscribers): array
    {
        $created = 0;
        $skipped = 0;

        foreach ($subscribers as $decl) {
            $eligibility = $this->eligibility($event, $decl);

            if ($eligibility === null) {
                continue;   // not for this subscriber at all
            }

            [$status, $skipReason] = $eligibility;

            if ($this->insertDelivery($event, $decl, $status, $skipReason)) {
                $status === 'skipped' ? $skipped++ : $created++;
            }
        }

        // Mark the event fanned out regardless of how many rows were created.
        // delivery_count 0 is meaningful: fan-out ran, nothing was eligible.
        DB::table('platform_events')->where('id', $event->id)->update([
            'fanned_out_at' => now(),
        ]);

        // DERIVED from the delivery ledger, never from this pass's insert count.
        // `$created + $skipped` describes what THIS pass managed to insert: re-running
        // fan-out over an event whose rows already exist inserts nothing, which would
        // rewrite a correct count back to 0. The ledger is authoritative.
        DeliveryProjection::sync((string) $event->event_id);

        return ['created' => $created, 'skipped' => $skipped];
    }

    /**
     * Decide whether this subscriber gets a delivery row, and in what state.
     *
     * @return array{0:string,1:?string}|null null = ineligible, create nothing
     */
    private function eligibility(object $event, SubscriberDeclaration $decl): ?array
    {
        // 1. Governance eligibility: activation boundary, future-disable and
        //    retirement. Deliberately independent of every feature flag.
        if (! $decl->eligibleForEventRecordedAt((string) $event->recorded_at)) {
            return null;
        }

        // 2. Event type must be declared.
        if (! $decl->acceptsType((string) $event->event_type)) {
            return null;
        }

        // 3. Sensitivity. A subscriber that may not see restricted data does not
        //    silently receive a redacted copy — it gets no row at all.
        if (! $decl->maySee((string) $event->sensitivity)) {
            return ['skipped', 'sensitivity ' . $event->sensitivity . ' exceeds subscriber allowance'];
        }

        // 4. Schema version. An unsupported version is NEVER treated as success:
        //    it is recorded as skipped or permanently failed, per declaration.
        if (! $decl->acceptsSchema((string) $event->event_type, (int) $event->schema_version)) {
            $reason = 'schema v' . $event->schema_version . ' not accepted by ' . $decl->key . ' v' . $decl->version;

            return $decl->unsupportedSchemaPolicy === SubscriberDeclaration::UNSUPPORTED_SCHEMA_FAIL
                ? ['permanently_failed', $reason]
                : ['skipped', $reason];
        }

        return ['pending', null];
    }

    /** @return bool true when a row was created, false when it already existed */
    private function insertDelivery(object $event, SubscriberDeclaration $decl, string $status, ?string $skipReason): bool
    {
        try {
            DB::table('platform_event_deliveries')->insert([
                'event_id' => (string) $event->event_id,
                'subscriber_key' => $decl->key,
                'subscriber_version' => $decl->version,
                'workspace_id' => (int) $event->workspace_id,
                'status' => $status,
                'attempt_count' => 0,
                'next_attempt_at' => $status === 'pending' ? now() : null,
                'failed_at' => $status === 'permanently_failed' ? now() : null,
                'skip_reason' => $skipReason,
                'last_error' => $status === 'permanently_failed' ? $skipReason : null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return true;
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            // Already fanned out to this subscriber at this version. This is the
            // idempotency guarantee working, not an error.
            return false;
        } catch (Throwable $e) {
            Log::error('[FanOut] failed to create a delivery row', [
                'event_id' => $event->event_id, 'subscriber' => $decl->key,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
