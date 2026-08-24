<?php

namespace App\Core\PlatformEvents;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The ONLY writer to the platform_events table.
 *
 * Nothing else may INSERT into `platform_events`. An architecture test enforces
 * that, because an outbox with two writers has no invariants: the whole value
 * of this table is that every row was validated, redaction-checked and
 * tenancy-checked by one place.
 *
 * TRANSACTION RULE
 * record() performs a plain INSERT. It opens no transaction of its own and
 * commits nothing. The CALLER must already be inside the transaction that
 * writes the business state, so the event and the state commit together or not
 * at all. record() asserts it is inside a transaction and refuses otherwise —
 * a best-effort emit after commit is exactly the dual-write gap this design
 * exists to remove.
 */
class Outbox
{
    public const STATUS_PENDING        = 'pending';
    public const STATUS_DISPATCHING    = 'dispatching';
    public const STATUS_DISPATCHED     = 'dispatched';
    public const STATUS_NO_SUBSCRIBERS = 'no_subscribers';
    public const STATUS_FAILED         = 'failed';

    /**
     * Record a typed event inside the caller's transaction.
     *
     * @return string|null the event_id, or null when production is disabled or
     *                     the event is a duplicate of one already recorded
     */
    /**
     * The envelope of an earlier event about the same subject, for linking a journey.
     *
     * Lives HERE, not in the producer, because the architecture invariant is that the
     * Outbox is the only component that touches platform_events. A producer needing
     * the origin of its own subject would otherwise have to query the table directly,
     * and "only the Outbox touches the outbox" would quietly become "except when it
     * was convenient".
     *
     * READ-ONLY.
     *
     * @return object|null with ->event_id and ->correlation_id
     */
    public function originEnvelope(int $workspaceId, string $eventType, string $subjectType, string $subjectId): ?object
    {
        return DB::table('platform_events')
            ->where('workspace_id', $workspaceId)
            ->where('event_type', $eventType)
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId)
            ->orderBy('id')
            ->first(['event_id', 'correlation_id']);
    }

    public function record(PlatformEvent $event): ?string
    {
        if (! $this->enabledFor($event::type())) {
            return null;
        }

        // PHASE 1C — workspace gate. Independent of the producer flag: config is
        // global, production activation is not. A workspace outside the allow-list
        // can never produce an event, whatever the flags say.
        if (! $this->workspaceAllowed($event->workspaceId)) {
            return null;
        }

        if (DB::transactionLevel() < 1) {
            throw new RuntimeException(
                'Outbox::record() must be called inside the transaction that writes the business state. '
                . 'Emitting after commit reintroduces the dual-write gap this outbox exists to close.'
            );
        }

        $payload = $event->payload;

        // ORDER MATTERS. A payload carrying a secret is refused before any structural
        // question is asked: if both are wrong, the secret is the one that must be
        // reported, or the caller fixes the type and re-submits the secret.
        $this->assertNoBlockedKeys($payload, $event::type());
        $this->assertDeclaredEventClass($event);

        $eventId = $event->eventId();
        $now = now();

        try {
            DB::table('platform_events')->insert([
                'event_id'        => $eventId,
                'workspace_id'    => $event->workspaceId,
                'event_type'      => $event::type(),
                'schema_version'  => $event::schemaVersion(),
                'capability_key'  => $event->capabilityKey,
                'actor_type'      => $event->actorType,
                'actor_id'        => $event->actorId,
                'subject_type'    => $event->subjectType(),
                'subject_id'      => $event->subjectId(),
                'correlation_id'  => $event->correlationId ?: (string) Str::uuid(),
                'causation_id'    => $event->causationId,
                'payload_json'    => json_encode($payload, JSON_UNESCAPED_SLASHES),
                'sensitivity'     => $event->sensitivity(),
                'status'          => self::STATUS_PENDING,
                'occurred_at'     => $event->occurredAt ?? $now,
                'recorded_at'     => $now,
                'next_attempt_at' => null,
                'attempt_count'   => 0,
            ]);
        } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
            // Deterministic event_id collided: this exact logical event is
            // already recorded. That is the idempotency guarantee working, not
            // an error. Do NOT rethrow — rethrowing would roll back the
            // caller's business transaction over a duplicate.
            Log::info('[Outbox] duplicate event suppressed', [
                'event_type' => $event::type(),
                'event_id'   => $eventId,
            ]);

            return null;
        }

        return $eventId;
    }

    /**
     * Is this workspace permitted to produce events?
     *
     * FAIL-CLOSED. An empty allow-list permits NOTHING — forgetting to populate
     * it disables production rather than enabling it for every tenant. That is
     * the opposite of the usual "empty means all" convention, and deliberately so:
     * the expensive mistake here is producing events for customer workspaces.
     */
    public function workspaceAllowed(int $workspaceId): bool
    {
        $allowed = (array) config('platform_events.producer_workspace_allowlist', []);

        if ($allowed === []) {
            return false;
        }

        return in_array($workspaceId, array_map('intval', $allowed), true);
    }

    /** The workspaces currently permitted to produce. For health reporting. */
    public function allowedWorkspaces(): array
    {
        return array_map('intval', (array) config('platform_events.producer_workspace_allowlist', []));
    }

    /** Master switch AND the per-producer flag must both be on. */
    public function enabledFor(string $eventType): bool
    {
        if (config('platform_events.enabled') !== true) {
            return false;
        }

        // NOTE: config() dot-notation cannot address a key that itself
        // contains dots ('domain.order.created' would be read as a nested
        // path). Fetch the array and index it directly.
        $producers = (array) config('platform_events.producers', []);

        return ($producers[$eventType] ?? false) === true;
    }

    /**
     * Refuse any payload carrying a blocked field name, at any depth.
     *
     * Name-based, not value-scanning: cheap, total, and it catches the
     * realistic mistake — someone passing a whole request body or a provider
     * response straight through.
     */
    /**
     * The event's class must be the one declared for its type.
     *
     * This is what makes config('platform_events.event_classes') load-bearing rather
     * than decorative: a type with no declared class, or a class that disagrees with
     * the declared one, is refused at the point of recording.
     */
    private function assertDeclaredEventClass(PlatformEvent $event): void
    {
        $type = $event::type();
        $map = (array) config('platform_events.event_classes', []);

        if (! isset($map[$type])) {
            throw new RuntimeException(
                "DENIED: event type '{$type}' has no declared class in "
                . 'config/platform_events.php event_classes.'
            );
        }

        if ($event::class !== $map[$type]) {
            throw new RuntimeException(
                "DENIED: event type '{$type}' is declared as {$map[$type]} but was recorded as "
                . $event::class . '.'
            );
        }
    }

    private function assertNoBlockedKeys(array $payload, string $eventType, string $path = ''): void
    {
        $blocked = array_map('strtolower', (array) config('platform_events.blocked_payload_keys', []));

        foreach ($payload as $key => $value) {
            $here = $path === '' ? (string) $key : $path . '.' . $key;

            if (is_string($key) && in_array(strtolower($key), $blocked, true)) {
                throw new RuntimeException(
                    "Outbox refused {$eventType}: payload field '{$here}' is on the blocked list. "
                    . 'Secrets, credentials, payment tokens and raw bodies must never be recorded in a platform event.'
                );
            }

            if (is_array($value)) {
                $this->assertNoBlockedKeys($value, $eventType, $here);
            }
        }
    }
}
