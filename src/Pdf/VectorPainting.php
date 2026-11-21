<?php

declare(strict_types=1);

namespace YetiPdf\Write\Pdf;

use YetiPdf\Write\Layout\Frame;
use YetiPdf\Write\Svg\Svg;

/**
 * Vector drawing items for PdfWriter (the ones SvgParser produces):
 *
 *   ['path', segments, fill|null, stroke|null]  filled and/or stroked path, solid or gradient paint
 *   ['clip', segments, evenodd, Frame]           Frame drawn inside a clipping path
 *   ['opacity', Frame, alpha]                    Frame drawn as one transparency group
 *
 * Alpha goes through ExtGState /ca and /CA. Gradients become shading patterns (axial or radial,
 * stitched type 2 functions); their matrix maps gradient space to the content stream's default
 * space, which is why emit() tracks the CTM of nested groups in $state['ctm']. Gradients whose
 * stops vary in opacity get a luminosity soft mask built from the same gradient in grey.
 * An SVG is written once as a Form XObject and drawn like an image wherever it's used.
 */
trait VectorPainting
{
    /** @var array<string, array{id:int, name:string}> */
    private array $forms = [];
    /** @var array<string, array{id:int, name:string}> resource body => object */
    private array $vectorObjects = [];

    /** A number with more precision than n(), for coordinates and matrices. */
    private static function nf(float $v, int $dp = 4): string
    {
        if (!is_finite($v) || abs($v) < 0.5 * 10 ** -$dp) {
            return '0';
        }
        $s = number_format($v, $dp, '.', '');
        return rtrim(rtrim($s, '0'), '.');
    }

    private static function matrix(array $m): string
    {
        return '[' . implode(' ', array_map(fn ($v) => self::nf($v, 6), $m)) . ']';
    }

    /** Apply $m1, then $m2 (PDF matrices [a b c d e f]). */
    private static function mul(array $m1, array $m2): array
    {
        [$a1, $b1, $c1, $d1, $e1, $f1] = $m1;
        [$a2, $b2, $c2, $d2, $e2, $f2] = $m2;
        return [$a1 * $a2 + $b1 * $c2, $a1 * $b2 + $b1 * $d2, $c1 * $a2 + $d1 * $c2, $c1 * $b2 + $d1 * $d2, $e1 * $a2 + $f1 * $c2 + $e2, $e1 * $b2 + $f1 * $d2 + $f2];
    }

    /** The Form XObject for an SVG, mapped to the unit square like an image XObject. */
    private function form(Svg $svg): array
    {
        if (isset($this->forms[$svg->key])) {
            return $this->forms[$svg->key];
        }
        $ops = [];
        $res = ['font' => [], 'image' => []];
        $state = ['font' => null, 'size' => null, 'color' => null, 'inText' => false, 'ctm' => [1, 0, 0, 1, 0, 0]];
        $this->emit($ops, $state, $res, $svg->frame->items, 0.0, $svg->h);
        if ($state['inText']) {
            $ops[] = 'ET';
        }
        $id = $this->alloc();
        $this->objects[$id] = $this->stream(implode("\n", $ops), ' /Type /XObject /Subtype /Form /BBox [0 0 ' . self::nf($svg->w) . ' ' . self::nf($svg->h) . ']'
            . ' /Matrix ' . self::matrix([1 / $svg->w, 0, 0, 1 / $svg->h, 0, 0]) . ' /Resources << ' . $this->resourceDict($res) . ' >>');
        return $this->forms[$svg->key] = ['id' => $id, 'name' => 'Fm' . (count($this->forms) + 1)];
    }

    private function resourceDict(array $res): string
    {
        $dict = '';
        foreach (['font' => 'Font', 'image' => 'XObject'] as $k => $key) {
            if (!empty($res[$k])) {
                $dict .= " /$key << ";
                foreach ($res[$k] as $name => $id) {
                    $dict .= "/$name $id 0 R ";
                }
                $dict .= '>>';
            }
        }
        return ltrim($dict . $this->vectorResources($res));
    }

    /** The ExtGState and Pattern entries of a resource dictionary. */
    private function vectorResources(array $res): string
    {
        $dict = '';
        foreach (['gs' => 'ExtGState', 'pattern' => 'Pattern'] as $k => $key) {
            if (!empty($res[$k])) {
                $dict .= " /$key << ";
                foreach ($res[$k] as $name => $id) {
                    $dict .= "/$name $id 0 R ";
                }
                $dict .= '>>';
            }
        }
        return $dict;
    }

