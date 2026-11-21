<?php

declare(strict_types=1);

namespace YetiPdf\Write\Image;

/**
 * An image ready to embed as a PDF XObject: pixel size, natural size in points, and the
 * stream plus its dictionary entries (filter, colour space, decode parameters, soft mask).
 */
final class Image
{
    public function __construct(
        public readonly string $key,
        public readonly int $px,
        public readonly int $py,
        public readonly float $dpi,
        public readonly string $data,
        public readonly string $dict,
        public readonly ?Image $smask = null,
        /** EXIF orientation (1-8): how the stored pixels turn to display upright. */
        public readonly int $orientation = 1,
    ) {
    }

    /** Whether the image displays turned a quarter (EXIF orientations 5-8 swap width and height). */
    public function sideways(): bool
    {
        return $this->orientation >= 5;
    }

    /** Natural width in points, as displayed. */
    public function width(): float
    {
        return ($this->sideways() ? $this->py : $this->px) * 72 / $this->dpi;
    }

    /** Natural height in points, as displayed. */
    public function height(): float
    {
        return ($this->sideways() ? $this->px : $this->py) * 72 / $this->dpi;
    }

    /**
     * The unit-square transform (PDF matrix, y up) that turns the stored pixels upright.
     *
     * @return array{0: int, 1: int, 2: int, 3: int, 4: int, 5: int}
     */
    public function orient(): array
    {
        return match ($this->orientation) {
            2 => [-1, 0, 0, 1, 1, 0],   // mirrored
            3 => [-1, 0, 0, -1, 1, 1],  // upside down
            4 => [1, 0, 0, -1, 0, 1],   // mirrored vertically
            5 => [0, -1, -1, 0, 1, 1],  // transposed
            6 => [0, -1, 1, 0, 0, 1],   // turned 90° clockwise
            7 => [0, 1, 1, 0, 0, 0],    // transversed
            8 => [0, 1, -1, 0, 1, 0],   // turned 90° counter-clockwise
            default => [1, 0, 0, 1, 0, 0],
        };
    }
}
