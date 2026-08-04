<?php

namespace App\Engines\Studio\Document\Contracts;

use App\Engines\Studio\Document\StudioElement;

/**
 * STUDIO888 · Studio Document Model — Semantic Graph contract.
 *
 * The canonical, renderer-neutral description of a design: elements as nodes,
 * typed meaning/structure/spatial relations as edges. It is THE source the
 * Selection Engine and Inspector query. It never exposes HTML, selectors, or
 * DOM parentage — only StudioElements and RelationType edges.
 */
interface SemanticGraphInterface
{
    public function element(string $id): ?StudioElement;

    /** @return StudioElement[] all nodes */
    public function elements(): array;

    /**
     * Directed relations, optionally filtered by type.
     * @return array<int,array{from:string,type:string,to:string}>
     */
    public function relations(?string $type = null): array;

    /**
     * Elements directly related to $id by $type (following the edge direction).
     * @return StudioElement[]
     */
    public function related(string $id, string $type): array;

    /** True if the directed typed edge exists. */
    public function hasRelation(string $from, string $type, string $to): bool;

    /**
     * Canvas facts (renderer-neutral).
     * @return array{width:float,height:float,fold:float}
     */
    public function canvas(): array;
}
