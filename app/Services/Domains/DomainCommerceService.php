<?php

namespace App\Services\Domains;

use App\Connectors\Infrastructure\Namecheap\NamecheapRegistrarConnector;
use App\Core\PlatformEvents\Outbox;
use App\Core\PlatformEvents\Types\DomainOrderCreated;
use App\Core\PlatformEvents\Types\DomainOrderPaid;
use App\Jobs\RegisterDomainJob;
use App\Models\DomainOrder;
use App\Models\DomainOrderItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Customer domain commerce: search -> cart -> order -> payment -> registration.
 *
 * This service owns the COMMERCIAL flow. It does not talk to Namecheap's
 * protocol (that is the connector's job), does not compute markup (that is
 * DomainPricingService), and does not perform the registration (that is
 * RegisterDomainJob). It coordinates.
 *
 * PRICE INTEGRITY
 * Prices are re-derived from the registrar at order creation and are never
 * taken from the client. A cart posted from a browser carries domains and
 * terms only; every figure that reaches the database is one this server
 * measured. Otherwise a customer could buy a $60 domain for a dollar by
 * editing a form field.
 */
class DomainCommerceService
{
    /** Hard ceiling on a single cart, to bound the blast radius of a bad request. */
    public const MAX_ITEMS_PER_ORDER = 20;

    public function __construct(
        private readonly DomainPricingService $pricing,
        private readonly DomainAuditLogger $audit,
        private readonly Outbox $outbox = new Outbox(),
    ) {
    }

    public static function make(): self
    {
        return new self(DomainPricingService::make(), new DomainAuditLogger(), new Outbox());
    }

    // ------------------------------------------------------------- search --

    /**
     * Availability and full commercial detail for one domain.
     * Read-only; spends nothing.
     */
    public function search(string $domain, int $years = 1): array
    {
        $years = max(1, min(10, $years));
        $result = $this->pricing->searchWithPricing($domain, $years);

        // A blocked or unavailable domain gets no pricing embellishment.
        if (($result['available'] ?? false) !== true) {
            return $result + ['searched_at' => now()->toIso8601String()];
        }

        $registrar = NamecheapRegistrarConnector::make();

        // Renewal price matters at the point of sale: a $1 first year with a $40
        // renewal is a materially different product from a $12/$12 domain, and
        // hiding that is the oldest trick in the registrar business.
        $renewal = $registrar->quoteRenewal($domain, 1);
        $result['renewal'] = $renewal->success
            ? $this->retailFromCost((int) $renewal->data['amount_minor'])
            : ['available' => false, 'blocked_reason' => $renewal->errorSummary];

        // Transfer eligibility only means something for a domain that exists.
        $result['transfer_eligible'] = false;
        $result['transfer_note'] = 'Domain is unregistered; transfer does not apply.';

        $result['registration_period'] = [
            'years'      => $years,
            'min_years'  => 1,
            'max_years'  => 10,
            'expires_on' => now()->addYears($years)->toDateString(),
        ];

        $result['searched_at'] = now()->toIso8601String();

        return $result;
    }

    /** Availability for an already-registered domain the customer may transfer in. */
    public function searchTransfer(string $domain): array
    {
        $registrar = NamecheapRegistrarConnector::make();
        $availability = $registrar->searchDomain($domain);

        if (! $availability->success) {
            return ['available' => false, 'blocked' => true, 'blocked_reason' => $availability->errorSummary];
        }

        if (($availability->data['available'] ?? false) === true) {
            return [
                'transfer_eligible' => false,
                'reason' => 'Domain is not registered; it can be purchased outright instead of transferred.',
            ];
        }

        $quote = $registrar->quoteTransfer($domain);

        return [
            'transfer_eligible' => $quote->success,
            'domain'            => $domain,
            'pricing'           => $quote->success ? $this->retailFromCost((int) $quote->data['amount_minor']) : null,
            'blocked_reason'    => $quote->success ? null : $quote->errorSummary,
            'requirements'      => [
                'Domain must be unlocked at the current registrar',
                'You must supply the EPP/authorisation code',
                'Domain must be older than 60 days',
            ],
        ];
    }

