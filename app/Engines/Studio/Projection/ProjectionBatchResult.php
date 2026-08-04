<?php

namespace App\Engines\Studio\Projection;

use App\Engines\Studio\Projection\Support\ArrayGuard;

/**
 * STUDIO888 · Projection — aggregate result of a projection batch.
 *
 * Reports the per-request results plus an overall status derived from them
 * under the batch's transaction boundary. Serializable.
 */
final class ProjectionBatchResult
{
    public const SCHEMA_VERSION = 1;

    /** @param ProjectionResult[] $results */
    public function __construct(
        public readonly string  $batchId,
        public readonly string  $status,
        public readonly array   $results,
        public readonly ?string $rendererVersion = null,
        public readonly string  $replay = ReplayState::FIRST,
        public readonly string  $correlationId = '',
    ) {
    }

    public function appliedCount(): int
    {
        return count(array_filter($this->results, fn (ProjectionResult $r) => $r->isSuccess()));
    }

    public function withReplay(string $replay): self
    {
        return new self($this->batchId, $this->status, $this->results, $this->rendererVersion, $replay, $this->correlationId);
    }

    public function toArray(): array
    {
        return [
            'schema_version'   => self::SCHEMA_VERSION,
            'batch_id'         => $this->batchId,
            'status'           => $this->status,
            'results'          => array_map(fn (ProjectionResult $r) => $r->toArray(), $this->results),
            'renderer_version' => $this->rendererVersion,
            'replay'           => $this->replay,
            'correlation_id'   => $this->correlationId,
        ];
    }

    public static function fromArray(array $a): self
    {
        ArrayGuard::requireSchema($a, self::SCHEMA_VERSION);

        return new self(
            batchId: ArrayGuard::str($a, 'batch_id'),
            status: ArrayGuard::str($a, 'status'),
            results: array_map(
                fn ($r) => ProjectionResult::fromArray(is_array($r) ? $r : []),
                ArrayGuard::arr($a, 'results')
            ),
            rendererVersion: ArrayGuard::strOrNull($a, 'renderer_version'),
            replay: ArrayGuard::str($a, 'replay'),
            correlationId: ArrayGuard::str($a, 'correlation_id'),
        );
    }
}
