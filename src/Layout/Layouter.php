<?php

declare(strict_types=1);

namespace YetiPdf\Write\Layout;

use YetiPdf\Write\Font\FontBook;
use YetiPdf\Write\Image\ImageLoader;
use YetiPdf\Write\Node;
use YetiPdf\Write\PdfException;
use YetiPdf\Write\Style;
use YetiPdf\Write\Text\Bidi;
use YetiPdf\Write\Text\Hyphenator;
use YetiPdf\Write\Value;

/**
 * Turns the element tree into frames. The model follows Typst: content flows into regions,
 * block-level content stacks with collapsing spacing, inline content becomes paragraphs,
 * and grids break between rows with repeated headers.
 */
final class Layouter
{
    private const INLINE = ['text', 'span', 'b', 'i', 'strong', 'em', 'link', 'a', 'box', 'br', 'h', 'page-number', 'page-count'];
    private const LINK_SCHEMES = ['http', 'https', 'mailto', 'tel'];
    /** Elements that are block-level in a flow but can also sit inline inside <text> or <box>. */
    private const ATOMIC = ['image', 'rotate'];
    private const EPS = 0.01;
    private const ZERO_GLUE = ['w' => 0.0];

    public int $pageNumber = 0;
    public int $pageCount = 1;
    /** Laying out page furniture (header, footer, background), where the page number is known. */
    public bool $furniture = false;
    /** @var array<int, int> body <page-number> node id => page it landed on in the last pass */
    public array $pageOf = [];
    /** Whether <page-count> appears in the body (which then needs a second pass). */
    public bool $countInBody = false;

    /** @param array<string, array<string,string>> $named */
    public function __construct(
        private readonly FontBook $fonts,
        private readonly array $named,
        private readonly ?ImageLoader $images = null,
    ) {
    }

    // ------------------------------------------------------------------ flow

    /**
     * Lay out a sequence of children (in a container whose style is $st) into regions.
     *
     * @param list<Node|string> $children
     * @param ?\Closure(Frame): void $sink takes each region's frame as it's finished (then none are returned)
     * @return list<Frame> one per region used
     */
    public function flow(array $children, array $st, Regions $r, ?\Closure $sink = null): array
    {
        // Fast path: a single text node in an unbounded region (the common case for table cells
        // and simple blocks) avoids FlowBuilder and flowItems() overhead.
        if ($sink === null && count($children) === 1 && is_string($children[0]) && $r->backlog === null && !$r->fill) {
            if (trim($children[0]) === '') {
                return [new Frame($r->expand && is_finite($r->w) ? $r->w : 0.0, 0.0)];
            }
            $par = $this->paragraph([['text', $children[0], $st]], $st, $r->w, $r->expand);
            $lines = $par['lines'];
            if (count($lines) === 1) {
                $line = $lines[0];
                if ($r->expand && is_finite($r->w)) {
                    $line->w = $r->w;
                }
                return [$line];
            }
            $leading = $par['leading'];
            $natural = 0.0;
            $y = 0.0;
            $baseline = null;
            foreach ($lines as $i => $line) {
                if ($i > 0) {
                    $y += $leading;
                }
                if ($baseline === null && $line->baseline !== null) {
                    $baseline = $y + $line->baseline;
                }
                $natural = max($natural, $line->w);
                $y += $line->h;
            }
            $w = $r->expand && is_finite($r->w) ? $r->w : $natural;
            $frame = new Frame($w, $y, $baseline);
            $y = 0.0;
            foreach ($lines as $i => $line) {
                if ($i > 0) {
                    $y += $leading;
                }
                $frame->place(0.0, $y, $line);
                $y += $line->h;
            }
            return [$frame];
        }
        $b = new FlowBuilder($r, $sink);
        foreach ($this->flowItems($children, $st) as $item) {
            switch ($item[0]) {
                case 'par':
                    $this->placeParagraph($b, $item[1], $item[2]);
                    break;
                case 'v':
                    $b->spacing($item[1], $item[2]);
                    break;
                case 'pagebreak':
                    $b->pageBreak($item[1]);
                    break;
                case 'place':
                    $this->placeFloat($b, $item[1], $item[2]);
                    break;
                case 'node':
                    $this->placeBlock($b, $item[1], $item[2]);
                    break;
            }
        }
        return $b->finish();
    }

    private const INLINE_MAP = [
        'text' => true, 'span' => true, 'b' => true, 'i' => true, 'strong' => true, 'em' => true,
        'link' => true, 'a' => true, 'box' => true, 'br' => true, 'h' => true, 'page-number' => true, 'page-count' => true,
    ];

    /** Group inline content into paragraphs; everything else is block-level. */
    private function flowItems(array $children, array $st): array
    {
        $items = [];
        $inline = [];
        $hasContent = false;
        foreach ($children as $c) {
            if (is_string($c)) {
                $inline[] = ['text', $c, $st];
                if (!$hasContent && trim($c) !== '') {
                    $hasContent = true;
                }
                continue;
            }
            $type = $c->type;
            if (isset(self::INLINE_MAP[$type])) {
                $before = count($inline);
                $this->collectInline($c, $st, $inline);
                if (!$hasContent) {
                    for ($k = $before, $n = count($inline); $k < $n; $k++) {
                        if ($inline[$k][0] !== 'text' || trim($inline[$k][1]) !== '') {
                            $hasContent = true;
                            break;
                        }
                    }
                }
            } elseif ($type === 'style' || $type === 'page') {
                continue;
            } else {
                if ($hasContent) {
                    $items[] = ['par', $inline, $st];
                }
                $inline = [];
                $hasContent = false;
                if ($type === 'v') {
                    $items[] = ['v', Value::abs($c->attr('size', '0pt'), $st['size']), $c->bool('weak')];
                } elseif ($type === 'pagebreak') {
                    $items[] = ['pagebreak', $c->bool('weak')];
                } elseif ($type === 'place') {
                    $items[] = ['place', $c, $st];
                } else {
                    $items[] = ['node', $c, $st];
                }
            }
        }
        if ($hasContent) {
            $items[] = ['par', $inline, $st];
        }
        return $items;
    }

    private function collectInline(Node $n, array $st, array &$out): void
    {
        switch ($n->type) {
            case 'br':
                $out[] = ['br', '', $st];
                return;
            case 'h':
                $out[] = ['h', $n->attr('size', '0pt'), $st];
                return;
            case 'box':
            case 'image':
            case 'rotate':
                $out[] = ['box', $n, $st];
                return;
            case 'page-number':
                // In page furniture the page is known; in the body it comes from the previous
                // layout pass, and the run is tagged so the next pass can see where it landed.
                $pst = Style::apply($st, $n, $this->named);
                if ($this->furniture) {
                    $out[] = ['text', (string) $this->pageNumber, $pst];
                } else {
                    $pst['marker'] = spl_object_id($n);
                    $out[] = ['text', (string) ($this->pageOf[$pst['marker']] ?? 1), $pst];
                }
                return;
            case 'page-count':
                if (!$this->furniture) {
                    $this->countInBody = true;
                }
                $out[] = ['text', (string) $this->pageCount, Style::apply($st, $n, $this->named)];
                return;
        }
        // Which element last set the style: text keeps shaping as one only across elements that
        // set nothing (a bare <text> or <span>), as in Typst; one restating an inherited value
        // still separates it.
        if (!in_array($n->type, ['text', 'span'], true) || $n->attrs !== []) {
            $st['origin'] = spl_object_id($n);
        }
        if ($n->type === 'link' || $n->type === 'a') {
            $st['link'] = $this->href($n);
        }
        if ($n->type === 'b' || $n->type === 'strong') {
            $st['weight'] = 700;
        } elseif ($n->type === 'i' || $n->type === 'em') {
            $st['italic'] = true;
        }
        $st = Style::apply($st, $n, $this->named);
        foreach ($n->children as $c) {
            if (is_string($c)) {
                $out[] = ['text', $c, $st];
            } elseif (in_array($c->type, self::INLINE, true) || in_array($c->type, self::ATOMIC, true)) {
                $this->collectInline($c, $st, $out);
            } else {
                throw new PdfException("<{$c->type}> is block-level and can't go inside <{$n->type}> (line {$c->line}); use <box> for inline blocks");
            }
        }
    }

    private function placeParagraph(FlowBuilder $b, array $items, array $st): void
    {
        $par = $this->paragraph($items, $st, $b->width(), $b->expand());
        $b->paragraph($par['lines'], $par['leading'], $par['spacing'], Style::halign($st));
    }

    private function placeBlock(FlowBuilder $b, Node $n, array $st): void
    {
        $own = Style::apply($st, $n, $this->named);
        // As in Typst, blocks default to block-spacing when set, else the paragraph spacing; spacing
        // set either way on the block or through block-spacing beats the paragraph fallback.
        $def = $own['blockSpacing'] !== null
            ? [Value::resolve($own['blockSpacing'], $own['size']), FlowBuilder::BLOCK]
            : [Value::resolve($own['parSpacing'], $own['size']), FlowBuilder::PAR];
        $spacing = $n->has('spacing') ? [Value::abs($n->attrs['spacing'], $own['size']), FlowBuilder::BLOCK] : $def;
        $above = $n->has('above') ? [Value::abs($n->attrs['above'], $own['size']), FlowBuilder::BLOCK] : $spacing;
        $below = $n->has('below') ? [Value::abs($n->attrs['below'], $own['size']), FlowBuilder::BLOCK] : $spacing;
        // Placed by its alignment, start and end following the text direction (a justified block
        // sits at the start).
        $align = Style::side($own['align'] === 'justify' ? 'start' : $own['align'], Style::rtl($own));
        $b->block(fn (Regions $r) => $this->blockLevel($n, $st, $r), $above, $below, $align);
    }

    /** @return list<Frame> */
    public function blockLevel(Node $n, array $st, Regions $r): array
    {
        return match ($n->type) {
            'block', 'p', 'section', 'div' => $this->block($n, $st, $r),
            'grid', 'table' => (new GridLayout($this, $this->named))->layout($n, $st, $r),
            'line' => [$this->line($n, $st, $r)],
            'image' => [$this->image($n, $st, $r->w)],
            'rotate' => [$this->rotate($n, $st, $r->w)],
            default => throw new PdfException("Unknown element <{$n->type}> (line {$n->line})"),
        };
    }

    // ------------------------------------------------------------------ block & box

    /** @return list<Frame> */
    private function block(Node $n, array $parent, Regions $r): array
    {
        $st = Style::apply($parent, $n, $this->named);
        $size = $st['size'];
        $width = $n->has('width') ? Value::abs($n->attrs['width'], $size, is_finite($r->w) ? $r->w : 0) : null;
        $height = $n->has('height') ? Value::abs($n->attrs['height'], $size, is_finite($r->h) ? $r->h : 0) : null;
        [$it, $ir, $ib, $il] = Value::sides($n, 'inset', $size, $width ?? (is_finite($r->w) ? $r->w : 0));
        $fill = $n->has('fill') ? Value::color($n->attrs['fill']) : null;
        $stroke = Value::stroke($n->attr('stroke'), $size);
        $radius = $n->has('radius') ? Value::abs($n->attrs['radius'], $size) : 0.0;
        $breakable = $n->bool('breakable', true) && $height === null && $r->backlog !== null;

        $outerW = $width ?? $r->w;
        $innerW = $outerW - $il - $ir;
        $expand = $width !== null || $r->expand;
        $insetY = $it + $ib;
        $inner = match (true) {
            // A set height is the region's (so content placed at the bottom goes to its bottom).
            $height !== null => new Regions($innerW, $height - $insetY, null, $expand, true),
            $breakable => new Regions($innerW, $r->h - $insetY, $r->backlog - $insetY, $expand, false, array_map(fn ($h) => $h - $insetY, $r->queue)),
            default => Regions::unbounded($innerW, $expand),
        };

        if ($n->attr('fit') === 'shrink' && is_finite($innerW)) {
            $natural = $this->flow($n->children, $st, Regions::unbounded(INF, false));
            $nw = $natural[0]->w ?? 0;
            if ($nw > $innerW + self::EPS) {
                $st['scale'] *= $innerW / $nw * 0.98;
            }
        }

        $frames = $this->flow($n->children, $st, $inner);
        $out = [];
        foreach ($frames as $i => $f) {
            $w = $expand && is_finite($outerW) ? $outerW : $f->w + $il + $ir;
            if ($i === 0 && count($frames) > 1 && $f->items === []) {
                // Nothing starts in this region (its first lines moved on, e.g. to avoid an
                // orphan): leave no empty box behind, the whole block starts in the next one.
                $out[] = new Frame($w, 0);
                continue;
            }
            $h = $height ?? $f->h + $insetY;
            $frame = new Frame($w, $h, $f->baseline !== null ? $it + $f->baseline : null);
            if ($fill !== null || $stroke !== null) {
                $frame->push(0, 0, ['rect', $w, $h, $fill, $stroke, $radius]);
            }
            $frame->place($il, $it, $f);
            $out[] = $frame;
        }
        return $out;
    }

    /** Inline box: laid out as a unit, sits on the baseline (shifted by `baseline`). */
    private function box(Node $n, array $parent, float $avail): Frame
    {
        $st = Style::apply($parent, $n, $this->named);
        $size = $st['size'] * $st['scale'];
        $width = $n->has('width') ? Value::abs($n->attrs['width'], $size, is_finite($avail) ? $avail : 0) : null;
        $height = $n->has('height') ? Value::abs($n->attrs['height'], $size) : null;
        [$it, $ir, $ib, $il] = Value::sides($n, 'inset', $size, $width ?? 0);
        $fill = $n->has('fill') ? Value::color($n->attrs['fill']) : null;
        $stroke = Value::stroke($n->attr('stroke'), $size);
        $radius = $n->has('radius') ? Value::abs($n->attrs['radius'], $size) : 0.0;
        $innerW = $width !== null ? $width - $il - $ir : $avail;
        $frames = $this->flow($n->children, $st, Regions::unbounded($innerW, $width !== null));
        $f = $frames[0] ?? new Frame(0, 0);
        $w = $width ?? $f->w + $il + $ir;
        $h = $height ?? $f->h + $it + $ib;
        $frame = new Frame($w, $h);
        if ($fill !== null || $stroke !== null) {
            $frame->push(0, 0, ['rect', $w, $h, $fill, $stroke, $radius]);
        }
        $frame->place($il, $it, $f);
        // The box's baseline is its content's first baseline (else its bottom edge); a positive
        // `baseline` shift moves the box down, as in Typst.
        $b = $f->baseline !== null ? $it + $f->baseline : $h;
        $frame->baseline = $b - ($n->has('baseline') ? Value::abs($n->attrs['baseline'], $size) : 0.0);
        return $frame;
    }

