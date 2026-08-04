<?php

namespace App\Engines\Studio\Transform;

use App\Engines\Studio\Transform\Contracts\CapabilityRegistryInterface;
use App\Engines\Studio\Transform\Contracts\OperationRegistryInterface;

/**
 * STUDIO888 · AI Transformation Engine — default Capability Registry.
 *
 * Advertises what Studio currently exposes by PROJECTING capabilities from the
 * Operation Registry. It stores no metadata of its own, so operation truth
 * lives in exactly one place. The AI planner asks this registry what exists;
 * an unavailable operation can never enter a plan even if a prompt names it.
 */
final class CapabilityRegistry implements CapabilityRegistryInterface
{
    public function __construct(
        private readonly OperationRegistryInterface $operations,
    ) {
    }

    public function get(string $operation): ?Capability
    {
        $def = $this->operations->get($operation);

        return $def ? Capability::fromDefinition($def) : null;
    }

    public function isAvailable(string $operation): bool
    {
        $def = $this->operations->get($operation);

        return $def !== null && $def->isAvailable();
    }

    public function all(): array
    {
        $out = [];
        foreach ($this->operations->all() as $type => $def) {
            $out[$type] = Capability::fromDefinition($def);
        }

        return $out;
    }

    public function available(): array
    {
        return array_filter($this->all(), fn (Capability $c) => $c->available);
    }

    public function unavailable(): array
    {
        return array_filter($this->all(), fn (Capability $c) => ! $c->available);
    }
}
