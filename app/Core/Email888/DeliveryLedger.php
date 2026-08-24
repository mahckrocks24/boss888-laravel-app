<?php

namespace App\Core\Email888;

use App\Core\Email888\Models\EmailDelivery;
use App\Core\Email888\Models\EmailDeliveryEvent;
use App\Core\Email888\States\DeliveryState;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * EMAIL888 — the delivery ledger.
 *
 * Two rules govern everything here.
 *
 * 1. NEVER BREAK THE SEND. Observability that can take down outbound mail is
 *    worse than no observability. Every public method swallows its own storage
 *    failures and logs them; none of them may throw into a mail path.
 *
 * 2. STATE ONLY MOVES FORWARD. Provider webhooks arrive out of order and get
 *    replayed. A late 'accepted' must never un-deliver a delivered message.
 */
class DeliveryLedger
{
    /**
     * The row created by the most recent MessageSending in THIS process.
     * Lets a caller that catches a transport exception attribute the failure
     * without threading an id through Laravel's mail closure signature.
     * Process-scoped by design — a queue worker handles one job at a time.
     */
    private static ?int $lastRecordedId = null;

    public static function lastRecordedId(): ?int
    {
        return self::$lastRecordedId;
    }

    public static function forgetLastRecorded(): void
    {
        self::$lastRecordedId = null;
    }

