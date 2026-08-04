<?php

namespace App\Engines\Studio\Execution\Contracts;

use App\Engines\Studio\Document\StudioElement;

/**
 * STUDIO888 · Execution — Studio document adapter.
 *
 * The ONLY way the executor reads and writes a document. It exposes
 * StudioElements and immutable replacement — never HTML, DOM, a renderer, or
 * persistence. This is the seam the Golden Rule depends on:
 *   Executor → Document Adapter → updated Studio Elements   (never → DOM)
 * A locked element rejects writes unless the write is an unlock.
 */
interface StudioDocumentAdapterInterface
{
    /** @return StudioElement[] */
    public function all(): array;

    public function find(string $id): ?StudioElement;

    /**
     * Immutably replace an element by id (others preserved untouched).
     * Returns false (no change) if the current element is locked and this is
     * not an unlock write.
     */
    public function replace(StudioElement $after, bool $isUnlock = false): bool;

    /**
     * A renderer-neutral snapshot of the whole document (id => field snapshot),
     * for before/after comparison.
     * @return array<string,array>
     */
    public function snapshot(): array;
}
