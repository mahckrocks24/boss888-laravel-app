<?php

namespace App\Core\PlatformEvents;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Explicit historical replay — DESIGNED, NOT EXPOSED.
 *
 * There is no route, no command and no UI for this in Phase 1B. It exists so
 * that reaching historical events is a deliberate, audited, dry-runnable
 * operation rather than something achieved by hand-editing delivery rows.
 *
 * Normal delivery is prospective: fan-out ignores events recorded before a
 * subscriber's activation timestamp. Replay is the ONLY sanctioned way past that
 * boundary, and it requires an explicit confirmation token plus an authorising
 * user.
 *
 * Replay NEVER:
 *   - modifies an event payload (events are immutable)
 *   - deletes or overwrites a prior delivery result
 *   - creates a duplicate delivery identity
 *   - targets "all history" implicitly — a time or id window is mandatory
 *   - runs customer-facing side effects without separate authorisation
 */
class EventReplay
{
    /**
     * Plan a replay. Always safe: reads only, writes nothing.
     *
     * @return array{subscriber:string,version:int,eligible:int,already_delivered:int,
     *               would_create:int,window:array,event_types:array,dry_run:true}
     */
    public function plan(
        string $subscriberKey,
        array $eventTypes,
        string $fromRecordedAt,
        string $toRecordedAt,
        ?int $workspaceId = null,
    ): array {
        $decl = SubscriberRegistry::declaration($subscriberKey);

        $this->assertWindow($fromRecordedAt, $toRecordedAt);
        $this->assertTypes($decl, $eventTypes);

        $candidates = $this->candidateQuery($eventTypes, $fromRecordedAt, $toRecordedAt, $workspaceId);
        $eligible = (clone $candidates)->count();

        $existing = DB::table('platform_event_deliveries')
            ->where('subscriber_key', $decl->key)
            ->where('subscriber_version', $decl->version)
            ->whereIn('event_id', (clone $candidates)->pluck('event_id'))
            ->count();

        return [
            'subscriber' => $decl->key,
            'version' => $decl->version,
            'eligible' => $eligible,
            'already_delivered' => $existing,
            'would_create' => max(0, $eligible - $existing),
            'window' => ['from' => $fromRecordedAt, 'to' => $toRecordedAt],
            'event_types' => $eventTypes,
            'workspace_id' => $workspaceId,
            'dry_run' => true,
        ];
    }

