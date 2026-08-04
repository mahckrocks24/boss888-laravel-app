<?php

namespace App\Engines\Studio\Execution\Contracts;

use App\Engines\Studio\Execution\ExecutionContext;
use App\Engines\Studio\Execution\ExecutionResult;
use App\Engines\Studio\Execution\Operation;
use App\Engines\Studio\Selection\SelectionResult;

/**
 * STUDIO888 · Execution — Operation Executor.
 *
 * Enforces capability + validation, runs the handler through the document
 * adapter, verifies the change, and returns a typed ExecutionResult. It never
 * touches HTML/DOM, never persists, never bills, and never trusts a reply as
 * proof. Callers depend on this interface, not the concrete executor.
 */
interface OperationExecutorInterface
{
    /** Execute against an already-resolved target id. */
    public function execute(Operation $op, StudioDocumentAdapterInterface $adapter, ExecutionContext $ctx): ExecutionResult;

    /**
     * Execute against a Selection Engine result. RESOLVED (meeting the
     * confidence threshold) executes; AMBIGUOUS / NOT_FOUND / low-confidence
     * refuse — there is no silent fallback target.
     */
    public function executeWithSelection(Operation $op, SelectionResult $selection, StudioDocumentAdapterInterface $adapter, ExecutionContext $ctx): ExecutionResult;
}