    private function retailFromCost(int $costMinor): array
    {
        $percent = (float) config('namecheap.pricing.markup_percent', 30.0);
        $minimum = (int) round(((float) config('namecheap.pricing.markup_minimum_usd', 4.00)) * 100);
        $markup = max((int) round($costMinor * ($percent / 100)), $minimum);

        return [
            'available'    => true,
            'cost_minor'   => $costMinor,
            'markup_minor' => $markup,
            'retail_minor' => $costMinor + $markup,
            'retail'       => '$' . number_format(($costMinor + $markup) / 100, 2),
            'currency'     => 'USD',
        ];
    }

    // -------------------------------------------------------------- order --

    /**
     * Turn a cart into an order. Every price is measured here, server-side.
     *
     * @param array<int,array{domain:string,years?:int}> $cart
     */
    public function createOrder(int $workspaceId, ?int $userId, array $cart, ?string $idempotencyKey = null): array
    {
        if ($cart === []) {
            return ['error' => 'Cart is empty', 'code' => 'CART_EMPTY'];
        }

        if (count($cart) > self::MAX_ITEMS_PER_ORDER) {
            return ['error' => 'A single order is limited to ' . self::MAX_ITEMS_PER_ORDER . ' domains', 'code' => 'CART_TOO_LARGE'];
        }

        // A replayed submission returns the original order rather than a second one.
        if ($idempotencyKey !== null) {
            $existing = DomainOrder::where('idempotency_key', $idempotencyKey)->first();

            if ($existing !== null) {
                if ((int) $existing->workspace_id !== $workspaceId) {
                    return ['error' => 'Order not found', 'code' => 'NOT_FOUND'];
                }

                return ['order' => $this->presentOrder($existing), 'idempotent_replay' => true];
            }
        }

        // De-duplicate within the cart itself.
        $seen = [];
        $normalized = [];

        foreach ($cart as $line) {
            $domain = strtolower(trim((string) ($line['domain'] ?? '')));
            $years = max(1, min(10, (int) ($line['years'] ?? 1)));

            if ($domain === '' || isset($seen[$domain])) {
                continue;
            }

            $seen[$domain] = true;
            $normalized[] = ['domain' => $domain, 'years' => $years];
        }

        if ($normalized === []) {
            return ['error' => 'Cart contains no valid domains', 'code' => 'CART_EMPTY'];
        }

        // Price everything BEFORE opening a transaction: these are slow network
        // calls and must not hold row locks.
        $priced = [];
        $rejected = [];

        foreach ($normalized as $line) {
            $quote = $this->pricing->searchWithPricing($line['domain'], $line['years']);

            if (($quote['available'] ?? false) !== true) {
                $rejected[] = [
                    'domain' => $line['domain'],
                    'reason' => $quote['blocked_reason'] ?? $quote['reason'] ?? 'Not available',
                ];
                continue;
            }

            // A premium name needs a human decision before it can be sold.
            if (($quote['requires_review'] ?? false) === true) {
                $rejected[] = ['domain' => $line['domain'], 'reason' => 'Premium domain requires manual review before purchase'];
                continue;
            }

            $priced[] = ['line' => $line, 'quote' => $quote];
        }

        if ($priced === []) {
            return ['error' => 'None of the requested domains can be purchased', 'code' => 'NONE_AVAILABLE', 'rejected' => $rejected];
        }

        $order = DB::transaction(function () use ($workspaceId, $userId, $priced, $idempotencyKey) {
            $subtotal = 0;
            $cost = 0;

            foreach ($priced as $p) {
                $subtotal += (int) $p['quote']['retail_minor'];
                $cost += (int) $p['quote']['cost_minor'];
            }

            $order = DomainOrder::create([
                'workspace_id'     => $workspaceId,
                'user_id'          => $userId,
                'status'           => DomainOrder::STATUS_PENDING,
                'currency'         => 'USD',
                'subtotal_minor'   => $subtotal,
                'tax_minor'        => 0,
                'total_minor'      => $subtotal,
                'cost_total_minor' => $cost,
                'idempotency_key'  => $idempotencyKey,
                'metadata_json'    => ['priced_at' => now()->toIso8601String()],
            ]);

            foreach ($priced as $p) {
                DomainOrderItem::create([
                    'domain_order_id'      => $order->id,
                    'workspace_id'         => $workspaceId,
                    'domain'               => $p['line']['domain'],
                    'tld'                  => $this->tldOf($p['line']['domain']),
                    'years'                => $p['line']['years'],
                    'action'               => 'register',
                    'provider'             => 'namecheap',
                    'registrar_cost_minor' => (int) $p['quote']['cost_minor'],
                    'markup_minor'         => (int) $p['quote']['markup_minor'],
                    'retail_minor'         => (int) $p['quote']['retail_minor'],
                    'currency'             => 'USD',
                    'is_premium'           => (bool) ($p['quote']['premium'] ?? false),
                    'status'               => DomainOrderItem::STATUS_PENDING,
                ]);
            }

            // ── PLATFORM EVENT (shadow producer, Phase 1A) ──────────────────
            // Recorded INSIDE this transaction, so the order and the event
            // commit together or not at all. Default-OFF: with the flag
            // disabled this is a no-op and nothing is written.
            //
            // It describes ONLY what has committed locally. Nothing has been
            // paid for or registered at this point, and the event does not
            // claim otherwise. No subscriber consumes it.
            $this->outbox->record(new DomainOrderCreated(
                workspaceId: $workspaceId,
                payload: [
                    'order_id'       => (int) $order->id,
                    'currency'       => (string) $order->currency,
                    'subtotal_minor' => (int) $order->subtotal_minor,
                    'item_count'     => count($priced),
                    'domains'        => array_map(
                        static fn (array $p): string => $p['line']['domain'],
                        $priced
                    ),
                ],
                actorType: $userId ? DomainOrderCreated::ACTOR_USER : DomainOrderCreated::ACTOR_SYSTEM,
                actorId: $userId,
                capabilityKey: 'domain.register',
            ));

            return $order->fresh('items');
        });

        $this->audit->orderCreated($order);

        return [
            'order'    => $this->presentOrder($order),
            'rejected' => $rejected,
        ];
    }

