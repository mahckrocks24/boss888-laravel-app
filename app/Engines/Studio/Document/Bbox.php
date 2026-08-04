<?php

namespace App\Engines\Studio\Document;

/**
 * STUDIO888 · Studio Document Model — bounding box.
 *
 * Renderer-neutral geometry in canvas coordinates. It carries NO notion of
 * HTML, CSS, pixels-vs-points, or any specific renderer — just a rectangle in
 * the design's own coordinate space. All spatial reasoning (above/below,
 * overlaps, largest, above-the-fold) is expressed against these values.
 */
final class Bbox
{
    public function __construct(
        public readonly float $x,
        public readonly float $y,
        public readonly float $w,
        public readonly float $h,
    ) {
    }

    public static function from(array $a): self
    {
        return new self(
            (float) ($a['x'] ?? 0),
            (float) ($a['y'] ?? 0),
            (float) ($a['w'] ?? 0),
            (float) ($a['h'] ?? 0),
        );
    }

    public function right(): float
    {
        return $this->x + $this->w;
    }

    public function bottom(): float
    {
        return $this->y + $this->h;
    }

    public function centerX(): float
    {
        return $this->x + $this->w / 2;
    }

    public function centerY(): float
    {
        return $this->y + $this->h / 2;
    }

    public function area(): float
    {
        return max(0.0, $this->w) * max(0.0, $this->h);
    }

    /** True when the two rectangles share any interior area. */
    public function intersects(Bbox $o): bool
    {
        return $this->x < $o->right() && $this->right() > $o->x
            && $this->y < $o->bottom() && $this->bottom() > $o->y;
    }

    /** True when this rectangle fully encloses the other. */
    public function encloses(Bbox $o): bool
    {
        return $o->x >= $this->x && $o->y >= $this->y
            && $o->right() <= $this->right() && $o->bottom() <= $this->bottom();
    }

    /** Horizontal ranges overlap (used for above/below adjacency). */
    public function overlapsHorizontally(Bbox $o): bool
    {
        return $this->x < $o->right() && $this->right() > $o->x;
    }

    /** Vertical ranges overlap (used for left/right adjacency). */
    public function overlapsVertically(Bbox $o): bool
    {
        return $this->y < $o->bottom() && $this->bottom() > $o->y;
    }

    public function toArray(): array
    {
        return ['x' => $this->x, 'y' => $this->y, 'w' => $this->w, 'h' => $this->h];
    }
}
