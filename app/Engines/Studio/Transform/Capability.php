<?php

namespace App\Engines\Studio\Transform;

/**
 * STUDIO888 · AI Transformation Engine — Capability.
 *
 * The public advertisement of one operation: what Studio currently exposes.
 * This is a pure PROJECTION of an OperationDefinition (see fromDefinition) —
 * it holds no independent metadata, so there is exactly one source of truth.
 * The AI planner consumes capabilities; it never consumes raw prompts as a
 * source of what is possible.
 */
final class Capability
{
    public function __construct(
        public readonly string $operation,
        public readonly bool   $available,
        public readonly bool   $enabled,
        public readonly string $maturity,
        public readonly array  $supportedMedia,
        public readonly array  $supportedTargets,
        public readonly array  $requiredCapabilities,
        public readonly bool   $requiresCreative888,
        public readonly bool   $requiresConfirmation,
        public readonly bool   $affectsBilling,
        public readonly bool   $reversible,
        public readonly bool   $collaborative,
        public readonly bool   $supportsAnimation,
        public readonly string $estimatedComplexity,
    ) {
    }

    /** Project a capability advertisement from its definition (no duplication). */
    public static function fromDefinition(OperationDefinition $d): self
    {
        return new self(
            operation:            $d->type,
            available:            $d->isAvailable(),
            enabled:              $d->enabled,
            maturity:             $d->maturity,
            supportedMedia:       $d->supportedMedia,
            supportedTargets:     $d->supportedTargets,
            requiredCapabilities: $d->requiredCapabilities,
            requiresCreative888:  $d->requiresCreative888,
            requiresConfirmation: $d->requiresConfirmation,
            affectsBilling:       $d->affectsBilling,
            reversible:           $d->reversible,
            collaborative:        $d->collaborative,
            supportsAnimation:    $d->supportsAnimation,
            estimatedComplexity:  $d->estimatedComplexity,
        );
    }

    /** Stable, serialisable shape for the (future) capability-discovery API. */
    public function toArray(): array
    {
        return [
            'operation'             => $this->operation,
            'available'             => $this->available,
            'enabled'               => $this->enabled,
            'maturity'              => $this->maturity,
            'supported_media'       => $this->supportedMedia,
            'supported_targets'     => $this->supportedTargets,
            'required_capabilities' => $this->requiredCapabilities,
            'requires_creative888'  => $this->requiresCreative888,
            'requires_confirmation' => $this->requiresConfirmation,
            'affects_billing'       => $this->affectsBilling,
            'reversible'            => $this->reversible,
            'collaborative'         => $this->collaborative,
            'animation_support'     => $this->supportsAnimation,
            'estimated_complexity'  => $this->estimatedComplexity,
        ];
    }
}
