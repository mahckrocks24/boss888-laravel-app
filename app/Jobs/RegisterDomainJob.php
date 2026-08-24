<?php

namespace App\Jobs;

use App\Connectors\Infrastructure\Namecheap\NamecheapRegistrarConnector;
use App\Models\CustomerDomain;
use App\Models\DomainOrderItem;
use App\Services\Domains\DomainAuditLogger;
use App\Services\Domains\DomainContactResolver;
use Illuminate\Bus\Queueable;
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
class RegisterDomainJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

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

            if (! in_array($item->status, [DomainOrderItem::STATUS_PENDING, DomainOrderItem::STATUS_REGISTERING], true)) {
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

            $this->settleFailure($item, $audit, (string) $result->errorCode, (string) $result->errorSummary, $terminal);

            if (! $terminal && $this->attempts() < $this->tries) {
                // Hand back to the queue for a genuine transient condition.
                $item->update(['status' => DomainOrderItem::STATUS_PENDING]);
                $this->release($this->backoff[min($this->attempts() - 1, count($this->backoff) - 1)]);
            }

            return;
        }

        $replay = ($result->data['idempotent_replay'] ?? false) === true;

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
                ]
            );
        });

        $audit->registrationSucceeded($item, $result->data, $replay);

        $order->recomputeStatus();

        // Pull authoritative expiry and nameservers from the registrar rather
        // than trusting our own arithmetic.
        SyncCustomerDomainJob::dispatch($item->domain)->onQueue('tasks-low');
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
