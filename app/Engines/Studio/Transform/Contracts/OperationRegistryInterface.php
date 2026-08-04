<?php

namespace App\Engines\Studio\Transform\Contracts;

use App\Engines\Studio\Transform\OperationDefinition;

/**
 * STUDIO888 · AI Transformation Engine — Operation Registry contract.
 *
 * The single source of truth for which operations exist and their metadata.
 * Callers depend on this interface, never on a concrete registry, so the
 * implementation is swappable (dependency inversion). Operations register
 * themselves; there is no central switch statement anywhere in the platform.
 */
interface OperationRegistryInterface
{
    /** Register (or override) an operation definition. */
    public function register(OperationDefinition $definition): void;

    /** True if an operation type is registered. */
    public function has(string $type): bool;

    /** The definition for a type, or null if unregistered. */
    public function get(string $type): ?OperationDefinition;

    /** @return array<string, OperationDefinition> keyed by type */
    public function all(): array;

    /** The supported operation-contract schema version. */
    public function schemaVersion(): int;
}
