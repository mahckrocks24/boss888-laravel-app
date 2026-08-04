<?php

namespace App\Engines\Studio\Projection\Support;

use InvalidArgumentException;

/**
 * STUDIO888 · Projection — strict array field extraction for fromArray().
 *
 * The projection protocol is exchanged as plain arrays (future JSON /
 * postMessage). Deserialization must be strict: a missing or wrong-typed field
 * is an explicit error, never a silent default. This guard centralises that.
 * Pure — no I/O, no framework.
 */
final class ArrayGuard
{
    public static function requireSchema(array $a, int $expected): void
    {
        $v = $a['schema_version'] ?? null;
        if (! is_int($v)) {
            throw new InvalidArgumentException("missing/invalid 'schema_version'");
        }
        if ($v !== $expected) {
            throw new InvalidArgumentException("unsupported schema_version {$v} (expected {$expected})");
        }
    }

    public static function str(array $a, string $k): string
    {
        if (! array_key_exists($k, $a) || ! is_string($a[$k])) {
            throw new InvalidArgumentException("missing/invalid string field '{$k}'");
        }

        return $a[$k];
    }

    public static function strOrNull(array $a, string $k): ?string
    {
        if (! array_key_exists($k, $a) || $a[$k] === null) {
            return null;
        }
        if (! is_string($a[$k])) {
            throw new InvalidArgumentException("invalid nullable-string field '{$k}'");
        }

        return $a[$k];
    }

    public static function arr(array $a, string $k): array
    {
        if (! array_key_exists($k, $a) || ! is_array($a[$k])) {
            throw new InvalidArgumentException("missing/invalid array field '{$k}'");
        }

        return $a[$k];
    }

    /** @return string[] */
    public static function strList(array $a, string $k): array
    {
        $list = self::arr($a, $k);
        foreach ($list as $v) {
            if (! is_string($v)) {
                throw new InvalidArgumentException("field '{$k}' must be a list of strings");
            }
        }

        return array_values($list);
    }

    public static function bool(array $a, string $k): bool
    {
        if (! array_key_exists($k, $a) || ! is_bool($a[$k])) {
            throw new InvalidArgumentException("missing/invalid bool field '{$k}'");
        }

        return $a[$k];
    }
}
