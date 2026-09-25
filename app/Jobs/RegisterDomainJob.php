<?php

namespace App\Jobs;

use App\Connectors\Infrastructure\Namecheap\NamecheapRegistrarConnector;
use App\Models\CustomerDomain;
use App\Models\DomainOrderItem;
use App\Services\Domains\DomainAuditLogger;
use App\Services\Domains\DomainContactResolver;
use App\Services\Domains\DomainLinkService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Registers one paid domain with the registrar.
 *
 * THE CENTRAL RISK
 * This job spends money. Everything about it is arranged so that it can never
 * spend money twice:
 *
 *   1. A DB-level advisory lock on the item, so two workers cannot process the
 *      same item concurrently.
 *   2. A status guard: only a pending item is ever attempted.
 *   3. A deterministic idempotency key per item, so the adapter's own
 *      already-registered check recognises a replay.
 *   4. The adapter checks the registrar account before purchasing.
 *   5. A unique index on customer_domains.domain as the final backstop.
 *
 * RETRY POLICY
 * Retries are for TRANSIENT failures only. A permanent failure -- domain taken,
 * contact rejected, insufficient funds -- is recorded and the job STOPS. An
 * ambiguous failure (timeout, 5xx) is treated as permanent and parked for
 * reconciliation, because a blind retry is exactly how a customer gets charged
 * twice.
 */
class RegisterDomainJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** RISK-0202: one instance per item on the queue; the lock outlives the job timeout. */
    public int $uniqueFor = 600;

    /** Attempts are driven by our own classification, not by blind retrying. */
    public int $tries = 3;

    /** Backoff between transient retries, in seconds. */
    public array $backoff = [30, 120];

    public int $timeout = 180;

    public function __construct(
        public readonly int $itemId
    ) {
    }

    public function uniqueId(): string
    {
        return 'register-domain-' . $this->itemId;
    }

    public function handle(): void
    {
        $audit = new DomainAuditLogger();

        // Claim the item atomically. If another worker already moved it out of
        // 'pending', this worker does nothing at all.
        $item = DB::transaction(function () {
            $item = DomainOrderItem::lockForUpdate()->find($this->itemId);

            if ($item === null) {
                return null;
            }

            if ($item->status === DomainOrderItem::STATUS_REGISTERING) {
                // RISK-0202: another runner holds this item. Only a dead one — no write for longer than
                // the job may run — is taken over; otherwise two runners would both call the registrar.
                $stale = $item->updated_at === null || $item->updated_at->lt(now()->subSeconds($this->timeout + 60));
                if (! $stale) {
                    return 'busy';
                }
            } elseif ($item->status !== DomainOrderItem::STATUS_PENDING) {
                return false;   // already settled
            }

            $item->update([
                'status'   => DomainOrderItem::STATUS_REGISTERING,
                'attempts' => (int) $item->attempts + 1,
            ]);

            return $item->fresh();
        });

        if ($item === null) {
            Log::warning('RegisterDomainJob: item not found', ['item_id' => $this->itemId]);

            return;
        }

        if ($item === false) {
            Log::info('RegisterDomainJob: item already settled, nothing to do', ['item_id' => $this->itemId]);

            return;
        }

        if ($item === 'busy') {
            Log::info('RegisterDomainJob: item is being registered by another runner, leaving it', ['item_id' => $this->itemId]);

            return;
        }

        // Refuse to register anything that was not paid for.
        $order = $item->order;

        if ($order === null || ! $order->isPaid()) {
            $item->update([
                'status'     => DomainOrderItem::STATUS_FAILED,
                'last_error' => 'Refusing to register: the order is not paid.',
                'last_error_code' => 'NOT_PAID',
            ]);
            $audit->registrationFailed($item, 'NOT_PAID', 'Order is not paid', true);

            return;
        }

        $audit->registrationAttempt($item, (int) $item->attempts);

        $contacts = $this->resolveContacts();

        if ($contacts === null) {
            $this->settleFailure($item, $audit, 'CONTACT_UNAVAILABLE',
                'No registrant contact is configured for this environment.', true);

            return;
        }

        $registrar = NamecheapRegistrarConnector::make();

        $result = $registrar->registerDomain(
            $item->domain,
            (int) $item->years,
            $contacts,
            $item->registrarIdempotencyKey()
        );

        if (! $result->success) {
            // 'permanent' covers both genuine refusals and AMBIGUOUS outcomes,
            // which the adapter deliberately downgrades so nothing auto-retries
            // a call that may already have charged us.
            $terminal = $result->retryClassification !== 'transient';

            // RISK-0202: before calling a terminal failure a refund, ask the registrar. "Domain name not
            // available" after OUR OWN concurrent registration, or a timeout after a charge that went
            // through, means the domain is in the account — that is a success, never a refund.
            if ($terminal) {
                $status = $registrar->getDomainStatus($item->domain);
                if ($status->success && ($status->data['owned'] ?? false) === true) {
                    Log::warning('RegisterDomainJob: registrar reports the domain in our account after a failed create — treating as registered', [
                        'item_id' => $item->id, 'domain' => $item->domain, 'error' => (string) $result->errorSummary,
                    ]);
                    $this->settleSuccess($item, $order, [
                        'order_id'         => null,
                        'transaction_id'   => null,
                        'charged_amount'   => null,
                        'environment'      => (string) config('namecheap.environment'),
                        'idempotent_replay' => true,
                        'recovered_from'   => (string) $result->errorCode,
                    ], true, $audit);

                    return;
                }
            }

            $this->settleFailure($item, $audit, (string) $result->errorCode, (string) $result->errorSummary, $terminal);

            if (! $terminal && $this->attempts() < $this->tries) {
                // Hand back to the queue for a genuine transient condition.
                $item->update(['status' => DomainOrderItem::STATUS_PENDING]);
                $this->release($this->backoff[min($this->attempts() - 1, count($this->backoff) - 1)]);
            }

            return;
        }

        $this->settleSuccess($item, $order, $result->data, ($result->data['idempotent_replay'] ?? false) === true, $audit);
    }

    /** The one success path: item registered, ownership row written, audit, DNS/attach set-up queued. */
    private function settleSuccess(DomainOrderItem $item, $order, array $data, bool $replay, DomainAuditLogger $audit): void
    {
        $result = (object) ['data' => $data];

        DB::transaction(function () use ($item, $result, $order, $replay) {
            $item->update([
                'status'                  => DomainOrderItem::STATUS_REGISTERED,
                'registered_at'           => now(),
                'provider_order_id'       => $result->data['order_id'] ?? null,
                'provider_transaction_id' => $result->data['transaction_id'] ?? null,
                'last_error'              => null,
                'last_error_code'         => null,
                'provider_metadata_json'  => [
                    'charged_amount'    => $result->data['charged_amount'] ?? null,
                    'environment'       => $result->data['environment'] ?? null,
                    'idempotent_replay' => $replay,
                    'recovered_from'    => $result->data['recovered_from'] ?? null,
                ],
            ]);

            // updateOrCreate keyed on the globally-unique domain: the final
            // backstop against a duplicate ownership row.
            CustomerDomain::updateOrCreate(
                ['domain' => $item->domain],
                [
                    'workspace_id'            => $item->workspace_id,
                    'user_id'                 => $order->user_id,
                    'domain_order_item_id'    => $item->id,
                    'provider'                => $item->provider,
                    'provider_order_id'       => $result->data['order_id'] ?? null,
                    'provider_transaction_id' => $result->data['transaction_id'] ?? null,
                    'status'                  => CustomerDomain::STATUS_ACTIVE,
                    'registered_at'           => now(),
                    'expires_at'              => now()->addYears((int) $item->years),
                    'auto_renew'              => false,
                    'last_synced_at'          => now(),
                    // DOMAIN-LINK-1 (RFC-0015): the website chosen at purchase, and the automatic
                    // set-up that follows. States are claims about what happened, set by the jobs.
                    'website_id'              => $item->website_id ? (int) $item->website_id : null,
                    'dns_state'               => DomainLinkService::DNS_PENDING,
                    'connect_state'           => $item->website_id ? DomainLinkService::CONNECT_PENDING : null,
                    'link_error'              => null,
                    'connect_started_at'      => null,
                ]
            );
        });

        $audit->registrationSucceeded($item, $result->data, $replay);

        $order->recomputeStatus();

        // Pull authoritative expiry and nameservers from the registrar rather
        // than trusting our own arithmetic.
        SyncCustomerDomainJob::dispatch($item->domain)->onQueue('tasks-low');

        // DOMAIN-LINK-1: point the domain at us (DNS-AUTO-1), then attach it (CONNECT-AUTO-1).
        $owned = CustomerDomain::where('domain', $item->domain)->first();
        if ($owned !== null) {
            DomainLinkService::make()->advance($owned);
        }
    }

    /**
     * Sandbox uses the gated fictional identity. Production must use real
     * customer contact data and will refuse rather than fall back.
     */
    private function resolveContacts(): ?array
    {
        if (config('namecheap.environment') === 'sandbox') {
            return DomainContactResolver::sandboxTestContact(true);
        }

        $contact = (array) config('namecheap.default_contact');

        return DomainContactResolver::isValid($contact) ? $contact : null;
    }

    private function settleFailure(DomainOrderItem $item, DomainAuditLogger $audit, string $code, string $summary, bool $terminal): void
    {
        // RISK-0202: a failure can never downgrade an item another runner has meanwhile registered.
        $current = (string) DomainOrderItem::where('id', $item->id)->value('status');
        if ($current === DomainOrderItem::STATUS_REGISTERED) {
            Log::info('RegisterDomainJob: failure ignored, item already registered', ['item_id' => $item->id, 'code' => $code]);

            return;
        }

        $item->update([
            'status'          => $terminal ? DomainOrderItem::STATUS_FAILED : DomainOrderItem::STATUS_PENDING,
            'last_error'      => mb_substr($summary, 0, 1000),
            'last_error_code' => $code,
        ]);

        $audit->registrationFailed($item, $code, $summary, $terminal);

        if ($terminal) {
            // The customer paid and did not get the domain. Flag it explicitly
            // rather than leaving it as a quiet 'failed' row nobody looks at.
            $item->update(['status' => DomainOrderItem::STATUS_REFUND_DUE]);
            $audit->refundDue($item, $code . ': ' . mb_substr($summary, 0, 200));
            $item->order?->recomputeStatus();
        }
    }

    public function failed(\Throwable $e): void
    {
        Log::error('RegisterDomainJob exhausted', [
            'item_id' => $this->itemId,
            'error'   => $e->getMessage(),
        ]);

        $item = DomainOrderItem::find($this->itemId);

        if ($item !== null && $item->status !== DomainOrderItem::STATUS_REGISTERED) {
            $item->update([
                'status'          => DomainOrderItem::STATUS_REFUND_DUE,
                'last_error'      => 'Job exhausted: ' . mb_substr($e->getMessage(), 0, 500),
                'last_error_code' => 'JOB_EXHAUSTED',
            ]);
            (new DomainAuditLogger())->refundDue($item, 'Job exhausted after ' . $this->tries . ' attempts');
            $item->order?->recomputeStatus();
        }
    }
}
