<?php

namespace App\Core\Engineer888\Workflow;

/**
 * The outcome of one lifecycle stage.
 *
 * Three states, deliberately. OK continues. BLOCKED means the stage cannot
 * proceed but nothing is wrong — an approval is missing, evidence is absent —
 * and the task waits. FAILED means something is wrong and the workflow halts.
 * Collapsing blocked into failed would make "waiting for a human" look like a
 * defect; collapsing it into ok would let work continue unapproved.
 */
final class StageResult
{
    public const OK = 'ok';
    public const BLOCKED = 'blocked';
    public const FAILED = 'failed';
    public const SKIPPED = 'skipped';

    public function __construct(
        public readonly string $status,
        public readonly string $summary,
        public readonly array $outputs = [],
        public readonly array $evidence = [],
        public readonly ?string $failure = null,
    ) {}

    public static function ok(string $summary, array $outputs = [], array $evidence = []): self
    {
        return new self(self::OK, $summary, $outputs, $evidence);
    }

    public static function blocked(string $summary, string $failure, array $outputs = [], array $evidence = []): self
    {
        return new self(self::BLOCKED, $summary, $outputs, $evidence, $failure);
    }

    public static function failed(string $summary, string $failure, array $outputs = [], array $evidence = []): self
    {
        return new self(self::FAILED, $summary, $outputs, $evidence, $failure);
    }

    public static function skipped(string $summary, string $why): self
    {
        return new self(self::SKIPPED, $summary, [], [], $why);
    }

    public function halts(): bool
    {
        return $this->status === self::FAILED || $this->status === self::BLOCKED;
    }
}
