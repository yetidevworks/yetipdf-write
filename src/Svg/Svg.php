<?php

declare(strict_types=1);

namespace YetiPdf\Write\Svg;

use YetiPdf\Write\Layout\Frame;

/**
 * A parsed SVG image: its natural size in points (CSS pixels at 96 dpi) and a frame of vector
 * drawing items covering that size. The writer embeds it once as a Form XObject and draws it
 * wherever an image item uses it, like a raster image.
 */
final class Svg
{
    public function __construct(
        public readonly string $key,
        public readonly float $w,
        public readonly float $h,
        public readonly Frame $frame,
    ) {
    }

    /** Natural width in points. */
    public function width(): float
    {
        return $this->w;
    }

    /** Natural height in points. */
    public function height(): float
    {
        return $this->h;
    }
}
