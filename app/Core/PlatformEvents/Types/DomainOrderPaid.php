<?php

namespace App\Core\PlatformEvents\Types;

use App\Core\PlatformEvents\PlatformEvent;
use InvalidArgumentException;

/**
 * A customer domain order was confirmed paid.
 *
 * The second producer, and the first to describe a MONEY fact. It is recorded
 * inside the same transaction that commits the payment transition, so the order
 * being paid and the event saying so cannot disagree.
 *
 * ── WHAT THIS EVENT DOES NOT SAY ──
 *
 * It does not say a domain was registered, or that fulfilment started. Payment and
 * provisioning are separate facts with separate failure modes, and conflating them
 * is how an audit trail comes to claim a customer owns something they do not.
 *
 * ── ACTOR SEMANTICS ──
 *
 * actor_type = system, actor_id = null. The payment transition is caused by a
 * Stripe webhook, not by a person. An operator who authorises a controlled replay
 * is recorded in a SEPARATE governance audit row — a business fact and an operator
 * authorisation are different records, and merging them would make the audit trail
 * claim a human performed a payment they merely permitted a test of.
 *
 * ── PAYLOAD DISCIPLINE ──
 *
 * Included: the identity of the order, when it was paid, what was paid, and the
 * provider reference needed to reconcile against the provider's own records.
 *
 * Deliberately EXCLUDED, and asserted:
 *   - Stripe secrets, the webhook signing secret, the raw webhook body
 *   - payment method or card details of any kind
 *   - billing address and any other personal data
 *   - internal commercial figures (registrar cost, markup)
 *   - workspace_id: the envelope already carries it as a mandatory field. A second
 *     copy in the payload can disagree with the first, and then neither is
 *     trustworthy. Same reasoning for actor and correlation, which are envelope
 *     fields.
 *
 * ── SENSITIVITY: internal ──
 *
 * It names a tenant's spend and a provider reference. That is commercially
 * sensitive to that tenant and must not leak across tenants, but it is not a
 * secret and not personal data, so `restricted` would overstate it and block the
 * audit subscriber for no benefit.
 */
final class DomainOrderPaid extends PlatformEvent
{
    /** The only payment provider this event currently describes. */
    public const PROVIDER_STRIPE = 'stripe';

    public static function type(): string
    {
        return 'domain.order.paid';
    }

    public static function schemaVersion(): int
    {
        return 1;
    }

    public static function requiredPayloadKeys(): array
    {
        return ['order_id', 'currency', 'total_minor', 'paid_at', 'payment_provider', 'payment_reference'];
    }

    public function subjectType(): string
    {
        // The SAME subject as domain.order.created, so both events about one order
        // group naturally and the audit trail reads as one object's history.
        return 'domain_order';
    }

    public function subjectId(): string
    {
        return (string) $this->payload['order_id'];
    }

    public function sensitivity(): string
    {
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

        // The canonical monetary representation in this codebase is minor units as
        // an integer. A float here would silently lose money to rounding.
        if (! is_int($p['total_minor']) || $p['total_minor'] < 0) {
            throw new InvalidArgumentException(self::type() . ': total_minor must be a non-negative integer of minor units');
        }

        if (! is_string($p['paid_at']) || ! preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}/', $p['paid_at'])) {
            throw new InvalidArgumentException(self::type() . ': paid_at must be an explicit timestamp');
        }

        if ($p['payment_provider'] !== self::PROVIDER_STRIPE) {
            throw new InvalidArgumentException(
                self::type() . ": payment_provider must be '" . self::PROVIDER_STRIPE . "'; "
                . 'a new provider needs its own review, not a free-text value'
            );
        }

        if (! is_string($p['payment_reference']) || $p['payment_reference'] === '') {
            throw new InvalidArgumentException(
                self::type() . ': payment_reference must be a non-empty string — without it the payment cannot be reconciled'
            );
        }

        // A payment intent id, not a secret. Refuse anything that looks like a key:
        // Stripe secrets start sk_ or rk_, the webhook secret whsec_.
        foreach (['sk_', 'rk_', 'whsec_'] as $prefix) {
            if (str_starts_with($p['payment_reference'], $prefix)) {
                throw new InvalidArgumentException(
                    self::type() . ": payment_reference looks like a credential ('{$prefix}…'), not a payment identifier"
                );
            }
        }

        // ── the realistic mistakes, refused by name ──
        //
        // The Outbox blocked-key scan already refuses secrets at any depth. These
        // are the domain-specific temptations: fields someone would add because the
        // webhook payload happened to have them to hand.
        foreach ([
            'card', 'card_last4', 'card_brand', 'payment_method', 'payment_method_details',
            'billing_address', 'billing_details', 'customer_email', 'receipt_url',
            'raw_event', 'stripe_event', 'signature',
            'registrar_cost_minor', 'markup_minor', 'cost_total_minor',
        ] as $forbidden) {
            if (array_key_exists($forbidden, $p)) {
                throw new InvalidArgumentException(
                    self::type() . ": '{$forbidden}' must not be published in a platform event. "
                    . 'It is either personal data, provider detail or internal commercial data.'
                );
            }
        }

        // workspace_id lives in the envelope. Two copies can disagree.
        if (array_key_exists('workspace_id', $p)) {
            throw new InvalidArgumentException(
                self::type() . ': workspace_id is an envelope field and must not be duplicated in the payload'
            );
        }
    }
}
