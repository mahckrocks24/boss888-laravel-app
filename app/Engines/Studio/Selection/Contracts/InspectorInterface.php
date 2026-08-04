<?php

namespace App\Engines\Studio\Selection\Contracts;

use App\Engines\Studio\Document\Contracts\SemanticGraphInterface;

/**
 * STUDIO888 · Selection — Inspector contract.
 *
 * Read-only understanding of a document. It answers questions ("what font is
 * this?", "what overlaps?", "what is hidden?", "what uses the brand colour?")
 * by querying the Semantic Graph and the Selection Engine. It NEVER mutates,
 * NEVER touches HTML, and NEVER executes an edit.
 */
interface InspectorInterface
{
    /** @return array{role:string,font_family:mixed,font_size:mixed,color:mixed,visible:bool,locked:bool}|null */
    public function describe(SemanticGraphInterface $graph, string $elementId): ?array;

    public function fontOf(SemanticGraphInterface $graph, string $elementId): ?string;

    /** @return array<int,array{a:string,b:string}> overlapping element id pairs */
    public function overlaps(SemanticGraphInterface $graph): array;

    /** @return string[] hidden element ids */
    public function hidden(SemanticGraphInterface $graph): array;

    /** @return string[] locked element ids */
    public function lockedElements(SemanticGraphInterface $graph): array;

    /** @return string[] element ids whose colour or background matches (normalized) */
    public function usesColor(SemanticGraphInterface $graph, string $color): array;

    /** @return string[] image-bearing element ids */
    public function images(SemanticGraphInterface $graph): array;

    /** @return string[] element ids with editable text */
    public function editableText(SemanticGraphInterface $graph): array;
}