    private function line(Node $n, array $parent, Regions $r): Frame
    {
        $st = Style::apply($parent, $n, $this->named);
        $len = Value::abs($n->attr('length', '100%'), $st['size'], is_finite($r->w) ? $r->w : 0);
        $stroke = Value::stroke($n->attr('stroke', '1pt'), $st['size']);
        $f = new Frame($len, 0);
        if ($stroke) {
            $f->push(0, 0, ['line', $len, 0, $stroke]);
        }
        return $f;
    }

    /** <place>: laid out at its natural size and positioned against the container, out of flow. */
    private function placeFloat(FlowBuilder $b, Node $n, array $parent): void
    {
        $st = Style::apply($parent, $n, $this->named);
        $frames = $this->flow($n->children, $st, Regions::unbounded($b->width(), false));
        $f = $frames[0] ?? new Frame(0, 0);
        $h = 'start'; // as in Typst: start and end follow the text direction
        foreach (preg_split('/[\s+]+/', (string) $n->attr('align', '')) as $tok) {
            if (in_array($tok, ['left', 'center', 'right', 'start', 'end'], true)) {
                $h = $tok;
            }
        }
        $h = Style::side($h, Style::rtl($st));
        $v = Style::valign($n->attr('align')) ?? 'top';
        $size = $st['size'];
        $dx = $n->has('dx') ? Value::abs($n->attrs['dx'], $size, is_finite($b->width()) ? $b->width() : 0) : 0.0;
        $dy = $n->has('dy') ? Value::abs($n->attrs['dy'], $size) : 0.0;
        $b->float($f, $h, $v, $dx, $dy);
    }

    /** <rotate angle="-32deg">: rotates its content about the centre without changing its layout size. */
    private function rotate(Node $n, array $parent, float $avail): Frame
    {
        $st = Style::apply($parent, $n, $this->named);
        $frames = $this->flow($n->children, $st, Regions::unbounded($avail, false));
        $f = $frames[0] ?? new Frame(0, 0);
        $a = Value::angle($n->attr('angle', '0deg'));
        $out = new Frame($f->w, $f->h, $f->h);
        $c = cos($a);
        $sn = sin($a);
        $cx = $f->w / 2;
        $cy = $f->h / 2;
        // y-down matrix [a b c d e f]: x' = a x + c y + e, y' = b x + d y + f (clockwise for positive angles).
        $out->push(0, 0, ['group', $f, [$c, $sn, -$sn, $c, $cx - $c * $cx + $sn * $cy, $cy - $sn * $cx - $c * $cy]]);
        return $out;
    }

    /**
     * <image src="..." width="" height="" fit="contain|cover|stretch" max-width="" max-height="">.
     * With one dimension the other follows the aspect ratio; with none it's the natural size,
     * scaled down to fit the width. max-width/max-height then scale it down to fit both limits.
     */
    private function image(Node $n, array $parent, float $avail): Frame
    {
        if ($this->images === null || !$n->has('src')) {
            throw new PdfException("<image> needs a src (line {$n->line})");
        }
        $st = Style::apply($parent, $n, $this->named);
        try {
            $img = $this->images->load($n->attrs['src']);
        } catch (PdfException $e) {
            throw new PdfException($e->getMessage() . " (line {$n->line})", 0, $e);
        }
        $size = $st['size'];
        $base = is_finite($avail) ? $avail : 0.0;
        $nw = $img->width();
        $nh = $img->height();
        if (!($nw > 0 && $nh > 0 && is_finite($nw) && is_finite($nh))) {
            throw new PdfException("Image '{$n->attrs['src']}' has no usable size (line {$n->line})");
        }
        $w = $n->has('width') ? Value::abs($n->attrs['width'], $size, $base) : null;
        $h = $n->has('height') ? Value::abs($n->attrs['height'], $size) : null;
        if (($w !== null && !($w > 0)) || ($h !== null && !($h > 0))) {
            throw new PdfException("<image> width and height must be above zero (line {$n->line})");
        }
        if ($w === null && $h === null) {
            $w = is_finite($avail) ? min($nw, $avail) : $nw;
            $h = $w * $nh / $nw;
        } elseif ($h === null) {
            $h = $w * $nh / $nw;
        } elseif ($w === null) {
            $w = $h * $nw / $nh;
        }
        // max-width / max-height scale the image down (keeping its ratio) to fit both limits.
        $maxW = $n->has('max-width') ? Value::abs($n->attrs['max-width'], $size, $base) : INF;
        $maxH = $n->has('max-height') ? Value::abs($n->attrs['max-height'], $size) : INF;
        $down = min(1.0, $maxW / $w, $maxH / $h);
        $w *= $down;
        $h *= $down;
        $iw = $w;
        $ih = $h;
        $fit = $n->attr('fit', 'contain');
        if ($fit !== 'stretch' && $n->has('width') && $n->has('height')) {
            $scale = $fit === 'cover' ? max($w / $nw, $h / $nh) : min($w / $nw, $h / $nh);
            $iw = $nw * $scale;
            $ih = $nh * $scale;
        }
        $f = new Frame($w, $h, $h);
        $f->push(0, 0, ['image', $img, $w, $h, ($w - $iw) / 2, ($h - $ih) / 2, $iw, $ih, $fit === 'cover']);
        return $f;
    }

    // ------------------------------------------------------------------ paragraphs

    /**
     * Set the text, break it into lines and build the line frames of one paragraph.
     *
     * @return array{lines: list<Frame>, leading: float, spacing: float}
     */
    public function paragraph(array $items, array $pst, float $width, bool $expand): array
    {
        $psize = $pst['size'] * $pst['scale'];
        $rtl = Style::rtl($pst);
        $align = Style::halign($pst);
        $justify = $align === 'justify';
        $segments = $this->segments($items, $width, $justify, $rtl, $bidi);
        $optimized = ($pst['linebreaks'] ?? null) === 'optimized' || (($pst['linebreaks'] ?? null) === null && $justify);
        $lines = $optimized && is_finite($width)
            ? $this->breakOptimized($segments, $width, $justify, $psize)
            : $this->breakSimple($segments, $width);
        $segments = null;

        $leading = Value::resolve($pst['leading'], $psize);
        $spacing = Value::resolve($pst['parSpacing'], $psize);
        $strut = $this->fonts->faces($pst['font'], $pst['weight'], $pst['italic'])[0]->capHeight() * $psize;

        $natural = 0.0;
        foreach ($lines as $l) {
            $natural = max($natural, $l['w']);
        }
        $parW = $expand && is_finite($width) ? $width : $natural;

        $frames = [];
        foreach ($lines as $k => $l) {
            $lines[$k] = null;
            $frames[] = $this->buildLine($bidi ? $this->reorder($l, $rtl ? 1 : 0) : $l, $parW, $align, $strut, $rtl);
        }
        return ['lines' => $frames, 'leading' => $leading, 'spacing' => $spacing];
    }

    private static function pushSeg(array &$segs, array &$cur, ?array $glue, bool $forced): void
    {
        if ($cur['atoms'] || $cur['forced'] || isset($cur['glue']['cl'])) {
            $segs[] = $cur;
        }
        $cur = ['atoms' => [], 'w' => 0.0, 'glue' => $glue, 'forced' => $forced];
    }

