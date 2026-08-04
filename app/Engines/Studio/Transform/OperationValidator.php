<?php

namespace App\Engines\Studio\Transform;

use App\Engines\Studio\Transform\Contracts\ColorNormalizerInterface;
use App\Engines\Studio\Transform\Contracts\OperationRegistryInterface;
use App\Engines\Studio\Transform\Contracts\OperationValidatorInterface;

/**
 * STUDIO888 · AI Transformation Engine — default Operation Validator.
 *
 * Decides, per operation, whether it is well-formed, registered, available,
 * targeted structurally (never by a raw selector), property-legal, in-range,
 * and free of injection — and normalizes values (colours, units) as it goes.
 * It NEVER resolves targets, mutates a design, or executes. All verdicts are
 * data (see ValidationResult); only a structurally malformed batch is an error.
 *
 * Value kinds are resolved from operation + property metadata, so there is no
 * per-operation branching — one loop, driven by the registry.
 */
final class OperationValidator implements OperationValidatorInterface
{
    /** CSS-valued injection tokens (strict — applied to colour/length/number/enum/media). */
    private const CSS_UNSAFE = ['javascript:', 'url(', 'expression(', 'calc(', 'var(', '<', '>', ';', '{', '}', '/*', '\\', '@import'];

    /** Script/markup injection tokens (applied to free text copy, which tolerates ordinary punctuation). */
    private const TEXT_UNSAFE = ['javascript:', '<script', '</', 'onerror=', 'onload=', 'onclick=', 'expression(', 'url(', 'data:text/html'];

    /** Canonical CSS-property → value-kind map (metadata about CSS, not per-op duplication). */
    private const PROPERTY_KIND = [
        'color' => OperationDefinition::KIND_COLOR,
        'background-color' => OperationDefinition::KIND_COLOR,
        'border-color' => OperationDefinition::KIND_COLOR,
        'fill' => OperationDefinition::KIND_COLOR,
        'stroke' => OperationDefinition::KIND_COLOR,
        'border-width' => OperationDefinition::KIND_LENGTH,
        'font-size' => OperationDefinition::KIND_LENGTH,
        'line-height' => OperationDefinition::KIND_LENGTH,
        'letter-spacing' => OperationDefinition::KIND_LENGTH,
        'border-radius' => OperationDefinition::KIND_LENGTH,
        'opacity' => OperationDefinition::KIND_NUMBER,
        'font-weight' => OperationDefinition::KIND_TEXT,
        'font-family' => OperationDefinition::KIND_TEXT,
        'text-align' => OperationDefinition::KIND_TEXT,
        'text-decoration' => OperationDefinition::KIND_TEXT,
        'text-transform' => OperationDefinition::KIND_TEXT,
        'box-shadow' => OperationDefinition::KIND_TEXT,
    ];

    private const DEFAULT_LENGTH_UNITS = ['px', '%', 'rem', 'em'];

    public function __construct(
        private readonly OperationRegistryInterface $registry,
        private readonly ColorNormalizerInterface $colors,
    ) {
    }

    public function validate(array $batch): ValidationResult
    {
        if (! array_key_exists('operations', $batch) || ! is_array($batch['operations'])) {
            return ValidationResult::malformed(ValidationResult::R_MALFORMED_BATCH);
        }

        $version = $batch['version'] ?? null;
        if ($version === null || ! is_int($version)) {
            return ValidationResult::malformed(ValidationResult::R_MALFORMED_BATCH);
        }
        if ($version !== $this->registry->schemaVersion()) {
            return ValidationResult::malformed(ValidationResult::R_UNSUPPORTED_VERSION);
        }

        $results = [];
        $allOk = true;

        foreach ($batch['operations'] as $i => $op) {
            $entry = $this->validateOperation(is_array($op) ? $op : [], $i);
            $results[] = $entry;
            if ($entry['result'] !== 'accepted') {
                $allOk = false;
            }
        }

        return new ValidationResult($allOk, $results);
    }

    private function validateOperation(array $op, int $index): array
    {
        $opId = $op['op_id'] ?? ('op_' . $index);
        $type = $op['type'] ?? null;
        $property = $op['property'] ?? null;

        $reject = fn (string $reason): array => [
            'op_id' => $opId, 'type' => $type, 'property' => $property,
            'result' => 'rejected', 'normalized_value' => null, 'failure_reason' => $reason,
        ];

        if (! is_string($type) || $type === '') {
            return $reject(ValidationResult::R_MISSING_TYPE);
        }

        $def = $this->registry->get($type);
        if ($def === null) {
            return $reject(ValidationResult::R_UNKNOWN_OPERATION);
        }
        if (! $def->isAvailable()) {
            return $reject(ValidationResult::R_UNAVAILABLE);
        }

        // Target must be present and STRUCTURED — never a raw selector string.
        if (! array_key_exists('target', $op) || $op['target'] === null || $op['target'] === '') {
            return $reject(ValidationResult::R_MISSING_TARGET);
        }
        if (is_string($op['target'])) {
            return $reject(ValidationResult::R_RAW_SELECTOR);
        }

        // Property legality for property-bearing operations.
        if ($def->hasPropertyConstraint()) {
            if (! is_string($property) || $property === '') {
                return $reject(ValidationResult::R_MISSING_PROPERTY);
            }
            if (str_starts_with($property, '--')) {
                return $reject(ValidationResult::R_CUSTOM_PROPERTY);
            }
            if (! $def->allowsProperty($property)) {
                return $reject(ValidationResult::R_PROPERTY_NOT_ALLOWED);
            }
        }

        // Resolve the effective value kind from property first, then the op default.
        $kind = ($def->hasPropertyConstraint() && is_string($property) && isset(self::PROPERTY_KIND[$property]))
            ? self::PROPERTY_KIND[$property]
            : $def->valueKind;

        $value = $op['value'] ?? null;

        [$ok, $normalized, $reason] = $this->validateValue($kind, $value, $def);
        if (! $ok) {
            return $reject($reason);
        }

        return [
            'op_id' => $opId, 'type' => $type, 'property' => $property,
            'result' => 'accepted', 'normalized_value' => $normalized, 'failure_reason' => null,
        ];
    }

