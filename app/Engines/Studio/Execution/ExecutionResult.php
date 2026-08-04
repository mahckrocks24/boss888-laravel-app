<?php

namespace App\Engines\Studio\Execution;

/**
 * STUDIO888 · Execution — typed execution result.
 *
 * The ONLY proof of what happened. Success is derived from verified before/after
 * state, never from any generated assistant reply. Carries enough before/after
 * and reversibility metadata for Phase 5 to build grouped undo later.
 */
final class ExecutionResult
{
    // status
    public const APPLIED            = 'applied';
    public const PARTIALLY_APPLIED  = 'partially_applied';
    public const FAILED             = 'failed';
    public const AMBIGUOUS          = 'ambiguous';
    public const REJECTED           = 'rejected';

    // failure reason codes (stable identifiers, not user copy)
    public const R_MISSING_TARGET      = 'missing_target';
    public const R_TARGET_NOT_FOUND    = 'target_not_found';
    public const R_UNKNOWN_OPERATION   = 'unknown_operation';
    public const R_UNAVAILABLE         = 'unavailable_operation';
    public const R_UNSUPPORTED_TARGET  = 'unsupported_target';
    public const R_UNSUPPORTED_PROPERTY = 'unsupported_property';
    public const R_LOCKED_TARGET       = 'locked_target';
    public const R_NO_HANDLER          = 'no_handler';
    public const R_VALIDATION_FAILED   = 'validation_failed';
    public const R_AMBIGUOUS_TARGET    = 'ambiguous_target';
    public const R_LOW_CONFIDENCE      = 'low_confidence';
    public const R_NO_CHANGE           = 'no_change';
    public const R_VERIFICATION_FAILED = 'verification_failed';

    public function __construct(
        public readonly string  $operationId,
        public readonly string  $operationType,
        public readonly ?string $targetId,
        public readonly string  $status,
        public readonly ?array  $beforeState = null,
        public readonly ?array  $afterState = null,
        public readonly mixed   $normalizedValue = null,
        public readonly ?string $failureReason = null,
        public readonly string  $explanation = '',
        public readonly bool    $reversible = false,
        public readonly array   $changedFields = [],
        public readonly ?float  $confidence = null,
        public readonly array   $meta = [],
    ) {
    }

    public function isSuccess(): bool
    {
        return $this->status === self::APPLIED || $this->status === self::PARTIALLY_APPLIED;
    }

    public static function rejected(Operation $op, string $reason, string $explanation, ?float $confidence = null): self
    {
        return new self(
            operationId: $op->opId, operationType: $op->type, targetId: $op->targetId,
            status: self::REJECTED, failureReason: $reason, explanation: $explanation, confidence: $confidence,
        );
    }

    public static function ambiguous(Operation $op, string $explanation, ?float $confidence = null): self
    {
        return new self(
            operationId: $op->opId, operationType: $op->type, targetId: null,
            status: self::AMBIGUOUS, failureReason: self::R_AMBIGUOUS_TARGET, explanation: $explanation, confidence: $confidence,
        );
    }

    public function toArray(): array
    {
        return [
            'operation_id'     => $this->operationId,
            'operation_type'   => $this->operationType,
            'target_id'        => $this->targetId,
            'status'           => $this->status,
            'before'           => $this->beforeState,
            'after'            => $this->afterState,
            'normalized_value' => $this->normalizedValue,
            'failure_reason'   => $this->failureReason,
            'explanation'      => $this->explanation,
            'reversible'       => $this->reversible,
            'changed_fields'   => $this->changedFields,
            'confidence'       => $this->confidence,
            'meta'             => $this->meta,
        ];
    }
}
