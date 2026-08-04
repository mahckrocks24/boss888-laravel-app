<?php

namespace App\Engines\Studio\Transform;

/**
 * STUDIO888 · AI Transformation Engine — Validation Result.
 *
 * The structured outcome of validating an operation batch. Immutable and
 * side-effect free. Each entry mirrors the input operation plus the validator's
 * verdict (accepted|rejected), the canonical normalized_value, and — on
 * rejection — a machine-readable failure reason. The Validator NEVER throws for
 * a bad operation; it reports. Only a structurally malformed batch is an error.
 */
final class ValidationResult
{
    /** Rejection reason codes (stable identifiers, not user copy). */
    public const R_MALFORMED_BATCH      = 'malformed_batch';
    public const R_UNSUPPORTED_VERSION  = 'unsupported_schema_version';
    public const R_MISSING_TYPE         = 'missing_operation_type';
    public const R_UNKNOWN_OPERATION    = 'unknown_operation';
    public const R_UNAVAILABLE          = 'unavailable_operation';
    public const R_MISSING_TARGET       = 'missing_target';
    public const R_RAW_SELECTOR         = 'raw_selector_rejected';
    public const R_MISSING_PROPERTY     = 'missing_property';
    public const R_PROPERTY_NOT_ALLOWED = 'property_not_allowed';
    public const R_CUSTOM_PROPERTY      = 'unregistered_custom_property';
    public const R_INVALID_COLOR        = 'invalid_color';
    public const R_INVALID_VALUE        = 'invalid_value';
    public const R_INVALID_UNIT         = 'invalid_unit';
    public const R_OUT_OF_RANGE         = 'out_of_range';
    public const R_INVALID_ENUM         = 'invalid_enum_value';
    public const R_UNSAFE_VALUE         = 'unsafe_value';

    /**
     * @param bool     $ok         true only if the batch is structurally valid AND every op accepted
     * @param array[]  $operations per-op verdicts (see accepted()/rejected() entry shape)
     * @param string[] $errors     batch-level structural errors (empty unless malformed)
     */
    public function __construct(
        public readonly bool  $ok,
        public readonly array $operations = [],
        public readonly array $errors = [],
    ) {
    }

    public static function malformed(string $reason): self
    {
        return new self(false, [], [$reason]);
    }

    /** @return array[] only the operations the validator accepted */
    public function accepted(): array
    {
        return array_values(array_filter($this->operations, fn ($o) => ($o['result'] ?? null) === 'accepted'));
    }

    /** @return array[] only the operations the validator rejected */
    public function rejected(): array
    {
        return array_values(array_filter($this->operations, fn ($o) => ($o['result'] ?? null) === 'rejected'));
    }

    public function isMalformed(): bool
    {
        return $this->errors !== [];
    }
}
