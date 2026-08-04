<?php

namespace App\Engines\Studio\Transform\Contracts;

use App\Engines\Studio\Transform\Capability;

/**
 * STUDIO888 · AI Transformation Engine — Capability Registry contract.
 *
 * Advertises which operations Studio currently EXPOSES, so the AI plans only
 * from capabilities that actually exist. This prevents prompt engineering from
 * becoming the source of truth: an operation that is unavailable here can never
 * enter a plan, regardless of what a prompt or model asserts.
 *
 * Capabilities are PROJECTED from the Operation Registry (single source of
 * truth) — capability metadata is never duplicated or hand-maintained.
 */
interface CapabilityRegistryInterface
{
    /** The capability for an operation id, or null if the operation is unknown. */
    public function get(string $operation): ?Capability;

    /** True only if the operation is registered, available, and enabled. */
    public function isAvailable(string $operation): bool;

    /** @return array<string, Capability> every capability, keyed by operation id */
    public function all(): array;

    /** @return array<string, Capability> only available+enabled capabilities */
    public function available(): array;

    /** @return array<string, Capability> declared-but-unavailable capabilities */
    public function unavailable(): array;
}