    /**
     * Break the inline items into unbreakable segments. A segment is the atoms between two
     * break opportunities, with the glue (space width) that precedes it. $bidi is set to whether
     * the paragraph needs the bidi algorithm (its lines then get reordered).
     */
    private function segments(array $items, float $width, bool $justify = false, bool $rtl = false, ?bool &$bidi = null): array
    {
        $segs = [];
        $cur = ['atoms' => [], 'w' => 0.0, 'glue' => null, 'forced' => false];
        $lastSpace = true; // collapse leading whitespace
        // As in Typst: text in the same style is shaped as one (of<text>fice</text> keeps its
        // ligature) and zero-width <h> isn't there at all (words run on across it).
        if (count($items) > 1 || ($items[0][0] ?? null) === 'h') {
            $merged = [];
            foreach ($items as $item) {
                [$kind, $val, $st] = $item;
                if ($kind === 'h' && (preg_match('/^(\d*\.?\d+)fr$/', trim($val), $m) ? (float) $m[1] == 0.0 : Value::abs($val, $st['size'] * $st['scale']) == 0.0)) {
                    continue;
                }
                $last = count($merged) - 1;
                if ($kind === 'text' && $last >= 0 && $merged[$last][0] === 'text' && $merged[$last][2] === $st) {
                    $merged[$last][1] .= $val;
                    continue;
                }
                $merged[] = $item;
            }
            $items = $merged;
        }
        // Text set in the other direction than the paragraph's (by dir, or a right-to-left lang)
        // is embedded in it, as Typst does: an explicit embedding around each word (Typst's text
        // pieces end at spaces), so the spaces between stay at the paragraph's level.
        foreach ($items as $idx => [$kind, $val, $st]) {
            if ($kind === 'text' && Style::rtl($st) !== $rtl) {
                $items[$idx][1] = preg_replace('/[^ ]+/', (Style::rtl($st) ? "\u{202B}" : "\u{202A}") . "\$0\u{202C}", $val);
            }
        }
        // Hyphenation points come from the paragraph's whole text, so a word split across inline
        // elements (of<b>fice</b>) still hyphenates; each text item gets the points inside it.
        if (count($items) === 1 && isset($items[0]) && $items[0][0] === 'text' && !($items[0][2]['hyphenate'] ?? $justify) && $items[0][2]['case'] === null && !str_contains($items[0][1], "\u{AD}")) {
            $v0 = $items[0][1];
            $texts = [0 => (strpbrk($v0, "\t\n\r\f") === false && !str_contains($v0, '  ')) ? $v0 : preg_replace('/[ \t\n\r\f]+/', ' ', $v0)];
            $points = [];
            $endShy = false;
        } else {
            [$texts, $points, $endShy] = $this->hyphenation($items, $justify);
        }
        // Bidi levels (UAX #9) over the paragraph's text, with a space standing in for <h>, an
        // object replacement character for an inline box and a line feed for <br/>, as in Typst.
        // Text runs are split where the level changes; the lines are reordered once broken.
        $base = $rtl ? 1 : 0;
        $itemLevel = []; // item => single constant level (all non-text items and single-level text items)
        $levelRuns = []; // item => list of [endByte, level] runs (only for multi-level text items)
        $hasLevels = false;
        $bidi = $rtl || Bidi::needed(implode('', $texts));
        if ($bidi) {
            $cps = [];
            $ownerIdx = [];
            $ownerEnd = []; // end byte in $texts[$idx], or -1 for non-text items
            foreach ($items as $idx => [$kind]) {
                if ($kind === 'text') {
                    $s = $texts[$idx];
                    $slen = strlen($s);
                    for ($b = 0; $b < $slen; ) {
                        $c0 = ord($s[$b]);
                        if ($c0 < 0x80) {
                            $cps[] = $c0;
                            $b++;
                        } elseif ($c0 < 0xE0 && $b + 1 < $slen) {
                            $cps[] = (($c0 & 0x1F) << 6) | (ord($s[$b + 1]) & 0x3F);
                            $b += 2;
                        } elseif ($c0 < 0xF0 && $b + 2 < $slen) {
                            $cps[] = (($c0 & 0x0F) << 12) | ((ord($s[$b + 1]) & 0x3F) << 6) | (ord($s[$b + 2]) & 0x3F);
                            $b += 3;
                        } elseif ($b + 3 < $slen) {
                            $cps[] = (($c0 & 0x07) << 18) | ((ord($s[$b + 1]) & 0x3F) << 12) | ((ord($s[$b + 2]) & 0x3F) << 6) | (ord($s[$b + 3]) & 0x3F);
                            $b += 4;
                        } else {
                            $cps[] = 0xFFFD;
                            $b++;
                        }
                        $ownerIdx[] = $idx;
                        $ownerEnd[] = $b;
                    }
                } else {
                    $cps[] = match ($kind) { 'br' => 0x0A, 'box' => 0xFFFC, default => 0x20 };
                    $ownerIdx[] = $idx;
                    $ownerEnd[] = -1;
                }
            }
            $last = $base;
            foreach (Bidi::levels($cps, $base) as $i => $l) {
                $l ??= $last; // characters the algorithm sets aside go with the one before
                $last = $l;
                $idx = $ownerIdx[$i];
                $endB = $ownerEnd[$i];
                if ($endB < 0) {
                    $itemLevel[$idx] = $l;
                } elseif (!isset($levelRuns[$idx])) {
                    $levelRuns[$idx] = [[$endB, $l]];
                } else {
                    $lastRunIdx = count($levelRuns[$idx]) - 1;
                    if ($levelRuns[$idx][$lastRunIdx][1] === $l) {
                        $levelRuns[$idx][$lastRunIdx][0] = $endB;
                    } else {
                        $levelRuns[$idx][] = [$endB, $l];
                    }
                }
            }
            unset($cps, $ownerIdx, $ownerEnd);
            foreach ($levelRuns as $idx => $runs) {
                if (count($runs) === 1) {
                    $itemLevel[$idx] = $runs[0][1];
                    unset($levelRuns[$idx]);
                }
            }
            $hasLevels = $itemLevel !== [] || $levelRuns !== [];
        }
        $lvAt = $byLevel = null;
        if ($hasLevels) {
            $runCur = [];
            // The level at a byte of an item, and a word part as pieces of one level each: [text,
            // level]. (Only called when the paragraph has levels.)
            $lvAt = static function (int $idx, int $byte = 0) use ($itemLevel, $levelRuns, &$runCur, $base): int {
                if (isset($itemLevel[$idx])) {
                    return $itemLevel[$idx];
                }
                if (!isset($levelRuns[$idx])) {
                    return $base;
                }
                $runs = $levelRuns[$idx];
                $rc = $runCur[$idx] ?? 0;
                if ($rc > 0 && $runs[$rc - 1][0] > $byte) {
                    $rc = 0;
                }
                $rn = count($runs);
                while ($rc < $rn && $runs[$rc][0] <= $byte) {
                    $rc++;
                }
                $runCur[$idx] = $rc;
                return $rc < $rn ? $runs[$rc][1] : $base;
            };
            $byLevel = static function (int $idx, int $off, string $part) use ($itemLevel, $levelRuns, &$runCur, $base): array {
                if (isset($itemLevel[$idx])) {
                    return [[$part, $itemLevel[$idx]]];
                }
                if (!isset($levelRuns[$idx])) {
                    return [[$part, $base]];
                }
                $runs = $levelRuns[$idx];
                $rc = $runCur[$idx] ?? 0;
                if ($rc > 0 && $runs[$rc - 1][0] > $off) {
                    $rc = 0;
                }
                $rn = count($runs);
                while ($rc < $rn && $runs[$rc][0] <= $off) {
                    $rc++;
                }
                $end = $off + strlen($part);
                if ($rc < $rn && $runs[$rc][0] >= $end) {
                    $runCur[$idx] = $rc;
                    return [[$part, $runs[$rc][1]]];
                }
                $out = [];
                $pos = $off;
                while ($pos < $end) {
                    if ($rc < $rn) {
                        $segEnd = $runs[$rc][0] < $end ? $runs[$rc][0] : $end;
                        $lvl = $runs[$rc][1];
                        if ($runs[$rc][0] <= $segEnd) {
                            $rc++;
                        }
                    } else {
                        $segEnd = $end;
                        $lvl = $base;
                    }
                    $out[] = [substr($part, $pos - $off, $segEnd - $pos), $lvl];
                    $pos = $segEnd;
                }
                $runCur[$idx] = $rc;
                return $out;
            };
        }
        $levelAt = $hasLevels;
        // The text item before this one when no space separates them: [last run, hyphen style].
        $prev = null;
        // Text right after an inline box may break from it (as in Typst).
        $afterBox = false;
        $lastChar = ''; // the last character of text before an inline box
        $hasBoxes = false;
        foreach ($items as [$kind]) {
            if ($kind === 'box') {
                $hasBoxes = true;
                break;
            }
        }
        foreach ($items as $idx => [$kind, $val, $st]) {
            if ($kind !== 'text') {
                $prev = null;
            }
            if ($kind === 'br') {
                self::pushSeg($segs, $cur, null, true);
                $lastSpace = true;
                $afterBox = false;
                continue;
            }
            if ($kind === 'h') {
                $afterBox = false;
                $lastChar = '';
                if (preg_match('/^(\d*\.?\d+)fr$/', trim($val), $m)) {
                    $cur['atoms'][] = $levelAt ? ['fr', (float) $m[1], $lvAt($idx)] : ['fr', (float) $m[1]];
                    self::pushSeg($segs, $cur, self::ZERO_GLUE, false);
                    continue;
                }
                $w = Value::abs($val, $st['size'] * $st['scale']);
                $cur['atoms'][] = $levelAt ? ['h', $w, $lvAt($idx)] : ['h', $w];
                $cur['w'] += $w;
                // A line may break after <h> (as in Typst). Spaces on both sides of it collapse
                // into one.
                self::pushSeg($segs, $cur, self::ZERO_GLUE, false);
                continue;
            }
            if ($kind === 'box') {
                $f = match ($val->type) {
                    'image' => $this->image($val, $st, $width),
                    'rotate' => $this->rotate($val, $st, $width),
                    default => $this->box($val, $st, $width),
                };
                // A line may break on either side of an inline box (as in Typst), but not after
                // opening punctuation or before closing punctuation, nor on either side of a
                // quotation mark.
                if ($cur['atoms'] && !$lastSpace && !preg_match('/[\p{Ps}\p{Pi}\p{Pf}"\']$/u', $lastChar)) {
                    self::pushSeg($segs, $cur, self::ZERO_GLUE, false);
                }
                $cur['atoms'][] = $levelAt ? ['box', $f, $st['link'] ?? null, $lvAt($idx)] : ['box', $f, $st['link'] ?? null];
                $cur['w'] += $f->w;
                $lastSpace = false;
                $afterBox = true;
                $lastChar = ''; // only text right before a box can hold it back
                continue;
            }
            // text
            $text = $texts[$idx];
            if ($text === '') {
                continue;
            }
            $cuts = $points[$idx] ?? [];
            $cutKeys = $cuts ? array_keys($cuts) : []; // in order (see hyphenation())
            $nk = count($cutKeys);
            $cutAt = 0;
            $size = $st['size'] * $st['scale'];
            $faces = $this->fonts->faces($st['font'], $st['weight'], $st['italic']);
            $face = $faces[0];
            $primaryCoversAscii = $face->coversAscii();
            $primaryCapH = $face->capHeight() * $size;
            $tracking = Value::resolve($st['tracking'], $size);
            $features = [];
            if ($st['ligatures'] && $tracking == 0.0) {
                $features[] = 'liga';
            }
            if ($st['numbers'] === 'tabular') {
                $features[] = 'tnum';
            } elseif ($st['numbers'] === 'proportional') {
                $features[] = 'pnum';
            }
            $lastRun = null;
            $off = 0; // byte offset of the current part in $text
            $spaceGid = null;
            $spaceW = 0.0;
            $spaceGlue = null;
            $spaceGlues = [];
            $hyphenW = null;
            $linkAttr = $st['link'] ?? null;
            $overhangAttr = $st['overhang'];
            $markerAttr = $st['marker'] ?? null;
            $colorAttr = $st['color'];
            $langAttr = $st['lang'];
            $textAscii = !preg_match('/[^\x20-\x7E]/', $text);
            $primaryAsciiFast = !$levelAt && $base === 0 && $primaryCoversAscii && $face->simpleKerning();
            foreach (explode(' ', $text) as $ti => $tok) {
                if ($ti > 0) {
                    $off++;
                    $lastRun = null;
                    $afterBox = false;
                    if (!$lastSpace) {
                        if ($spaceGid === null) {
                            $spaceGid = $face->glyph(32);
                            $spaceW = $face->advances[$spaceGid] / $face->upem * $size + $tracking;
                        }
                        if ($levelAt) {
                            $lv = $lvAt($idx, $off - 1);
                            $glue = $spaceGlues[$lv] ??= ['w' => $spaceW, 'gid' => $spaceGid, 'face' => $face, 'size' => $size, 'color' => $colorAttr, 'lv' => $lv];
                        } else {
                            $glue = $spaceGlue ??= ['w' => $spaceW, 'gid' => $spaceGid, 'face' => $face, 'size' => $size, 'color' => $colorAttr];
                        }
                        if ($cur['atoms'] || $cur['forced'] || isset($cur['glue']['cl'])) {
                            $segs[] = $cur;
                        }
                        $cur = ['atoms' => [], 'w' => 0.0, 'glue' => $glue, 'forced' => false];
                        $lastSpace = true;
                    }
                }
                if ($tok === '') {
                    continue;
                }
                $lastSpace = false;
                if ($afterBox && !preg_match('/^[\p{Pe}\p{Pf}\p{Pi}"\',.;:!?%]/u', $tok)) {
                    if ($cur['atoms'] || $cur['forced'] || isset($cur['glue']['cl'])) {
                        $segs[] = $cur;
                    }
                    $cur = ['atoms' => [], 'w' => 0.0, 'glue' => self::ZERO_GLUE, 'forced' => false];
                }
                $afterBox = false;
                if ($hasBoxes) {
                    $lastChar = $textAscii ? $tok[strlen($tok) - 1] : mb_substr($tok, -1);
                }
                // Break opportunities after hyphens, dashes, slashes and the breaking Unicode spaces
                // (thin, em, en and the like; not the no-break ones).
                // A space ending the item breaks too; a hyphen or slash there doesn't (the next item
                // could continue the word).
                if ($textAscii) {
                    $tokAscii = true;
                    $parts = strpbrk($tok, '-/') !== false
                        ? preg_split('/(?<=[-\/])(?=.)/', $tok)
                        : [$tok];
                } else {
                    $tokAscii = !preg_match('/[^\x20-\x7E]/', $tok);
                    if ($tokAscii) {
                        $parts = strpbrk($tok, '-/') !== false
                            ? preg_split('/(?<=[-\/])(?=.)/', $tok)
                            : [$tok];
                    } else {
                        $parts = strpbrk($tok, "-/\xE2\xE3") !== false
                            ? preg_split('/(?<=[-\x{2013}\x{2014}\/\x{2000}-\x{2006}\x{2008}-\x{200A}\x{205F}\x{3000}])(?=.)|(?<=[\x{2000}-\x{2006}\x{2008}-\x{200A}\x{205F}\x{3000}])$/u', $tok)
                            : [$tok];
                    }
                }
                foreach ($parts as $pi => $part) {
                    if ($pi > 0) {
                        if ($cur['atoms'] || $cur['forced'] || isset($cur['glue']['cl'])) {
                            $segs[] = $cur;
                        }
                        $cur = ['atoms' => [], 'w' => 0.0, 'glue' => self::ZERO_GLUE, 'forced' => false];
                    }
                    if ($part === '') {
                        continue;
                    }
                    if ($off === 0 && isset($cuts[0]) && $prev !== null) {
                        // A hyphenation point right where this item meets the one before it: the
                        // hyphen is set in the style of the text before the break.
                        [$pf, $psize, $pcolor, $plink, $poverhang, $ptracking] = $prev[1];
                        self::pushSeg($segs, $cur, ['w' => 0.0, 'hyph' => $cuts[0], 'hw' => $this->hyphenWidth($pf, $psize, $ptracking), 'kern' => 0.0,
                            'face' => $pf, 'size' => $psize, 'color' => $pcolor, 'link' => $plink, 'overhang' => $poverhang], false);
                    }
                    $partLen = strlen($part);
                    // The hyphenation points inside this part ($cuts has the whole item's; the
                    // parts come in order, so a cursor finds them without going over all of them).
                    $partCuts = [];
                    if ($nk > 0) {
                        while ($cutAt < $nk && $cutKeys[$cutAt] <= $off) {
                            $cutAt++;
                        }
                        for ($q = $cutAt; $q < $nk && $cutKeys[$q] < $off + $partLen; $q++) {
                            $partCuts[$cutKeys[$q] - $off] = $cuts[$cutKeys[$q]];
                        }
                    }
                    // Fast path for plain LTR ASCII in the primary font with simple kerning: avoid
                    // intermediate $runs arrays and throwaway GlyphRuns on hyphenated words.
                    if ($tokAscii && $primaryAsciiFast) {
                        $fast = $face->asciiGlyphRun($part, $features, $langAttr, null, $size, $tracking);
                        if ($fast !== null) {
                            if (!$partCuts) {
                                $off += $partLen;
                                $run = new GlyphRun($face, $size, $colorAttr, $fast[0], $fast[1], 0);
                                if ($markerAttr !== null) {
                                    $run->marker = $markerAttr;
                                }
                                $cur['atoms'][] = ['run', $run, $primaryCapH, $size, $linkAttr, $overhangAttr];
                                $cur['w'] += $fast[1];
                                $lastRun = $run;
                                continue;
                            }
                            $glyphs = $fast[0];
                            $plain = true;
                            foreach ($glyphs as $g) {
                                if (strlen($g[2]) !== 1) {
                                    $plain = false;
                                    break;
                                }
                            }
                            if ($plain) {
                                $off += $partLen;
                                $piece = [];
                                $pw = 0.0;
                                foreach ($glyphs as $gn => $g) {
                                    if ($gn > 0 && isset($partCuts[$gn])) {
                                        if ($piece) {
                                            $run = new GlyphRun($face, $size, $colorAttr, $piece, $pw, 0);
                                            if ($markerAttr !== null) {
                                                $run->marker = $markerAttr;
                                            }
                                            $cur['atoms'][] = ['run', $run, $primaryCapH, $size, $linkAttr, $overhangAttr];
                                            $cur['w'] += $pw;
                                            $lastRun = $run;
                                            $piece = [];
                                            $pw = 0.0;
                                        }
                                        $prevGid = $lastRun->glyphs[count($lastRun->glyphs) - 1][0];
                                        $kern = -$face->kern($prevGid, $g[0]) * $size / $face->upem;
                                        $hyphenW ??= $this->hyphenWidth($face, $size, $tracking);
                                        if ($cur['atoms'] || $cur['forced'] || isset($cur['glue']['cl'])) {
                                            $segs[] = $cur;
                                        }
                                        $cur = ['atoms' => [], 'w' => 0.0, 'glue' => [
                                            'w' => 0.0, 'hyph' => $partCuts[$gn], 'hw' => $hyphenW + $kern, 'kern' => $kern,
                                            'face' => $face, 'size' => $size, 'color' => $colorAttr, 'link' => $linkAttr, 'overhang' => $overhangAttr,
                                        ], 'forced' => false];
                                    }
                                    $piece[] = $g;
                                    $pw += $g[1];
                                }
                                if ($piece) {
                                    $run = new GlyphRun($face, $size, $colorAttr, $piece, $pw, 0);
                                    if ($markerAttr !== null) {
                                        $run->marker = $markerAttr;
                                    }
                                    $cur['atoms'][] = ['run', $run, $primaryCapH, $size, $linkAttr, $overhangAttr];
                                    $cur['w'] += $pw;
                                    $lastRun = $run;
                                }
                                continue;
                            }
                            $runs = [new GlyphRun($face, $size, $colorAttr, $glyphs, $fast[1], 0)];
                        } else {
                            $runs = [$this->glyphRun($face, $part, $size, $colorAttr, $tracking, $features, $base, $langAttr, null, true)];
                        }
                    } else {
                        // The part is shaped whole (so ligatures and kerning survive unused hyphenation
                        // points), then cut into pieces at the hyphenation points, each break an optional
                        // hyphen between two pieces.
                        $runs = [];
                        // Text with no script of its own (a tatweel and its marks, say) is shaped in
                        // the script of the text around it: the letter before, else the one after.
                        $context = null;
                        if (!$tokAscii && !preg_match('/[^\p{Common}\p{Inherited}]/u', $part)) {
                            $context = preg_match('/[^\p{Common}\p{Inherited}](?=[\p{Common}\p{Inherited}]*$)/u', substr($texts[$idx], 0, $off), $cm)
                                || preg_match('/[^\p{Common}\p{Inherited}]/u', substr($texts[$idx], $off + $partLen), $cm) ? $cm[0] : null;
                        }
                        if (!$levelAt && $tokAscii && $primaryCoversAscii) {
                            $runs[] = $this->glyphRun($face, $part, $size, $colorAttr, $tracking, $features, $base, $langAttr, null, true);
                        } else {
                            foreach ($levelAt ? $byLevel($idx, $off, $part) : [[$part, $base]] as [$sub, $lvl]) {
                                if ($tokAscii && $primaryCoversAscii) {
                                    $runs[] = $this->glyphRun($face, $sub, $size, $colorAttr, $tracking, $features, $lvl, $langAttr, $context, true);
                                } else {
                                    foreach ($this->fontRuns($sub, $faces, $st) as [$f, $runText]) {
                                        $runs[] = $this->glyphRun($f, $runText, $size, $colorAttr, $tracking, $features, $lvl, $langAttr, $context);
                                    }
                                }
                            }
                        }
                    }
                    if (!$partCuts) {
                        $off += $partLen;
                        foreach ($runs as $run) {
                            $run->marker = $markerAttr;
                            $capH = $run->face === $face ? $primaryCapH : $run->face->capHeight() * $size;
                            $atom = $levelAt
                                ? ['run', $run, $capH, $size, $linkAttr, $overhangAttr, $idx]
                                : ['run', $run, $capH, $size, $linkAttr, $overhangAttr];
                            $cur['atoms'][] = $atom;
                            $cur['w'] += $run->width;
                            $lastRun = $run;
                        }
                        continue;
                    }
                    $joined = $off === 0 && $prev !== null; // continues a word from the item before
                    $off += $partLen;
                    $clusters = [];
                    foreach ($this->cutRuns($runs, $partCuts, $joined) as [$cut, $pieceRuns]) {
                        if ($cut !== null && isset($cut['cl'])) {
                            // A hyphenation point inside a ligature: see cluster().
                            $id = $cut['cl']['id'];
                            $clusters[$id] ??= $this->cluster($cut['cl'], $size, $features, $st, $face);
                            self::pushSeg($segs, $cur, ['w' => 0.0, 'hyph' => $cut['hyph'], 'cl' => $clusters[$id], 't' => $cut['t'],
                                'face' => $face, 'size' => $size, 'color' => $colorAttr, 'link' => $linkAttr], false);
                        } elseif ($cut !== null) {
                            // When the break is taken, the glyph before it loses its kerning against the
                            // letter after it (Typst reshapes the broken word) and the hyphen follows
                            // unkerned (Typst appends it without shaping).
                            $prevGid = $lastRun->glyphs[count($lastRun->glyphs) - 1][0];
                            $kern = $lastRun->face === $pieceRuns[0]->face
                                ? -$lastRun->face->kern($prevGid, $pieceRuns[0]->glyphs[0][0]) * $size / $lastRun->face->upem
                                : 0.0;
                            $hyphenW ??= $this->hyphenWidth($face, $size, $tracking);
                            self::pushSeg($segs, $cur, ['w' => 0.0, 'hyph' => $cut, 'hw' => $hyphenW + $kern, 'kern' => $kern,
                                'face' => $face, 'size' => $size, 'color' => $colorAttr, 'link' => $linkAttr, 'overhang' => $overhangAttr], false);
                        }
                        foreach ($pieceRuns as $run) {
                            $run->marker = $markerAttr;
                            $capH = $run->face === $face ? $primaryCapH : $run->face->capHeight() * $size;
                            $atom = $levelAt
                                ? ['run', $run, $capH, $size, $linkAttr, $overhangAttr, $idx]
                                : ['run', $run, $capH, $size, $linkAttr, $overhangAttr];
                            $cur['atoms'][] = $atom;
                            $cur['w'] += $run->width;
                            $lastRun = $run;
                        }
                    }
                }
            }
            // Tracking goes between glyphs: none after the last glyph of a text run (as in Typst).
            if ($lastRun !== null && $tracking != 0.0 && $lastRun->glyphs) {
                $lastRun->glyphs[count($lastRun->glyphs) - 1][1] -= $tracking;
                $lastRun->width -= $tracking;
                $cur['w'] -= $tracking;
            }
            $prev = $lastSpace || $lastRun === null ? null
                : [$lastRun, [$face, $size, $st['color'], $st['link'] ?? null, $st['overhang'], $tracking]];
        }
        if ($endShy) {
            // A soft hyphen ending the paragraph is drawn, as Typst does (and copies out as one).
            $st = $items[array_key_last($items)][2];
            $size = $st['size'] * $st['scale'];
            $face = $this->fonts->faces($st['font'], $st['weight'], $st['italic'])[0];
            $lastIdx = array_key_last($items);
            $t = $texts[$lastIdx] ?? '';
            $run = $this->glyphRun($face, '-', $size, $st['color'], 0.0, [], $t === '' || !$levelAt ? $base : $lvAt($lastIdx, strlen($t) - strlen(mb_substr($t, -1))));
            $run->glyphs[0][2] = "\u{AD}";
            $cur['atoms'][] = ['run', $run, $face->capHeight() * $size, $size, $st['link'] ?? null, $st['overhang']];
            $cur['w'] += $run->width;
        }
        // A trailing <br/> ends the last line; it doesn't start an empty one (as in Typst).
        if ($cur['atoms'] || isset($cur['glue']['cl'])) {
            $segs[] = $cur;
        }
        return $segs;
    }

