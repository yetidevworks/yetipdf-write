<?php

declare(strict_types=1);

namespace YetiPdf\Write\Layout;

/**
 * Where content may go, Typst-style: a width, the height left in the current region, and the
 * height of each following region (null when content can't continue anywhere, e.g. a cell).
 * expand: whether content should fill the width or shrink to its natural width.
 * fill: whether the frame takes the region's full height (pages, fixed-height blocks), which is
 * what <place> aligns against.
 * queue: heights of the regions right after this one, when they differ from the backlog (a cell
 * spanning rows gets exactly the height its rows take on each page).
 */
final class Regions
{
    public function __construct(
        public readonly float $w,
        public readonly float $h,
        public readonly ?float $backlog,
        public readonly bool $expand = true,
        public readonly bool $fill = false,
        /** @var list<float> */
        public readonly array $queue = [],
    ) {
    }

    public function next(): ?self
    {
        if ($this->backlog === null) {
            return null;
        }
        if ($this->queue) {
            $rest = $this->queue;
            $h = array_shift($rest);
            return new self($this->w, $h, $this->backlog, $this->expand, $this->fill, $rest);
        }
        return new self($this->w, $this->backlog, $this->backlog, $this->expand, $this->fill);
    }

    public static function unbounded(float $w, bool $expand): self
    {
        return new self($w, INF, null, $expand);
    }
}
