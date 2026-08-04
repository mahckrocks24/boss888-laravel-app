<?php

namespace App\Engines\Studio\Selection\Contracts;

use App\Engines\Studio\Document\Contracts\SemanticGraphInterface;
use App\Engines\Studio\Selection\SelectionQuery;
use App\Engines\Studio\Selection\SelectionResult;

/**
 * STUDIO888 · Selection — Selection Engine contract.
 *
 * Resolves a structured query against a Semantic Graph into a SelectionResult
 * with confidence and first-class ambiguity. It is STATELESS with respect to
 * the document (the graph is passed in), understands NO HTML, and performs NO
 * mutation. This is understanding only — the execution phase comes later.
 */
interface SelectionEngineInterface
{
    public function select(SelectionQuery $query, SemanticGraphInterface $graph): SelectionResult;
}
