<?php

namespace App\Engines\Studio\Selection;

use App\Engines\Studio\Document\Contracts\SemanticGraphInterface;
use App\Engines\Studio\Document\StudioElement;
use App\Engines\Studio\Selection\Contracts\ElementResolverInterface;

/**
 * STUDIO888 · Selection — default graph element resolver.
 *
 * Applies a structured query against the Semantic Graph and returns scored
 * candidates. Confidence is deterministic and EXPLAINABLE: it rises with query
 * specificity and a clear superlative winner, and falls when several elements
 * compete for a single-target query (that competition is what surfaces as
 * ambiguity upstream). No HTML, no mutation.
 */
final class GraphElementResolver implements ElementResolverInterface
{
    private const IMAGE_ROLES = ['image', 'hero', 'logo'];
    private const EPS = 1e-6;

    public function resolve(SelectionQuery $query, SemanticGraphInterface $graph): array
    {
        $canvas = $graph->canvas();

        // 1. hard filters
        $matches = array_values(array_filter(
            $graph->elements(),
            fn (StudioElement $e) => $this->matchesHard($e, $query, $canvas, $graph)
        ));

        // 2. spatial-relative narrowing ("below the image")
        if ($query->relativeTo !== null) {
            $matches = $this->applyRelativeTo($matches, $query, $graph);
        }

        if ($matches === []) {
            return [];
        }

        // 3. superlative / ordinal narrowing
        $superlativeUnique = false;
        $metricUsed = null;
        if ($query->superlative !== null) {
            [$matches, $superlativeUnique, $metricUsed] = $this->applySuperlative($matches, $query);
        }
        if ($query->ordinal !== null) {
            $matches = $this->applyOrdinal($matches, $query->ordinal);
        }

        if ($matches === []) {
            return [];
        }

        // 4. score
        return $this->score($matches, $query, $superlativeUnique, $metricUsed);
    }

    private function matchesHard(StudioElement $e, SelectionQuery $q, array $canvas, SemanticGraphInterface $graph): bool
    {
        if ($q->role !== null && $e->role !== $q->role) {
            return false;
        }
        foreach ($q->visualTags as $t) {
            if (! $e->hasVisualTag($t)) {
                return false;
            }
        }
        foreach ($q->semanticTags as $t) {
            if (! $e->hasSemanticTag($t)) {
                return false;
            }
        }
        if ($q->text !== null) {
            if ($e->text === null || stripos($e->text, $q->text) === false) {
                return false;
            }
        }
        if ($q->mediaType !== null && ! $this->matchesMedia($e, $q->mediaType)) {
            return false;
        }
        if ($q->visible !== null && $e->visible !== $q->visible) {
            return false;
        }
        if ($q->locked !== null && $e->locked !== $q->locked) {
            return false;
        }
        if ($q->selected !== null && $e->selected !== $q->selected) {
            return false;
        }
        if ($q->region !== null && ! $this->matchesRegion($e, $canvas, $q->region)) {
            return false;
        }
        if ($q->withinId !== null && ! $this->isDescendant($e, $q->withinId, $graph)) {
            return false;
        }

        return true;
    }

    private function matchesMedia(StudioElement $e, string $mediaType): bool
    {
        if (($e->style('media_type')) === $mediaType) {
            return true;
        }

        return $mediaType === 'image' && in_array($e->role, self::IMAGE_ROLES, true);
    }

    private function matchesRegion(StudioElement $e, array $canvas, string $region): bool
    {
        $fold = (float) ($canvas['fold'] ?? 600.0);
        $w = (float) ($canvas['width'] ?? 1080.0);
        $h = (float) ($canvas['height'] ?? 1080.0);
        $cx = $e->bbox->centerX();
        $cy = $e->bbox->centerY();

        return match ($region) {
            'above_fold' => $cy <= $fold,
            'below_fold' => $cy > $fold,
            'top'        => $cy < $h / 2,
            'bottom'     => $cy >= $h / 2,
            'left'       => $cx < $w / 2,
            'right'      => $cx >= $w / 2,
            default      => true,
        };
    }

    private function isDescendant(StudioElement $e, string $ancestorId, SemanticGraphInterface $graph): bool
    {
        $cur = $e->parent;
        $guard = 0;
        while ($cur !== null && $guard++ < 100) {
            if ($cur === $ancestorId) {
                return true;
            }
            $cur = $graph->element($cur)?->parent;
        }

        return false;
    }