    /**
     * The text of each text item (whitespace collapsed, case applied) and its hyphenation points:
     * [item => [byte => [chars before, chars after]]]. Words are runs of letters across the whole
     * paragraph, so they may span items. As in Typst, a word is hyphenated in the language of its
     * first letter, and a point is used only where hyphenation is on for the text after it.
     *
     * The third value says whether the paragraph ends in a soft hyphen (which Typst draws).
     *
     * @return array{0: array<int, string>, 1: array<int, array<int, array{0: int, 1: int}>>, 2: bool}
     */
    private function hyphenation(array $items, bool $justify): array
    {
        $texts = [];
        $starts = [];
        $points = [];
        $all = '';
        $any = false;
        $anySupported = false;
        $shys = []; // where typed soft hyphens were in $all: a word holding one isn't hyphenated
        $endShy = false;
        $lastItemKey = array_key_last($items);
        foreach ($items as $k => [$kind, $val, $st]) {
            if ($kind !== 'text') {
                $all .= "\n"; // not a letter: words don't run across breaks, spacing or boxes
                continue;
            }
            $t = (strpbrk($val, "\t\n\r\f") === false && !str_contains($val, '  '))
                ? $val
                : preg_replace('/[ \t\n\r\f]+/', ' ', $val);
            if ($st['case'] === 'upper') {
                $t = mb_strtoupper($t);
            } elseif ($st['case'] === 'lower') {
                $t = mb_strtolower($t);
            }
            // A soft hyphen typed in the text (&shy;) is a hyphenation point of its own, used
            // whether hyphenation is on or not; the character itself isn't drawn.
            $endShy = $k === $lastItemKey && str_ends_with($t, "\u{AD}");
            if (str_contains($t, "\u{AD}")) {
                $clean = '';
                foreach (explode("\u{AD}", $t) as $i => $piece) {
                    if ($i > 0) {
                        $shys[] = strlen($all) + strlen($clean);
                        $points[$k][strlen($clean)] = [99, 99];
                    }
                    $clean .= $piece;
                }
                $t = $clean;
            }
            $texts[$k] = $t;
            $starts[$k] = strlen($all);
            $all .= $t;
            $any = $any || ($st['hyphenate'] ?? $justify);
            $anySupported = $anySupported || Hyphenator::supports($st['lang']);
        }
        // A soft hyphen at the end of an item (or alone in one) is a point at the start of the
        // text right after it, where the breaking code looks for it.
        if ($points) {
            $keys = array_keys($items);
            foreach ($keys as $n => $k) {
                if (!isset($texts[$k], $points[$k][strlen($texts[$k])])) {
                    continue;
                }
                $next = $keys[$n + 1] ?? null;
                if ($next !== null && isset($texts[$next]) && $starts[$next] === $starts[$k] + strlen($texts[$k])) {
                    $points[$next][0] ??= $points[$k][strlen($texts[$k])];
                    unset($points[$k][strlen($texts[$k])]);
                }
            }
        }
        if (!$any || !$anySupported) {
            return [$texts, $points, $endShy];
        }
        // Words, as Typst finds them: Unicode word boundaries (letters, marks and digits run
        // together, format characters such as a word joiner or a soft hyphen belong to the
        // character before them, an apostrophe, full stop or colon joins two letters, an
        // apostrophe, full stop, comma or semicolon joins two digits), and only words made
        // entirely of letters are hyphenated ("(hyphen-ation)." is; "hy1phenation", "it's", a
        // word with a combining accent and one holding a soft hyphen aren't).
        $hadPoints = $points !== [];
        $run = '[\p{L}\p{N}_][\p{L}\p{M}\p{N}_\p{Cf}]*';
        preg_match_all("/$run(?:(?:(?<=\\p{L})['\\x{2019}.:\\x{B7}\\x{2027}](?=\\p{L})|(?<=\\p{N})['\\x{2019}.,;](?=\\p{N}))$run)*/u", $all, $m, PREG_OFFSET_CAPTURE);
        $textKeys = array_keys($starts);
        $textCount = count($textKeys);
        $asciiLetters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';
        if (!$shys && $textCount === 1) {
            $k0 = $textKeys[0];
            $lang = $items[$k0][2]['lang'];
            $minLen = Hyphenator::minLength($lang);
            if ($minLen !== PHP_INT_MAX && ($items[$k0][2]['hyphenate'] ?? $justify)) {
                $start0 = $starts[$k0];
                $k0Points = $points[$k0] ?? [];
                foreach ($m[0] as [$whole, $from]) {
                    $wlen = strlen($whole);
                    if ($wlen < $minLen) {
                        continue;
                    }
                    $spn = strspn($whole, $asciiLetters);
                    if ($spn === $wlen) {
                        $found = Hyphenator::points($whole, $lang);
                        if ($found) {
                            $base = $from - $start0;
                            foreach ($found as $p) {
                                $k0Points[$base + $p] = [$p, $wlen - $p];
                            }
                        }
                    } elseif (ord($whole[$spn]) >= 0x80 && preg_match('/^\p{L}+$/u', $whole)) {
                        $found = Hyphenator::points($whole, $lang);
                        if ($found) {
                            $chars = mb_str_split($whole);
                            $total = count($chars);
                            $bytes = [];
                            $offset = 0;
                            foreach ($chars as $ci => $char) {
                                $bytes[$ci] = $offset;
                                $offset += strlen($char);
                            }
                            $base = $from - $start0;
                            foreach ($found as $p) {
                                $k0Points[$base + $bytes[$p]] = [$p, $total - $p];
                            }
                        }
                    }
                }
                if ($k0Points) {
                    $points[$k0] = $k0Points;
                }
            }
            if ($hadPoints) {
                foreach ($points as &$byByte) {
                    ksort($byByte);
                }
            }
            return [$texts, $points, $endShy];
        }
        $words = [];
        foreach ($m[0] as [$whole, $from]) {
            if (!$shys) {
                $wlen = strlen($whole);
                $spn = strspn($whole, $asciiLetters);
                if ($spn === $wlen) {
                    $words[] = [$whole, $from, true];
                } elseif (ord($whole[$spn]) >= 0x80 && preg_match('/^\p{L}+$/u', $whole)) {
                    $words[] = [$whole, $from, false];
                }
                continue;
            }
            // A soft hyphen is a line break opportunity, and Typst finds words between those:
            // the text before one (holding it) isn't hyphenated, the text after it is a word of
            // its own.
            $end = $from + strlen($whole);
            $cuts = array_values(array_filter($shys, fn ($p) => $p > $from && $p <= $end));
            $at = $from;
            foreach ([...$cuts, null] as $cut) {
                if ($cut === null && $at < $end) {
                    $word = substr($all, $at, $end - $at);
                    $wlen = strlen($word);
                    $spn = strspn($word, $asciiLetters);
                    if ($spn === $wlen) {
                        $words[] = [$word, $at, true];
                    } elseif ($wlen > 0 && ord($word[$spn]) >= 0x80 && preg_match('/^\p{L}+$/u', $word)) {
                        $words[] = [$word, $at, false];
                    }
                }
                $at = $cut ?? $at;
            }
        }
        $itemCursor = 0;
        // Words and their points arrive in byte order, so each inline item is visited once.
        foreach ($words as [$word, $byte, $ascii]) {
            while ($itemCursor + 1 < $textCount && $starts[$textKeys[$itemCursor + 1]] <= $byte) {
                $itemCursor++;
            }
            $wk = $textKeys[$itemCursor];
            $wk = $byte < $starts[$wk] + strlen($texts[$wk]) ? $wk : $textKeys[0];
            $lang = $items[$wk][2]['lang'];
            if (strlen($word) < Hyphenator::minLength($lang) || !($found = Hyphenator::points($word, $lang))) {
                continue;
            }
            if ($ascii) {
                $total = strlen($word);
                $bytes = null;
            } else {
                $chars = mb_str_split($word);
                $total = count($chars);
                $bytes = [];
                $offset = 0;
                foreach ($chars as $ci => $char) {
                    $bytes[$ci] = $offset;
                    $offset += strlen($char);
                }
            }
            foreach ($found as $p) {
                $at = $byte + ($ascii ? $p : $bytes[$p]);
                while ($itemCursor + 1 < $textCount && $starts[$textKeys[$itemCursor + 1]] <= $at) {
                    $itemCursor++;
                }
                $k = $textKeys[$itemCursor];
                $k = $at < $starts[$k] + strlen($texts[$k]) ? $k : $textKeys[0];
                if ($items[$k][2]['hyphenate'] ?? $justify) {
                    $points[$k][$at - $starts[$k]] = [$p, $total - $p];
                }
            }
        }
        if ($hadPoints) {
            foreach ($points as &$byByte) {
                ksort($byByte);
            }
        }
        return [$texts, $points, $endShy];
    }