    /** @return array{0:bool,1:mixed,2:string} [ok, normalized_value, failure_reason] */
    private function validateValue(string $kind, mixed $value, OperationDefinition $def): array
    {
        if ($kind === OperationDefinition::KIND_NONE) {
            return [true, null, ''];
        }

        // Structured media reference is handled separately (may be array or ref string).
        if ($kind === OperationDefinition::KIND_MEDIA) {
            return $this->validateMedia($value);
        }

        if (! is_scalar($value)) {
            return [false, null, ValidationResult::R_INVALID_VALUE];
        }
        $s = trim((string) $value);
        if ($s === '') {
            return [false, null, ValidationResult::R_INVALID_VALUE];
        }

        // Injection guard.
        $unsafe = $kind === OperationDefinition::KIND_TEXT ? self::TEXT_UNSAFE : self::CSS_UNSAFE;
        $low = strtolower($s);
        foreach ($unsafe as $bad) {
            if (str_contains($low, $bad)) {
                return [false, null, ValidationResult::R_UNSAFE_VALUE];
            }
        }

        switch ($kind) {
            case OperationDefinition::KIND_COLOR:
                $hex = $this->colors->normalize($s);
                return $hex === null ? [false, null, ValidationResult::R_INVALID_COLOR] : [true, $hex, ''];

            case OperationDefinition::KIND_ENUM:
                return in_array($s, $def->enum, true)
                    ? [true, $s, '']
                    : [false, null, ValidationResult::R_INVALID_ENUM];

            case OperationDefinition::KIND_TEXT:
                // Reject control characters; otherwise accept the trimmed copy.
                if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $s)) {
                    return [false, null, ValidationResult::R_INVALID_VALUE];
                }
                return [true, $s, ''];

            case OperationDefinition::KIND_LENGTH:
                return $this->validateNumeric($s, $def, self::DEFAULT_LENGTH_UNITS, true);

            case OperationDefinition::KIND_ANGLE:
                return $this->validateNumeric($s, $def, ['deg'], true);

            case OperationDefinition::KIND_PERCENT:
                return $this->validateNumeric($s, $def, ['%'], true);

            case OperationDefinition::KIND_NUMBER:
                return $this->validateNumeric($s, $def, [], false);
        }

        return [false, null, ValidationResult::R_INVALID_VALUE];
    }

    /** @return array{0:bool,1:mixed,2:string} */
    private function validateNumeric(string $s, OperationDefinition $def, array $defaultUnits, bool $unitAllowed): array
    {
        if (! preg_match('/^(-?\d+(?:\.\d+)?)\s*([a-z%]*)$/', strtolower($s), $m)) {
            return [false, null, ValidationResult::R_INVALID_VALUE];
        }

        $num = (float) $m[1];
        $unit = $m[2];

        $units = $def->range['units'] ?? $defaultUnits;

        if (! $unitAllowed) {
            if ($unit !== '') {
                return [false, null, ValidationResult::R_INVALID_UNIT];
            }
        } else {
            if ($unit === '') {
                // A bare number is only valid where an empty unit is explicitly allowed.
                if (! in_array('', $units, true)) {
                    return [false, null, ValidationResult::R_INVALID_UNIT];
                }
            } elseif (! in_array($unit, $units, true)) {
                return [false, null, ValidationResult::R_INVALID_UNIT];
            }
        }

        if (isset($def->range['min']) && $num < $def->range['min']) {
            return [false, null, ValidationResult::R_OUT_OF_RANGE];
        }
        if (isset($def->range['max']) && $num > $def->range['max']) {
            return [false, null, ValidationResult::R_OUT_OF_RANGE];
        }

        $canonical = rtrim(rtrim(sprintf('%.4f', $num), '0'), '.') . $unit;

        return [true, $canonical, ''];
    }

    /** @return array{0:bool,1:mixed,2:string} */
    private function validateMedia(mixed $value): array
    {
        // A media reference must be a Studio-hosted asset id/ref — never an arbitrary URL.
        if (is_array($value)) {
            $ref = $value['asset_id'] ?? ($value['ref'] ?? null);
        } else {
            $ref = $value;
        }

        if (! is_string($ref) || trim($ref) === '') {
            return [false, null, ValidationResult::R_INVALID_VALUE];
        }
        $ref = trim($ref);

        $low = strtolower($ref);
        if (str_starts_with($low, 'http://') || str_starts_with($low, 'https://') || str_starts_with($low, 'data:') || str_contains($low, 'javascript:')) {
            return [false, null, ValidationResult::R_UNSAFE_VALUE];
        }

        return [true, $ref, ''];
    }
}