    /**
     * Execute a replay. Requires the exact confirmation token returned by
     * confirmationTokenFor(), so a caller cannot execute without having first
     * seen the plan.
     *
     * Customer-facing subscribers are refused outright: replaying a notification
     * subscriber would email customers about historical orders.
     */
    public function execute(
        string $subscriberKey,
        array $eventTypes,
        string $fromRecordedAt,
        string $toRecordedAt,
        int $authorisedByUserId,
        string $confirmationToken,
        ?int $workspaceId = null,
    ): array {
        $decl = SubscriberRegistry::declaration($subscriberKey);

        if ($decl->emitsCustomerFacingSideEffects) {
            throw new RuntimeException(
                "Refusing to replay '{$decl->key}': it emits customer-facing side effects. "
                . 'Replaying it would contact customers about historical events. '
                . 'This requires separate, explicit authorisation.'
            );
        }

        $expected = $this->confirmationTokenFor($subscriberKey, $eventTypes, $fromRecordedAt, $toRecordedAt, $workspaceId);

        if (! hash_equals($expected, $confirmationToken)) {
            throw new RuntimeException(
                'Replay confirmation token does not match this plan. Re-run plan() and confirm the exact scope.'
            );
        }

        if ($authorisedByUserId <= 0) {
            throw new InvalidArgumentException('Replay requires an authorising user id for the audit record.');
        }

        $plan = $this->plan($subscriberKey, $eventTypes, $fromRecordedAt, $toRecordedAt, $workspaceId);
        $created = 0;

        foreach ($this->candidateQuery($eventTypes, $fromRecordedAt, $toRecordedAt, $workspaceId)->cursor() as $event) {
            try {
                // The delivery row and the projection it implies are written in ONE
                // transaction. Phase 1D: replay used to insert the obligation and
                // leave platform_events.delivery_count untouched, which is how
                // event #1 ended up delivered with a projected count of 0.
                DB::transaction(function () use ($event, $decl): void {
                    DB::table('platform_event_deliveries')->insert([
                        'event_id' => (string) $event->event_id,
                        'subscriber_key' => $decl->key,
                        'subscriber_version' => $decl->version,
                        'workspace_id' => (int) $event->workspace_id,
                        'status' => DeliveryWorker::STATUS_PENDING,
                        'attempt_count' => 0,
                        'next_attempt_at' => now(),
                        'skip_reason' => null,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    DeliveryProjection::sync((string) $event->event_id);
                });

                // Incremented only after the transaction commits, so a rolled-back
                // write is never reported as created.
                $created++;
            } catch (\Illuminate\Database\UniqueConstraintViolationException) {
                // Already delivered (or already queued) at this version. Prior
                // results are never overwritten — but the projection is still
                // synchronised, because a duplicate-suppressed insert must not leave
                // a stale count behind. sync() counts rows, so this cannot overcount.
                DeliveryProjection::sync((string) $event->event_id);
            }
        }

        // Who authorised reaching back over history, and how far.
        DB::table('audit_logs')->insert([
            'workspace_id' => $workspaceId,
            'user_id' => $authorisedByUserId,
            'action' => 'platform_events.replay',
            'entity_type' => 'platform_event_subscriber',
            'entity_id' => null,
            'metadata_json' => json_encode([
                'subscriber_key' => $decl->key,
                'subscriber_version' => $decl->version,
                'event_types' => $eventTypes,
                'window' => ['from' => $fromRecordedAt, 'to' => $toRecordedAt],
                'workspace_id' => $workspaceId,
                'planned_would_create' => $plan['would_create'],
                'actually_created' => $created,
            ], JSON_UNESCAPED_SLASHES),
            'created_at' => now(),
        ]);

        return ['created' => $created, 'plan' => $plan];
    }

    public function confirmationTokenFor(
        string $subscriberKey,
        array $eventTypes,
        string $from,
        string $to,
        ?int $workspaceId,
    ): string {
        sort($eventTypes);

        return substr(hash('sha256', implode('|', [
            $subscriberKey, implode(',', $eventTypes), $from, $to, (string) $workspaceId,
        ])), 0, 24);
    }

    private function candidateQuery(array $eventTypes, string $from, string $to, ?int $workspaceId)
    {
        $q = DB::table('platform_events')
            ->whereIn('event_type', $eventTypes)
            ->where('recorded_at', '>=', $from)
            ->where('recorded_at', '<=', $to)
            ->orderBy('id');

        if ($workspaceId !== null) {
            $q->where('workspace_id', $workspaceId);
        }

        return $q;
    }

    private function assertWindow(string $from, string $to): void
    {
        foreach (['from' => $from, 'to' => $to] as $label => $v) {
            if (! preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}/', $v)) {
                throw new InvalidArgumentException("replay '{$label}' must be an explicit timestamp — replay never targets all history implicitly");
            }
        }

        if ($from >= $to) {
            throw new InvalidArgumentException('replay window must be ordered: from < to');
        }
    }

    private function assertTypes(SubscriberDeclaration $decl, array $eventTypes): void
    {
        if ($eventTypes === []) {
            throw new InvalidArgumentException('replay requires an explicit event type filter');
        }

        foreach ($eventTypes as $t) {
            if (! $decl->acceptsType($t)) {
                throw new InvalidArgumentException("{$decl->key} does not accept event type '{$t}'");
            }
        }
    }
}