    /** A shared resource object (ExtGState, pattern), written once per distinct body. */
    private function shared(string $prefix, string $body): array
    {
        $key = $prefix . md5($body);
        if (!isset($this->vectorObjects[$key])) {
            $id = $this->alloc();
            $this->objects[$id] = $body;
            $this->vectorObjects[$key] = ['id' => $id, 'name' => $prefix . count($this->vectorObjects)];
        }
        return $this->vectorObjects[$key];
    }

    private function vector(array &$ops, array &$state, array &$res, array $item, float $x, float $y): void
    {
        if ($item[0] === 'path') {
            $this->paintPath($ops, $state, $res, $item, $x, $y);
            return;
        }
        if ($item[0] === 'clip') {
            [, $segs, $evenodd, $frame] = $item;
            $ops[] = 'q ' . $this->pathOps($segs, $x, $y) . ($evenodd ? ' W* n' : ' W n');
            $inner = $state;
            $this->emit($ops, $inner, $res, $frame->items, $x, $y);
            if ($inner['inText']) {
                $ops[] = 'ET';
            }
            $ops[] = 'Q';
            return;
        }
        if ($item[0] === 'opacity') {
            [, $frame, $alpha] = $item;
            $gops = [];
            $gres = ['font' => [], 'image' => []];
            $gstate = ['font' => null, 'size' => null, 'color' => null, 'inText' => false, 'ctm' => [1, 0, 0, 1, 0, 0]];
            $this->emit($gops, $gstate, $gres, $frame->items, $x, $y);
            if ($gstate['inText']) {
                $gops[] = 'ET';
            }
            $id = $this->alloc();
            $this->objects[$id] = $this->stream(implode("\n", $gops), ' /Type /XObject /Subtype /Form /BBox [-100000 -100000 100000 100000]'
                . ' /Group << /S /Transparency >> /Resources << ' . $this->resourceDict($gres) . ' >>');
            $name = 'Tg' . $id;
            $res['image'][$name] = $id;
            $gs = $this->gs($res, $alpha, $alpha);
            $ops[] = "q /$gs gs /$name Do Q";
        }
    }

    private function pathOps(array $segs, float $x, float $y): string
    {
        $o = [];
        foreach ($segs as $s) {
            $o[] = match ($s[0]) {
                'M' => self::nf($x + $s[1]) . ' ' . self::nf($y - $s[2]) . ' m',
                'L' => self::nf($x + $s[1]) . ' ' . self::nf($y - $s[2]) . ' l',
                'C' => self::nf($x + $s[1]) . ' ' . self::nf($y - $s[2]) . ' ' . self::nf($x + $s[3]) . ' ' . self::nf($y - $s[4])
                    . ' ' . self::nf($x + $s[5]) . ' ' . self::nf($y - $s[6]) . ' c',
                'Z' => 'h',
            };
        }
        return implode(' ', $o);
    }

    private function paintPath(array &$ops, array &$state, array &$res, array $item, float $x, float $y): void
    {
        [, $segs, $fill, $stroke] = $item;
        $path = $this->pathOps($segs, $x, $y);
        // Gradient space -> this content stream's default space: the item's y flip and offset, then the CTM.
        $local = [1, 0, 0, -1, $x, $y];
        $ctm = $state['ctm'] ?? [1, 0, 0, 1, 0, 0];
        $f = $fill ? $this->colour($res, $fill, false, $local, $ctm) : null;
        $s = $stroke ? $this->colour($res, $stroke, true, $local, $ctm) : null;
        $strokeOps = '';
        if ($stroke) {
            $strokeOps = self::nf($stroke['w']) . ' w ' . $stroke['cap'] . ' J ' . $stroke['join'] . ' j ' . self::nf($stroke['miter']) . ' M ';
            if ($stroke['dash']) {
                $strokeOps .= '[' . implode(' ', array_map(fn ($v) => self::nf($v), $stroke['dash'])) . '] ' . self::nf($stroke['offset']) . ' d ';
            }
        }
        $fillOp = $fill && $fill['evenodd'] ? 'f*' : 'f';
        if ($f && $s && $f[2] === null && $s[2] === null) {
            $gs = $f[1] < 1 || $s[1] < 1 ? '/' . $this->gs($res, $f[1], $s[1]) . ' gs ' : '';
            $ops[] = 'q ' . $gs . $f[0] . ' ' . $s[0] . ' ' . $strokeOps . $path . ' ' . ($fill['evenodd'] ? 'B*' : 'B') . ' Q';
            return;
        }
        if ($f) {
            $gs = $f[2] !== null || $f[1] < 1 ? '/' . $this->gs($res, $f[1], 1.0, $f[2]) . ' gs ' : '';
            $ops[] = 'q ' . $gs . $f[0] . ' ' . $path . ' ' . $fillOp . ' Q';
        }
        if ($s) {
            $gs = $s[2] !== null || $s[1] < 1 ? '/' . $this->gs($res, 1.0, $s[1], $s[2]) . ' gs ' : '';
            $ops[] = 'q ' . $gs . $s[0] . ' ' . $strokeOps . $path . ' S Q';
        }
    }

