<?php

namespace App\Engines\Studio\Selection;

use App\Engines\Studio\Document\Contracts\SemanticGraphInterface;
use App\Engines\Studio\Selection\Contracts\ElementResolverInterface;
use App\Engines\Studio\Selection\Contracts\SelectionEngineInterface;

/**
 * STUDIO888 · Selection — default Selection Engine.
 *
 * Delegates candidate finding to a resolver, then classifies the outcome:
 * a group query resolves to all members; a single-target query resolves only
 * when there is exactly one candidate, otherwise it is AMBIGUOUS with
 * clarification required. The engine never guesses and never mutates.
 */
final class SelectionEngine implements SelectionEngineInterface
{
    public function __construct(
        private readonly ElementResolverInterface $resolver,
    ) {
    }

    public function select(SelectionQuery $query, SemanticGraphInterface $graph): SelectionResult
    {
        $candidates = $this->resolver->resolve($query, $graph);

        if ($candidates === []) {
            return SelectionResult::notFound();
        }

        if ($query->expectMultiple || count($candidates) === 1) {
            return new SelectionResult(SelectionResult::RESOLVED, $candidates, false);
        }

        // More than one candidate for a single-target query → ambiguity is a result.
        return new SelectionResult(SelectionResult::AMBIGUOUS, $candidates, true);
    }
}
