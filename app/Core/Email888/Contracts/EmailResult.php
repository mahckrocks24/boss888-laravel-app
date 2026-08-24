<?php

namespace App\Core\Email888\Contracts;

/**
 * EMAIL888 - the canonical result of a send. FROZEN CONTRACT.
 *
 * `accepted` means the provider took responsibility for the message. It does
 * NOT mean anyone received it, and it is deliberately not called `success` for
 * that reason - conflating the two is the exact mistake that lost 37 messages.
 * The delivery outcome lives on the ledger row this result points at, and only
 * a provider event may move it.
 */
final class EmailResult
{
    private function __construct(
        public readonly bool $accepted,
        public readonly string $provider,
        public readonly ?string $providerMessageId,
        public readonly ?int $deliveryRecordId,
        public readonly bool $retryable,
        public readonly ?string $failureCategory,
        public readonly string $correlationId,
        /** True when a prior send with the same idempotency key was returned instead. */
        public readonly bool $deduplicated = false,
    ) {
    }

    public static function accepted(
        string $provider,
        ?string $providerMessageId,
        ?int $deliveryRecordId,
        string $correlationId,
    ): self {
        return new self(true, $provider, $providerMessageId, $deliveryRecordId, false, null, $correlationId);
    }

    public static function refused(
        string $provider,
        ?int $deliveryRecordId,
        string $failureCategory,
        bool $retryable,
        string $correlationId,
    ): self {
        return new self(false, $provider, null, $deliveryRecordId, $retryable, $failureCategory, $correlationId);
    }

    /**
     * A duplicate of an earlier command. Reports the ORIGINAL send's identifiers
     * so the caller cannot tell - and must not care - which attempt won.
     */
    public static function duplicate(
        string $provider,
        ?string $providerMessageId,
        ?int $deliveryRecordId,
        string $correlationId,
    ): self {
        return new self(true, $provider, $providerMessageId, $deliveryRecordId, false, null, $correlationId, true);
    }

    public function toArray(): array
    {
        return [
            'accepted'            => $this->accepted,
            'provider'            => $this->provider,
            'provider_message_id' => $this->providerMessageId,
            'delivery_record_id'  => $this->deliveryRecordId,
            'retryable'           => $this->retryable,
            'failure_category'    => $this->failureCategory,
            'correlation_id'      => $this->correlationId,
            'deduplicated'        => $this->deduplicated,
        ];
    }
}