    /**
     * Open a ledger row at hand-off. Returns null only if the ledger itself is
     * unavailable, which must not stop the message.
     */
    public function open(array $attrs): ?EmailDelivery
    {
        try {
            $row = EmailDelivery::create(array_merge([
                'correlation_id' => (string) \Illuminate\Support\Str::uuid(),
                'provider'       => \App\Core\Email888\OutboundPolicy::provider(),
                'purpose'        => 'unclassified',
                'stream_class'   => 'transactional',
                'state'          => DeliveryState::QUEUED->value,
                'queued_at'      => now(),
            ], $attrs));

            self::$lastRecordedId = (int) $row->id;

            return $row;
        } catch (Throwable $e) {
            Log::warning('email888.ledger.open_failed', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * The provider took the message and issued an id. Acceptance, not delivery —
     * the state name says so on purpose.
     */
    public function markAccepted(int $deliveryId, ?string $providerMessageId, ?string $stream = null): void
    {
        try {
            $row = EmailDelivery::find($deliveryId);
            if (! $row) {
                return;
            }

            // A provider id can collide with an existing row only if the provider
            // reused it, which would be a provider bug — but the unique index is
            // real, so degrade to "no id recorded" rather than losing the row.
            $row->forceFill(array_filter([
                'state'               => DeliveryState::ACCEPTED->value,
                'accepted_at'         => now(),
                'provider_message_id' => $providerMessageId,
                'provider_stream'     => $stream,
            ], fn ($v) => $v !== null))->save();
        } catch (QueryException $e) {
            Log::warning('email888.ledger.accept_id_conflict', [
                'delivery_id' => $deliveryId,
                'error'       => $e->getMessage(),
            ]);
            $this->safeUpdate($deliveryId, [
                'state'       => DeliveryState::ACCEPTED->value,
                'accepted_at' => now(),
            ]);
        } catch (Throwable $e) {
            Log::warning('email888.ledger.accept_failed', ['delivery_id' => $deliveryId, 'error' => $e->getMessage()]);
        }
    }

    /**
     * We never handed it over, or it died without a provider verdict.
     */
    public function markFailed(
        int $deliveryId,
        string $category,
        bool $retryable,
        array $evidence = [],
        ?DeliveryState $state = null
    ): void {
        $state ??= DeliveryState::FAILED;

        $stamp = match ($state) {
            DeliveryState::SUPPRESSED => 'suppressed_at',
            DeliveryState::BOUNCED    => 'bounced_at',
            default                   => 'failed_at',
        };

        $this->safeUpdate($deliveryId, [
            'state'             => $state->value,
            $stamp              => now(),
            'failure_category'  => $category,
            'retryable'         => $retryable,
            'provider_response' => $this->sanitiseEvidence($evidence),
        ]);
    }

    /**
     * Apply a provider event. Idempotent on payload digest, monotonic on state.
     *
     * @return string one of: applied | duplicate | out_of_order | unmatched | error
     */
    public function applyProviderEvent(
        string $provider,
        ?string $providerMessageId,
        DeliveryState $state,
        string $rawEventType,
        string $payloadDigest,
        array $evidence = [],
        ?Carbon $occurredAt = null
    ): string {
        try {
            return DB::transaction(function () use (
                $provider, $providerMessageId, $state, $rawEventType, $payloadDigest, $evidence, $occurredAt
            ) {
                // Replay safety first. The unique index is the real guard; this
                // read just avoids burning an insert attempt in the common case.
                $exists = EmailDeliveryEvent::where('provider', $provider)
                    ->where('payload_digest', $payloadDigest)
                    ->exists();

                if ($exists) {
                    return 'duplicate';
                }

                $row = $providerMessageId
                    ? EmailDelivery::where('provider', $provider)
                        ->where('provider_message_id', $providerMessageId)
                        ->lockForUpdate()
                        ->first()
                    : null;

                try {
                    EmailDeliveryEvent::create([
                        'email_delivery_id'   => $row?->id,
                        'provider'            => $provider,
                        'provider_message_id' => $providerMessageId,
                        'event_type'          => $state->value,
                        'provider_event_type' => $rawEventType,
                        'occurred_at'         => $occurredAt ?? now(),
                        'payload_digest'      => $payloadDigest,
                        'raw_metadata'        => $this->sanitiseEvidence($evidence),
                    ]);
                } catch (QueryException $e) {
                    // Lost the race to a concurrent identical delivery.
                    return 'duplicate';
                }

                if (! $row) {
                    // Event for a message this platform never recorded — keep the
                    // event (it is evidence) but say so plainly.
                    return 'unmatched';
                }

                $current = $row->deliveryState();

                if ($current->isTerminal() || $state->rank() < $current->rank()) {
                    return 'out_of_order';
                }

                $stamp = match ($state) {
                    DeliveryState::DELIVERED  => 'delivered_at',
                    DeliveryState::BOUNCED    => 'bounced_at',
                    DeliveryState::SUPPRESSED => 'suppressed_at',
                    DeliveryState::DEFERRED   => 'deferred_at',
                    DeliveryState::FAILED     => 'failed_at',
                    default                   => null,
                };

                $patch = ['state' => $state->value];
                if ($stamp) {
                    $patch[$stamp] = $occurredAt ?? now();
                }
                if ($evidence !== []) {
                    $patch['provider_response'] = $this->sanitiseEvidence($evidence);
                }
                if (in_array($state, [DeliveryState::BOUNCED, DeliveryState::SUPPRESSED], true)) {
                    $patch['failure_category'] = $state->value;
                    $patch['retryable'] = false;
                }

                $row->forceFill($patch)->save();

                return 'applied';
            });
        } catch (Throwable $e) {
            Log::error('email888.ledger.event_failed', [
                'provider' => $provider,
                'error'    => $e->getMessage(),
            ]);

            return 'error';
        }
    }

    public function findByProviderMessageId(string $provider, string $id): ?EmailDelivery
    {
        return EmailDelivery::where('provider', $provider)->where('provider_message_id', $id)->first();
    }

    private function safeUpdate(int $deliveryId, array $patch): void
    {
        try {
            EmailDelivery::whereKey($deliveryId)->update($patch);
        } catch (Throwable $e) {
            Log::warning('email888.ledger.update_failed', [
                'delivery_id' => $deliveryId,
                'error'       => $e->getMessage(),
            ]);
        }
    }

    /**
     * Provider diagnostics are useful and occasionally contain things we do not
     * want durably stored. Keep it bounded and scalar.
     */
    private function sanitiseEvidence(array $evidence): array
    {
        $out = [];
        foreach ($evidence as $k => $v) {
            if (count($out) >= 20) {
                break;
            }
            $key = substr((string) $k, 0, 40);
            if (is_scalar($v) || $v === null) {
                $out[$key] = is_string($v) ? substr($v, 0, 500) : $v;
            } else {
                $out[$key] = substr(json_encode($v), 0, 500);
            }
        }

        return $out;
    }
}