    /**
     * Cut runs into pieces at hyphenation points. Returns [break before
     * the piece ([chars before, chars after]) or null, runs] per piece.
     *
     * A ligature with hyphenation points inside it (the "ffi" of "of-fice") becomes a cluster: one
     * piece holding the ligature, then an empty piece for each further point, each piece's break
     * marked ['hyph' => [l, r], 'cl' => the cluster, 't' => which point]. Unbroken, the ligature
     * stays; a line starting or ending at one of its points reshapes its share of the letters.
     *
     * @param list<GlyphRun> $runs
     * @param array<int, array{0: int, 1: int}> $cuts points by byte relative to the word part
     * @return list<array{0: ?array, 1: list<GlyphRun>}>
     */
    private function cutRuns(array $runs, array $cuts, bool $joined = false): array
    {
        if (!$cuts) {
            return [[null, $runs]];
        }
        // Most words use one font and level: traverse its glyphs without per-glyph wrappers.
        $flat = $runs[0]->glyphs;
        $owners = [];
        if (count($runs) > 1) {
            $flat = [];
            foreach ($runs as $ri => $run) {
                foreach ($run->glyphs as $g) {
                    $flat[] = $g;
                    $owners[] = $ri;
                }
            }
        }
        $out = [[null, []]];
        $outLast = 0;
        $curRi = -1;
        $curGlyphs = [];
        $curW = 0.0;
        $byte = 0;
        foreach ($flat as $n => $g) {
            $ri = $owners[$n] ?? 0;
            $len = strlen($g[2]);
            if ($len > 1) {
                $inside = [];
                for ($b = $byte + 1; $b < $byte + $len; $b++) {
                    if (isset($cuts[$b])) {
                        $inside[] = $b;
                    }
                }
                // (A ligature first in the part needs letters before it: in an earlier item when $joined.)
                if ($inside && ($n > 0 || $joined)) {
                    $face = $runs[$ri]->face;
                    $offsets = [];
                    $hyphs = [];
                    if (isset($cuts[$byte])) {
                        $offsets[] = 0;
                        $hyphs[] = $cuts[$byte];
                    }
                    foreach ($inside as $b) {
                        $offsets[] = mb_strlen(substr($g[2], 0, $b - $byte));
                        $hyphs[] = $cuts[$b];
                    }
                    $prev = $flat[$n - 1] ?? null;
                    $next = $flat[$n + 1] ?? null;
                    $cl = ['id' => ++$this->clusterSeq, 'face' => $face, 'level' => $runs[$ri]->level, 'gid' => $g[0], 'gw' => $g[1], 'chars' => mb_str_split($g[2]), 'k' => $offsets,
                        'prev' => $prev !== null && $runs[$owners[$n - 1] ?? 0]->face === $face ? $prev[0] : null,
                        'next' => $next !== null && $runs[$owners[$n + 1] ?? 0]->face === $face ? $next[0] : null];
                    if ($curGlyphs) {
                        $r = $runs[$curRi];
                        $out[$outLast][1][] = new GlyphRun($r->face, $r->size, $r->color, $curGlyphs, $curW, $r->level, $r->marks);
                        $curGlyphs = [];
                        $curW = 0.0;
                        $curRi = -1;
                    }
                    foreach ($offsets as $t => $_) {
                        $out[] = [['hyph' => $hyphs[$t], 'cl' => $cl, 't' => $t], []];
                        $outLast++;
                        if ($t === 0) {
                            $r = $runs[$ri];
                            $out[$outLast][1][] = new GlyphRun($r->face, $r->size, $r->color, [$g], $g[1], $r->level, $r->marks);
                        }
                    }
                    $byte += $len;
                    continue;
                }
            }
            if ($n > 0 && isset($cuts[$byte])) {
                if ($curGlyphs) {
                    $r = $runs[$curRi];
                    $out[$outLast][1][] = new GlyphRun($r->face, $r->size, $r->color, $curGlyphs, $curW, $r->level, $r->marks);
                    $curGlyphs = [];
                    $curW = 0.0;
                    $curRi = -1;
                }
                $out[] = [$cuts[$byte], []];
                $outLast++;
            }
            if ($curRi !== -1 && $curRi !== $ri) {
                $r = $runs[$curRi];
                $out[$outLast][1][] = new GlyphRun($r->face, $r->size, $r->color, $curGlyphs, $curW, $r->level, $r->marks);
                $curGlyphs = [];
                $curW = 0.0;
            }
            $curRi = $ri;
            $curGlyphs[] = $g;
            $curW += $g[1];
            $byte += $len;
        }
        if ($curGlyphs) {
            $r = $runs[$curRi];
            $out[$outLast][1][] = new GlyphRun($r->face, $r->size, $r->color, $curGlyphs, $curW, $r->level, $r->marks);
        }
        return $out;
    }

    private int $clusterSeq = 0;

    /**
     * Shape every share of a cluster's letters a line can hold: from its start or one of its points
     * to one of its points (plus an unkerned hyphen, as Typst appends it) or its end (kerned
     * against the glyph after it). 'fix' is how the glyph before the cluster changes when it's
     * kerned against the first reshaped letter instead of the ligature.
     */
    private function cluster(array $cl, float $size, array $features, array $st, \YetiPdf\Write\Font\Face $primary): array
    {
        $f = $cl['face'];
        $scale = $size / $f->upem;
        $n = count($cl['chars']);
        // The hyphen comes from the primary font, as with any inserted hyphen (a fallback font's
        // ligature still gets it).
        $hyphen = $primary->glyph(0x2D);
        $hw = $primary->advances[$hyphen] / $primary->upem * $size;
        $atom = fn (GlyphRun $r) => ['run', $r, $r->face->capHeight() * $size, $size, $st['link'] ?? null, $st['overhang']];
        $cl['atoms'] = [];
        $cl['w'] = [];
        $cl['fix'] = [];
        foreach (array_unique([0, ...$cl['k']]) as $a) {
            foreach (array_unique([...$cl['k'], $n]) as $b) {
                if ($b < $a) {
                    continue;
                }
                $run = $this->glyphRun($f, implode('', array_slice($cl['chars'], $a, $b - $a)), $size, $st['color'], 0.0, $features, $cl['level'], $st['lang']);
                $glyphs = $run->glyphs;
                $w = $run->width;
                if ($a === 0) {
                    $first = $glyphs[0][0] ?? null;
                    $cl['fix'][$b] = $cl['prev'] === null ? 0.0
                        : (($first === null ? 0 : $f->kern($cl['prev'], $first)) - $f->kern($cl['prev'], $cl['gid'])) * $scale;
                }
                $tail = null;
                if ($b < $n) {
                    // Copies out as a soft hyphen, so copied text rejoins the word.
                    if ($primary === $f) {
                        $glyphs[] = [$hyphen, $hw, "\u{AD}"];
                    } else {
                        $tail = new GlyphRun($primary, $size, $st['color'], [[$hyphen, $hw, "\u{AD}"]], $hw, $cl['level']);
                    }
                    $w += $hw;
                } elseif ($cl['next'] !== null && $glyphs) {
                    $k = $f->kern($glyphs[count($glyphs) - 1][0], $cl['next']) * $scale;
                    $glyphs[count($glyphs) - 1][1] += $k;
                    $w += $k;
                }
                $runs = $glyphs ? [new GlyphRun($f, $size, $st['color'], $glyphs, $w - ($tail ? $hw : 0.0), $cl['level'], $run->marks)] : [];
                if ($tail !== null) {
                    $runs[] = $tail;
                }
                foreach ($runs as $r) {
                    $r->marker = $st['marker'] ?? null;
                }
                $cl['atoms'][$a][$b] = array_map($atom, $runs);
                $cl['w'][$a][$b] = $w;
            }
        }
        return $cl;
    }

    /**
     * The clusters a line i..j-1 reshapes: [cluster, from letter, to letter, ligature within the
     * line, glyph before it within the line].
     */
    private function clusterShares(array $pre, int $i, int $j): array
    {
        $cls = $pre[10];
        $p0 = $pre[11];
        $x = $cls[$i] ?? null;
        $y = $cls[$j] ?? null;
        $out = [];
        if ($x !== null && $y !== null && $x['cl']['id'] === $y['cl']['id']) {
            return [[$x['cl'], $x['cl']['k'][$x['t']], $y['cl']['k'][$y['t']], $i === $p0[$x['cl']['id']], false]];
        }
        if ($x !== null) {
            $out[] = [$x['cl'], $x['cl']['k'][$x['t']], count($x['cl']['chars']), $i === $p0[$x['cl']['id']], false];
        }
        if ($y !== null) {
            $p = $p0[$y['cl']['id']];
            $out[] = [$y['cl'], 0, $y['cl']['k'][$y['t']], $i < $p && $p < $j, $i < $p];
        }
        return $out;
    }

    /**
     * Font fallback: each character goes to the first face in the font list that has it, else to
     * any registered font that does (as Typst falls back), else stays with the first face.
     * Combining marks stay with the character before them.
     *
     * @param list<\YetiPdf\Write\Font\Face> $faces
     * @return list<array{0: \YetiPdf\Write\Font\Face, 1: string}>
     */
    private function fontRuns(string $text, array $faces, array $st): array
    {
        $primary = $faces[0];
        if ($primary->coversAscii() && !preg_match('/[^\x20-\x7E]/', $text)) {
            return [[$primary, $text]];
        }
        $cmap = $primary->m['cmap'];
        $chars = mb_str_split($text);
        $covered = true;
        foreach ($chars as $c) {
            if (!isset($cmap[mb_ord($c)])) {
                $covered = false;
                break;
            }
        }
        if ($covered) {
            return [[$primary, $text]];
        }
        $runs = [];
        $cur = null;
        foreach ($chars as $c) {
            $cp = mb_ord($c);
            $face = null;
            if ($cur !== null && preg_match('/^\p{M}$/u', $c)) {
                $face = $cur[0];
            } else {
                foreach ($faces as $f) {
                    if ($f->glyph($cp) !== 0) {
                        $face = $f;
                        break;
                    }
                }
                $face ??= $this->fonts->fallback($cp, $st['weight'], $st['italic']) ?? $primary;
            }
            if ($cur !== null && $cur[0] === $face) {
                $cur[1] .= $c;
            } else {
                if ($cur !== null) {
                    $runs[] = $cur;
                }
                $cur = [$face, $c];
            }
        }
        $runs[] = $cur;
        return $runs;
    }

    private array $hyphenWidths = [];

    private function hyphenWidth(\YetiPdf\Write\Font\Face $face, float $size, float $tracking): float
    {
        return $this->hyphenWidths[spl_object_id($face) . ':' . pack('d', $size)]
            ??= $face->advances[$face->glyph(0x2D)] / $face->upem * $size;
    }

    /** @var array<string, bool> */
    private static array $rtlScriptChar = [];