    // ----------------------------------------------------------- checkout --

    /**
     * Hand the order to Stripe. Reuses the existing StripeService client and
     * configuration -- no second billing implementation.
     */
    public function startCheckout(DomainOrder $order, ?int $userId = null): array
    {
        if ($order->isPaid()) {
            return ['error' => 'Order is already paid', 'code' => 'ALREADY_PAID'];
        }

        if ($order->items()->count() === 0) {
            return ['error' => 'Order has no items', 'code' => 'EMPTY_ORDER'];
        }

        /** @var \App\Core\Billing\StripeService $stripe */
        $stripe = app(\App\Core\Billing\StripeService::class);

        $result = $stripe->createDomainCheckoutSession($order, $userId);

        if (isset($result['error'])) {
            return $result;
        }

        $order->update([
            'status'            => DomainOrder::STATUS_AWAITING_PAYMENT,
            'stripe_session_id' => $result['session_id'] ?? null,
        ]);

        $this->audit->checkoutStarted($order, $result['session_id'] ?? null);

        return $result;
    }

    /**
     * Called by the Stripe webhook once payment is confirmed.
     *
     * Idempotent by construction: the order is only advanced when it has not
     * already been paid, and the check plus the write happen inside a locked
     * transaction, so a duplicate webhook delivery cannot dispatch the
     * registration jobs twice.
     */
    public function markPaidAndProvision(string $sessionId, ?string $paymentIntentId = null): array
    {
        $outcome = DB::transaction(function () use ($sessionId, $paymentIntentId) {
            $order = DomainOrder::where('stripe_session_id', $sessionId)->lockForUpdate()->first();

            if ($order === null) {
                return ['handled' => false, 'reason' => 'No domain order for this session'];
            }

            if ($order->isPaid()) {
                return ['handled' => true, 'action' => 'already_processed', 'order_id' => $order->id];
            }

            $paidAt = now();

            $order->update([
                'status'                   => DomainOrder::STATUS_PAID,
                'paid_at'                  => $paidAt,
                'stripe_payment_intent_id' => $paymentIntentId,
            ]);

            // ── PHASE 1E: domain.order.paid, inside THIS transaction ──
            //
            // Recorded here, not after the commit, so the order being paid and the
            // event saying so cannot disagree. Outbox::record() asserts it is inside
            // a transaction and refuses otherwise, and it is gated by the producer
            // flag and the workspace allow-list — with the flag off this is a no-op
            // and the money path is byte-for-byte unchanged.
            //
            // Deterministic event identity (type|v1|domain_order|id|ws) means a
            // duplicate webhook delivery collides on the unique index rather than
            // recording a second payment event.
            // Through the Outbox, not by querying platform_events here: that table
            // has exactly one sanctioned toucher, and an architecture test enforces it.
            $origin = $this->outbox->originEnvelope(
                (int) $order->workspace_id,
                'domain.order.created',
                'domain_order',
                (string) $order->id,
            );

            // A payment intent id when Stripe supplied one, otherwise the session.
            // NEVER empty: an unreconcilable payment record is worse than none, and
            // an empty reference would make the event throw and roll back a payment
            // that genuinely succeeded.
            $reference = ($paymentIntentId !== null && $paymentIntentId !== '')
                ? $paymentIntentId
                : 'session:' . $sessionId;

            $this->outbox->record(new DomainOrderPaid(
                workspaceId: (int) $order->workspace_id,
                payload: [
                    'order_id' => (int) $order->id,
                    'currency' => (string) $order->currency,
                    // total_minor is the amount actually payable and paid. Wholesale
                    // cost (cost_total_minor) is internal and is refused by name.
                    'total_minor' => (int) $order->total_minor,
                    'paid_at' => $paidAt->toDateTimeString(),
                    'payment_provider' => DomainOrderPaid::PROVIDER_STRIPE,
                    'payment_reference' => $reference,
                ],
                // A webhook confirmed this, not a person. An operator who authorises
                // a controlled replay is recorded in a separate governance row.
                actorType: DomainOrderPaid::ACTOR_SYSTEM,
                actorId: null,
                // No capability applies: this is an inbound provider callback, not an
                // actor invoking an engine action.
                capabilityKey: null,
                // Same journey, and caused by the order-created event where one exists.
                correlationId: $origin->correlation_id ?? null,
                causationId: $origin->event_id ?? null,
            ));

            return ['handled' => true, 'action' => 'marked_paid', 'order_id' => $order->id, 'fresh' => true];
        });

        if (($outcome['fresh'] ?? false) !== true) {
            return $outcome;
        }

        $order = DomainOrder::with('items')->find($outcome['order_id']);
        $this->audit->paid($order);

        // ── PHASE 1E.1 FULFILMENT CONTAINMENT ──
        //
        // The payment is already committed above and is not conditional on any of
        // this. Fulfilment is a separate decision with separate failure modes, and a
        // successful payment must never depend on registrar readiness.
        if (config('domains.fulfilment.enabled') !== true) {
            $this->recordFulfilmentSuppressed($order);

            return $outcome + ['dispatched' => 0, 'fulfilment' => 'suppressed'];
        }

        // Dispatch AFTER the transaction commits, so a worker cannot pick up a
        // job for a row that is not yet visible.
        $order->update(['status' => DomainOrder::STATUS_PROVISIONING]);

        foreach ($order->items as $item) {
            RegisterDomainJob::dispatch($item->id)->onQueue('tasks-high');
        }

        $this->audit->provisioningDispatched($order);

        return $outcome + ['dispatched' => $order->items->count(), 'fulfilment' => 'dispatched'];
    }

