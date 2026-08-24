<?php

namespace Tests\Feature\Chat\Support;

/**
 * The result of one normative conformance case against one surface.
 *
 * Clause V-02/V-03/V-04: `not_applicable` and `unsupported` are distinct from
 * `pass` and MUST NOT be folded into it to improve a score. `blocked` means the
 * harness could not obtain evidence — it is never a pass either.
 */
class Outcome
{
    public const PASS           = 'pass';
    public const FAIL           = 'fail';
    public const NOT_APPLICABLE = 'not_applicable';
    public const UNSUPPORTED    = 'unsupported';
    public const BLOCKED        = 'blocked';

    public function __construct(
        public string $status,
        public string $detail = '',
        public ?string $location = null,
    ) {}

    public static function pass(string $detail = '', ?string $location = null): self
    {
        return new self(self::PASS, $detail, $location);
    }

    public static function fail(string $detail, ?string $location = null): self
    {
        return new self(self::FAIL, $detail, $location);
    }

    public static function notApplicable(string $detail = ''): self
    {
        return new self(self::NOT_APPLICABLE, $detail);
    }

    public static function unsupported(string $detail = ''): self
    {
        return new self(self::UNSUPPORTED, $detail);
    }

    public static function blocked(string $detail): self
    {
        return new self(self::BLOCKED, $detail);
    }

    /** Convenience: pass when true, fail with $detail when false. */
    public static function assert(bool $ok, string $detail, ?string $location = null): self
    {
        return $ok ? self::pass('', $location) : self::fail($detail, $location);
    }

    public function counts(): bool
    {
        return in_array($this->status, [self::PASS, self::FAIL], true);
    }
}
