<?php

declare(strict_types=1);

namespace YetiPdf\Write\Layout;

use YetiPdf\Write\Node;
use YetiPdf\Write\PdfException;
use YetiPdf\Write\Style;
use YetiPdf\Write\Value;

/**
 * Grid and table layout. Columns: fixed lengths and % first, then auto columns sized to their
 * widest cell, then fr columns share what's left. Rows break across regions (unless marked
 * breakable="false"), header rows repeat in every region, rows marked keep="with-next" stay
 * together when they fit on one page, and spanning cells flow across pages with their rows.
 *
 * Children: <cell>, <header> (cells repeated on each page), <row> (cells; starts a new row),
 * <hline/> (a rule at the next row boundary), or any other element/text as an implicit cell.
 */
final class GridLayout
{
    private const EPS = 0.01;
    private const MAX_ROWSPAN = 1000;

    /** For right-to-left grids, the grid's width: columns are laid out from the right. */
    private ?float $mirror = null;

    public function __construct(private readonly Layouter $lay, private readonly array $named)
    {
    }

    /** @return list<Frame> */
    public function layout(Node $n, array $parent, Regions $r): array
    {
        $st = Style::apply($parent, $n, $this->named);
        $size = $st['size'];
        $table = $n->type === 'table';

        $tracks = Value::tracks($n->attr('columns', '1'));
        $ncols = count($tracks);
        if ($ncols === 0) {
            throw new PdfException("<{$n->type}> needs at least one column (line {$n->line})");
        }
        $colGap = Value::abs($n->attr('column-gutter', $n->attr('gutter', '0pt')), $size);
        $rowGap = Value::abs($n->attr('row-gutter', $n->attr('gutter', '0pt')), $size);
        $defaults = [
            'inset' => Value::sides($n, 'inset', $size, 0, $table ? [5.0, 5.0, 5.0, 5.0] : null),
            'stroke' => Value::stroke($n->attr('stroke'), $size),
            'fill' => $n->has('fill') ? Value::color($n->attrs['fill']) : null,
            'valign' => Style::valign($n->attr('align')) ?? 'top',
            'breakable' => $n->bool('breakable', true),
        ];

        [$cells, $hlines, $nrows, $headerRows, $keep] = $this->collect($n, $ncols, $st, $defaults);

        // ---- columns
        $W = $r->w;
        $finite = is_finite($W);
        $widths = array_fill(0, $ncols, 0.0);
        $autos = [];
        $frTotal = 0.0;
        foreach ($tracks as $i => $t) {
            if ($t[0] === 'len') {
                $widths[$i] = Value::resolve($t[1], $size, $finite ? $W : 0.0);
            } elseif ($t[0] === 'fr' && $finite) {
                $frTotal += $t[1];
            } else {
                $autos[] = $i;
            }
        }
        foreach ($cells as $c) {
            if ($c['colspan'] === 1 && in_array($c['col'], $autos, true)) {
                $frames = $this->lay->flow($c['children'], $c['style'], Regions::unbounded(INF, false));
                $nat = ($frames[0]->w ?? 0) + $c['inset'][1] + $c['inset'][3];
                $widths[$c['col']] = max($widths[$c['col']], $nat);
            }
        }
        // A cell spanning auto columns that are too narrow for it widens the last of them (as in
        // Typst); spans that include an fr column leave it to the fr column.
        foreach ($cells as $c) {
            if ($c['colspan'] < 2) {
                continue;
            }
            $span = range($c['col'], $c['col'] + $c['colspan'] - 1);
            $spanAutos = array_values(array_intersect($span, $autos));
            $hasFr = $finite && array_filter($span, fn ($i) => $tracks[$i][0] === 'fr');
            if (!$spanAutos || $hasFr) {
                continue;
            }
            $frames = $this->lay->flow($c['children'], $c['style'], Regions::unbounded(INF, false));
            $nat = ($frames[0]->w ?? 0) + $c['inset'][1] + $c['inset'][3];
            $have = $colGap * ($c['colspan'] - 1);
            foreach ($span as $i) {
                $have += $widths[$i];
            }
            if ($nat > $have) {
                $widths[end($spanAutos)] += $nat - $have;
            }
        }
        $gutters = $colGap * ($ncols - 1);
        if ($finite) {
            $used = array_sum($widths) + $gutters;
            $free = $W - $used;
            if ($frTotal > 0) {
                foreach ($tracks as $i => $t) {
                    if ($t[0] === 'fr') {
                        $widths[$i] = max(0.0, $free) * $t[1] / $frTotal;
                    }
                }
            }
            if ($free < -self::EPS && $autos) {
                // Auto columns overflow: shrink them proportionally into the space that's left.
                $autoSum = 0.0;
                foreach ($autos as $i) {
                    $autoSum += $widths[$i];
                }
                $room = max(0.0, $autoSum + $free);
                foreach ($autos as $i) {
                    $widths[$i] = $autoSum > 0 ? $widths[$i] * $room / $autoSum : 0.0;
                }
            }
        }
        $xs = [];
        $x = 0.0;
        foreach ($widths as $i => $w) {
            $xs[$i] = $x;
            $x += $w + $colGap;
        }
        $gridW = array_sum($widths) + $gutters;
        // In right-to-left text the first column is on the right (as in Typst).
        $this->mirror = Style::rtl($st) ? $gridW : null;

        // ---- rows: lay out every cell once at its final width
        $rowH = array_fill(0, $nrows, 0.0);
        $rowTracks = $n->has('rows') ? Value::tracks($n->attrs['rows']) : [];
        foreach ($cells as $k => $c) {
            $w = $widths[$c['col']];
            for ($s = 1; $s < $c['colspan']; $s++) {
                $w += $colGap + $widths[$c['col'] + $s];
            }
            [$it, $ir, $ib, $il] = $c['inset'];
            $frames = $this->lay->flow($c['children'], $c['style'], Regions::unbounded(max(0.0, $w - $il - $ir), true));
            $cells[$k]['w'] = $w;
            $cells[$k]['frame'] = $frames[0];
            if ($c['rowspan'] === 1) {
                $rowH[$c['row']] = max($rowH[$c['row']], $frames[0]->h + $it + $ib);
            }
        }
        foreach ($rowTracks as $i => $t) {
            if ($i < $nrows && $t[0] === 'len') {
                $rowH[$i] = Value::resolve($t[1], $size);
            }
        }
        // Spanning cells: grow their last row if the rows they cover are too short. As in Typst, the
        // rows a cell spans may still break apart (the cell flows across pages with them), unless
        // the cell is marked breakable="false", which keeps them together.
        foreach ($cells as $c) {
            if ($c['rowspan'] > 1) {
                $last = min($nrows - 1, $c['row'] + $c['rowspan'] - 1);
                $have = $this->spanHeight($rowH, $c['row'], $last, $rowGap);
                $need = $c['frame']->h + $c['inset'][0] + $c['inset'][2];
                if ($need > $have) {
                    $rowH[$last] += $need - $have;
                }
                for ($ri = $c['row']; $ri < $last && !$c['breakable']; $ri++) {
                    $keep[$ri] = true;
                }
            }
        }
        $byRow = [];
        $spansAt = [];
        foreach ($cells as $c) {
            if ($c['rowspan'] > 1) {
                $spansAt[$c['row']][] = $c;
            } else {
                $byRow[$c['row']][] = $c;
            }
        }
        // Columns a spanning cell covers at each row boundary inside it: rules stop there (as in Typst).
        $crossing = [];
        foreach ($cells as $c) {
            for ($b = $c['row'] + 1; $b < $c['row'] + $c['rowspan'] && $b < $nrows; $b++) {
                $crossing[$b][] = [$c['col'], $c['col'] + $c['colspan']];
            }
        }
        // A row can break across regions unless it has a fixed height or a cell marked
        // breakable="false". (A spanning cell marked so instead gets its whole height on the page it
        // starts on; its rows stay breakable so a taller neighbour can still continue.)
        $breakable = array_fill(0, $nrows, true);
        foreach ($cells as $c) {
            if ($c['rowspan'] === 1 && !$c['breakable']) {
                $breakable[$c['row']] = false;
            }
        }
        foreach ($rowTracks as $i => $t) {
            if ($i < $nrows && $t[0] === 'len') {
                $breakable[$i] = false;
            }
        }
        $cells = null;

        // ---- pagination
        $out = [];
        $frame = new Frame($gridW, 0);
        $region = $r;
        $y = 0.0;
        $bodyRows = 0;
        $headerH = 0.0;
        // Spanning cells being placed: the cell, where it starts on this page, its content laid
        // out across pages, which piece this page shows, and its last row.
        $open = [];
        $drawSpan = function (array $s, float $bottom) use (&$frame, $xs) {
            $c = $s['c'];
            $whole = $s['p'] === 0 && count($s['pieces']) === 1;
            $c['frame'] = $s['pieces'][$s['p']] ?? new Frame(0, 0);
            if (!$whole) {
                $c['valign'] = 'top';
            }
            $this->cell($frame, $c, $xs[$c['col']], $s['y0'], $bottom - $s['y0']);
        };
        $openSpans = function (int $row) use (&$open, &$region, &$y, &$headerH, $spansAt, $nrows) {
            foreach ($spansAt[$row] ?? [] as $c) {
                $flows = $c['breakable'] && $region->backlog !== null && is_finite($region->h);
                $s = ['c' => $c, 'y0' => $y, 'p' => 0, 'last' => min($nrows - 1, $row + $c['rowspan'] - 1), 'flows' => $flows,
                    'heights' => [$region->h - $y], 'next' => $flows ? $region->backlog - $headerH : 0.0];
                $s['pieces'] = $flows ? $this->flowSpan($c, $s['heights'], $s['next']) : [$c['frame']];
                $open[] = $s;
            }
        };
        $closeSpans = function (int $row) use (&$open, &$y, $rowGap, $drawSpan) {
            foreach ($open as $i => $s) {
                if ($s['last'] === $row) {
                    $drawSpan($s, $y - $rowGap);
                    unset($open[$i]);
                }
            }
        };
        // What spanning cells ending at $row still need: [pieces left, height of the last piece, offset of their top above $y].
        $endingSpans = function (int $row) use (&$open, &$y) {
            $info = [];
            foreach ($open as $s) {
                if ($s['last'] === $row) {
                    $left = count($s['pieces']) - $s['p'];
                    $c = $s['c'];
                    $now = $s['pieces'][$s['p']] ?? null;
                    $info[] = $left > 0
                        ? [$left, end($s['pieces'])->h + $c['inset'][0] + $c['inset'][2], $y - $s['y0'], $left > 1 && $now !== null && $now->items === []]
                        : [1, 0.0, $y - $s['y0'], false];
                }
            }
            return $info;
        };
        // A row ending spanning cells is at least as tall as what they still have to show on this
        // page (more than its own height when a cell's content moved here from an earlier page).
        $spanRest = function (int $row) use (&$open, &$y) {
            $rest = 0.0;
            foreach ($open as $s) {
                if ($s['last'] === $row && isset($s['pieces'][$s['p']])) {
                    $rest = max($rest, $s['pieces'][$s['p']]->h + $s['c']['inset'][0] + $s['c']['inset'][2] - ($y - $s['y0']));
                }
            }
            return $rest;
        };
        $placeRow = function (int $row, bool $close = true) use (&$frame, &$y, &$byRow, $rowH, $xs, $hlines, $gridW, $rowGap, $colGap, $openSpans, $closeSpans, $crossing, $spanRest) {
            $this->hlines($frame, $hlines[$row] ?? [], $y - ($y > 0 ? $rowGap / 2 : 0), $xs, $gridW, $crossing[$row] ?? [], $colGap);
            $openSpans($row);
            $h = max($rowH[$row], $spanRest($row));
            foreach ($byRow[$row] ?? [] as $c) {
                $this->cell($frame, $c, $xs[$c['col']], $y, $h);
            }
            $y += $h + $rowGap;
            if ($close) {
                $closeSpans($row);
            }
        };
        $placeHeader = function () use ($headerRows, $placeRow) {
            foreach ($headerRows as $hr) {
                $placeRow($hr);
            }
        };
        // How much a row needs on this page: itself plus the rows kept with it, unless that group
        // couldn't fit even on a fresh page (then it breaks like any other row).
        // A cell spanning rows with breakable="false" needs its whole height on the page it starts on.
        $spanMin = function (int $row) use ($spansAt) {
            $min = 0.0;
            foreach ($spansAt[$row] ?? [] as $c) {
                if (!$c['breakable']) {
                    $min = max($min, $c['frame']->h + $c['inset'][0] + $c['inset'][2]);
                }
            }
            return $min;
        };
        $needFor = function (int $row) use (&$region, &$headerH, $keep, $rowH, $rowGap, $nrows, $spanMin) {
            $own = max($rowH[$row], $spanMin($row));
            if (!isset($keep[$row])) {
                return [$own, false];
            }
            $need = $rowH[$row];
            for ($k = $row; isset($keep[$k]) && $k + 1 < $nrows; $k++) {
                $need += $rowGap + $rowH[$k + 1];
            }
            if ($region->backlog !== null && $headerH + $need > $region->backlog + self::EPS) {
                return [$own, false];
            }
            return [max($need, $own), true];
        };
        // A kept-together group at the start that doesn't fit here but would on a fresh page:
        // start the whole table there (leaving an empty piece here) rather than splitting it.
        $first = count($headerRows);
        foreach ($headerRows as $hr) {
            $headerH += $rowH[$hr] + $rowGap;
        }
        if ($first < $nrows && $r->backlog !== null && $r->h < $r->backlog - self::EPS) {
            [$need, $kept] = $needFor($first);
            if ($kept && $headerH + $need > $r->h + self::EPS) {
                $out[] = new Frame($gridW, 0);
                $region = $region->next();
            }
        }
        $placeHeader();
        $headerH = $y;
        $newRegion = function () use (&$frame, &$out, &$region, &$y, &$bodyRows, &$open, $gridW, $rowGap, $placeHeader, $drawSpan, $hlines, $nrows, $xs) {
            $bottom = max(0.0, $y - $rowGap);
            foreach ($open as $i => $s) {
                // The rows stopped short of what this piece was laid out for: lay the cell out
                // again with the height its rows really took, so nothing runs past its border.
                $used = $bottom - $s['y0'];
                if ($s['flows'] && $used < ($s['heights'][$s['p']] ?? $s['next']) - self::EPS) {
                    $s['heights'][$s['p']] = $used;
                    $s['pieces'] = $this->flowSpan($s['c'], $s['heights'], $s['next']);
                }
                $s['heights'][$s['p']] = $used;
                $drawSpan($s, $bottom);
                $s['p']++;
                $open[$i] = $s;
            }
            // A rule after the last row closes every page of the table (as in Typst).
            if (isset($hlines[$nrows])) {
                $this->hlines($frame, $hlines[$nrows], $bottom, $xs, $gridW);
            }
            $frame->h = $bottom;
            $out[] = $frame;
            $region = $region->next();
            $frame = new Frame($gridW, 0);
            $y = 0.0;
            $bodyRows = 0;
            $placeHeader();
            foreach ($open as $i => $s) {
                $open[$i]['y0'] = $y;
            }
        };
        // Nothing of the first row fits under the header in a partly filled region: start the whole
        // table in the next one (leaving an empty piece here), as Typst does rather than orphan the header.
        $movable = fn () => $bodyRows === 0 && $out === [] && $region->backlog !== null && $region->h < $region->backlog - self::EPS;
        $moveTable = function () use (&$frame, &$out, &$region, &$y, $gridW, $placeHeader) {
            $out[] = new Frame($gridW, 0);
            $region = $region->next();
            $frame = new Frame($gridW, 0);
            $y = 0.0;
            $placeHeader();
        };
        // A spanning cell ending in $row that still has pieces left (laying it out again for a page
        // its rows left early can add one) carries the row on to further pages until it's all drawn.
        $finishSpans = function (int $row) use (&$open, &$y, &$region, $newRegion, $rowGap) {
            while ($region->backlog !== null) {
                $more = false;
                foreach ($open as $s) {
                    $more = $more || ($s['last'] === $row && $s['p'] < count($s['pieces']) - 1);
                }
                if (!$more) {
                    return;
                }
                $y = $region->h + $rowGap;
                $newRegion();
                $h = 0.0;
                foreach ($open as $s) {
                    if ($s['last'] === $row && isset($s['pieces'][$s['p']])) {
                        $h = max($h, $s['pieces'][$s['p']]->h + $s['c']['inset'][0] + $s['c']['inset'][2]);
                    }
                }
                $y += $h + $rowGap;
            }
        };
        for ($row = $first; $row < $nrows; $row++) {
            // A spanning cell marked breakable="false" starting here needs its whole height on this page.
            $min = $spanMin($row);
            if ($min > 0 && $region->backlog !== null && $y + $min > $region->h + self::EPS) {
                if ($bodyRows > 0) {
                    $newRegion();
                } elseif ($movable()) {
                    $moveTable();
                }
            }
            [$need, $kept] = $needFor($row);
            $need = max($need, $spanRest($row));
            if ($region->backlog !== null && $y + $need > $region->h + self::EPS) {
                // Too tall for what's left: a breakable row splits here (as in Typst, even when it
                // would fit on a fresh page), unless none of its content fits in the space left.
                if ($breakable[$row] && !$kept) {
                    $pieces = $this->split($byRow[$row] ?? [], $region->h - $y, $region->backlog - $headerH, $endingSpans($row), $bodyRows > 0 || $movable());
                    if ($pieces === null && ($bodyRows > 0 || $movable())) {
                        $bodyRows > 0 ? $newRegion() : $moveTable();
                        $pieces = $rowH[$row] > $region->h - $y + self::EPS ? $this->split($byRow[$row] ?? [], $region->h - $y, $region->backlog - $headerH, $endingSpans($row)) : null;
                    }
                    if ($pieces !== null) {
                        $this->hlines($frame, $hlines[$row] ?? [], $y - ($y > 0 ? $rowGap / 2 : 0), $xs, $gridW, $crossing[$row] ?? [], $colGap);
                        $openSpans($row);
                        foreach ($pieces as $k => [$h, $parts]) {
                            if ($k > 0) {
                                $y += $rowGap;
                                $newRegion();
                            }
                            foreach ($parts as [$c, $content]) {
                                $c['frame'] = $content ?? new Frame(0, 0);
                                $c['valign'] = 'top';
                                $this->cell($frame, $c, $xs[$c['col']], $y, $h);
                            }
                            $y += $h;
                        }
                        $y += $rowGap;
                        $finishSpans($row);
                        $closeSpans($row);
                        $bodyRows++;
                        continue;
                    }
                } elseif ($bodyRows > 0) {
                    $newRegion();
                } elseif ($movable() && $headerH + $need <= $region->backlog + self::EPS) {
                    $moveTable();
                }
            }
            $placeRow($row, false);
            $finishSpans($row);
            $closeSpans($row);
            unset($byRow[$row]);
            $bodyRows++;
        }
        $bottom = max(0.0, $y - $rowGap);
        foreach ($open as $s) {
            $drawSpan($s, $bottom);
        }
        if (isset($hlines[$nrows])) {
            $this->hlines($frame, $hlines[$nrows], $bottom, $xs, $gridW);
        }
        $frame->h = $bottom;
        $out[] = $frame;

        if ($r->expand && $finite && $frTotal > 0) {
            foreach ($out as $f) {
                $f->w = $W;
            }
        }
        return $out;
    }