    /**
     * Colour operators for a paint: [ops, alpha, soft mask form id or null].
     * @return array{0: string, 1: float, 2: int|null}
     */
    private function colour(array &$res, array $paint, bool $stroke, array $local, array $ctm): array
    {
        $c = $paint['c'];
        $a = (float) $paint['a'];
        if (!is_string($c[0])) {
            return [self::n($c[0]) . ' ' . self::n($c[1]) . ' ' . self::n($c[2]) . ($stroke ? ' RG' : ' rg'), $a, null];
        }
        [, , $gm, $stops] = $c;
        $alphas = array_column($stops, 2);
        $p = $this->pattern($c, self::mul(self::mul($gm, $local), $ctm), false);
        $res['pattern'][$p['name']] = $p['id'];
        $ops = $stroke ? '/Pattern CS /' . $p['name'] . ' SCN' : '/Pattern cs /' . $p['name'] . ' scn';
        if (max($alphas) - min($alphas) < 0.002) {
            return [$ops, $a * $alphas[0], null];
        }
        // Stops that vary in opacity: a luminosity soft mask painted with the same gradient in grey.
        // The mask group is drawn in the user space current at `gs`, so its pattern skips the CTM.
        foreach ($stops as &$st) {
            $st[2] *= $a;
        }
        unset($st);
        $c[3] = $stops;
        $mp = $this->pattern($c, self::mul($gm, $local), true);
        $mask = $this->shared('Sm', $this->stream('/Pattern cs /P scn -100000 -100000 200000 200000 re f',
            ' /Type /XObject /Subtype /Form /BBox [-100000 -100000 100000 100000] /Group << /S /Transparency /CS /DeviceGray >>'
            . ' /Resources << /Pattern << /P ' . $mp['id'] . ' 0 R >> >>'));
        return [$ops, 1.0, $mask['id']];
    }

    /** A shading pattern for a gradient; $alpha paints the stop opacities in grey instead of the colours. */
    private function pattern(array $grad, array $matrix, bool $alpha): array
    {
        [$type, $coords, , $stops] = $grad;
        if ($stops[0][0] > 0) {
            array_unshift($stops, [0.0, $stops[0][1], $stops[0][2]]);
        }
        $last = end($stops);
        if ($last[0] < 1) {
            $stops[] = [1.0, $last[1], $last[2]];
        }
        $val = fn (array $s) => $alpha ? self::nf($s[2]) : self::nf($s[1][0]) . ' ' . self::nf($s[1][1]) . ' ' . self::nf($s[1][2]);
        $fns = [];
        $bounds = [];
        for ($i = 0, $n = count($stops); $i + 1 < $n; $i++) {
            if ($stops[$i + 1][0] <= $stops[$i][0] && !($i + 2 === $n && !$fns)) {
                continue; // a hard stop: no interval between the two
            }
            if ($fns) {
                $bounds[] = self::nf($stops[$i][0], 5);
            }
            $fns[] = '<< /FunctionType 2 /Domain [0 1] /C0 [' . $val($stops[$i]) . '] /C1 [' . $val($stops[$i + 1]) . '] /N 1 >>';
        }
        $fn = count($fns) === 1 ? $fns[0] : '<< /FunctionType 3 /Domain [0 1] /Functions [' . implode(' ', $fns) . '] /Bounds [' . implode(' ', $bounds)
            . '] /Encode [' . implode(' ', array_fill(0, count($fns), '0 1')) . '] >>';
        $shading = '<< /ShadingType ' . ($type === 'linear' ? 2 : 3) . ' /ColorSpace /' . ($alpha ? 'DeviceGray' : 'DeviceRGB')
            . ' /Coords [' . implode(' ', array_map(fn ($v) => self::nf($v), $coords)) . '] /Function ' . $fn . ' /Extend [true true] >>';
        return $this->shared('P', '<< /Type /Pattern /PatternType 2 /Shading ' . $shading . ' /Matrix ' . self::matrix($matrix) . ' >>');
    }

    /** An ExtGState for fill/stroke alpha and an optional soft mask; returns its resource name. */
    private function gs(array &$res, float $ca, float $CA, ?int $smask = null): string
    {
        $body = '<< /Type /ExtGState /ca ' . self::nf($ca, 3) . ' /CA ' . self::nf($CA, 3)
            . ($smask !== null ? " /SMask << /Type /Mask /S /Luminosity /G $smask 0 R >>" : '') . ' >>';
        $g = $this->shared('GS', $body);
        $res['gs'][$g['name']] = $g['id'];
        return $g['name'];
    }
}
