<?php

namespace App\Engines\Studio\Projection\Contracts;

use App\Engines\Studio\Projection\ProjectionBatch;
use App\Engines\Studio\Projection\ProjectionBatchResult;
use App\Engines\Studio\Projection\ProjectionCapability;
use App\Engines\Studio\Projection\ProjectionRequest;
use App\Engines\Studio\Projection\ProjectionResult;
use App\Engines\Studio\Projection\StudioDocumentVersion;

/**
 * STUDIO888 · Projection — the stable contract between the abstract executor
 * and any future live renderer.
 *
 *   Executor -> Projection Protocol -> Renderer Adapter -> Render Target
 *
 * The executor depends ONLY on this interface. It never knows whether the
 * target is iframe HTML, Canvas, SVG, PDF, a video timeline, mobile native,
 * presentation, or print. A live implementation is owned by the frontend; this
 * phase ships only the contract and a fake in-memory adapter for tests.
 */
interface StudioProjectionAdapterInterface
{
    /** What this renderer can project (for pre-projection negotiation). */
    public function capability(): ProjectionCapability;

    /** The renderer's current document version (optimistic concurrency). */
    public function currentVersion(): StudioDocumentVersion;

    /** Project a single change and report the ACTUAL applied state. */
    public function project(ProjectionRequest $request): ProjectionResult;

    /** Project a batch under its declared transaction boundary. */
    public function projectBatch(ProjectionBatch $batch): ProjectionBatchResult;
}
