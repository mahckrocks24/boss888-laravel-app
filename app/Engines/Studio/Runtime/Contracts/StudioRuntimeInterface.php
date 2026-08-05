<?php

namespace App\Engines\Studio\Runtime\Contracts;

use App\Engines\Studio\Document\Contracts\SemanticGraphInterface;
use App\Engines\Studio\Execution\ExecutionResult;
use App\Engines\Studio\Execution\Operation;
use App\Engines\Studio\Projection\Contracts\StudioProjectionAdapterInterface;
use App\Engines\Studio\Projection\ProjectionCapability;
use App\Engines\Studio\Projection\StudioDocumentVersion;
use App\Engines\Studio\Runtime\Events\RuntimeEventBus;
use App\Engines\Studio\Runtime\RuntimeState;
use App\Engines\Studio\Runtime\RuntimeTransaction;
use App\Engines\Studio\Runtime\Viewport;
use App\Engines\Studio\Selection\SelectionResult;

/**
 * STUDIO888 · Runtime — the Studio Runtime contract.
 *
 * The runtime owns editor SESSION STATE and COORDINATES between the
 * Transformation Engine and a renderer. It never executes AI, mutates HTML,
 * knows the DOM/studio.js/browser/iframe/Canvas, or draws. A renderer is any
 * StudioProjectionAdapterInterface — no concrete renderer is referenced.
 *
 *   Selection → Execution → Projection Bridge → Studio Runtime → Projection Adapter
 *
 * Callers depend on this interface, not the concrete runtime.
 */
interface StudioRuntimeInterface
{
    public function loadDocument(string $documentId, SemanticGraphInterface $graph, ?StudioDocumentVersion $version = null): void;

    public function attachRenderer(StudioProjectionAdapterInterface $renderer): void;

    public function detachRenderer(): void;

    public function currentRenderer(): ?StudioProjectionAdapterInterface;

    public function capabilities(): ?ProjectionCapability;

    public function setSelection(SelectionResult $selection): void;

    public function currentSelection(): ?SelectionResult;

    public function updateViewport(Viewport $viewport): void;

    public function viewport(): Viewport;

    public function beginTransaction(?string $label = null): RuntimeTransaction;

    public function currentTransaction(): ?RuntimeTransaction;

    public function commitTransaction(): ?RuntimeTransaction;

    /** Coordinate one operation through the bridge + current renderer. */
    public function execute(Operation $operation, ?string $idempotencyKey = null): ExecutionResult;

    public function state(): RuntimeState;

    public function events(): RuntimeEventBus;

    public function metadata(string $key, mixed $default = null): mixed;

    public function setMetadata(string $key, mixed $value): void;
}
