<?php

namespace App\Core\PlatformEvents\Subscribers;

use App\Core\PlatformEvents\PermanentSubscriberFailure;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * platform.audit — the first and only Phase 1B subscriber.
 *
 * Writes exactly one row into the platform's audit trail (`audit_logs`) for each
 * delivered event, closing the Phase 0 finding that a domain purchase produced
 * 18 infra_events and 0 audit_logs.
 *
 * WHAT IT DOES NOT DO
 * No notification. No memory write. No analytics. No customer-facing side
 * effect of any kind. Tests assert all four.
 *
 * MAPPING, NOT COPYING
 * The event payload is NOT copied wholesale into audit_logs. Each field is
 * mapped deliberately, and anything unnecessary is omitted. A blind copy is how
 * a payload change silently widens what the audit trail stores.
 *
 * CLAIMS DISCIPLINE
 * The audit entry describes ONLY that a domain order was created. It does not
 * claim payment succeeded, registration occurred, fulfilment completed, or that
 * the customer owns anything. Those are separate future events.
 */
class AuditSubscriber
{
    public const KEY = 'platform.audit';

    /**
     * Behaviour version. Increment when the mapping changes: the delivery
     * identity includes it, so a version bump permits a deliberate re-delivery
     * under new behaviour without reopening settled rows.
     */
    public const VERSION = 1;

    /**
     * Handle one event.
     *
     * MUST be called inside the delivery transaction. The audit row and the
     * delivery's 'delivered' status commit together — that shared transaction,
     * plus the unique delivery identity, is the whole idempotency guarantee.
     * A crash between the two is impossible because there is no "between".
     *
     * @param object $event  a platform_events row
     * @param array  $payload decoded payload_json
     */
    public function handle(object $event, array $payload): void
    {
        if (DB::transactionLevel() < 1) {
            throw new RuntimeException(
                self::KEY . ' must run inside the delivery transaction so its output and the '
                . 'delivery status commit atomically.'
            );
        }

        $action = $this->actionFor((string) $event->event_type);

        if ($action === null) {
            // Declared acceptance is checked at fan-out, so reaching here means
            // the declaration and the mapping disagree. Retrying cannot invent a
            // mapping, so this is declared permanent rather than burning the
            // whole retry budget on a structural mismatch.
            throw new PermanentSubscriberFailure(
                self::KEY . ": no audit mapping for event type '{$event->event_type}'"
            );
        }

        DB::table('audit_logs')->insert([
            'workspace_id' => (int) $event->workspace_id,

            // Only a real user id belongs here. An agent or system actor is
            // recorded in metadata instead of being misattributed to a person.
            'user_id' => $event->actor_type === 'user' && $event->actor_id
                ? (int) $event->actor_id
                : null,

            'action' => $action,
            'entity_type' => (string) $event->subject_type,

            // entity_id is a bigint; a non-numeric subject (a domain name) is
            // carried in metadata rather than coerced into a wrong number.
            'entity_id' => is_numeric($event->subject_id) ? (int) $event->subject_id : null,

            'metadata_json' => json_encode($this->metadataFor($event, $payload), JSON_UNESCAPED_SLASHES),
            'created_at' => now(),
        ]);
    }

    /** Explicit map. An event type with no entry is a hard error, not a silent skip. */
    private function actionFor(string $eventType): ?string
    {
        return [
            // Deliberately mirrors the event name: audit_logs already uses
            // engine.action naming (task.created, write.publish_article).
            'domain.order.created' => 'domain.order.created',
            'domain.order.paid' => 'domain.order.paid',
        ][$eventType] ?? null;
    }

    /**
     * Traceability first, then a minimal description. Explicitly mapped — never
     * a wholesale payload copy.
     */
    private function metadataFor(object $event, array $payload): array
    {
        return [
            // ── traceability: connects the audit row back to its source ──
            'source' => 'platform_event',
            'event_id' => (string) $event->event_id,
            'event_type' => (string) $event->event_type,
            'schema_version' => (int) $event->schema_version,
            'correlation_id' => (string) $event->correlation_id,
            'causation_id' => $event->causation_id ? (string) $event->causation_id : null,
            'capability_key' => $event->capability_key ? (string) $event->capability_key : null,
            'actor_type' => (string) $event->actor_type,
            'actor_id' => $event->actor_id ? (int) $event->actor_id : null,
            'subject_type' => (string) $event->subject_type,
            'subject_id' => (string) $event->subject_id,
            'occurred_at' => (string) $event->occurred_at,
            'subscriber_key' => self::KEY,
            'subscriber_version' => self::VERSION,

            // ── the description: mapped fields only ──
            'summary' => $this->summaryFor((string) $event->event_type, $payload),
            'detail' => $this->detailFor((string) $event->event_type, $payload),
        ];
    }

    private function summaryFor(string $eventType, array $payload): string
    {
        return match ($eventType) {
            // Careful wording: an order was CREATED. Not paid, not registered.
            'domain.order.created' => sprintf(
                'Domain order #%s created with %d domain(s)',
                $payload['order_id'] ?? '?',
                (int) ($payload['item_count'] ?? 0)
            ),
            // A money fact. States the amount actually paid and the provider
            // reference, and says nothing about registration.
            'domain.order.paid' => sprintf(
                'Domain order #%s paid — %s %s via %s',
                $payload['order_id'] ?? '?',
                (string) ($payload['currency'] ?? ''),
                number_format(((int) ($payload['total_minor'] ?? 0)) / 100, 2),
                (string) ($payload['payment_provider'] ?? 'unknown')
            ),
            default => $eventType,
        };
    }

    /** Explicit per-type field selection. Unlisted payload fields are dropped. */
    private function detailFor(string $eventType, array $payload): array
    {
        return match ($eventType) {
            'domain.order.created' => [
                'order_id' => (int) ($payload['order_id'] ?? 0),
                'item_count' => (int) ($payload['item_count'] ?? 0),
                'domains' => array_values(array_map('strval', (array) ($payload['domains'] ?? []))),
                'currency' => (string) ($payload['currency'] ?? ''),
                // Retail subtotal. NOT revenue: nothing has been paid at this point.
                'subtotal_minor' => (int) ($payload['subtotal_minor'] ?? 0),
                'payment_state' => 'not_paid_at_time_of_event',
            ],
            'domain.order.paid' => [
                'order_id' => (int) ($payload['order_id'] ?? 0),
                'currency' => (string) ($payload['currency'] ?? ''),
                // The amount actually paid. This IS revenue, unlike subtotal_minor
                // on the created event.
                'total_minor' => (int) ($payload['total_minor'] ?? 0),
                'paid_at' => (string) ($payload['paid_at'] ?? ''),
                'payment_provider' => (string) ($payload['payment_provider'] ?? ''),
                // A payment intent reference for reconciliation. Never a secret —
                // DomainOrderPaid refuses anything with a credential prefix.
                'payment_reference' => (string) ($payload['payment_reference'] ?? ''),
                // Paid is not fulfilled. Registration is a separate fact.
                'fulfilment_state' => 'not_fulfilled_at_time_of_event',
            ],
            default => [],
        };
    }
}
