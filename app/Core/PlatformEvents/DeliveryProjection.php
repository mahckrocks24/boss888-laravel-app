<?php

namespace App\Core\PlatformEvents;

use Illuminate\Support\Facades\DB;

/**
 * THE delivery-count projection on platform_events.
 *
 * platform_events.delivery_count is a denormalised convenience. The delivery
 * LEDGER (platform_event_deliveries) is and remains authoritative — this class
 * exists so the projection is always DERIVED from that ledger and never guessed.
 *
 * WHY THIS EXISTS (Phase 1D)
 *
 * Two ways the projection drifted:
 *
 *  1. EventFanOut wrote `delivery_count = created + skipped`, i.e. what THIS pass
 *     managed to insert. Re-running fan-out over an event whose rows already
 *     existed inserted nothing, so the count was rewritten to 0 while rows existed.
 *
 *  2. EventReplay inserted a delivery row and never touched the projection at all.
 *     That is how event #1 ended up with a delivered obligation and delivery_count 0.
 *
 * Both are the same mistake: describing the ledger from the outcome of one write
 * instead of reading the ledger. Every writer now calls sync(), which counts rows.
 * That makes the operation idempotent — running it twice cannot overcount, and a
 * duplicate-suppressed insert leaves the number unchanged.
 */
class DeliveryProjection
{
    /**
     * Recompute one event's delivery_count from the ledger.
     *
     * Deliberately NOT an increment. Callers may run this any number of times, in
     * any order, after any partially-suppressed insert, and the result is the same.
     *
     * @return int the count now stored
     */
    public static function sync(string $eventId): int
    {
        $count = (int) DB::table('platform_event_deliveries')
            ->where('event_id', $eventId)
            ->count();

        DB::table('platform_events')
            ->where('event_id', $eventId)
            ->update(['delivery_count' => $count]);

        return $count;
    }

    /**
     * READ-ONLY comparison of every event's projection against the ledger.
     *
     * Writes nothing, so it is safe for the verification command and for a health
     * report. Returns one row per event that disagrees.
     *
     * @return array{checked:int,drifted:array<int,array{event_id:string,projected:int,ledger:int}>}
     */
    public static function audit(?int $workspaceId = null): array
    {
        $events = DB::table('platform_events')
            ->when($workspaceId !== null, fn ($q) => $q->where('workspace_id', $workspaceId))
            ->orderBy('id')
            ->get(['id', 'event_id', 'workspace_id', 'delivery_count']);

        $ledger = DB::table('platform_event_deliveries')
            ->select('event_id', DB::raw('COUNT(*) as n'))
            ->groupBy('event_id')
            ->pluck('n', 'event_id');

        $drifted = [];

        foreach ($events as $e) {
            $actual = (int) ($ledger[$e->event_id] ?? 0);

            if ((int) $e->delivery_count !== $actual) {
                $drifted[] = [
                    'row_id' => (int) $e->id,
                    'event_id' => (string) $e->event_id,
                    'workspace_id' => (int) $e->workspace_id,
                    'projected' => (int) $e->delivery_count,
                    'ledger' => $actual,
                ];
            }
        }

        return ['checked' => $events->count(), 'drifted' => $drifted];
    }

    /**
     * Repair only the events whose projection disagrees with the ledger.
     *
     * Touches nothing that is already correct, so a repair run is inspectable:
     * the returned list is exactly what changed.
     *
     * @return array<int,array{event_id:string,from:int,to:int}>
     */
    public static function repairDrifted(?int $workspaceId = null): array
    {
        $repaired = [];

        foreach (self::audit($workspaceId)['drifted'] as $d) {
            $to = self::sync($d['event_id']);

            $repaired[] = [
                'event_id' => $d['event_id'],
                'from' => $d['projected'],
                'to' => $to,
            ];
        }

        return $repaired;
    }
}
