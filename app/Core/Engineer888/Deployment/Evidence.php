<?php

namespace App\Core\Engineer888\Deployment;

/**
 * One observation, with where it came from and how much it is worth.
 *
 * The distinction this class exists to force: a value and its confidence travel
 * together, always. The runtime reports a version string; that string is a
 * hardcoded literal in index.js and has read 2.37.3 across deployments. Storing
 * it as `version => "2.37.3"` would make it indistinguishable from a fact.
 * Storing it as OBSERVED-not-authoritative keeps it useful without letting it
 * masquerade as proof of what is running.
 */
final class Evidence
{
    /** Verified against an authoritative source that cannot disagree with reality. */
    public const PROVEN = 'PROVEN';

    /** Directly measured, but the source could be stale or self-reported. */
    public const OBSERVED = 'OBSERVED';

    /** Derived from something else; reasonable, not proof. */
    public const INFERRED = 'INFERRED';

    /** Could not be determined. Never a value with a shrug attached. */
    public const UNKNOWN = 'UNKNOWN';

    public function __construct(
        public readonly mixed $value,
        public readonly string $source,
        public readonly string $confidence,
        public readonly ?string $caveat = null,
    ) {}

    public static function proven(mixed $value, string $source, ?string $caveat = null): self
    {
        return new self($value, $source, self::PROVEN, $caveat);
    }

    public static function observed(mixed $value, string $source, ?string $caveat = null): self
    {
        return new self($value, $source, self::OBSERVED, $caveat);
    }

    public static function inferred(mixed $value, string $source, ?string $caveat = null): self
    {
        return new self($value, $source, self::INFERRED, $caveat);
    }

    public static function unknown(string $source, string $reason): self
    {
        return new self(null, $source, self::UNKNOWN, $reason);
    }

    public function isKnown(): bool
    {
        return $this->confidence !== self::UNKNOWN;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'value'      => $this->value,
            'source'     => $this->source,
            'confidence' => $this->confidence,
            'caveat'     => $this->caveat,
        ];
    }
}
