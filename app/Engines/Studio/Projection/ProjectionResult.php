<?php

namespace App\Engines\Studio\Projection;

use App\Engines\Studio\Projection\Support\ArrayGuard;

/**
 * STUDIO888 · Projection — the renderer-neutral result of a projection.
 *
 * Reports the ACTUAL applied state read back from the renderer, never mere
 * acceptance. The abstract verifier compares desired vs actual to decide real
 * success. Serializable for JSON / postMessage exchange with the frontend owner.
 */
final class ProjectionResult
{
    public const SCHEMA_VERSION = 1;

    // error reason codes (stable identifiers)
    public const R_STALE_VERSION       = 'stale_version';
    public const R_SNAPSHOT_MISMATCH   = 'snapshot_mismatch';
    public const R_UNSUPPORTED_PROPERTY = 'unsupported_property';
    public const R_UNSUPPORTED_OPERATION = 'unsupported_operation';
    public const R_UNSUPPORTED_TARGET  = 'unsupported_target';
    public const R_TARGET_MISSING      = 'target_missing';
    public const R_IN_PROGRESS         = 'in_progress';
    public const R_EXPIRED             = 'expired';
    public const R_NO_CHANGE           = 'no_change';
    public const R_VERIFICATION_FAILED = 'verification_failed';
    public const R_ROLLED_BACK         = 'rolled_back';
    public const R_SKIPPED             = 'skipped';
    public const R_MISSING_FIELD       = 'missing_field';

    /**
     * @param string[]            $appliedFields
     * @param string[]            $rejectedFields
     * @param array<string,mixed> $actualAfterState
     * @param array               $verification  e.g. {verified:bool, matched:int, total:int}
     */
    public function __construct(
        public readonly string  $status,
        public readonly string  $operationId,
        public readonly string  $targetId,
        public readonly array   $appliedFields = [],
        public readonly array   $rejectedFields = [],
        public readonly ?string $rendererVersion = null,
        public readonly array   $actualAfterState = [],
        public readonly array   $verification = [],
        public readonly ?string $errorReason = null,
        public readonly string  $explanation = '',
        public readonly float   $durationMs = 0.0,
        public readonly string  $replay = ReplayState::FIRST,
        public readonly string  $correlationId = '',
    ) {
    }

    public function isSuccess(): bool
    {
        return ProjectionStatus::isSuccess($this->status);
    }

    /** Return a copy with a different replay state (for cached-replay reporting). */
    public function withReplay(string $replay): self
    {
        return new self(
            $this->status, $this->operationId, $this->targetId, $this->appliedFields, $this->rejectedFields,
            $this->rendererVersion, $this->actualAfterState, $this->verification, $this->errorReason,
            $this->explanation, $this->durationMs, $replay, $this->correlationId,
        );
    }

    public function toArray(): array
    {
        return [
            'schema_version'    => self::SCHEMA_VERSION,
            'status'            => $this->status,
            'operation_id'      => $this->operationId,
            'target_id'         => $this->targetId,
            'applied_fields'    => $this->appliedFields,
            'rejected_fields'   => $this->rejectedFields,
            'renderer_version'  => $this->rendererVersion,
            'actual_after_state' => $this->actualAfterState,
            'verification'      => $this->verification,
            'error_reason'      => $this->errorReason,
            'explanation'       => $this->explanation,
            'duration_ms'       => $this->durationMs,
            'replay'            => $this->replay,
            'correlation_id'    => $this->correlationId,
        ];
    }

    public static function fromArray(array $a): self
    {
        ArrayGuard::requireSchema($a, self::SCHEMA_VERSION);

        return new self(
            status: ArrayGuard::str($a, 'status'),
            operationId: ArrayGuard::str($a, 'operation_id'),
            targetId: ArrayGuard::str($a, 'target_id'),
            appliedFields: ArrayGuard::strList($a, 'applied_fields'),
            rejectedFields: ArrayGuard::strList($a, 'rejected_fields'),
            rendererVersion: ArrayGuard::strOrNull($a, 'renderer_version'),
            actualAfterState: ArrayGuard::arr($a, 'actual_after_state'),
            verification: ArrayGuard::arr($a, 'verification'),
            errorReason: ArrayGuard::strOrNull($a, 'error_reason'),
            explanation: ArrayGuard::str($a, 'explanation'),
            durationMs: (float) ($a['duration_ms'] ?? 0.0),
            replay: ArrayGuard::str($a, 'replay'),
            correlationId: ArrayGuard::str($a, 'correlation_id'),
        );
    }
}
