<?php

namespace App\Engines\Studio\Execution\Contracts;

use App\Engines\Studio\Document\StudioElement;
use App\Engines\Studio\Execution\Operation;

/**
 * STUDIO888 · Execution — execution verifier.
 *
 * Compares the actual after-state to the handler's expected changes and decides
 * the real status. A change that did not happen is FAILED; a match that changed
 * nothing (a no-op) is FAILED, never a fabricated success.
 */
interface ExecutionVerifierInterface
{
    /**
     * @return array{status:string,changed_fields:string[],explanation:string}
     */
    public function verify(Operation $op, OperationHandlerInterface $handler, StudioElement $before, StudioElement $after): array;
}
