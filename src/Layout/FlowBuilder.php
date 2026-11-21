<?php

declare(strict_types=1);

namespace YetiPdf\Write\Layout;

/**
 * Stacks block-level content into regions. Spacing follows Typst: the spacing below one item and
 * above the next are weak and collapse into one, the stronger kind winning (weak <v> beats
 * spacing set on a block or through block-spacing, which beats the paragraph spacing a block
 * falls back to) and the larger of two of the same kind; strong <v> adds on top of whatever wins.
 * Weak spacing at the top of a region is dropped. Content that doesn't fit moves to the next
 * region, unless the region is empty.
 */
final class FlowBuilder
{
    private const EPS = 0.01;

    /** Kinds of weak spacing, weakest first. */
    public const PAR = 1;
    public const BLOCK = 2;
    public const WEAK_V = 3;

    /** @var list<Frame> */
    private array $frames = [];
    /** @var list<array{0: Frame, 1: float, 2: string}> placed children: frame, y, align */
    private array $placed = [];
    /** @var list<array{0: Frame, 1: string, 2: string, 3: float, 4: float, 5: int}> out-of-flow: frame, h-align, v-align, dx, dy, position in document order */
    private array $floats = [];
    private float $y = 0.0;
    /** Weak spacing waiting for the next item: [amount, kind]. */
    private ?array $weak = null;
    /** Whether anything (content or strong spacing) is in this region. */
    private bool $started = false;
    /** Whether strong spacing, even zero, is in this region (content placed out of flow before it stays here). */
    private bool $spaced = false;
    /** Whether content is in this region (weak spacing needs some above it). */
    private bool $content = false;
    private ?float $baseline = null;
    private Regions $region;

    /**
     * With $sink, each finished region's frame goes to it as soon as it's built instead of being
     * kept (how a document's pages are written while later ones are still being laid out).
     */
    public function __construct(private readonly Regions $first, private readonly ?\Closure $sink = null)
    {
        $this->region = $first;
    }

    public function width(): float
    {
        return $this->region->w;
    }

    public function expand(): bool
    {
        return $this->region->expand;
    }

    /** Of two weak spacings ([amount, kind]), the one that wins: the stronger kind, else the larger. */
    private static function stronger(?array $a, array $b): array
    {
        return $a === null || $b[1] > $a[1] || ($b[1] === $a[1] && $b[0] > $a[0]) ? $b : $a;
    }

    /** The weak spacing before the next item, given the spacing above it. */
    private function gap(array $above): float
    {
        return $this->content ? self::stronger($this->weak, $above)[0] : 0.0;
    }

    private function canBreak(): bool
    {
        return $this->started && $this->region->backlog !== null;
    }

    private function place(Frame $f, float $y, string $align): void
    {
        if ($this->baseline === null && $f->baseline !== null) {
            $this->baseline = $y + $f->baseline;
        }
        $this->placed[] = [$f, $y, $align];
        $this->started = true;
        $this->content = true;
        $this->weak = null;
    }

    /** Out-of-flow content (<place>): positioned against the finished region, takes no space. */
    public function float(Frame $f, string $halign, string $valign, float $dx, float $dy): void
    {
        $this->floats[] = [$f, $halign, $valign, $dx, $dy, count($this->placed)];
    }

    public function spacing(float $amount, bool $weak): void
    {
        if ($weak) {
            if ($this->content) {
                $this->weak = self::stronger($this->weak, [$amount, self::WEAK_V]);
            }
            return;
        }
        // Strong spacing adds on top of the weak spacing around it, which still collapses across it.
        $this->y += $amount;
        $this->started = $this->started || $amount != 0.0;
        $this->spaced = true;
    }

    public function pageBreak(bool $weak): void
    {
        if ($this->region->backlog === null) {
            return;
        }
        if ($weak && !$this->started) {
            return;
        }
        $this->nextRegion(true);
    }

    /** @param list<Frame> $lines */
    public function paragraph(array $lines, float $leading, float $spacing, string $align): void
    {
        // Widows and orphans, as in Typst: the first line needs room for the first two, the
        // second-to-last for the last two, and the first line of a three-line paragraph for all
        // three. (A line from an empty <br/> doesn't count.) The group moves to the next region
        // when it doesn't fit here but would there.
        $len = count($lines);
        $orphans = $len >= 2 && $lines[1]->items !== [];
        $widows = $len >= 2 && $lines[$len - 2]->items !== [];
        foreach ($lines as $i => $line) {
            $gap = $i === 0 ? $this->gap([$spacing, self::PAR]) : $leading;
            $need = match (true) {
                $i === 0 && $len === 3 && $orphans && $widows => $line->h + $leading + $lines[1]->h + $leading + $lines[2]->h,
                $i === 0 && $orphans => $line->h + $leading + $lines[1]->h,
                $i >= 2 && $i + 2 === $len && $widows => $line->h + $leading + $lines[$i + 1]->h,
                default => $line->h,
            };
            // Moving the group on helps only when there's a next region and it offers more room:
            // after content here, or when this region is already partly used by what's around it.
            $movable = $this->region->backlog !== null && ($this->started || $this->region->h < $this->region->backlog - self::EPS);
            if (($this->canBreak() && $this->y + $gap + $line->h > $this->region->h + self::EPS)
                || ($movable && $this->y + $gap + $need > $this->region->h + self::EPS && $need <= $this->region->backlog + self::EPS)) {
                // Nothing here yet (not even strong spacing): content placed out of flow goes along
                // to the next region.
                $this->nextRegion(carryFloats: !$this->started && !$this->spaced);
                $gap = 0.0;
            }
            $this->y += $gap;
            $this->place($line, $this->y, 'left');
            $this->y += $line->h;
        }
        $this->weak = [$spacing, self::PAR];
    }

