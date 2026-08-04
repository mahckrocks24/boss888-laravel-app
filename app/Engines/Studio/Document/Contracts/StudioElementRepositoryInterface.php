<?php

namespace App\Engines\Studio\Document\Contracts;

use App\Engines\Studio\Document\StudioElement;

/**
 * STUDIO888 · Studio Document Model — element source.
 *
 * The seam between "where elements come from" and "everything that reasons
 * about them". Phase 2 ships only an in-memory implementation fed by explicit
 * StudioElements. A future phase may add an adapter that projects elements
 * from a persisted document — but NO implementation here parses HTML or reads
 * a renderer. Callers depend on this interface, never a concrete source.
 */
interface StudioElementRepositoryInterface
{
    /** @return StudioElement[] every element in the document */
    public function all(): array;

    public function find(string $id): ?StudioElement;

    /**
     * Canvas facts in renderer-neutral coordinates.
     * @return array{width:float,height:float,fold:float}
     */
    public function canvas(): array;
}
