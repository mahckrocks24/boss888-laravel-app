<?php

namespace App\Services\Domains;

use RuntimeException;

/**
 * Raised on a hard/unexpected provider failure (transport error, unexpected
 * payload, non-idempotent conflict). Carries the provider's error strings and
 * whether the failure is likely transient (worth a retry / reconciliation).
 */
class ProviderException extends RuntimeException
{
    /** @param string[] $providerErrors */
    public function __construct(
        string $message,
        public array $providerErrors = [],
        public bool $retryable = false,
    ) {
        parent::__construct($message);
    }

    public function providerErrors(): array
    {
        return $this->providerErrors;
    }

    public function isRetryable(): bool
    {
        return $this->retryable;
    }
}