    public function glyphRun(\YetiPdf\Write\Font\Face $face, string $text, float $size, array $color, float $tracking, array $features, int $level = 0, ?string $lang = null, ?string $context = null, ?bool $asciiHint = null): GlyphRun
    {
        $rtl = ($level & 1) === 1;
        // Text in a left-to-right script set right to left (by an override, say) is shaped in
        // display order, as HarfBuzz does: its letters reversed (each with its marks), then set
        // left to right, and the glyphs turned back to reading order at the end.
        $visual = $rtl && !self::rtlScript(preg_match('/[^\p{Common}\p{Inherited}]/u', $text) ? $text : ($context ?? $text));
        if ($visual) {
            preg_match_all('/\X/u', $text, $m);
            $text = implode('', array_reverse($m[0]));
        }
        // (Right-to-left text never takes the plain path: its brackets are mirrored.)
        $ascii = !$rtl && ($asciiHint ?? !preg_match('/[^\x20-\x7E]/', $text));
        if ($ascii && $face->simpleKerning()) {
            $fast = $face->asciiGlyphRun($text, $features, $lang, $context, $size, $tracking);
            if ($fast !== null) {
                return new GlyphRun($face, $size, $color, $fast[0], $fast[1], $level);
            }
        }
        $gl = $face->glyphs($text, $features, $rtl, $ascii, $lang, $context);
        $scale = $size / $face->upem;
        $adv = $face->advances;
        $out = [];
        $w = 0.0;
        $n = count($gl);
        // (A plain run the font deleted a glyph from comes back in the full form.)
        if ($ascii && !isset($gl[0][2])) {
            // (Kerning that places glyphs too goes the full way.)
            $classes = $face->m['classes'] ?? [];
            $gl = array_map(fn ($e) => [$e[0], $e[1], 0, $classes ? ($classes[$e[0]] ?? 0) : 1, 0, 0], $gl);
        }
        // Kerning from the script's own lookups, each passing over what its flags say to (marks,
        // usually) and over ignorables. A pair can place either glyph too, and when it adjusts
        // the second glyph the next pair starts after it, as in HarfBuzz.
        $isMark = [];
        $hasGdefMark = false;
        for ($i = 0; $i < $n; $i++) {
            $m = $gl[$i][3] === 3;
            $isMark[] = $m;
            if ($m) {
                $hasGdefMark = true;
            }
        }
        [$kernLookups, $markLookups, , $positionsMarks] = $face->positioning($text, $lang, $context, $hasGdefMark);
        $hasAnyMark = $hasGdefMark;
        if (!$positionsMarks) {
            // A font that doesn't position marks for the script has its non-spacing marks take no
            // room (HarfBuzz's fallback), drawn where the font put them.
            for ($i = 0; $i < $n; $i++) {
                if (!$isMark[$i] && !empty($gl[$i][6])) {
                    $isMark[$i] = true;
                    $hasAnyMark = true;
                }
            }
        }
        $kern = array_fill(0, $n, 0);
        $place = [];
        foreach ($kernLookups as $lookup) {
            [$flag, $set, $subs] = $lookup;
            $checkSkips = ($flag & 0xFF1E) !== 0;
            $isTable = isset($lookup['table']);
            for ($i = 0; $i < $n; $i++) {
                if ($gl[$i][2] || ($checkSkips && $face->skips($flag, $gl[$i], $set))) {
                    continue;
                }
                $k = $i + 1;
                while ($k < $n && ($gl[$k][2] || ($checkSkips && $face->skips($flag, $gl[$k], $set)))) {
                    $k++;
                }
                if ($k >= $n || ($v = \YetiPdf\Write\Font\Face::pair($subs, $gl[$i][0], $gl[$k][0], $second)) === null) {
                    continue;
                }
                if ($isTable) {
                    // The kern table's value is split: half on the first glyph's advance, half on
                    // the second's, which moves over by that much too (HarfBuzz).
                    $half = $v >> 1;
                    $kern[$i] += $half;
                    $kern[$k] += $v - $half;
                    $place[$k] = [($place[$k][0] ?? 0) + $v - $half, $place[$k][1] ?? 0];
                } elseif (is_int($v)) {
                    $kern[$i] += $v;
                } else {
                    $kern[$i] += $v[0];
                    $kern[$k] += $v[3];
                    if ($v[1] || $v[2]) {
                        $place[$i] = [($place[$i][0] ?? 0) + $v[1], ($place[$i][1] ?? 0) + $v[2]];
                    }
                    if ($v[4] || $v[5]) {
                        $place[$k] = [($place[$k][0] ?? 0) + $v[4], ($place[$k][1] ?? 0) + $v[5]];
                    }
                }
                if ($second) {
                    $i = $k;
                }
            }
        }
        $hasDi = false;
        for ($i = 0; $i < $n; $i++) {
            $g = $gl[$i][0];
            if ($gl[$i][2]) {
                $out[] = [$g, 0.0, $gl[$i][1], 'di' => true]; // a default ignorable: removed below
                $hasDi = true;
                continue;
            }
            if ($isMark[$i]) {
                // A mark takes no room; it's placed on its base below (offsets mark it as one).
                $out[] = [$g, 0.0, $gl[$i][1], ($place[$i][0] ?? 0) * $scale, ($place[$i][1] ?? 0) * $scale];
                continue;
            }
            $a = ($adv[$g] + $kern[$i]) * $scale + $tracking;
            $out[] = isset($place[$i]) ? [$g, $a, $gl[$i][1], $place[$i][0] * $scale, $place[$i][1] * $scale] : [$g, $a, $gl[$i][1]];
            $w += $a;
        }
        if ($hasAnyMark && $markLookups) {
            // A mark sits where its anchor meets its base's: offset from where the pen would put
            // it, past the glyphs between (before them in right-to-left text, which is reversed
            // for display), plus whatever offset its base has.
            $attached = $face->attach($gl, $markLookups);
            if ($attached) {
                ksort($attached);
                foreach ($attached as $i => [$j, $dx, $dy]) {
                    $x = $dx * $scale + ($out[$j][3] ?? 0.0);
                    if ($rtl && !$visual) {
                        for ($k = $j + 1; $k <= $i; $k++) {
                            $x += $out[$k][1];
                        }
                    } else {
                        for ($k = $j; $k < $i; $k++) {
                            $x -= $out[$k][1];
                        }
                    }
                    $out[$i][3] = $x;
                    $out[$i][4] = $dy * $scale + ($out[$j][4] ?? 0.0);
                }
            }
        }
        if ($hasDi) {
            $out = $this->dropIgnorables($out, $rtl && !$visual);
        }
        if ($visual) {
            $out = array_reverse($out);
            foreach ($out as $k => $g) {
                if (preg_match_all('/\X/u', $g[2], $m) > 1) {
                    $out[$k][2] = implode('', array_reverse($m[0]));
                }
            }
        }
        return new GlyphRun($face, $size, $color, $out, $w, $level, $place || $hasAnyMark || (!$ascii && preg_match('/\p{M}/u', $text) === 1));
    }

    /**
     * Whether text's script (that of its first letter not common to scripts) is written right to
     * left; text with no such letter counts as left to right, as in HarfBuzz.
     */
    private static function rtlScript(string $text): bool
    {
        if (preg_match('/[^\p{Common}\p{Inherited}]/u', $text, $m) !== 1) {
            return false;
        }
        $ch = $m[0];
        return self::$rtlScriptChar[$ch] ??= preg_match('/[\p{Arabic}\p{Hebrew}\p{Syriac}\p{Thaana}\p{Nko}\p{Samaritan}\p{Mandaic}\p{Adlam}\p{Hanifi_Rohingya}'
            . '\p{Cypriot}\p{Kharoshthi}\p{Phoenician}\p{Lydian}\p{Avestan}\p{Imperial_Aramaic}\p{Inscriptional_Pahlavi}'
            . '\p{Inscriptional_Parthian}\p{Old_South_Arabian}\p{Old_North_Arabian}\p{Old_Turkic}\p{Meroitic_Cursive}'
            . '\p{Meroitic_Hieroglyphs}\p{Manichaean}\p{Mende_Kikakui}\p{Nabataean}\p{Palmyrene}\p{Psalter_Pahlavi}\p{Hatran}'
            . '\p{Old_Sogdian}\p{Sogdian}\p{Elymaic}\p{Chorasmian}\p{Yezidi}\p{Old_Uyghur}\p{Garay}]/u', $ch) === 1;
    }

    /**
     * Default ignorables (direction marks, joiners) draw nothing: as in HarfBuzz their glyphs go
     * and their text joins the glyph before them on the line (or after, when there's none), so
     * copied text keeps them. In right-to-left text the glyph before on the line comes after in
     * reading order.
     */
    private function dropIgnorables(array $glyphs, bool $rtl): array
    {
        $out = [];
        $pending = '';
        foreach ($rtl ? array_reverse($glyphs) : $glyphs as $g) {
            if (isset($g['di'])) {
                if ($out) {
                    $k = count($out) - 1;
                    $out[$k][2] = $rtl ? $g[2] . $out[$k][2] : $out[$k][2] . $g[2];
                } else {
                    $pending = $rtl ? $g[2] . $pending : $pending . $g[2];
                }
                continue;
            }
            if ($pending !== '') {
                $g[2] = $rtl ? $g[2] . $pending : $pending . $g[2];
                $pending = '';
            }
            $out[] = $g;
        }
        return $rtl ? array_reverse($out) : $out;
    }

    /**
     * Prefix sums over segments so any line (segments i..j-1) is measured in O(1): width,
     * space stretch (½ space) and shrink (⅓ space), and the number of spaces.
     */
    private function prefix(array $segs): array
    {
        $cw = [0.0];
        $cs = [0.0];
        $ck = [0.0];
        $cj = [0];
        $gw = [];
        $gs = [];
        $gk = [];
        $gj = [];
        $hyph = [];
        $hw = [];
        $cls = [];
        $p0 = [];
        $wAcc = 0.0;
        $sAcc = 0.0;
        $kAcc = 0.0;
        $jAcc = 0;
        foreach ($segs as $k => $s) {
            $g = $s['glue'];
            $forced = $s['forced'];
            if ($g === null || $forced) {
                $w0 = 0.0;
                $gw[] = 0.0;
                $gs[] = 0.0;
                $gk[] = 0.0;
                $gj[] = 0;
                $hyph[] = null;
                $hw[] = 0.0;
                if ($g !== null && isset($g['cl'])) {
                    $cls[$k] = $g;
                    if ($g['t'] === 0) {
                        $p0[$g['cl']['id']] = $k;
                    }
                }
            } else {
                $w0 = $g['w'];
                $gw[] = $w0;
                if (isset($g['gid'])) {
                    $s0 = $w0 / 2;
                    $k0 = $w0 / 3;
                    $gs[] = $s0;
                    $gk[] = $k0;
                    $gj[] = 1;
                    $sAcc += $s0;
                    $kAcc += $k0;
                    $jAcc++;
                } else {
                    $gs[] = 0.0;
                    $gk[] = 0.0;
                    $gj[] = 0;
                }
                if (isset($g['cl'])) {
                    $hyph[] = null;
                    $hw[] = 0.0;
                    $cls[$k] = $g;
                    if ($g['t'] === 0) {
                        $p0[$g['cl']['id']] = $k;
                    }
                } elseif (isset($g['hyph'])) {
                    $hyph[] = $g;
                    $hw[] = $g['hw'];
                } else {
                    $hyph[] = null;
                    $hw[] = 0.0;
                }
            }
            $wAcc = $wAcc + $w0 + $s['w'];
            $cw[] = $wAcc;
            $cs[] = $sAcc;
            $ck[] = $kAcc;
            $cj[] = $jAcc;
        }
        $hyph[] = null;
        $hw[] = 0.0;
        return [$cw, $cs, $ck, $cj, $gw, $gs, $gk, $gj, $hyph, $hw, $cls, $p0];
    }

    /** Line i..j-1: [width (with a hyphen if it breaks inside a word), stretch, shrink, spaces, hyphen glue|null]. */
    private function measure(array $segs, array $pre, int $i, int $j): array
    {
        [$cw, $cs, $ck, $cj, $gw, $gs, $gk, $gj, $hyphs, $hw] = $pre;
        $hyph = $hyphs[$j];
        $w = $cw[$j] - $cw[$i] - $gw[$i] + $hw[$j];
        if ($pre[10]) {
            // Ligatures the line starts or ends inside: their letters reshaped instead.
            foreach ($this->clusterShares($pre, $i, $j) as [$cl, $a, $b, $whole, $before]) {
                $w += $cl['w'][$a][$b] - ($whole ? $cl['gw'] : 0.0) + ($before ? $cl['fix'][$b] : 0.0);
            }
        }
        return [
            $w,
            $cs[$j] - $cs[$i] - $gs[$i],
            $ck[$j] - $ck[$i] - $gk[$i],
            $cj[$j] - $cj[$i] - $gj[$i],
            $hyph,
        ];
    }

    private function buildRange(array $segs, array $pre, int $i, int $j, bool $mandatory): array
    {
        $hyph = $pre[8][$j];
        $w = $pre[0][$j] - $pre[0][$i] - $pre[4][$i] + $pre[9][$j];
        // Ligatures the line starts or ends inside: their share of the letters, reshaped, goes where
        // the ligature was, or at the start or end of the line when the ligature isn't on it.
        $shares = $pre[10] ? $this->clusterShares($pre, $i, $j) : [];
        if ($shares) {
            foreach ($shares as [$cl, $a, $b, $whole, $before]) {
                $w += $cl['w'][$a][$b] - ($whole ? $cl['gw'] : 0.0) + ($before ? $cl['fix'][$b] : 0.0);
            }
        }
        $line = ['atoms' => [], 'w' => $w, 'mandatory' => $mandatory];
        $swap = [];
        foreach ($shares as $n => [$cl, , , $whole]) {
            if ($whole) {
                $swap[$pre[11][$cl['id']]] = $n;
            }
        }
        $fixAt = [];
        for ($k = $i; $k < $j; $k++) {
            if ($k > $i && ($segs[$k]['glue']['w'] ?? 0.0) > 0) {
                $line['atoms'][] = ['space', $segs[$k]['glue']['w'], $segs[$k]['glue']];
            }
            $atoms = $segs[$k]['atoms'];
            if (isset($swap[$k])) {
                [$cl, $a, $b, , $before] = $shares[$swap[$k]];
                array_splice($atoms, 0, 1, $cl['atoms'][$a][$b]);
                if ($before) {
                    $fixAt[] = [count($line['atoms']), $cl['fix'][$b]];
                }
            }
            array_push($line['atoms'], ...$atoms);
        }
        foreach ($shares as [$cl, $a, $b, $whole, $before]) {
            if ($whole) {
                continue;
            }
            $atoms = $cl['atoms'][$a][$b];
            if ($a > 0) {
                array_unshift($line['atoms'], ...$atoms);
                foreach ($fixAt as $n => [$at, $d]) {
                    $fixAt[$n][0] = $at + count($atoms);
                }
            } else {
                if ($before) {
                    $fixAt[] = [count($line['atoms']), $cl['fix'][$b]];
                }
                array_push($line['atoms'], ...$atoms);
            }
        }
        // The glyph before a reshaped ligature is kerned against its first letter instead.
        foreach ($fixAt as [$at, $d]) {
            for ($n = $at - 1; $d != 0.0 && $n >= 0; $n--) {
                if ($line['atoms'][$n][0] === 'run') {
                    $r = $line['atoms'][$n][1];
                    $glyphs = $r->glyphs;
                    $glyphs[count($glyphs) - 1][1] += $d;
                    $line['atoms'][$n][1] = new GlyphRun($r->face, $r->size, $r->color, $glyphs, $r->width + $d, $r->level, $r->marks);
                    break;
                }
            }
        }
        if ($hyph !== null) {
            if ($hyph['kern'] != 0.0) {
                for ($a = count($line['atoms']) - 1; $a >= 0; $a--) {
                    if ($line['atoms'][$a][0] === 'run') {
                        $r = $line['atoms'][$a][1];
                        $glyphs = $r->glyphs;
                        $glyphs[count($glyphs) - 1][1] += $hyph['kern'];
                        $line['atoms'][$a][1] = new GlyphRun($r->face, $r->size, $r->color, $glyphs, $r->width + $hyph['kern'], $r->level, $r->marks);
                        break;
                    }
                }
            }
            // The hyphen ends the word: it goes with the run before it, in its direction.
            $level = 0;
            for ($a = count($line['atoms']) - 1; $a >= 0; $a--) {
                if ($line['atoms'][$a][0] === 'run') {
                    $level = $line['atoms'][$a][1]->level;
                    break;
                }
            }
            $run = $this->glyphRun($hyph['face'], '-', $hyph['size'], $hyph['color'], 0.0, [], $level);
            // An inserted hyphen extracts as a soft hyphen, so copied text rejoins the word.
            $run->glyphs[0][2] = "\u{AD}";
            $line['atoms'][] = ['run', $run, $hyph['face']->capHeight() * $hyph['size'], $hyph['size'], $hyph['link'], $hyph['overhang']];
        }
        return $line;
    }

