<?php

declare(strict_types=1);

namespace YetiPdf\Write\Layout;

use YetiPdf\Write\Font\Face;

/**
 * Positioned glyphs on one baseline: list of [gid, advance in pt, source text, x offset, y offset]
 * (offsets in pt, y up, only on glyphs that have them: placed marks).
 *
 * $level is the bidi embedding level: an odd level is right-to-left text, whose glyphs stay in
 * reading order until its line is reordered for display.
 */
final class GlyphRun
{
    public function __construct(
        public readonly Face $face,
        public readonly float $size,
        public readonly array $color,
        public array $glyphs,
        public float $width,
        public int $level = 0,
        /** Whether any glyph stands for a combining mark (the PDF then groups letters and marks). */
        public bool $marks = false,
    ) {
    }

    /** Set on a body <page-number> run, so the next layout pass can find its page. */
    public ?int $marker = null;
}
