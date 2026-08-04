<?php

namespace App\Engines\Studio\Execution;

use App\Engines\Studio\Execution\Contracts\OperationHandlerInterface;
use App\Engines\Studio\Execution\Handlers\LockOperationHandler;
use App\Engines\Studio\Execution\Handlers\SetStyleOperationHandler;
use App\Engines\Studio\Execution\Handlers\TextOperationHandler;
use App\Engines\Studio\Execution\Handlers\VisibilityOperationHandler;

/**
 * STUDIO888 · Execution — handler registry.
 *
 * Maps an operation type to its handler by asking each handler which types it
 * supports (self-registration). There is NO central switch: adding an operation
 * later means registering a new handler, not editing control flow here.
 */
final class OperationHandlerRegistry
{
    /** @var array<string, OperationHandlerInterface> */
    private array $byType = [];

    /** @param OperationHandlerInterface[] $handlers */
    public function __construct(array $handlers = [])
    {
        foreach ($handlers as $h) {
            $this->register($h);
        }
    }

    public function register(OperationHandlerInterface $handler): void
    {
        foreach ($handler->supportedTypes() as $type) {
            $this->byType[$type] = $handler;
        }
    }

    public function handlerFor(string $type): ?OperationHandlerInterface
    {
        return $this->byType[$type] ?? null;
    }

    public function has(string $type): bool
    {
        return isset($this->byType[$type]);
    }

    /** @return string[] */
    public function types(): array
    {
        return array_keys($this->byType);
    }

    /** The default Phase-3A safe handler set. */
    public static function withDefaults(): self
    {
        return new self([
            new TextOperationHandler(),
            new SetStyleOperationHandler(),
            new VisibilityOperationHandler(),
            new LockOperationHandler(),
        ]);
    }
}