    /**
     * Durable, honest trace that fulfilment was deliberately not attempted.
     *
     * The order stays `paid` rather than advancing to `provisioning`: that status IS
     * the trace, and it must never claim registration happened. The marker is merged
     * into the existing metadata_json column — no schema change, and priced_at and any
     * other provenance already there survives.
     */
    private function recordFulfilmentSuppressed(DomainOrder $order): void
    {
        $existing = is_array($order->metadata_json) ? $order->metadata_json : [];

        $order->update(['metadata_json' => $existing + [
            'fulfilment_suppressed' => true,
            'fulfilment_suppressed_at' => now()->toDateTimeString(),
            'fulfilment_suppressed_reason' => 'domains.fulfilment.enabled is false',
            // Explicitly NOT a claim that registration completed.
            'registration_state' => 'pending_operator_fulfilment',
        ]]);

        Log::warning('[DomainCommerce] payment committed with fulfilment suppressed', [
            'order_id' => $order->id,
            'workspace_id' => $order->workspace_id,
            'items' => $order->items->count(),
            'registration_dispatched' => false,
        ]);
    }

    // ------------------------------------------------------------ present --

    public function presentOrder(DomainOrder $order): array
    {
        $order->loadMissing('items');

        return [
            'id'          => $order->id,
            'status'      => $order->status,
            'currency'    => $order->currency,
            'subtotal'    => $this->money($order->subtotal_minor),
            'tax'         => $this->money($order->tax_minor),
            'total'       => $this->money($order->total_minor),
            'total_minor' => (int) $order->total_minor,
            'paid_at'     => $order->paid_at?->toIso8601String(),
            'created_at'  => $order->created_at?->toIso8601String(),
            'items'       => $order->items->map(fn (DomainOrderItem $i) => [
                'id'       => $i->id,
                'domain'   => $i->domain,
                'years'    => $i->years,
                'price'    => $this->money($i->retail_minor),
                'price_minor' => (int) $i->retail_minor,
                'status'   => $i->status,
                'premium'  => (bool) $i->is_premium,
                'registered_at' => $i->registered_at?->toIso8601String(),
                'error'    => $i->last_error,
                // Wholesale figures are deliberately ABSENT from customer output.
            ])->all(),
        ];
    }