    /**
     * Lay out one row's cells across regions: the first gets $avail, later ones $next (the full
     * region minus the repeated header). Returns pieces [height, [[cell, content frame|null], ...]],
     * every piece but the last filling its region, or null when no cell's content starts in $avail.
     * $spans are the spanning cells that end in this row: the row lasts as long as they do.
     *
     * @param list<array{0: int, 1: float, 2: float, 3: bool}> $spans pieces left, last piece height, top offset above the row, nothing to show here yet
     */
    private function split(array $cells, float $avail, float $next, array $spans = [], bool $canMove = false): ?array
    {
        // A spanning cell ending here that shows nothing in this region yet counts as a cell that
        // would start empty (see below).
        foreach ($spans as $sp) {
            if ($canMove && $sp[3]) {
                return null;
            }
        }
        $frames = [];
        $any = $spans !== [];
        $count = 1;
        foreach ($spans as [$left]) {
            $count = max($count, $left);
        }
        foreach ($cells as $k => $c) {
            [$it, $ir, $ib, $il] = $c['inset'];
            $w = max(0.0, $c['w'] - $il - $ir);
            $f = $this->lay->flow($c['children'], $c['style'], new Regions($w, $avail - $it - $ib, $next - $it - $ib, true));
            if (($f[0]->h ?? 0) > $avail - $it - $ib + self::EPS) {
                // Not even its first line fits here: this cell starts in the next piece.
                $f = $this->lay->flow($c['children'], $c['style'], new Regions($w, $next - $it - $ib, $next - $it - $ib, true));
                array_unshift($f, null);
            } elseif (count($f) < 2 || $f[0]->items !== []) {
                $any = true;
            }
            // As in Typst, a cell that would start empty here (its first line doesn't fit, or its
            // first lines moved on to avoid an orphan) while it has content later moves the whole
            // row to the next region, when that region offers more room.
            if ($canMove && count($f) > 1 && ($f[0] === null || $f[0]->items === [])) {
                return null;
            }
            $frames[$k] = $f;
            $count = max($count, count($f));
        }
        if (!$any) {
            return null;
        }
        $pieces = [];
        for ($p = 0; $p < $count; $p++) {
            $parts = [];
            $h = 0.0;
            foreach ($cells as $k => $c) {
                $f = $frames[$k][$p] ?? null;
                $parts[] = [$c, $f];
                if ($f !== null) {
                    $h = max($h, $f->h + $c['inset'][0] + $c['inset'][2]);
                }
            }
            if ($p < $count - 1) {
                $h = $p === 0 ? $avail : $next;
            } else {
                foreach ($spans as [$left, $spanH, $offset]) {
                    if ($left === $count) {
                        $h = max($h, $spanH - ($p === 0 ? $offset : 0.0));
                    }
                }
            }
            $pieces[] = [$h, $parts];
        }
        return $pieces;
    }

