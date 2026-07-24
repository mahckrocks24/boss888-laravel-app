<?php

namespace App\Connectors\Infrastructure;

/**
 * Normalized provider result.
 *
 * WHY A DTO HERE, WHEN THE CODEBASE OTHERWISE USES ARRAYS
 * ------------------------------------------------------
 * The Phase 0 audit found DTOs are foreign to this codebase and recommended against
 * speculative scaffolding. The Phase 1 directive (§17 of the prior directive, §7
 * here) permits one "only when it materially normalizes provider payloads,
 * protects service boundaries or prevents arrays from leaking through the system."
 *
 * This is that case, concretely:
 *   1. Directive §7 forbids provider-specific payloads reaching controllers or the
 *      frontend. An untyped array cannot enforce that; this class can.
 *   2. Directive §8 requires normalized customer-facing statuses across
 *      interchangeable providers. The normalization has to happen SOMEWHERE typed,
 *      or every consumer re-implements it slightly differently.
 *   3. ConnectorInterface.php:47-49 doctrine: "Connector 'success' is NOT trusted
 *      until verified." A raw array makes it trivially easy to read ->success and
 *      skip verification. `verified` being a distinct required concept makes the
 *      untrusted case visible at the call site.
 *
 * It is one class, not a DTO layer. Nothing else in INFRA888 gets a DTO without the
 * same kind of concrete justification.
 */
final class ProviderResult
{
    private function __construct(
        public readonly bool $success,
        public readonly bool $verified,
        public readonly string $normalizedState,
        public readonly ?string $providerState = null,
        public readonly ?string $providerResourceId = null,
        public readonly ?string $correlationId = null,
        public readonly array $data = [],
        public readonly ?string $errorCode = null,
        public readonly ?string $errorSummary = null,
        public readonly string $retryClassification = 'permanent',
    ) {
    }

    /**
     * The provider accepted the call AND we independently confirmed the effect.
     */
    public static function verified(
        string $normalizedState,
        ?string $providerResourceId = null,
        ?string $providerState = null,
        array $data = [],
        ?string $correlationId = null
    ): self {
        return new self(
            success: true,
            verified: true,
            normalizedState: $normalizedState,
            providerState: $providerState,
            providerResourceId: $providerResourceId,
            correlationId: $correlationId,
            data: $data,
        );
    }

    /**
     * The provider returned success but the effect is NOT yet independently
     * confirmed — e.g. a hostname created but not yet validated or certified.
     * Callers must not present this to a customer as "done".
     */
    public static function accepted(
        string $normalizedState,
        ?string $providerResourceId = null,
        ?string $providerState = null,
        array $data = [],
        ?string $correlationId = null
    ): self {
        return new self(
            success: true,
            verified: false,
            normalizedState: $normalizedState,
            providerState: $providerState,
            providerResourceId: $providerResourceId,
            correlationId: $correlationId,
            data: $data,
        );
    }

    public static function failed(
        string $errorCode,
        string $errorSummary,
        string $retryClassification = 'permanent',
        string $normalizedState = 'failed',
        ?string $correlationId = null,
        ?string $providerState = null
    ): self {
        return new self(
            success: false,
            verified: false,
            normalizedState: $normalizedState,
            providerState: $providerState,
            correlationId: $correlationId,
            errorCode: $errorCode,
            errorSummary: $errorSummary,
            retryClassification: $retryClassification,
        );
    }

    public function isRetryable(): bool
    {
        return $this->retryClassification === 'retryable';
    }

    /**
     * Safe for persistence and logging. Never includes credentials.
     */
    public function toArray(): array
    {
        return [
            'success'              => $this->success,
            'verified'             => $this->verified,
            'normalized_state'     => $this->normalizedState,
            'provider_state'       => $this->providerState,
            'provider_resource_id' => $this->providerResourceId,
            'correlation_id'       => $this->correlationId,
            'error_code'           => $this->errorCode,
            'error_summary'        => $this->errorSummary,
            'retry_classification' => $this->retryClassification,
            'data'                 => $this->data,
        ];
    }
}
