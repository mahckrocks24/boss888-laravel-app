<?php

namespace App\Services\Domains;

use App\Models\DomainOrder;
use App\Models\DomainOrderItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Audit trail for domain commerce, written to the existing infra_events table.
 *
 * No new audit system: INFRA888 already has one, and a second would fragment
 * the operator's view of what happened.
 *
 * WHAT NEVER REACHES AN AUDIT RECORD
 * API keys, provider credentials, and registrant personal data. Provider
 * responses are recorded as codes and summaries, never as raw envelopes, since
 * a raw envelope is exactly where a credential would hide. Prices ARE recorded:
 * they are commercial facts an operator must be able to reconstruct.
 */
class DomainAuditLogger
{
    private const OWNER_ORDER = 'domain_order';
    private const OWNER_ITEM  = 'domain_order_item';

    public function orderCreated(DomainOrder $order): void
    {
        $this->write($order->workspace_id, self::OWNER_ORDER, $order->id, 'domain.order.created', 'info', [
            'items'          => $order->items->count(),
            'subtotal_minor' => (int) $order->subtotal_minor,
            'currency'       => $order->currency,
            'domains'        => $order->items->pluck('domain')->all(),
        ], null, $order->status, "Domain order #{$order->id} created with {$order->items->count()} domain(s)", $order->user_id);
    }

    public function checkoutStarted(DomainOrder $order, ?string $sessionId): void
    {
        $this->write($order->workspace_id, self::OWNER_ORDER, $order->id, 'domain.order.checkout_started', 'info', [
            // The session id is not a secret; it is the reconciliation handle.
            'stripe_session_id' => $sessionId,
            'total_minor'       => (int) $order->total_minor,
        ], DomainOrder::STATUS_PENDING, DomainOrder::STATUS_AWAITING_PAYMENT,
            "Checkout started for order #{$order->id}", $order->user_id);
    }

    public function paid(DomainOrder $order): void
    {
        $this->write($order->workspace_id, self::OWNER_ORDER, $order->id, 'domain.order.paid', 'info', [
            'total_minor'   => (int) $order->total_minor,
            'payment_intent'=> $order->stripe_payment_intent_id,
        ], DomainOrder::STATUS_AWAITING_PAYMENT, DomainOrder::STATUS_PAID,
            "Payment confirmed for order #{$order->id}", $order->user_id);
    }

    public function provisioningDispatched(DomainOrder $order): void
    {
        $this->write($order->workspace_id, self::OWNER_ORDER, $order->id, 'domain.order.provisioning', 'info', [
            'jobs_dispatched' => $order->items->count(),
        ], DomainOrder::STATUS_PAID, DomainOrder::STATUS_PROVISIONING,
            "Registration dispatched for order #{$order->id}", $order->user_id);
    }

    public function registrationAttempt(DomainOrderItem $item, int $attempt): void
    {
        $this->write($item->workspace_id, self::OWNER_ITEM, $item->id, 'domain.registration.attempt', 'info', [
            'domain'  => $item->domain,
            'attempt' => $attempt,
            'years'   => (int) $item->years,
        ], $item->status, DomainOrderItem::STATUS_REGISTERING,
            "Registering {$item->domain} (attempt {$attempt})", null, $item->provider, $item->domain);
    }

    public function registrationSucceeded(DomainOrderItem $item, array $providerData, bool $replay = false): void
    {
        $this->write($item->workspace_id, self::OWNER_ITEM, $item->id, 'domain.registration.succeeded', 'info', [
            'domain'            => $item->domain,
            'provider_order_id' => $providerData['order_id'] ?? null,
            'transaction_id'    => $providerData['transaction_id'] ?? null,
            'idempotent_replay' => $replay,
        ], DomainOrderItem::STATUS_REGISTERING, DomainOrderItem::STATUS_REGISTERED,
            $replay
                ? "{$item->domain} was already registered; no second charge"
                : "{$item->domain} registered successfully",
            null, $item->provider, $item->domain);
    }

    public function registrationFailed(DomainOrderItem $item, string $code, string $summary, bool $terminal): void
    {
        $this->write($item->workspace_id, self::OWNER_ITEM, $item->id, 'domain.registration.failed',
            $terminal ? 'error' : 'warning', [
                'domain'     => $item->domain,
                'error_code' => $code,
                // Summary only -- never the raw provider envelope.
                'summary'    => mb_substr($summary, 0, 500),
                'terminal'   => $terminal,
                'attempts'   => (int) $item->attempts,
            ], DomainOrderItem::STATUS_REGISTERING,
            $terminal ? DomainOrderItem::STATUS_FAILED : DomainOrderItem::STATUS_PENDING,
            "Registration of {$item->domain} failed: {$code}", null, $item->provider, $item->domain);
    }

    public function refundDue(DomainOrderItem $item, string $reason): void
    {
        $this->write($item->workspace_id, self::OWNER_ITEM, $item->id, 'domain.registration.refund_due', 'error', [
            'domain'       => $item->domain,
            'retail_minor' => (int) $item->retail_minor,
            'reason'       => $reason,
        ], DomainOrderItem::STATUS_FAILED, DomainOrderItem::STATUS_REFUND_DUE,
            "REFUND DUE: {$item->domain} was paid for but could not be registered", null, $item->provider, $item->domain);
    }

    public function domainSynced(int $workspaceId, string $domain, array $changes, ?int $actorUserId = null): void
    {
        $this->write($workspaceId, 'customer_domain', null, 'domain.synced', 'info', [
            'domain'  => $domain,
            'changed' => array_keys($changes),
        ], null, null, "Synced {$domain} from registrar", $actorUserId, 'namecheap', $domain);
    }

    /**
     * Never throws. An audit failure must not take down a purchase -- but it is
     * logged loudly, because a silent audit gap is its own incident.
     */
    private function write(
        ?int $workspaceId,
        string $ownerType,
        ?int $ownerId,
        string $event,
        string $severity,
        array $context,
        ?string $fromState = null,
        ?string $toState = null,
        ?string $summary = null,
        ?int $actorUserId = null,
        ?string $provider = null,
        ?string $providerResourceId = null,
    ): void {
        try {
            DB::table('infra_events')->insert([
                'workspace_id'         => $workspaceId,
                'owner_type'           => $ownerType,
                'owner_id'             => $ownerId,
                'event'                => $event,
                'severity'             => $severity,
                'from_state'           => $fromState,
                'to_state'             => $toState,
                'actor_user_id'        => $actorUserId,
                'source'               => 'domain_commerce',
                'provider'             => $provider,
                'provider_resource_id' => $providerResourceId,
                'summary'              => $summary,
                'context_json'         => json_encode($context),
                'created_at'           => now(),
            ]);
        } catch (\Throwable $e) {
            Log::error('DomainAuditLogger: failed to write audit event', [
                'event' => $event,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