    /**
     * Lay out a spanning cell across pages whose heights (insets included) are $heights, then
     * $next for every page after. A page that can't hold even the first line gets an empty piece.
     *
     * @param list<float> $heights
     * @return list<Frame>
     */
    private function flowSpan(array $c, array $heights, float $next): array
    {
        [$it, $ir, $ib, $il] = $c['inset'];
        $w = max(0.0, $c['w'] - $il - $ir);
        $hs = array_map(fn ($h) => max(0.0, $h - $it - $ib), $heights);
        $nx = max(0.0, $next - $it - $ib);
        $empty = [];
        for ($k = 0; ; $k++) {
            $first = $hs[$k] ?? $nx;
            $f = $this->lay->flow($c['children'], $c['style'], new Regions($w, $first, $nx, true, false, array_slice($hs, $k + 1)));
            if (($f[0]->h ?? 0) <= $first + self::EPS || $k >= count($hs)) {
                return [...$empty, ...$f];
            }
            $empty[] = new Frame(0, 0);
        }
    }

    private function spanHeight(array $rowH, int $first, int $last, float $gap): float
    {
        $h = 0.0;
        for ($r = $first; $r <= $last; $r++) {
            $h += $rowH[$r];
        }
        return $h + $gap * ($last - $first);
    }

