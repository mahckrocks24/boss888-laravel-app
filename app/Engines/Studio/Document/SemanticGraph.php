<?php

namespace App\Engines\Studio\Document;

use App\Engines\Studio\Document\Contracts\SemanticGraphInterface;
use App\Engines\Studio\Document\Contracts\StudioElementRepositoryInterface;

/**
 * STUDIO888 · Studio Document Model — Semantic Graph (default).
 *
 * Derives typed relations from element METADATA and geometry — parentage,
 * grouping, role meaning, and spatial arrangement. It reads StudioElements
 * only; there is no HTML, CSS, selector, or DOM anywhere in this derivation.
 * The same graph would result whatever renderer produced the elements.
 */
final class SemanticGraph implements SemanticGraphInterface
{
    /** @var array<string, StudioElement> */
    private array $nodes = [];

    /** @var array<int, array{from:string,type:string,to:string}> */
    private array $edges = [];

    /** @var array<string, true> dedup + hasRelation lookup, key "from|type|to" */
    private array $edgeSet = [];

    /**
     * @param StudioElement[] $elements
     * @param array{width?:float,height?:float,fold?:float} $canvas
     */
    public function __construct(
        array $elements,
        private array $canvas = ['width' => 1080.0, 'height' => 1080.0, 'fold' => 600.0],
        private readonly float $adjacencyGap = 48.0,
    ) {
        foreach ($elements as $e) {
            $this->nodes[$e->id] = $e;
        }
        $this->canvas = [
            'width'  => (float) ($this->canvas['width'] ?? 1080.0),
            'height' => (float) ($this->canvas['height'] ?? 1080.0),
            'fold'   => (float) ($this->canvas['fold'] ?? 600.0),
        ];
        $this->build();
    }

    public static function fromRepository(StudioElementRepositoryInterface $repo, float $adjacencyGap = 48.0): self
    {
        return new self($repo->all(), $repo->canvas(), $adjacencyGap);
    }

    // ---- SemanticGraphInterface ----

    public function element(string $id): ?StudioElement
    {
        return $this->nodes[$id] ?? null;
    }

    public function elements(): array
    {
        return array_values($this->nodes);
    }

    public function relations(?string $type = null): array
    {
        if ($type === null) {
            return $this->edges;
        }

        return array_values(array_filter($this->edges, fn ($e) => $e['type'] === $type));
    }

    public function related(string $id, string $type): array
    {
        $out = [];
        foreach ($this->edges as $e) {
            if ($e['from'] === $id && $e['type'] === $type && isset($this->nodes[$e['to']])) {
                $out[] = $this->nodes[$e['to']];
            }
        }

        return $out;
    }

    public function hasRelation(string $from, string $type, string $to): bool
    {
        return isset($this->edgeSet["$from|$type|$to"]);
    }

    public function canvas(): array
    {
        return $this->canvas;
    }

    // ---- derivation ----

    private function addEdge(string $from, string $type, string $to): void
    {
        if ($from === $to || ! isset($this->nodes[$from]) || ! isset($this->nodes[$to])) {
            return;
        }
        $key = "$from|$type|$to";
        if (isset($this->edgeSet[$key])) {
            return;
        }
        $this->edgeSet[$key] = true;
        $this->edges[] = ['from' => $from, 'type' => $type, 'to' => $to];
    }

    private function build(): void
    {
        $this->buildExplicit();
        $this->buildStructural();
        $this->buildRoleMeaning();
        $this->buildSpatial();
    }

    private function buildExplicit(): void
    {
        foreach ($this->nodes as $e) {
            foreach ($e->relationships as $rel) {
                $type = $rel['type'] ?? null;
                $to = $rel['target'] ?? null;
                if (is_string($type) && is_string($to)) {
                    $this->addEdge($e->id, $type, $to);
                }
            }
        }
    }

    private function buildStructural(): void
    {
        foreach ($this->nodes as $e) {
            if ($e->parent !== null && isset($this->nodes[$e->parent])) {
                $this->addEdge($e->parent, RelationType::CONTAINS, $e->id);
                $this->addEdge($e->id, RelationType::BELONGS_TO, $e->parent);
            }
            foreach ($e->children as $childId) {
                if (isset($this->nodes[$childId])) {
                    $this->addEdge($e->id, RelationType::CONTAINS, $childId);
                    $this->addEdge($childId, RelationType::BELONGS_TO, $e->id);
                }
            }
        }

        // grouped_with: co-children of a role=group container.
        foreach ($this->nodes as $g) {
            if ($g->role !== 'group') {
                continue;
            }
            $members = $this->childIds($g);
            foreach ($members as $a) {
                foreach ($members as $b) {
                    if ($a !== $b) {
                        $this->addEdge($a, RelationType::GROUPED_WITH, $b);
                    }
                }
            }
        }
    }

