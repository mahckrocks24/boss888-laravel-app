<?php

namespace App\Engines\Studio\Runtime;

use App\Engines\Studio\Execution\ExecutionResult;

/**
 * STUDIO888 · Runtime — a runtime transaction.
 *
 * Every execution belongs to exactly one transaction. A transaction accumulates
 * the ExecutionResults produced under it (each carrying before/after state and
 * reversibility metadata) so a FUTURE history/undo layer can consume them.
 * Phase 5A implements transaction OWNERSHIP only — no undo, no history, no
 * persistence.
 */
final class RuntimeTransaction
{
    public const STATUS_OPEN      = 'open';
    public const STATUS_COMMITTED = 'committed';

    private string $status = self::STATUS_OPEN;

    /** @var ExecutionResult[] */
    private array $results = [];

    public function __construct(
        public readonly string $id,
        public readonly ?string $label = null,
    ) {
    }

    public function record(ExecutionResult $result): void
    {
        $this->results[] = $result;
    }

    public function commit(): void
    {
        $this->status = self::STATUS_COMMITTED;
    }

    public function status(): string
    {
        return $this->status;
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }

    /** @return ExecutionResult[] */
    public function results(): array
    {
        return $this->results;
    }

    public function operationCount(): int
    {
        return count($this->results);
    }

    public function appliedCount(): int
    {
        return count(array_filter($this->results, fn (ExecutionResult $r) => $r->isSuccess()));
    }
}