    private function cell(Frame $frame, array $c, float $x, float $y, float $h): void
    {
        $w = $c['w'];
        if ($this->mirror !== null) {
            $x = $this->mirror - $x - $w;
        }
        if ($c['fill'] !== null) {
            $frame->push($x, $y, ['rect', $w, $h, $c['fill'], null, 0.0]);
        }
        [$it, , $ib, $il] = $c['inset'];
        $content = $c['frame'];
        $dy = match ($c['valign']) {
            'horizon' => ($h - $it - $ib - $content->h) / 2,
            'bottom' => $h - $it - $ib - $content->h,
            default => 0.0,
        };
        $frame->place($x + $il, $y + $it + $dy, $content);
        [$st, $sr, $sb, $sl] = $c['strokes'];
        if ($this->mirror !== null) {
            // Mirrored with the columns, as Typst does: a cell's left stroke ends up on its right.
            [$sr, $sl] = [$sl, $sr];
        }
        if ($st) {
            $frame->push($x, $y, ['line', $w, 0, $st]);
        }
        if ($sb) {
            $frame->push($x, $y + $h, ['line', $w, 0, $sb]);
        }
        if ($sl) {
            $frame->push($x, $y, ['line', 0, $h, $sl]);
        }
        if ($sr) {
            $frame->push($x + $w, $y, ['line', 0, $h, $sr]);
        }
    }

