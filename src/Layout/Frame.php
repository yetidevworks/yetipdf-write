<?php

declare(strict_types=1);

namespace YetiPdf\Write\Layout;

/**
 * A finished piece of layout: a size and positioned items, top-left origin, y down.
 * Items are [x, y, item] where item is a GlyphRun, a Frame, or a drawing array:
 *   ['rect', w, h, fill|null, stroke|null, radius]
 *   ['line', dx, dy, stroke]
 *   ['group', Frame, [a, b, c, d, e, f]]  child frame drawn through a y-down affine matrix
 *   ['image', Image|Svg, w, h, dx, dy, iw, ih, clip]  image drawn at (dx, dy) size iw×ih inside a w×h box
 *   ['path', segments, fill|null, stroke|null], ['clip', segments, evenodd, Frame], ['opacity', Frame, alpha]
 *       vector items from SVG images (see SvgParser and VectorPainting)
 */
final class Frame
{
    /** @var list<array{0: float, 1: float, 2: mixed}> */
    public array $items = [];

    public function __construct(public float $w, public float $h, public ?float $baseline = null)
    {
    }

    public function push(float $x, float $y, mixed $item): void
    {
        $this->items[] = [$x, $y, $item];
    }

    /** Place a child frame; its items are inlined with an offset (keeps the tree flat). */
    public function place(float $x, float $y, Frame $f): void
    {
        foreach ($f->items as [$ix, $iy, $item]) {
            $this->items[] = [$x + $ix, $y + $iy, $item];
        }
    }
}
