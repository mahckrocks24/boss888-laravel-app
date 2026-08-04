<?php

namespace App\Engines\Studio\Selection\Contracts;

use App\Engines\Studio\Document\Contracts\SemanticGraphInterface;
use App\Engines\Studio\Selection\SelectionQuery;

/**
 * STUDIO888 · Selection — element resolver strategy.
 *
 * Turns a structured query into scored candidates by querying the Semantic
 * Graph. Resolvers are strategies: the default handles the full criteria set,
 * and future resolvers can self-register for new strategies without changing
 * the engine. A resolver NEVER touches HTML and NEVER mutates anything.
 */
interface ElementResolverInterface
{
    /**
     * @return \App\Engines\Studio\Selection\Candidate[] scored, best-first
     */
    public function resolve(SelectionQuery $query, SemanticGraphInterface $graph): array;
}
