<?php

namespace App\Core\PlatformEvents\Types;

use App\Core\PlatformEvents\PlatformEvent;
use InvalidArgumentException;

/**
 * A customer domain order was created and committed locally.
 *
 * This is the FIRST and only producer in Phase 1A. It was chosen because it
 * describes a purely local state change: an order row and its items, inside an
 * existing transaction. It touches no provider, no payment, and no job.
 *
 * It deliberately does NOT describe an intended outcome — nothing has been paid
 * for or registered at this point. The event states what committed, nothing more.
 *
 * PAYLOAD DISCIPLINE
 * The minimum a future subscriber needs, and nothing else:
 *   - audit needs the order identity and value
 *   - analytics needs item count and currency
 *   - memory needs the domains a workspace shows intent to buy
 *
 * Excluded on purpose: wholesale cost and markup (internal commercial data no
 * approved subscriber needs), Stripe identifiers (none exist yet at this point
 * in the flow), registrar responses, personal data, raw request bodies.
 */
final class DomainOrderCreated extends PlatformEvent
{
    public static function type(): string
    {
        return 'domain.order.created';
    }

    public static function schemaVersion(): int
    {
        return 1;
    }

    public static function requiredPayloadKeys(): array
    {
        return ['order_id', 'currency', 'subtotal_minor', 'item_count', 'domains'];
    }

    public function subjectType(): string
    {
        return 'domain_order';
    }

    public function subjectId(): string
    {
        return (string) $this->payload['order_id'];
    }

    public function sensitivity(): string
    {
        // Contains domains a workspace intends to buy — commercially sensitive
        // to that tenant, but not a secret and not personal data.
        return self::SENSITIVITY_INTERNAL;
    }

    protected function assertPayloadTypes(): void
    {
        $p = $this->payload;

        if (! is_int($p['order_id']) || $p['order_id'] <= 0) {
            throw new InvalidArgumentException(self::type() . ': order_id must be a positive integer');
        }

        if (! is_string($p['currency']) || strlen($p['currency']) !== 3) {
            throw new InvalidArgumentException(self::type() . ': currency must be a 3-letter code');
        }

        if (! is_int($p['subtotal_minor']) || $p['subtotal_minor'] < 0) {
            throw new InvalidArgumentException(self::type() . ': subtotal_minor must be a non-negative integer');
        }

        if (! is_int($p['item_count']) || $p['item_count'] < 1) {
            throw new InvalidArgumentException(self::type() . ': item_count must be at least 1');
        }

        if (! is_array($p['domains']) || $p['domains'] === []) {
            throw new InvalidArgumentException(self::type() . ': domains must be a non-empty array');
        }

        foreach ($p['domains'] as $d) {
            if (! is_string($d) || $d === '') {
                throw new InvalidArgumentException(self::type() . ': every entry in domains must be a non-empty string');
            }
        }

        if (count($p['domains']) !== $p['item_count']) {
            throw new InvalidArgumentException(self::type() . ': item_count must match the number of domains');
        }

        // Guard against the realistic mistake: someone widening the payload to
        // include internal commercial figures because they were convenient.
        foreach (['registrar_cost_minor', 'markup_minor', 'cost_total_minor'] as $internal) {
            if (array_key_exists($internal, $p)) {
                throw new InvalidArgumentException(
                    self::type() . ": '{$internal}' is internal commercial data and must not be published. "
                    . 'Add it only when an approved subscriber demonstrably requires it.'
                );
            }
        }
    }
}