    /**
     * First-fit line breaking (Typst's "simple" mode): take the longest line that fits.
     * Lines are [atoms, w, mandatory]; mandatory lines end at a forced break or the paragraph end.
     */
    private function breakSimple(array $segs, float $width): array
    {
        $n = count($segs);
        if ($n === 0) {
            return [['atoms' => [], 'w' => 0.0, 'mandatory' => true]];
        }
        if ($n === 1 && !$segs[0]['forced'] && !isset($segs[0]['glue']['cl'])) {
            return [['atoms' => $segs[0]['atoms'], 'w' => $segs[0]['w'], 'mandatory' => true]];
        }
        $pre = $this->prefix($segs);
        $lines = [];
        if ($segs[0]['forced']) {
            $lines[] = ['atoms' => [], 'w' => 0.0, 'mandatory' => true];
        }
        $start = 0;
        $last = null;
        for ($j = 1; $j <= $n; $j++) {
            $mandatory = $j === $n || $segs[$j]['forced'];
            $w = $this->measure($segs, $pre, $start, $j)[0];
            if ($w > $width + self::EPS && $last !== null) {
                $lines[] = $this->buildRange($segs, $pre, $start, $last, false);
                $start = $last;
                $w = $this->measure($segs, $pre, $start, $j)[0];
            }
            if ($mandatory || $w > $width + self::EPS) {
                $lines[] = $this->buildRange($segs, $pre, $start, $j, $mandatory);
                $start = $j;
                $last = null;
            } else {
                $last = $j;
            }
        }
        return $lines ?: [['atoms' => [], 'w' => 0.0, 'mandatory' => true]];
    }

    /**
     * Knuth-Plass line breaking, following Typst's "optimized" mode: each line costs
     * (1 + 100|r|³ + penalties)², where r is how far its spaces must stretch (up to 1.5× their
     * width) or shrink (down to ⅔). Penalties: a lone word on the last line, a hyphenated
     * break (more near the word's edges), and two lines in a row ending in a dash.
     */
    private function breakOptimized(array $segs, float $width, bool $justify, float $fontSize): array
    {
        $hasForced = false;
        foreach ($segs as $s) {
            if ($s['forced']) {
                $hasForced = true;
                break;
            }
        }
        if (!$hasForced) {
            return $this->knuthPlass($segs, $width, $justify, $fontSize);
        }
        // Forced breaks split the paragraph into independent runs of lines.
        $chunks = [[]];
        foreach ($segs as $s) {
            if ($s['forced'] && $chunks[count($chunks) - 1] !== []) {
                $chunks[] = [];
            }
            $chunks[count($chunks) - 1][] = $s;
        }
        // A forced break before anything else leaves an empty first line (as in simple mode).
        $lines = $segs && $segs[0]['forced'] ? [['atoms' => [], 'w' => 0.0, 'mandatory' => true]] : [];
        foreach ($chunks as $chunk) {
            array_push($lines, ...$this->knuthPlass($chunk, $width, $justify, $fontSize));
        }
        return $lines;
    }

    private function knuthPlass(array $segs, float $width, bool $justify, float $fontSize): array
    {
        $n = count($segs);
        if ($n === 0) {
            return [['atoms' => [], 'w' => 0.0, 'mandatory' => true]];
        }
        $pre = $this->prefix($segs);
        [$cw, $cs, $ck, $cj, $gw, $gs, $gk, $gj, , $hw, $cls] = $pre;
        $hasClusters = $cls !== [];
        $minRatio = $justify ? -1.0 : 0.0;
        $halfFont = $fontSize / 2;

        // Per break: whether the line ending there ends in a dash (two in a row cost extra) and
        // the penalty for breaking there with a hyphen.
        $dashAt = [];
        $penaltyAt = [];
        for ($j = 1; $j < $n; $j++) {
            $dash = false;
            $hyphenPenalty = 0.0;
            $jHyph = $segs[$j]['glue']['hyph'] ?? null;
            if ($jHyph !== null) {
                $dash = true;
                $l = $jHyph[0];
                $r = $jHyph[1];
                $hyphenPenalty = (1.0 + 0.15 * (($l < 5 ? 5 - $l : 0) + ($r < 5 ? 5 - $r : 0))) * 135.0;
            } else {
                $atoms = $segs[$j - 1]['atoms'];
                if ($atoms) {
                    $lastAtom = $atoms[count($atoms) - 1];
                    if ($lastAtom[0] === 'run' && $lastAtom[1]->glyphs) {
                        $gl = $lastAtom[1]->glyphs;
                        $t = $gl[count($gl) - 1][2];
                        $dash = $t === '-' || $t === "\u{2013}" || $t === "\u{2014}" || $t === "\u{ad}";
                    }
                }
            }
            $dashAt[$j] = $dash;
            $penaltyAt[$j] = $hyphenPenalty;
        }
        $dashAt[$n] = false;
        $penaltyAt[$n] = 0.0;

        // An upper bound for the best total, as Typst bounds its search: the total of one valid
        // set of breaks (each line running to the furthest break that doesn't overfill it). A
        // break whose best total is already above it can't be on the best path, so it's dropped,
        // and later lines don't try to start there. The result is the same, with far fewer tries.
        // (The lines are picked with a quick width check; only the picked ones are costed exactly.
        // Any set of breaks gives a valid bound, and if the search can't match it, it runs unbounded.)
        $bound = 0.0;
        for ($i = 0; $i < $n;) {
            $next = $i + 1;
            $cwI = $cw[$i];
            $gwI = $gw[$i];
            $ckI = $ck[$i];
            $gkI = $gk[$i];
            for ($j = $i + 2; $j <= $n; $j++) {
                $delta = $width - ($cw[$j] - $cwI - $gwI + $hw[$j]);
                if ($delta < 0.0) {
                    $shrink = $ck[$j] - $ckI - $gkI;
                    if ($minRatio === 0.0 || $shrink <= 0.0 || $delta / $shrink < $minRatio) {
                        break;
                    }
                }
                $next = $j;
            }
            // Exact cost of line $i..$next.
            $w = $cw[$next] - $cwI - $gwI + $hw[$next];
            if ($hasClusters) {
                foreach ($this->clusterShares($pre, $i, $next) as [$cl, $a, $b, $whole, $before]) {
                    $w += $cl['w'][$a][$b] - ($whole ? $cl['gw'] : 0.0) + ($before ? $cl['fix'][$b] : 0.0);
                }
            }
            $delta = $width - $w;
            if ($delta > -1e-9 && $delta < 1e-9) {
                $delta = 0.0;
            }
            $adjust = $delta >= 0.0 ? $cs[$next] - $cs[$i] - $gs[$i] : $ck[$next] - $ckI - $gkI;
            if ($adjust > 0.0) {
                $ratio = $delta / $adjust;
            } elseif ($delta == 0.0) {
                $ratio = 0.0;
            } else {
                $ratio = $delta > 0.0 ? INF : -INF;
            }
            if ($ratio > 1.0) {
                $spaces = $cj[$next] - $cj[$i] - $gj[$i];
                $ratio = 1.0 + (($delta - $adjust) / ($spaces > 1 ? $spaces : 1)) / $halfFont;
            }
            if ($ratio < -2.0) {
                $ratio = -2.0;
            } elseif ($ratio > 10.0) {
                $ratio = 10.0;
            }
            $mandatory = $next === $n;
            if ($ratio < $minRatio) {
                $badness = 1_000_000.0;
            } elseif (!$mandatory || $ratio < 0.0) {
                $badness = 100.0 * abs($ratio) ** 3;
            } else {
                $badness = 0.0;
            }
            $penalty = $penaltyAt[$next];
            if ($mandatory && $i === $next - 1) {
                $penalty += 100.0;
            }
            if ($dashAt[$next] && ($dashAt[$i] ?? false)) {
                $penalty += 135.0;
            }
            $bound = $bound + (1.0 + $badness + $penalty) ** 2;
            $i = $next;
        }

        $lines = $this->knuthPlassTable($segs, $pre, $n, $minRatio, $halfFont, $width, $dashAt, $penaltyAt, $bound);
        if ($lines === null) {
            // (Only if the bound came from breaks the search itself wouldn't make.)
            $lines = $this->knuthPlassTable($segs, $pre, $n, $minRatio, $halfFont, $width, $dashAt, $penaltyAt, INF);
        }
        return $lines;
    }

    /** The Knuth-Plass table (see knuthPlass()), searched under $bound; null when no path fits under it. */
    private function knuthPlassTable(array $segs, array $pre, int $n, float $minRatio, float $halfFont, float $width, array $dashAt, array $penaltyAt, float $bound): ?array
    {
        [$cw, $cs, $ck, $cj, $gw, $gs, $gk, $gj, , $hw, $cls] = $pre;
        $hasClusters = $cls !== [];
        $tTotal = [0 => 0.0];
        $tPred = [0 => -1];
        $tDash = [0 => false];
        $dropped = []; // breaks whose best total was over the bound
        $candidates = [0];
        $candidateHead = 0;
        $candidateCount = 1;
        $active = 0;
        for ($j = 1; $j <= $n; $j++) {
            $cwJ = $cw[$j];
            $hwJ = $hw[$j];
            $csJ = $cs[$j];
            $ckJ = $ck[$j];
            $cjJ = $cj[$j];
            // Rejected breaks still advance the original first-candidate boundary when full.
            while ($active < $j && isset($dropped[$active])) {
                $aw = $cwJ - $cw[$active] - $gw[$active] + $hwJ;
                if ($hasClusters) {
                    foreach ($this->clusterShares($pre, $active, $j) as [$cl, $a, $b, $whole, $before]) {
                        $aw += $cl['w'][$a][$b] - ($whole ? $cl['gw'] : 0.0) + ($before ? $cl['fix'][$b] : 0.0);
                    }
                }
                $ad = $width - $aw;
                if ($ad > -1e-9) {
                    break;
                }
                if ($minRatio < 0.0) {
                    $ashrink = $ckJ - $ck[$active] - $gk[$active];
                    if ($ashrink > 0.0 && $ad / $ashrink >= $minRatio) {
                        break;
                    }
                }
                $active++;
            }
            $mandatory = $j === $n;
            $dash = $dashAt[$j];
            $hyphenPenalty = $penaltyAt[$j];
            $bestTotal = 0.0;
            $bestPred = -1;
            $cutoff = $bound;
            // Only retained breaks can contribute a cost. Keep their ascending order for ties.
            for ($candidate = $candidateHead; $candidate < $candidateCount; $candidate++) {
                $i = $candidates[$candidate];
                $ti = $tTotal[$i];
                if ($bestPred >= 0 && $i !== $active && $ti > $cutoff) {
                    continue;
                }
                // (Same evaluation order as before: float sums must round identically.)
                $w = $cwJ - $cw[$i] - $gw[$i] + $hwJ;
                if ($hasClusters) {
                    foreach ($this->clusterShares($pre, $i, $j) as [$cl, $a, $b, $whole, $before]) {
                        $w += $cl['w'][$a][$b] - ($whole ? $cl['gw'] : 0.0) + ($before ? $cl['fix'][$b] : 0.0);
                    }
                }
                $delta = $width - $w;
                if ($delta > -1e-9) {
                    if ($bestPred >= 0 && $ti > $cutoff) {
                        continue;
                    }
                    if ($mandatory || $delta < 1e-9) {
                        $badness = 0.0;
                    } else {
                        $adjust = $csJ - $cs[$i] - $gs[$i];
                        if ($adjust > 0.0) {
                            $ratio = $delta / $adjust;
                        } else {
                            $ratio = INF;
                        }
                        if ($ratio > 1.0) {
                            $spaces = $cjJ - $cj[$i] - $gj[$i];
                            $ratio = 1.0 + (($delta - $adjust) / ($spaces > 1 ? $spaces : 1)) / $halfFont;
                        }
                        if ($ratio >= 10.0) {
                            if ($bestPred >= 0 && $cutoff < 10_000_200_001.0) {
                                continue;
                            }
                            $badness = 100_000.0;
                        } else {
                            $badness = 100.0 * $ratio ** 3;
                        }
                    }
                } else {
                    $adjust = $ckJ - $ck[$i] - $gk[$i];
                    if ($adjust > 0.0) {
                        $ratio = $delta / $adjust;
                    } else {
                        $ratio = -INF;
                    }
                    if ($ratio < $minRatio) {
                        if ($active === $i) {
                            $candidateHead = $candidate + 1;
                            $active++;
                            while ($active < $j && isset($dropped[$active])) {
                                $aw = $cwJ - $cw[$active] - $gw[$active] + $hwJ;
                                if ($hasClusters) {
                                    foreach ($this->clusterShares($pre, $active, $j) as [$cl, $a, $b, $whole, $before]) {
                                        $aw += $cl['w'][$a][$b] - ($whole ? $cl['gw'] : 0.0) + ($before ? $cl['fix'][$b] : 0.0);
                                    }
                                }
                                $ad = $width - $aw;
                                if ($ad > -1e-9) {
                                    break;
                                }
                                if ($minRatio < 0.0) {
                                    $ashrink = $ckJ - $ck[$active] - $gk[$active];
                                    if ($ashrink > 0.0 && $ad / $ashrink >= $minRatio) {
                                        break;
                                    }
                                }
                                $active++;
                            }
                        }
                        if ($bestPred >= 0 && $cutoff < 1_000_002_000_001.0) {
                            continue;
                        }
                        $badness = 1_000_000.0;
                    } else {
                        if ($bestPred >= 0 && $ti > $cutoff) {
                            continue;
                        }
                        if ($ratio < -2.0) {
                            $ratio = -2.0;
                        }
                        $badness = 100.0 * abs($ratio) ** 3;
                    }
                }
                $penalty = $hyphenPenalty;
                if ($mandatory && $i === $j - 1) {
                    $penalty += 100.0; // runt
                }
                if ($dash && $tDash[$i]) {
                    $penalty += 135.0;
                }
                $total = $ti + (1.0 + $badness + $penalty) ** 2;
                if ($bestPred < 0 || $bestTotal >= $total) {
                    $bestTotal = $total;
                    $bestPred = $i;
                    if ($total < $cutoff) {
                        $cutoff = $total;
                    }
                }
            }
            if ($bestPred >= 0) {
                if ($bestTotal > $bound) {
                    $dropped[$j] = true;
                    continue;
                }
                $tTotal[$j] = $bestTotal;
                $tPred[$j] = $bestPred;
                $tDash[$j] = $dash;
                $candidates[] = $j;
                $candidateCount++;
            }
        }
        if (!isset($tTotal[$n])) {
            return null;
        }

        // Retrace the cheapest path and build the lines.
        $breaks = [];
        for ($j = $n; $j > 0; $j = $tPred[$j]) {
            $breaks[] = [$tPred[$j], $j];
        }
        $tTotal = $tPred = $tDash = null;
        $lines = [];
        for ($k = count($breaks) - 1; $k >= 0; $k--) {
            [$i, $j] = $breaks[$k];
            $lines[] = $this->buildRange($segs, $pre, $i, $j, $j === $n);
        }
        return $lines;
    }

