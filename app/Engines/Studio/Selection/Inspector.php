<?php

namespace App\Engines\Studio\Selection;

use App\Engines\Studio\Document\Contracts\SemanticGraphInterface;
use App\Engines\Studio\Document\RelationType;
use App\Engines\Studio\Document\StudioElement;
use App\Engines\Studio\Selection\Contracts\InspectorInterface;
use App\Engines\Studio\Selection\Contracts\SelectionEngineInterface;

/**
 * STUDIO888 · Selection — default Inspector (read-only).
 *
 * Answers questions ABOUT a document without changing it. Structural questions
 * (overlaps, hidden, locked) read the Semantic Graph directly; element-finding
 * questions (images) go through the Selection Engine so inspection and editing
 * speak the same language. No mutation, no HTML, no execution.
 */
final class Inspector implements InspectorInterface
{
    public function __construct(
        private readonly SelectionEngineInterface $selection,
    ) {
    }

    public function describe(SemanticGraphInterface $graph, string $elementId): ?array
    {
        $e = $graph->element($elementId);
        if ($e === null) {
            return null;
        }

        return [
            'role'        => $e->role,
            'font_family' => $e->style('font_family'),
            'font_size'   => $e->style('font_size'),
            'color'       => $e->style('color'),
            'visible'     => $e->visible,
            'locked'      => $e->locked,
        ];
    }

    public function fontOf(SemanticGraphInterface $graph, string $elementId): ?string
    {
        $v = $graph->element($elementId)?->style('font_family');

        return is_string($v) ? $v : null;
    }

    public function overlaps(SemanticGraphInterface $graph): array
    {
        $seen = [];
        $out = [];
        foreach ($graph->relations(RelationType::OVERLAPS) as $edge) {
            $a = $edge['from'];
            $b = $edge['to'];
            $key = $a < $b ? "$a|$b" : "$b|$a";
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = ['a' => $a < $b ? $a : $b, 'b' => $a < $b ? $b : $a];
        }

        return $out;
    }

    public function hidden(SemanticGraphInterface $graph): array
    {
        return $this->ids($graph, fn (StudioElement $e) => ! $e->visible);
    }

    public function lockedElements(SemanticGraphInterface $graph): array
    {
        return $this->ids($graph, fn (StudioElement $e) => $e->locked);
    }

    public function usesColor(SemanticGraphInterface $graph, string $color): array
    {
        $target = $this->normColor($color);

        return $this->ids($graph, function (StudioElement $e) use ($target) {
            foreach (['color', 'background_color'] as $key) {
                $v = $e->style($key);
                if (is_string($v) && $this->normColor($v) === $target) {
                    return true;
                }
            }

            return false;
        });
    }

    public function images(SemanticGraphInterface $graph): array
    {
        // Uses the Selection Engine — inspection and editing share one vocabulary.
        $result = $this->selection->select(new SelectionQuery(mediaType: 'image', expectMultiple: true), $graph);

        return $result->elementIds();
    }

    public function editableText(SemanticGraphInterface $graph): array
    {
        return $this->ids(
            $graph,
            fn (StudioElement $e) => $e->text !== null && in_array('set_text', $e->capabilities, true)
        );
    }

    /** @return string[] */
    private function ids(SemanticGraphInterface $graph, callable $pred): array
    {
        $out = [];
        foreach ($graph->elements() as $e) {
            if ($pred($e)) {
                $out[] = $e->id;
            }
        }

        return $out;
    }

    private function normColor(string $c): string
    {
        $c = strtolower(trim($c));
        if (preg_match('/^#([0-9a-f]{3})$/', $c, $m)) {
            $h = $m[1];

            return '#' . $h[0] . $h[0] . $h[1] . $h[1] . $h[2] . $h[2];
        }

        return $c;
    }
}
