<?php

namespace App\Connectors\Infrastructure\BusinessEmail\Values;

/**
 * INFRA888 · E2 — the catch-all as the PROVIDER reports it.
 *
 * `enabled` is nullable and that is load-bearing. Three states are genuinely
 * different and a boolean can only hold two:
 *
 *   true  — a catch-all is in effect
 *   false — no catch-all is in effect
 *   null  — this provider does not expose catch-all state at all
 *
 * Collapsing null into false would make reconciliation report "catch-all missing"
 * on every provider that simply cannot tell us, which is a drift finding about
 * our own ignorance rather than about the customer's configuration.
 */
final class RemoteCatchAll
{
    public function __construct(
        public readonly ?bool $enabled = null,
        public readonly ?string $targetAddress = null,
        public readonly ?string $providerRef = null,
    ) {
    }

    /** The provider answered the question, either way. */
    public function isKnown(): bool
    {
        return $this->enabled !== null;
    }

    public static function unknown(): self
    {
        return new self(null, null, null);
    }

    public static function disabled(): self
    {
        return new self(false, null, null);
    }

    public function normalizedTarget(): ?string
    {
        return $this->targetAddress === null ? null : strtolower(trim($this->targetAddress));
    }

    public function toAdminArray(): array
    {
        return [
            'enabled'        => $this->enabled,
            'target_address' => $this->targetAddress,
            'provider_ref'   => $this->providerRef,
            'known'          => $this->isKnown(),
        ];
    }
}
