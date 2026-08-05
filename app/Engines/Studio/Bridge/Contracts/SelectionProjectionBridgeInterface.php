<?php

namespace App\Engines\Studio\Bridge\Contracts;

use App\Engines\Studio\Document\Contracts\SemanticGraphInterface;
use App\Engines\Studio\Execution\ExecutionContext;
use App\Engines\Studio\Execution\ExecutionResult;
use App\Engines\Studio\Execution\Operation;
use App\Engines\Studio\Projection\Contracts\StudioProjectionAdapterInterface;
use App\Engines\Studio\Projection\ProjectionTransactionBoundary;
use App\Engines\Studio\Selection\SelectionResult;

/**
 * STUDIO888 · Bridge — Selection → Execution → Projection contract.
 *
 * Orchestrates the complete pipeline against a Projection ADAPTER INTERFACE:
 *   SelectionResult -> ExecutionPlan -> ProjectionRequest -> ProjectionAdapter
 *   -> ProjectionResult -> ExecutionResult
 *
 * The bridge (and the executor behind it) never knows HTML, DOM, the browser,
 * studio.js, the iframe, or any renderer implementation — only the Projection
 * interface. Callers depend on this contract, not the concrete bridge.
 */
interface SelectionProjectionBridgeInterface
{
    /**
     * Project a single resolved operation.
     *
     * @param string|null $baseVersion the document version the graph/selection was read at;
     *                                  null uses the adapter's current version (optimistic).
     */
    public function project(
        SelectionResult $selection,
        SemanticGraphInterface $graph,
        Operation $operation,
        StudioProjectionAdapterInterface $adapter,
        ExecutionContext $context,
        ?string $baseVersion = null,
        ?string $idempotencyKey = null,
    ): ExecutionResult;

    /**
     * Project a batch of resolved operations under a transaction boundary.
     *
     * @param array<int,array{selection:SelectionResult,operation:Operation,key?:?string}> $intents
     * @return array{results: ExecutionResult[], status: string}
     */
    public function projectBatch(
        array $intents,
        SemanticGraphInterface $graph,
        StudioProjectionAdapterInterface $adapter,
        ExecutionContext $context,
        ProjectionTransactionBoundary $boundary,
    ): array;
}
