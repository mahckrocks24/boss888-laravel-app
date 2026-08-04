<?php

namespace App\Engines\Studio\Transform\Contracts;

use App\Engines\Studio\Transform\ValidationResult;

/**
 * STUDIO888 · AI Transformation Engine — Operation Validator contract.
 *
 * Validates an operation batch against the registry + security policy BEFORE
 * anything executes. It never mutates a design, never resolves targets, never
 * executes — it only decides whether each operation is well-formed, allowed,
 * available, and safe, and it normalizes values (e.g. colours) in place.
 */
interface OperationValidatorInterface
{
    /**
     * Validate a full operation batch.
     *
     * @param array $batch { version:int, operations: array<int, array> }
     */
    public function validate(array $batch): ValidationResult;
}