    /**
     * @param StudioElement[] $matches
     * @return StudioElement[]
     */
    private function applyRelativeTo(array $matches, SelectionQuery $q, SemanticGraphInterface $graph): array
    {
        $relation = $q->relativeTo['relation'] ?? null;
        $anchorRole = $q->relativeTo['role'] ?? null;
        if ($relation === null || $anchorRole === null) {
            return $matches;
        }

        $anchors = array_filter($graph->elements(), fn (StudioElement $a) => $a->role === $anchorRole);
        if ($anchors === []) {
            return [];
        }

        return array_values(array_filter($matches, function (StudioElement $e) use ($anchors, $relation) {
            foreach ($anchors as $a) {
                if ($e->id === $a->id) {
                    continue;
                }
                $ok = match ($relation) {
                    'below' => $e->bbox->centerY() > $a->bbox->centerY() && $e->bbox->overlapsHorizontally($a->bbox),
                    'above' => $e->bbox->centerY() < $a->bbox->centerY() && $e->bbox->overlapsHorizontally($a->bbox),
                    'left'  => $e->bbox->centerX() < $a->bbox->centerX() && $e->bbox->overlapsVertically($a->bbox),
                    'right' => $e->bbox->centerX() > $a->bbox->centerX() && $e->bbox->overlapsVertically($a->bbox),
                    default => false,
                };
                if ($ok) {
                    return true;
                }
            }

            return false;
        }));
    }

    /**
     * @param StudioElement[] $matches
     * @return array{0:StudioElement[],1:bool,2:?string} [winners, unique, metric]
     */
    private function applySuperlative(array $matches, SelectionQuery $q): array
    {
        $metric = $q->metric ?? $this->defaultMetric($matches);
        $smallest = in_array($q->superlative, ['smallest'], true);

        $best = null;
        foreach ($matches as $e) {
            $v = $this->metricValue($e, $metric);
            $best = $best === null ? $v : ($smallest ? min($best, $v) : max($best, $v));
        }

        $winners = array_values(array_filter(
            $matches,
            fn (StudioElement $e) => abs($this->metricValue($e, $metric) - $best) <= self::EPS
        ));

        return [$winners, count($winners) === 1, $metric];
    }

    /** @param StudioElement[] $matches */
    private function defaultMetric(array $matches): string
    {
        foreach ($matches as $e) {
            if ($e->fontSize() === null) {
                return 'area';
            }
        }

        return 'font_size';
    }

    private function metricValue(StudioElement $e, string $metric): float
    {
        return match ($metric) {
            'font_size' => $e->fontSize() ?? 0.0,
            'height'    => $e->bbox->h,
            'width'     => $e->bbox->w,
            default     => $e->bbox->area(),
        };
    }

    /**
     * @param StudioElement[] $matches
     * @return StudioElement[]
     */
    private function applyOrdinal(array $matches, int $ordinal): array
    {
        usort($matches, function (StudioElement $a, StudioElement $b) {
            if (abs($a->bbox->y - $b->bbox->y) > self::EPS) {
                return $a->bbox->y <=> $b->bbox->y;
            }

            return $a->bbox->x <=> $b->bbox->x;
        });

        $idx = $ordinal - 1;

        return isset($matches[$idx]) ? [$matches[$idx]] : [];
    }

    /**
     * @param StudioElement[] $matches
     * @return Candidate[]
     */
    private function score(array $matches, SelectionQuery $q, bool $superlativeUnique, ?string $metric): array
    {
        $crit = max(1, $q->criterionCount());
        $single = min(0.98, 0.70 + 0.07 * min($crit, 4));
        if ($superlativeUnique) {
            $single = max($single, 0.92);
        }

        $n = count($matches);
        $isGroup = $q->expectMultiple;
        $perConfidence = ($isGroup || $n === 1) ? $single : $single / $n;

        $candidates = [];
        foreach ($matches as $e) {
            $candidates[] = new Candidate(
                $e->id,
                round($perConfidence, 4),
                $this->reason($e, $q, $n, $isGroup, $superlativeUnique, $metric),
            );
        }

        usort($candidates, function (Candidate $a, Candidate $b) use ($matches) {
            if (abs($a->confidence - $b->confidence) > self::EPS) {
                return $b->confidence <=> $a->confidence;
            }

            return $this->readingIndex($a->elementId, $matches) <=> $this->readingIndex($b->elementId, $matches);
        });

        return $candidates;
    }

    /** @param StudioElement[] $matches */
    private function readingIndex(string $id, array $matches): float
    {
        foreach ($matches as $e) {
            if ($e->id === $id) {
                return $e->bbox->y * 100000 + $e->bbox->x;
            }
        }

        return INF;
    }

    private function reason(StudioElement $e, SelectionQuery $q, int $n, bool $isGroup, bool $superlativeUnique, ?string $metric): string
    {
        $parts = [];
        if ($q->role !== null) {
            $parts[] = "role={$q->role}";
        }
        foreach ($q->visualTags as $t) {
            $parts[] = $t;
        }
        foreach ($q->semanticTags as $t) {
            $parts[] = $t;
        }
        if ($q->text !== null) {
            $parts[] = "text~\"{$q->text}\"";
        }
        if ($q->region !== null) {
            $parts[] = $q->region;
        }
        if ($superlativeUnique && $metric !== null) {
            $parts[] = "{$q->superlative} by {$metric}";
        }
        if ($q->ordinal !== null) {
            $parts[] = "#{$q->ordinal} in reading order";
        }
        $crit = $parts === [] ? 'any element' : implode(' + ', $parts);

        if ($isGroup) {
            return "group member: $crit";
        }
        if ($n === 1) {
            return "single candidate: $crit";
        }

        return "1 of $n candidates matching: $crit";
    }
}