    /** Admin view: the same order, with the commercial internals included. */
    public function presentOrderForAdmin(DomainOrder $order): array
    {
        $base = $this->presentOrder($order);
        $order->loadMissing('items');

        $base['cost_total'] = $this->money($order->cost_total_minor);
        $base['margin']     = $this->money($order->marginMinor());
        $base['workspace_id'] = (int) $order->workspace_id;
        $base['stripe_session_id'] = $order->stripe_session_id;
        $base['stripe_payment_intent_id'] = $order->stripe_payment_intent_id;

        foreach ($base['items'] as $idx => $row) {
            $item = $order->items->firstWhere('id', $row['id']);
            $base['items'][$idx]['registrar_cost'] = $this->money($item->registrar_cost_minor);
            $base['items'][$idx]['markup'] = $this->money($item->markup_minor);
            $base['items'][$idx]['margin'] = $this->money($item->marginMinor());
            $base['items'][$idx]['attempts'] = (int) $item->attempts;
            $base['items'][$idx]['provider_order_id'] = $item->provider_order_id;
            $base['items'][$idx]['provider_transaction_id'] = $item->provider_transaction_id;
            $base['items'][$idx]['last_error_code'] = $item->last_error_code;
        }

        return $base;
    }

    private function money(int|null $minor): string
    {
        return '$' . number_format(((int) $minor) / 100, 2);
    }

    private function tldOf(string $domain): string
    {
        $parts = explode('.', $domain);
        array_shift($parts);

        return implode('.', $parts) ?: 'com';
    }
}