    /** @param list<array{0: int, 1: int}> $skip column ranges [first, end) the rules don't cross */
    private function hlines(Frame $frame, array $lines, float $y, array $xs, float $gridW, array $skip = [], float $colGap = 0.0): void
    {
        $ncols = count($xs);
        foreach ($lines as [$stroke, $start, $end]) {
            $end = $end === null ? $ncols : min($end, $ncols);
            $from = $start;
            for ($col = $start; $col <= $end; $col++) {
                $blocked = false;
                foreach ($skip as [$a, $b]) {
                    if ($col >= $a && $col < $b) {
                        $blocked = true;
                        break;
                    }
                }
                if ($col === $end || $blocked) {
                    if ($col > $from) {
                        // A rule resuming after a spanning cell starts at that cell's edge, taking in the gutter.
                        $resumed = $from > $start && $from > 0;
                        $x0 = ($xs[$from] ?? 0.0) - ($resumed ? $colGap : 0.0);
                        $x1 = $col >= $ncols ? $gridW : $xs[$col];
                        if ($this->mirror !== null) {
                            [$x0, $x1] = [$this->mirror - $x1, $this->mirror - $x0];
                        }
                        $frame->push($x0, $y, ['line', $x1 - $x0, 0, $stroke]);
                    }
                    $from = $col + 1;
                }
            }
        }
    }