    /**
     * A broken line in display order (UAX #9 L1 and L2): trailing spacing goes back to the
     * paragraph's level, runs are reordered by level, and right-to-left runs have their glyphs
     * reversed.
     */
    private function reorder(array $line, int $base): array
    {
        $atoms = $line['atoms'];
        $levels = [];
        foreach ($atoms as $a) {
            $levels[] = match ($a[0]) {
                'run' => $a[1]->level,
                'space' => $a[2]['lv'] ?? $base,
                'box' => $a[3] ?? $base,
                default => $a[2] ?? $base,
            };
        }
        for ($i = count($atoms) - 1; $i >= 0 && in_array($atoms[$i][0], ['space', 'h', 'fr'], true); $i--) {
            $levels[$i] = $base;
        }
        if (!$levels || max($levels) === 0) {
            return $line;
        }
        // Where each atom sits in reading order, which the content is written in (as Typst
        // does): text from one item at one level counts as one piece, written in display order
        // within itself, so a word and the fallback-font punctuation next to it stay together.
        $keys = [];
        $key = 0;
        $prev = null;
        foreach ($atoms as $i => $a) {
            $group = $a[0] === 'run' && isset($a[6]) ? $a[6] . ':' . $levels[$i] : null;
            if ($a[0] !== 'space' && ($group === null || $group !== $prev)) {
                $key = $i;
            }
            $keys[$i] = $key;
            if ($a[0] !== 'space') {
                $prev = $group;
            }
        }
        $out = [];
        $outKeys = [];
        foreach (Bidi::order($levels) as $i) {
            $a = $atoms[$i];
            $outKeys[] = $keys[$i];
            if ($a[0] === 'run' && ($a[1]->level & 1) === 1) {
                $r = $a[1];
                // Reversed whole, marks and all, as HarfBuzz gives them (their offsets assume it).
                $a[1] = new GlyphRun($r->face, $r->size, $r->color, array_reverse($r->glyphs), $r->width, $r->level, $r->marks);
                $a[1]->marker = $r->marker;
            }
            $out[] = $a;
        }
        $line['atoms'] = $out;
        $line['keys'] = $outKeys;
        return $line;
    }

    private function buildLine(array $l, float $parW, string $align, float $strut, bool $rtl = false): Frame
    {
        $top = 0.0;
        $bottom = 0.0;
        $fr = 0.0;
        $spaces = 0;
        $stretch = 0.0;
        $shrink = 0.0;
        $atoms = $l['atoms'];
        foreach ($atoms as $a) {
            if ($a[0] === 'run') {
                if ($a[2] > $top) {
                    $top = $a[2];
                }
            } elseif ($a[0] === 'space') {
                $spaces++;
                if (isset($a[2]['gid'])) {
                    $stretch += $a[1] / 2;
                    $shrink += $a[1] / 3;
                }
            } elseif ($a[0] === 'box') {
                $top = max($top, $a[1]->baseline);
                $bottom = max($bottom, $a[1]->h - $a[1]->baseline);
            } elseif ($a[0] === 'fr') {
                $fr += $a[1];
            }
        }
        if (!$atoms) {
            $top = $strut;
        }
        // Justification as in Typst: overfull lines shrink their spaces (to ⅔ at most); justified
        // lines stretch them (to 1.5×), then share whatever is left equally.
        // Hanging punctuation, as Typst does it on the line in display order: at the left when
        // the line starts with right-to-left text, at the right when it ends with left-to-right.
        $hangLeft = $atoms && $atoms[0][0] === 'run' && ($atoms[0][1]->level & 1) === 1 ? $this->overhang($atoms, true) : 0.0;
        $hangRight = $atoms ? $this->overhang($atoms, false) : 0.0;
        $remaining = is_finite($parW) ? $parW - $l['w'] + $hangLeft + $hangRight : 0.0;
        $ratio = 0.0;
        $extra = 0.0;
        if ($remaining < 0 && $shrink > 0) {
            $ratio = max($remaining / $shrink, -1.0);
            $remaining = min($remaining + $shrink, 0.0);
        } elseif ($align === 'justify' && !$l['mandatory'] && $fr == 0.0) {
            if ($stretch > 0) {
                $ratio = min($remaining / $stretch, 1.0);
                $remaining = max($remaining - $stretch, 0.0);
            }
            if ($spaces > 0 && $remaining > 0) {
                $extra = $remaining / $spaces;
                $remaining = 0.0;
            }
        }
        $free = max(0.0, $remaining);
        $frUnit = $fr > 0 && is_finite($parW) ? $free / $fr : 0.0;
        $x = -$hangLeft;
        if ($fr == 0.0) {
            // An overfull line keeps its alignment too, sticking out on the left (as in Typst). A
            // justified line that doesn't fill (the last one) sits at the start.
            $side = $align === 'justify' ? ($rtl ? 'right' : 'left') : $align;
            if ($side === 'right') {
                $x += $remaining;
            } elseif ($side === 'center') {
                $x += $remaining / 2;
            }
        }
        $f = new Frame($fr > 0 ? $parW : $l['w'], $top + $bottom, $top);
        // Words in the same font, size and colour join into one run with real space glyphs
        // between them, so text extraction sees the spaces and the content stream stays small.
        $open = null;
        $openShared = false;
        $openItemIdx = -1;
        $joinable = false;
        // Link areas: consecutive atoms with the same href share one rectangle (spaces between
        // them included), from the font's ascender to its descender.
        $link = null;
        $links = [];
        // A reordered line's runs and boxes, [reading-order key, x, y, run or frame]: placed left
        // to right, written in reading order (as Typst does), so text extraction follows the text.
        $lineKeys = $l['keys'] ?? null;
        $reordered = $lineKeys !== null;
        $draw = [];
        $openAt = null;
        foreach ($atoms as $n => $a) {
            $key = $reordered ? $lineKeys[$n] : $n;
            switch ($a[0]) {
                case 'run':
                    $r = $a[1];
                    $href = $a[4] ?? null;
                    if ($href !== null || $link !== null) {
                        $up = $r->face->ascender() * $r->size;
                        $down = -$r->face->descender() * $r->size;
                        if ($href !== null && $link !== null && $link['href'] === $href) {
                            $link['x1'] = $x + $r->width;
                            $link['up'] = max($link['up'], $up);
                            $link['down'] = max($link['down'], $down);
                        } else {
                            if ($link !== null) {
                                $links[] = $link;
                            }
                            $link = $href === null ? null : ['href' => $href, 'x0' => $x, 'x1' => $x + $r->width, 'up' => $up, 'down' => $down];
                        }
                    }
                    // (On a reordered line, only text written as one piece joins: see reorder().)
                    if ($open !== null && $joinable && $r->marker === null && $open->marker === null
                        && $open->face === $r->face && $open->size === $r->size && $open->color === $r->color && $open->level === $r->level
                        && (!$reordered || $draw[$openAt][0] === $key)) {
                        if ($openShared) {
                            $marker = $open->marker;
                            $open = new GlyphRun($open->face, $open->size, $open->color, $open->glyphs, $open->width, $open->level, $open->marks);
                            $open->marker = $marker;
                            $openShared = false;
                            if ($reordered) {
                                $draw[$openAt][3] = $open;
                            } else {
                                $f->items[$openItemIdx][2] = $open;
                            }
                        }
                        foreach ($r->glyphs as $g) {
                            $open->glyphs[] = $g;
                        }
                        $open->width += $r->width;
                        $open->marks = $open->marks || $r->marks;
                    } else {
                        $open = $r;
                        $openShared = true;
                        if ($reordered) {
                            $draw[] = [$key, $x, $top, $open];
                            $openAt = count($draw) - 1;
                        } else {
                            $openItemIdx = count($f->items);
                            $f->push($x, $top, $open);
                        }
                    }
                    $joinable = true;
                    $x += $r->width;
                    break;
                case 'box':
                    $href = $a[2] ?? null;
                    if ($href !== null || $link !== null) {
                        $up = $a[1]->baseline;
                        $down = $a[1]->h - $a[1]->baseline;
                        if ($href !== null && $link !== null && $link['href'] === $href) {
                            $link['x1'] = $x + $a[1]->w;
                            $link['up'] = max($link['up'], $up);
                            $link['down'] = max($link['down'], $down);
                        } else {
                            if ($link !== null) {
                                $links[] = $link;
                            }
                            $link = $href === null ? null : ['href' => $href, 'x0' => $x, 'x1' => $x + $a[1]->w, 'up' => $up, 'down' => $down];
                        }
                    }
                    if ($reordered) {
                        $draw[] = [$key, $x, $top - $a[1]->baseline, $a[1]];
                    } else {
                        $f->place($x, $top - $a[1]->baseline, $a[1]);
                    }
                    $x += $a[1]->w;
                    $open = null;
                    break;
                case 'space':
                    $g = $a[2];
                    $w = $a[1] + (isset($g['gid']) ? $ratio * ($ratio < 0 ? $a[1] / 3 : $a[1] / 2) + $extra : 0.0);
                    if ($open !== null && isset($g['gid']) && $open->face === $g['face'] && $open->size === $g['size'] && $open->color === $g['color']) {
                        if ($openShared) {
                            $marker = $open->marker;
                            $open = new GlyphRun($open->face, $open->size, $open->color, $open->glyphs, $open->width, $open->level, $open->marks);
                            $open->marker = $marker;
                            $openShared = false;
                            if ($reordered) {
                                $draw[$openAt][3] = $open;
                            } else {
                                $f->items[$openItemIdx][2] = $open;
                            }
                        }
                        $open->glyphs[] = [$g['gid'], $w, ' '];
                        $open->width += $w;
                        $joinable = true;
                    } else {
                        $open = null;
                    }
                    $x += $w;
                    break;
                case 'h':
                    $x += $a[1];
                    $open = null;
                    break;
                case 'fr':
                    $x += $a[1] * $frUnit;
                    $open = null;
                    break;
            }
        }
        if ($link !== null) {
            $links[] = $link;
        }
        if ($draw) {
            usort($draw, fn ($a, $b) => $a[0] <=> $b[0]);
        }
        foreach ($draw as [, $dx, $dy, $item]) {
            if ($item instanceof Frame) {
                $f->place($dx, $dy, $item);
            } else {
                $f->push($dx, $dy, $item);
            }
        }
        foreach ($links as $k) {
            $f->push($k['x0'], $top - $k['up'], ['link', $k['x1'] - $k['x0'], $k['up'] + $k['down'], $k['href']]);
        }
        if ($align !== 'left' && $fr == 0.0) {
            $f->w = $parW;
        }
        return $f;
    }

    /**
     * Hanging punctuation (Typst's text.overhang): part of a trailing hyphen, dash or punctuation
     * mark may hang into the margin, giving the rest of the line that much more room.
     */
    private function overhang(array $atoms, bool $left): float
    {
        $edge = $left ? $atoms[0] : $atoms[count($atoms) - 1];
        if ($edge[0] !== 'run' || !($edge[5] ?? true) || !$edge[1]->glyphs || ($edge[1]->level % 2 === 1) !== $left) {
            return 0.0;
        }
        $run = $edge[1];
        if (count($atoms) < 2 && count($run->glyphs) < 2) {
            return 0.0;
        }
        [$gid, , $text] = $left ? $run->glyphs[0] : $run->glyphs[count($run->glyphs) - 1];
        $factor = match ($text) {
            "\u{2013}", "\u{2014}" => 0.2,
            '-', "\u{AD}" => 0.55,
            '.', ',' => 0.8,
            ':', ';' => 0.3,
            "\u{60C}", "\u{6D4}" => 0.4,
            default => 0.0,
        };
        return $factor * $run->face->advances[$gid] / $run->face->upem * $run->size;
    }

    private function href(Node $n): string
    {
        $href = trim((string) $n->attr('href', ''));
        $scheme = strtolower((string) parse_url($href, PHP_URL_SCHEME));
        if ($href === '' || !in_array($scheme, self::LINK_SCHEMES, true)) {
            throw new PdfException("<{$n->type}> needs an http, https, mailto or tel href (line {$n->line})");
        }
        return $href;
    }

    public function named(): array
    {
        return $this->named;
    }
}
