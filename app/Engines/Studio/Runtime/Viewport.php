<?php

namespace App\Engines\Studio\Runtime;

/**
 * STUDIO888 · Runtime — pure viewport model.
 *
 * Represents zoom, pan, active page, visible region, and selection box WITHOUT
 * any browser code. Immutable: every mutator returns a new Viewport. Regions
 * are renderer-neutral [x, y, w, h] tuples in canvas coordinates — no DOM, no
 * pixels-vs-points, no Canvas.
 */
final class Viewport
{
    /**
     * @param array{x:float,y:float,w:float,h:float}|null $visibleRegion
     * @param array{x:float,y:float,w:float,h:float}|null $selectionBox
     */
    public function __construct(
        public readonly float $zoom = 1.0,
        public readonly float $panX = 0.0,
        public readonly float $panY = 0.0,
        public readonly int   $page = 1,
        public readonly ?array $visibleRegion = null,
        public readonly ?array $selectionBox = null,
    ) {
    }

    public function withZoom(float $zoom): self
    {
        return new self(max(0.0, $zoom), $this->panX, $this->panY, $this->page, $this->visibleRegion, $this->selectionBox);
    }

    public function withPan(float $panX, float $panY): self
    {
        return new self($this->zoom, $panX, $panY, $this->page, $this->visibleRegion, $this->selectionBox);
    }

    public function withPage(int $page): self
    {
        return new self($this->zoom, $this->panX, $this->panY, max(1, $page), $this->visibleRegion, $this->selectionBox);
    }

    public function withVisibleRegion(?array $region): self
    {
        return new self($this->zoom, $this->panX, $this->panY, $this->page, $region, $this->selectionBox);
    }

    public function withSelectionBox(?array $box): self
    {
        return new self($this->zoom, $this->panX, $this->panY, $this->page, $this->visibleRegion, $box);
    }

    public function toArray(): array
    {
        return [
            'zoom' => $this->zoom, 'pan_x' => $this->panX, 'pan_y' => $this->panY, 'page' => $this->page,
            'visible_region' => $this->visibleRegion, 'selection_box' => $this->selectionBox,
        ];
    }
}