    /**
     * Place cells row-major. Returns [cells, hlines by row boundary, row count, header rows, keep rows].
     */
    private function collect(Node $n, int $ncols, array $st, array $defaults): array
    {
        $cells = [];
        $hlines = [];
        $taken = [];
        $pos = 0;
        $headerRows = [];
        $keep = [];

        $add = function (Node $cell, array $rowDefaults, bool $header) use (&$cells, &$taken, &$pos, &$headerRows, $ncols, $st) {
            // As in Typst: the next free slot (moving to the next row when the span doesn't fit in
            // this one); a cell whose span runs into an earlier cell is an error.
            $span = max(1, min((int) $cell->attr('colspan', '1'), $ncols));
            $rowspan = max(1, min((int) $cell->attr('rowspan', '1'), self::MAX_ROWSPAN));
            while (isset($taken[$pos]) || $pos % $ncols + $span > $ncols) {
                $pos++;
            }
            for ($r = 0; $r < $rowspan; $r++) {
                for ($s = 0; $s < $span; $s++) {
                    if (isset($taken[$pos + $r * $ncols + $s])) {
                        throw new PdfException(sprintf(
                            'Cell would span a previously placed cell at column %d, row %d (line %d); reorder the cells or reduce the colspan/rowspan',
                            ($pos + $s) % $ncols + 1, intdiv($pos, $ncols) + $r + 1, $cell->line,
                        ));
                    }
                }
            }
            $col = $pos % $ncols;
            $row = intdiv($pos, $ncols);
            for ($r = 0; $r < $rowspan; $r++) {
                for ($s = 0; $s < $span; $s++) {
                    $taken[$pos + $r * $ncols + $s] = true;
                }
            }
            $pos += $span;
            if ($header) {
                $headerRows[$row] = $row;
            }
            $cst = Style::apply($st, $cell, $this->named);
            $size = $cst['size'];
            $inset = Value::sides($cell, 'inset', $size, 0, $rowDefaults['inset']);
            $all = $cell->has('stroke') ? Value::stroke($cell->attrs['stroke'], $size) : $rowDefaults['stroke'];
            $strokes = [$all, $all, $all, $all];
            foreach (['top' => 0, 'right' => 1, 'bottom' => 2, 'left' => 3] as $side => $i) {
                if ($cell->has("stroke-$side")) {
                    $strokes[$i] = Value::stroke($cell->attrs["stroke-$side"], $size);
                }
            }
            $cells[] = [
                'col' => $col, 'row' => $row, 'colspan' => $span, 'rowspan' => $rowspan,
                'children' => $cell->children, 'style' => $cst, 'inset' => $inset, 'strokes' => $strokes,
                'fill' => $cell->has('fill') ? Value::color($cell->attrs['fill']) : $rowDefaults['fill'],
                'valign' => Style::valign($cell->attr('align')) ?? $rowDefaults['valign'],
                'breakable' => $cell->bool('breakable', $rowDefaults['breakable']),
            ];
        };
        $endRow = function () use (&$pos, $ncols) {
            if ($pos % $ncols !== 0) {
                $pos += $ncols - $pos % $ncols;
            }
        };
        $rowDefaultsFor = function (Node $row, array $base) use ($st) {
            $rst = Style::apply($st, $row, $this->named);
            return [
                'inset' => Value::sides($row, 'inset', $rst['size'], 0, $base['inset']),
                'stroke' => $row->has('stroke') ? Value::stroke($row->attrs['stroke'], $rst['size']) : $base['stroke'],
                'fill' => $row->has('fill') ? Value::color($row->attrs['fill']) : $base['fill'],
                'valign' => Style::valign($row->attr('align')) ?? $base['valign'],
                'breakable' => $row->bool('breakable', $base['breakable']),
            ];
        };
        $asCell = fn (Node|string $c) => $c instanceof Node && $c->type === 'cell' ? $c : new Node('cell', [], [$c]);

        foreach ($n->children as $c) {
            if (is_string($c)) {
                if (trim($c) !== '') {
                    $add($asCell($c), $defaults, false);
                }
                continue;
            }
            switch ($c->type) {
                case 'header':
                case 'row':
                    $endRow();
                    $rowNode = $c;
                    $rd = $rowDefaultsFor($rowNode, $defaults);
                    // Text props on the row apply to its cells.
                    $rowProps = $rowNode->attrs !== [] ? array_intersect_key($rowNode->attrs, Style::PROP_MAP) : [];
                    $rowClass = $rowNode->has('class') ? $rowNode->attrs['class'] : null;
                    foreach ($rowNode->children as $rc) {
                        if (is_string($rc) && trim($rc) === '') {
                            continue;
                        }
                        $cellNode = $asCell($rc);
                        if ($rowProps === [] && ($rowClass === null || $cellNode->has('class'))) {
                            $add($cellNode, $rd, $c->type === 'header');
                            continue;
                        }
                        $attrs = $rowProps !== [] ? array_merge($rowProps, $cellNode->attrs) : $cellNode->attrs;
                        if ($rowClass !== null && !$cellNode->has('class')) {
                            $attrs['class'] = $rowClass;
                        }
                        $add(new Node('cell', $attrs, $cellNode->children, $cellNode->line), $rd, $c->type === 'header');
                    }
                    $row = intdiv(max(0, $pos - 1), $ncols);
                    if ($c->attr('keep') === 'with-next') {
                        $keep[$row] = true;
                    }
                    $endRow();
                    break;
                case 'hline':
                    $boundary = intdiv($pos + $ncols - 1, $ncols);
                    $hst = Style::apply($st, $c, $this->named);
                    $stroke = Value::stroke($c->attr('stroke', '1pt'), $hst['size']);
                    if ($stroke) {
                        $end = $c->has('end') ? (int) $c->attrs['end'] : null;
                        $hlines[$boundary][] = [$stroke, (int) $c->attr('start', '0'), $end];
                    }
                    break;
                default:
                    $add($asCell($c), $defaults, false);
            }
        }
        $nrows = (int) ceil(max($pos, count($taken) ? max(array_keys($taken)) + 1 : 0) / $ncols);
        $headerRows = array_values(array_filter($headerRows, fn ($r) => $r < $nrows));
        sort($headerRows);
        // Only a leading run of rows can repeat as the header.
        $lead = [];
        foreach ($headerRows as $i => $r) {
            if ($r === $i) {
                $lead[] = $r;
            }
        }
        return [$cells, $hlines, $nrows, $lead, $keep];
    }
}