    private function buildRoleMeaning(): void
    {
        foreach ($this->nodes as $e) {
            $parent = $e->parent !== null ? ($this->nodes[$e->parent] ?? null) : null;
            $anchor = $parent?->id;
            if ($anchor === null) {
                continue;
            }

            switch ($e->role) {
                case 'headline':
                    $this->addEdge($e->id, RelationType::HEADLINE_OF, $anchor);
                    break;
                case 'cta':
                    $this->addEdge($e->id, RelationType::BUTTON_FOR, $anchor);
                    break;
                case 'image':
                case 'hero':
                case 'logo':
                    $this->addEdge($e->id, RelationType::IMAGE_OF, $anchor);
                    break;
                case 'background':
                    $this->addEdge($e->id, RelationType::BACKGROUND_OF, $anchor);
                    break;
                case 'caption':
                case 'label':
                    $img = $this->nearestSiblingImage($e);
                    $this->addEdge($e->id, RelationType::CAPTION_OF, $img ?? $anchor);
                    break;
            }
        }
    }

    private function buildSpatial(): void
    {
        $els = $this->elements();
        $n = count($els);
        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                $a = $els[$i];
                $b = $els[$j];

                // Containment is structural, not spatial adjacency/overlap.
                if ($a->bbox->encloses($b->bbox) || $b->bbox->encloses($a->bbox)) {
                    continue;
                }

                if ($a->bbox->intersects($b->bbox)) {
                    $this->addEdge($a->id, RelationType::OVERLAPS, $b->id);
                    $this->addEdge($b->id, RelationType::OVERLAPS, $a->id);
                    continue;
                }

                // Vertical stacking (share horizontal range).
                if ($a->bbox->overlapsHorizontally($b->bbox)) {
                    [$upper, $lower] = $a->bbox->centerY() <= $b->bbox->centerY() ? [$a, $b] : [$b, $a];
                    $this->addEdge($upper->id, RelationType::ABOVE, $lower->id);
                    $this->addEdge($lower->id, RelationType::BELOW, $upper->id);
                    if ($lower->bbox->y - $upper->bbox->bottom() <= $this->adjacencyGap) {
                        $this->addEdge($a->id, RelationType::ADJACENT_TO, $b->id);
                        $this->addEdge($b->id, RelationType::ADJACENT_TO, $a->id);
                    }
                    continue;
                }

                // Horizontal arrangement (share vertical range).
                if ($a->bbox->overlapsVertically($b->bbox)) {
                    [$left, $rightEl] = $a->bbox->centerX() <= $b->bbox->centerX() ? [$a, $b] : [$b, $a];
                    $this->addEdge($left->id, RelationType::LEFT_OF, $rightEl->id);
                    $this->addEdge($rightEl->id, RelationType::RIGHT_OF, $left->id);
                    if ($rightEl->bbox->x - $left->bbox->right() <= $this->adjacencyGap) {
                        $this->addEdge($a->id, RelationType::ADJACENT_TO, $b->id);
                        $this->addEdge($b->id, RelationType::ADJACENT_TO, $a->id);
                    }
                }
            }
        }
    }

    /** @return string[] */
    private function childIds(StudioElement $g): array
    {
        $ids = [];
        foreach ($g->children as $c) {
            if (isset($this->nodes[$c])) {
                $ids[] = $c;
            }
        }
        foreach ($this->nodes as $e) {
            if ($e->parent === $g->id && ! in_array($e->id, $ids, true)) {
                $ids[] = $e->id;
            }
        }

        return $ids;
    }

    private function nearestSiblingImage(StudioElement $e): ?string
    {
        $best = null;
        $bestDist = INF;
        foreach ($this->nodes as $other) {
            if ($other->id === $e->id || $other->parent !== $e->parent) {
                continue;
            }
            if (! in_array($other->role, ['image', 'hero', 'logo'], true)) {
                continue;
            }
            $dx = $other->bbox->centerX() - $e->bbox->centerX();
            $dy = $other->bbox->centerY() - $e->bbox->centerY();
            $dist = $dx * $dx + $dy * $dy;
            if ($dist < $bestDist) {
                $bestDist = $dist;
                $best = $other->id;
            }
        }

        return $best;
    }
}