    /**
     * @param callable(Regions): list<Frame> $layout
     * @param array{0: float, 1: int} $above spacing above: [amount, kind]
     * @param array{0: float, 1: int} $below spacing below: [amount, kind]
     */
    public function block(callable $layout, array $above, array $below, string $align): void
    {
        $gap = $this->gap($above);
        $frames = $layout($this->sub($gap));
        if ($frames && $this->canBreak() && $frames[0]->h > $this->region->h - $this->y - $gap + self::EPS) {
            // Doesn't fit what's left: start a fresh region and lay out again there.
            $this->nextRegion();
            $gap = 0.0;
            $frames = $layout($this->sub(0.0));
        }
        $untouched = !$this->started && !$this->spaced;
        foreach ($frames as $i => $f) {
            if ($i > 0) {
                // A block whose first piece is empty (its content all moved on) takes content placed
                // out of flow before it along, when nothing else is here yet.
                $this->nextRegion(carryFloats: $i === 1 && $untouched && $frames[0]->items === [] && $frames[0]->h == 0.0);
                $gap = 0.0;
            }
            $this->y += $gap;
            $this->place($f, $this->y, $align);
            $this->y += $f->h;
        }
        $this->weak = $below;
    }

    private function sub(float $gap): Regions
    {
        $r = $this->region;
        return new Regions($r->w, $r->h - $this->y - $gap, $r->backlog, $r->expand, false, $r->queue);
    }

    private function nextRegion(bool $forced = false, bool $carryFloats = false): void
    {
        $next = $this->region->next();
        $carry = $carryFloats && $next !== null ? $this->floats : [];
        $this->done($this->build($carry === []));
        if ($next === null) {
            return;
        }
        $this->region = $next;
        $this->placed = [];
        $this->floats = $carry;
        $this->y = 0.0;
        $this->weak = null;
        $this->started = false;
        $this->spaced = false;
        $this->content = false;
        $this->baseline = null;
    }

    private function build(bool $floats = true): Frame
    {
        $natural = 0.0;
        foreach ($this->placed as [$f]) {
            $natural = max($natural, $f->w);
        }
        $w = $this->region->expand && is_finite($this->region->w) ? $this->region->w : $natural;
        $h = $this->region->fill && is_finite($this->region->h) ? max($this->region->h, $this->y) : $this->y;
        $frame = new Frame($w, $h, $this->baseline);
        // Floats and flow content are drawn in document order (a watermark placed first sits
        // underneath, a stamp placed last sits on top).
        $floats = $floats ? $this->floats : [];
        if (!$floats) {
            foreach ($this->placed as [$f, $y, $align]) {
                $x = match ($align) {
                    'right' => $w - $f->w,
                    'center' => ($w - $f->w) / 2,
                    default => 0.0,
                };
                $frame->place($x, $y, $f);
            }
            return $frame;
        }
        $fi = 0;
        $fn = count($floats);
        foreach ($this->placed as $i => [$f, $y, $align]) {
            while ($fi < $fn && $floats[$fi][5] <= $i) {
                [$fl, $ha, $va, $dx, $dy] = $floats[$fi++];
                $fx = match ($ha) { 'right' => $w - $fl->w, 'center' => ($w - $fl->w) / 2, default => 0.0 };
                $fy = match ($va) { 'bottom' => $h - $fl->h, 'horizon' => ($h - $fl->h) / 2, default => 0.0 };
                $frame->place($fx + $dx, $fy + $dy, $fl);
            }
            $x = match ($align) {
                'right' => $w - $f->w,
                'center' => ($w - $f->w) / 2,
                default => 0.0,
            };
            $frame->place($x, $y, $f);
        }
        while ($fi < $fn) {
            [$fl, $ha, $va, $dx, $dy] = $floats[$fi++];
            $fx = match ($ha) { 'right' => $w - $fl->w, 'center' => ($w - $fl->w) / 2, default => 0.0 };
            $fy = match ($va) { 'bottom' => $h - $fl->h, 'horizon' => ($h - $fl->h) / 2, default => 0.0 };
            $frame->place($fx + $dx, $fy + $dy, $fl);
        }
        return $frame;
    }

    /** @return list<Frame> */
    public function finish(): array
    {
        $this->done($this->build());
        return $this->frames;
    }

    private function done(Frame $frame): void
    {
        if ($this->sink !== null) {
            ($this->sink)($frame);
        } else {
            $this->frames[] = $frame;
        }
    }
}
