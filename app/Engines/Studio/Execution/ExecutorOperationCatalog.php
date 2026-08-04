<?php

namespace App\Engines\Studio\Execution;

use App\Engines\Studio\Transform\Contracts\OperationRegistryInterface;
use App\Engines\Studio\Transform\OperationDefinition;

/**
 * STUDIO888 · Execution — Phase-3A operation catalog.
 *
 * Registers the Phase-3A safe operation types INTO the existing Phase-1
 * Operation Registry (additive; no Phase-1 file is modified). `set_style`
 * already exists in Phase 1 and is reused. Text ops carry a TEXT value; state
 * ops carry none. All are reversible, non-billing, and require no Creative888.
 */
final class ExecutorOperationCatalog
{
    public static function registerInto(OperationRegistryInterface $registry): void
    {
        $textTargets = ['text', 'headline', 'body', 'cta', 'stat', 'label', 'caption'];

        foreach (['replace_text', 'append_text', 'prepend_text'] as $type) {
            $registry->register(new OperationDefinition(
                type: $type, category: 'text', valueKind: OperationDefinition::KIND_TEXT,
                supportedTargets: $textTargets, reversible: true,
                estimatedComplexity: OperationDefinition::COMPLEXITY_INSTANT,
            ));
        }

        foreach (['hide' => false, 'show' => false, 'lock' => false, 'unlock' => false] as $type => $_) {
            $registry->register(new OperationDefinition(
                type: $type, category: 'state', valueKind: OperationDefinition::KIND_NONE,
                supportedTargets: ['*'], reversible: true,
                estimatedComplexity: OperationDefinition::COMPLEXITY_INSTANT,
            ));
        }
    }
}
