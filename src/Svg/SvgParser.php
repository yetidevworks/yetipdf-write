<?php

declare(strict_types=1);

namespace YetiPdf\Write\Svg;

use DOMDocument;
use DOMElement;
use YetiPdf\Write\Layout\Frame;
use YetiPdf\Write\PdfException;

/**
 * Turns SVG markup into a Frame of vector drawing items (see Frame for the item types):
 *
 *   ['path', segments, fill|null, stroke|null]   segments as in Geometry; paints are
 *       ['c' => [r, g, b] | gradient, 'a' => alpha, 'evenodd' => bool] for fills and
 *       ['c', 'a', 'w', 'cap', 'join', 'miter', 'dash' => list|null, 'offset'] for strokes;
 *       a gradient is ['linear'|'radial', coords, matrix, stops [[offset, [r, g, b], alpha], ...]]
 *   ['clip', segments, evenodd, Frame]            Frame drawn inside a clipping path
 *   ['opacity', Frame, alpha]                     Frame drawn as one transparency group
 *   ['group', Frame, matrix]                      transforms
 *
 * Nothing outside the document is read: only local `#id` references are followed, `<image>`,
 * `<script>`, `<foreignObject>` and external entities are ignored or refused, and `<use>`
 * expansion is bounded so a small file can't blow up.
 */
final class SvgParser
{
    private const PX = 0.75; // CSS px to pt

    /** Inherited properties with their initial values. */
    private const INHERITED = [
        'fill' => 'black', 'fill-opacity' => '1', 'fill-rule' => 'nonzero', 'stroke' => 'none', 'stroke-width' => '1',
        'stroke-linecap' => 'butt', 'stroke-linejoin' => 'miter', 'stroke-miterlimit' => '4', 'stroke-dasharray' => 'none',
        'stroke-dashoffset' => '0', 'stroke-opacity' => '1', 'color' => 'black', 'visibility' => 'visible', 'clip-rule' => 'nonzero',
    ];

    private const OWN = ['opacity' => 1, 'display' => 1, 'clip-path' => 1, 'stop-color' => 1, 'stop-opacity' => 1, 'overflow' => 1];

    /** Elements that draw nothing themselves when met in the tree. */
    private const CONTAINERS = ['g' => 1, 'a' => 1, 'switch' => 1, 'svg' => 1, 'use' => 1];
    private const GRAPHICS = ['path' => 1, 'rect' => 1, 'circle' => 1, 'ellipse' => 1, 'line' => 1, 'polyline' => 1, 'polygon' => 1];

    private const MAX_DEPTH = 24;
    private const MAX_USES = 5000;

    /** @var array<string, DOMElement> */
    private array $ids = [];
    private Css $css;
    private int $uses = 0;

    private function __construct()
    {
        $this->css = new Css();
    }

    public static function parse(string $bytes, string $key): Svg
    {
        return (new self())->document($bytes, $key);
    }

    private function document(string $bytes, string $key): Svg
    {
        if (str_starts_with($bytes, "\xEF\xBB\xBF")) {
            $bytes = substr($bytes, 3);
        }
        $doc = new DOMDocument();
        $prev = libxml_use_internal_errors(true);
        // No LIBXML_NOENT / DTDLOAD: external entities are refused, nothing is fetched.
        $ok = $doc->loadXML($bytes, LIBXML_NONET | LIBXML_NOCDATA | LIBXML_COMPACT);
        $err = libxml_get_last_error();
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        $root = $doc->documentElement;
        if (!$ok || $root === null) {
            throw new PdfException('Invalid SVG' . ($err ? ': ' . trim($err->message) : ''));
        }
        if ($root->localName !== 'svg') {
            throw new PdfException("Invalid SVG: the root element is <{$root->localName}>");
        }
        foreach ($doc->getElementsByTagName('*') as $el) {
            /** @var DOMElement $el */
            if (($id = $el->getAttribute('id')) !== '') {
                $this->ids[$id] ??= $el;
            }
            if ($el->localName === 'style') {
                $this->css->add($el->textContent);
            }
        }

        $vb = self::viewBox($root);
        $w = self::size($root->getAttribute('width'));
        $h = self::size($root->getAttribute('height'));
        if ($vb !== null) {
            $w ??= $h !== null ? $h * $vb[2] / $vb[3] : $vb[2];
            $h ??= $w * $vb[3] / $vb[2];
        }
        $w ??= 100.0;
        $h ??= 100.0;
        if ($w <= 0 || $h <= 0) {
            throw new PdfException('Invalid SVG: zero width or height');
        }

        $style = $this->style($root, self::INHERITED);
        $inner = new Frame($w, $h);
        $m = $vb === null ? Geometry::IDENTITY : self::fit($vb, $root->getAttribute('preserveAspectRatio'), $w, $h);
        if (($style['display'] ?? '') !== 'none') {
            $this->children($root, $style, $vb === null ? [$w, $h] : [$vb[2], $vb[3]], 0, $inner);
        }
        $inner = $this->effects($root, $style, $inner);
        $out = new Frame($w * self::PX, $h * self::PX);
        if ($inner !== null) {
            $out->push(0, 0, ['group', $inner, Geometry::mul($m, [self::PX, 0.0, 0.0, self::PX, 0.0, 0.0])]);
        }
        return new Svg($key, $w * self::PX, $h * self::PX, $out);
    }

    // ------------------------------------------------------------------ tree

    private function children(DOMElement $el, array $style, array $vp, int $depth, Frame $out): void
    {
        foreach ($el->childNodes as $c) {
            if ($c instanceof DOMElement) {
                $this->element($c, $style, $vp, $depth, $out);
            }
        }
    }

    private function element(DOMElement $el, array $parent, array $vp, int $depth, Frame $out): void
    {
        $tag = $el->localName;
        if (($el->prefix !== '' && $el->prefix !== 'svg') || (!isset(self::CONTAINERS[$tag]) && !isset(self::GRAPHICS[$tag])) || $depth > self::MAX_DEPTH) {
            return;
        }
        $st = $this->style($el, $parent);
        if (($st['display'] ?? '') === 'none') {
            return;
        }
        $f = new Frame(0, 0);
        $m = Geometry::transform($el->getAttribute('transform'));
        switch ($tag) {
            case 'g':
            case 'a':
                $this->children($el, $st, $vp, $depth + 1, $f);
                break;
            case 'switch':
                foreach ($el->childNodes as $c) {
                    if ($c instanceof DOMElement && (isset(self::CONTAINERS[$c->localName]) || isset(self::GRAPHICS[$c->localName]))) {
                        $this->element($c, $st, $vp, $depth + 1, $f);
                        break;
                    }
                }
                break;
            case 'svg':
                $this->viewport($el, $el, $st, $vp, $depth, $f, null, null);
                $m = Geometry::IDENTITY;
                break;
            case 'use':
                $m = Geometry::mul([1.0, 0.0, 0.0, 1.0, self::len($el->getAttribute('x'), $vp[0]), self::len($el->getAttribute('y'), $vp[1])], $m);
                $this->use($el, $st, $vp, $depth, $f);
                break;
            default:
                if (($segs = $this->geometry($el, $vp)) && ($item = $this->paint($segs, $st, $vp)) !== null) {
                    $f->push(0, 0, $item);
                }
        }
        if (($f = $this->effects($el, $st, $f, $vp)) === null) {
            return;
        }
        Geometry::isIdentity($m) ? $out->place(0, 0, $f) : $out->push(0, 0, ['group', $f, $m]);
    }

    /** Apply opacity and clip-path to an element's drawing; null when nothing is left. */
    private function effects(DOMElement $el, array $st, Frame $f, array $vp = [100.0, 100.0]): ?Frame
    {
        if (!$f->items) {
            return null;
        }
        $op = self::unit($st['opacity'] ?? '1');
        if ($op <= 0) {
            return null;
        }
        if ($op < 1) {
            $only = count($f->items) === 1 ? $f->items[0][2] : null;
            if (is_array($only) && $only[0] === 'path' && ($only[2] === null) !== ($only[3] === null)) {
                // One fill or one stroke: the opacity can go straight into its alpha.
                $k = $only[2] === null ? 3 : 2;
                $only[$k]['a'] *= $op;
                $f->items[0][2] = $only;
            } else {
                $g = new Frame(0, 0);
                $g->push(0, 0, ['opacity', $f, $op]);
                $f = $g;
            }
        }
        if (preg_match('/^url\(\s*[\'"]?#([^\'")]+)[\'"]?\s*\)/', $st['clip-path'] ?? '', $mm)) {
            $cp = $this->ids[$mm[1]] ?? null;
            if ($cp !== null && $cp->localName === 'clipPath') {
                $clip = $this->clipPath($cp, $f, $vp);
                if ($clip === null) {
                    return null; // an empty clip path hides the element
                }
                $g = new Frame(0, 0);
                $g->push(0, 0, ['clip', $clip[0], $clip[1], $f]);
                $f = $g;
            }
        }
        return $f;
    }

    /**
     * A new viewport for a nested <svg> or a <symbol> instanced by <use> (whose x, y are already
     * applied by the caller, and whose width/height override the element's own).
     */
    private function viewport(DOMElement $el, DOMElement $sizeFrom, array $st, array $vp, int $depth, Frame $out, ?string $uw, ?string $uh): void
    {
        $isSvg = $el->localName === 'svg';
        $x = $isSvg ? self::len($el->getAttribute('x'), $vp[0]) : 0.0;
        $y = $isSvg ? self::len($el->getAttribute('y'), $vp[1]) : 0.0;
        $w = self::len($uw ?? ($sizeFrom->hasAttribute('width') ? $sizeFrom->getAttribute('width') : '100%'), $vp[0]);
        $h = self::len($uh ?? ($sizeFrom->hasAttribute('height') ? $sizeFrom->getAttribute('height') : '100%'), $vp[1]);
        if ($w <= 0 || $h <= 0) {
            return;
        }
        $vb = self::viewBox($el);
        $inner = new Frame(0, 0);
        $this->children($el, $st, $vb === null ? [$w, $h] : [$vb[2], $vb[3]], $depth + 1, $inner);
        if (!$inner->items) {
            return;
        }
        $m = Geometry::mul($vb === null ? Geometry::IDENTITY : self::fit($vb, $el->getAttribute('preserveAspectRatio'), $w, $h), [1.0, 0.0, 0.0, 1.0, $x, $y]);
        $g = new Frame(0, 0);
        $g->push(0, 0, ['group', $inner, $m]);
        if (in_array($st['overflow'] ?? 'hidden', ['visible', 'auto'], true)) {
            $out->place(0, 0, $g);
            return;
        }
        $out->push(0, 0, ['clip', Geometry::rect($x, $y, $w, $h, 0, 0), false, $g]);
    }

    private function use(DOMElement $el, array $st, array $vp, int $depth, Frame $out): void
    {
        $href = $el->getAttribute('href') ?: $el->getAttributeNS('http://www.w3.org/1999/xlink', 'href') ?: $el->getAttribute('xlink:href');
        if (!str_starts_with($href, '#') || ++$this->uses > self::MAX_USES) {
            return; // only references inside this document
        }
        $target = $this->ids[substr($href, 1)] ?? null;
        if ($target === null || $depth >= self::MAX_DEPTH) {
            return;
        }
        for ($p = $el; $p instanceof DOMElement; $p = $p->parentNode) {
            if ($p === $target) {
                return; // a reference to its own ancestor
            }
        }
        $uw = $el->hasAttribute('width') ? $el->getAttribute('width') : null;
        $uh = $el->hasAttribute('height') ? $el->getAttribute('height') : null;
        if ($target->localName === 'symbol') {
            $sst = $this->style($target, $st);
            if (($sst['display'] ?? '') !== 'none') {
                $this->viewport($target, $target, $sst, $vp, $depth + 1, $out, $uw, $uh);
            }
            return;
        }
        if ($target->localName === 'svg') {
            $sst = $this->style($target, $st);
            if (($sst['display'] ?? '') !== 'none') {
                $f = new Frame(0, 0);
                $this->viewport($target, $target, $sst, $vp, $depth + 1, $f, $uw, $uh);
                if ($f = $this->effects($target, $sst, $f, $vp)) {
                    $out->place(0, 0, $f);
                }
            }
            return;
        }
        $this->element($target, $st, $vp, $depth + 1, $out);
    }

    // ------------------------------------------------------------------ style

    /** Computed style: inherited values from the parent, then attributes, style sheet, style="". */
    private function style(DOMElement $el, array $parent): array
    {
        $st = array_intersect_key($parent, self::INHERITED);
        $decl = [];
        if ($el->hasAttributes()) {
            foreach ($el->attributes as $a) {
                if (isset(self::INHERITED[$a->name]) || isset(self::OWN[$a->name])) {
                    $decl[$a->name] = trim($a->value);
                }
            }
        }
        if (!$this->css->empty()) {
            foreach ($this->css->match($el) as $k => $v) {
                $decl[$k] = $v;
            }
        }
        if (($inline = $el->getAttribute('style')) !== '') {
            foreach (Css::declarations($inline) as $k => $v) {
                $decl[$k] = $v;
            }
        }
        foreach ($decl as $k => $v) {
            if (!isset(self::INHERITED[$k]) && !isset(self::OWN[$k])) {
                continue;
            }
            if ($v === 'inherit') {
                if (isset($parent[$k])) {
                    $st[$k] = $parent[$k];
                }
                continue;
            }
            $st[$k] = $v;
        }
        return $st;
    }

    // ------------------------------------------------------------------ geometry and paint

    private function geometry(DOMElement $el, array $vp): array
    {
        $a = fn (string $n, float $ref) => self::len($el->getAttribute($n), $ref);
        switch ($el->localName) {
            case 'path':
                return Geometry::path($el->getAttribute('d'));
            case 'rect':
                $w = $a('width', $vp[0]);
                $h = $a('height', $vp[1]);
                if ($w <= 0 || $h <= 0) {
                    return [];
                }
                $hasRx = $el->hasAttribute('rx') && $el->getAttribute('rx') !== 'auto';
                $hasRy = $el->hasAttribute('ry') && $el->getAttribute('ry') !== 'auto';
                $rx = $hasRx ? $a('rx', $vp[0]) : null;
                $ry = $hasRy ? $a('ry', $vp[1]) : null;
                $rx ??= $ry ?? 0.0;
                $ry ??= $rx;
                return Geometry::rect($a('x', $vp[0]), $a('y', $vp[1]), $w, $h, $rx, $ry);
            case 'circle':
                $r = $a('r', self::diag($vp));
                return $r > 0 ? Geometry::ellipse($a('cx', $vp[0]), $a('cy', $vp[1]), $r, $r) : [];
            case 'ellipse':
                $rx = $el->hasAttribute('rx') && $el->getAttribute('rx') !== 'auto' ? $a('rx', $vp[0]) : null;
                $ry = $el->hasAttribute('ry') && $el->getAttribute('ry') !== 'auto' ? $a('ry', $vp[1]) : null;
                $rx ??= $ry ?? 0.0;
                $ry ??= $rx;
                return $rx > 0 && $ry > 0 ? Geometry::ellipse($a('cx', $vp[0]), $a('cy', $vp[1]), $rx, $ry) : [];
            case 'line':
                return [['M', $a('x1', $vp[0]), $a('y1', $vp[1])], ['L', $a('x2', $vp[0]), $a('y2', $vp[1])]];
            case 'polyline':
            case 'polygon':
                preg_match_all('/[-+]?(?:\d+\.?\d*|\.\d+)(?:[eE][-+]?\d+)?/', $el->getAttribute('points'), $n);
                $p = array_map('floatval', $n[0]);
                $segs = [];
                for ($i = 0; $i + 1 < count($p); $i += 2) {
                    $segs[] = [$i === 0 ? 'M' : 'L', $p[$i], $p[$i + 1]];
                }
                if ($segs && $el->localName === 'polygon') {
                    $segs[] = ['Z'];
                }
                return count($segs) > 1 ? $segs : [];
        }
        return [];
    }

    private function paint(array $segs, array $st, array $vp): ?array
    {
        if (in_array($st['visibility'] ?? 'visible', ['hidden', 'collapse'], true)) {
            return null;
        }
        $box = null;
        $bbox = function () use ($segs, &$box) {
            return $box ??= Geometry::bounds($segs);
        };
        $fill = $this->server($st['fill'] ?? 'black', $st, $bbox, $vp);
        if ($fill !== null) {
            $fill['a'] *= self::unit($st['fill-opacity'] ?? '1');
            $fill['evenodd'] = ($st['fill-rule'] ?? '') === 'evenodd';
        }
        $stroke = $this->server($st['stroke'] ?? 'none', $st, $bbox, $vp);
        if ($stroke !== null) {
            $diag = self::diag($vp);
            $w = self::len($st['stroke-width'] ?? '1', $diag);
            if ($w <= 0) {
                $stroke = null;
            } else {
                $stroke['a'] *= self::unit($st['stroke-opacity'] ?? '1');
                $stroke['w'] = $w;
                $stroke['cap'] = ['butt' => 0, 'round' => 1, 'square' => 2][$st['stroke-linecap'] ?? 'butt'] ?? 0;
                $stroke['join'] = ['miter' => 0, 'miter-clip' => 0, 'arcs' => 0, 'round' => 1, 'bevel' => 2][$st['stroke-linejoin'] ?? 'miter'] ?? 0;
                $stroke['miter'] = max(1.0, (float) ($st['stroke-miterlimit'] ?? 4));
                $stroke['dash'] = self::dashes($st['stroke-dasharray'] ?? 'none', $diag);
                $stroke['offset'] = self::len($st['stroke-dashoffset'] ?? '0', $diag);
            }
        }
        if (($fill === null || $fill['a'] <= 0) && ($stroke === null || $stroke['a'] <= 0)) {
            return null;
        }
        return ['path', $segs, $fill !== null && $fill['a'] > 0 ? $fill : null, $stroke !== null && $stroke['a'] > 0 ? $stroke : null];
    }

    private static function dashes(string $v, float $diag): ?array
    {
        if ($v === 'none' || $v === '') {
            return null;
        }
        $d = [];
        foreach (preg_split('/[\s,]+/', trim($v)) as $p) {
            $l = self::len($p, $diag);
            if ($l < 0) {
                return null;
            }
            $d[] = $l;
        }
        if (array_sum($d) <= 0) {
            return null;
        }
        return count($d) % 2 ? [...$d, ...$d] : $d;
    }

    /** Resolve a fill or stroke value: ['c' => colour or gradient, 'a' => alpha], or null for none. */
    private function server(string $v, array $st, callable $bbox, array $vp): ?array
    {
        $v = trim($v);
        if (str_starts_with($v, 'url(')) {
            if (!preg_match('/^url\(\s*[\'"]?([^\'")]*)[\'"]?\s*\)\s*(.*)$/s', $v, $m)) {
                return null;
            }
            // Only references inside this document; anything else falls back like a missing one.
            $ref = str_starts_with($m[1], '#') ? ($this->ids[substr($m[1], 1)] ?? null) : null;
            if ($ref !== null && ($ref->localName === 'linearGradient' || $ref->localName === 'radialGradient')) {
                return $this->gradient($ref, $bbox, $vp);
            }
            return $m[2] !== '' ? $this->server($m[2], $st, $bbox, $vp) : null;
        }
        if ($v === 'none' || $v === '') {
            return null;
        }
        $c = strtolower($v) === 'currentcolor' ? Color::parse($st['color'] ?? 'black') : Color::parse($v);
        if ($c === null) {
            return null;
        }
        return ['c' => [$c[0], $c[1], $c[2]], 'a' => $c[3]];
    }

    private function gradient(DOMElement $g, callable $bbox, array $vp): ?array
    {
        // Follow href templates for attributes and stops.
        $chain = [$g];
        $seen = [spl_object_id($g) => true];
        for ($e = $g; count($chain) < 16;) {
            $href = $e->getAttribute('href') ?: $e->getAttributeNS('http://www.w3.org/1999/xlink', 'href') ?: $e->getAttribute('xlink:href');
            $next = str_starts_with($href, '#') ? ($this->ids[substr($href, 1)] ?? null) : null;
            if ($next === null || isset($seen[spl_object_id($next)]) || !str_ends_with($next->localName, 'Gradient')) {
                break;
            }
            $seen[spl_object_id($next)] = true;
            $chain[] = $e = $next;
        }
        $attr = function (string $n) use ($chain): ?string {
            foreach ($chain as $e) {
                if ($e->hasAttribute($n)) {
                    return $e->getAttribute($n);
                }
            }
            return null;
        };
        $stops = [];
        foreach ($chain as $e) {
            $last = 0.0;
            foreach ($e->childNodes as $s) {
                if ($s instanceof DOMElement && $s->localName === 'stop') {
                    $sst = $this->style($s, []);
                    $off = $s->getAttribute('offset');
                    $off = max($last, max(0.0, min(1.0, str_ends_with($off, '%') ? (float) $off / 100 : (float) $off)));
                    $last = $off;
                    $col = $sst['stop-color'] ?? 'black';
                    $c = strtolower($col) === 'currentcolor' ? Color::parse($sst['color'] ?? 'black') : Color::parse($col);
                    $c ??= [0.0, 0.0, 0.0, 1.0];
                    $stops[] = [$off, [$c[0], $c[1], $c[2]], $c[3] * self::unit($sst['stop-opacity'] ?? '1')];
                }
            }
            if ($stops) {
                break;
            }
        }
        if (!$stops) {
            return null;
        }
        $end = end($stops);
        $solid = ['c' => $end[1], 'a' => $end[2]];
        if (count($stops) === 1) {
            return $solid;
        }
        $user = $attr('gradientUnits') === 'userSpaceOnUse';
        $m = Geometry::transform($attr('gradientTransform') ?? '');
        if (!$user) {
            $b = $bbox();
            if ($b === null || $b[2] - $b[0] <= 0 || $b[3] - $b[1] <= 0) {
                return null;
            }
            $m = Geometry::mul($m, [$b[2] - $b[0], 0.0, 0.0, $b[3] - $b[1], $b[0], $b[1]]);
        }
        $c = fn (string $n, string $def, float $ref) => $user ? self::len($attr($n) ?? $def, $ref) : self::fraction($attr($n) ?? $def);
        if ($g->localName === 'linearGradient') {
            $coords = [$c('x1', '0%', $vp[0]), $c('y1', '0%', $vp[1]), $c('x2', '100%', $vp[0]), $c('y2', '0%', $vp[1])];
            if ($coords[0] == $coords[2] && $coords[1] == $coords[3]) {
                return $solid;
            }
            return ['c' => ['linear', $coords, $m, $stops], 'a' => 1.0];
        }
        $diag = self::diag($vp);
        $cx = $c('cx', '50%', $vp[0]);
        $cy = $c('cy', '50%', $vp[1]);
        $r = $c('r', '50%', $diag);
        if ($r <= 0) {
            return $solid;
        }
        $fx = $attr('fx') !== null ? $c('fx', '', $vp[0]) : $cx;
        $fy = $attr('fy') !== null ? $c('fy', '', $vp[1]) : $cy;
        $fr = max(0.0, $c('fr', '0', $diag));
        // A focal point outside the end circle is moved onto it (SVG 1.1).
        $d = hypot($fx - $cx, $fy - $cy);
        if ($d > $r * 0.999) {
            $fx = $cx + ($fx - $cx) * $r * 0.999 / $d;
            $fy = $cy + ($fy - $cy) * $r * 0.999 / $d;
        }
        return ['c' => ['radial', [$fx, $fy, min($fr, $r), $cx, $cy, $r], $m, $stops], 'a' => 1.0];
    }

    /** @return array{0: array, 1: bool}|null clip segments in the element's user space, and the rule */
    private function clipPath(DOMElement $cp, Frame $content, array $vp): ?array
    {
        $segs = [];
        $rules = [];
        $m = Geometry::transform($cp->getAttribute('transform'));
        if ($cp->getAttribute('clipPathUnits') === 'objectBoundingBox') {
            $b = self::frameBounds($content);
            if ($b === null) {
                return null;
            }
            $m = Geometry::mul([$b[2] - $b[0], 0.0, 0.0, $b[3] - $b[1], $b[0], $b[1]], $m);
        }
        $base = $this->style($cp, self::INHERITED);
        foreach ($cp->childNodes as $c) {
            if (!$c instanceof DOMElement) {
                continue;
            }
            $el = $c;
            $t = Geometry::IDENTITY;
            if ($c->localName === 'use') {
                $href = $c->getAttribute('href') ?: $c->getAttributeNS('http://www.w3.org/1999/xlink', 'href') ?: $c->getAttribute('xlink:href');
                $el = str_starts_with($href, '#') ? ($this->ids[substr($href, 1)] ?? null) : null;
                if ($el === null || !isset(self::GRAPHICS[$el->localName])) {
                    continue;
                }
                $t = Geometry::mul(Geometry::transform($el->getAttribute('transform')), [1.0, 0.0, 0.0, 1.0, self::len($c->getAttribute('x'), $vp[0]), self::len($c->getAttribute('y'), $vp[1])]);
                $t = Geometry::mul($t, Geometry::transform($c->getAttribute('transform')));
            } elseif (isset(self::GRAPHICS[$c->localName])) {
                $t = Geometry::transform($c->getAttribute('transform'));
            } else {
                continue;
            }
            $st = $this->style($el, $base);
            if (($st['display'] ?? '') === 'none' || in_array($st['visibility'] ?? '', ['hidden', 'collapse'], true)) {
                continue;
            }
            $g = $this->geometry($el, $vp);
            if ($g) {
                array_push($segs, ...Geometry::apply($g, Geometry::mul($t, $m)));
                $rules[] = ($st['clip-rule'] ?? '') === 'evenodd';
            }
        }
        if (!$segs) {
            return null;
        }
        // One child keeps its own rule; several are combined into one nonzero path.
        return [$segs, count($rules) === 1 && $rules[0]];
    }

    /** Bounds of a frame's paths in its own coordinates (stroke widths not included). */
    private static function frameBounds(Frame $f): ?array
    {
        $box = null;
        foreach ($f->items as [$x, $y, $item]) {
            $b = match ($item[0]) {
                'path' => Geometry::bounds($item[1]),
                'group' => ($c = self::frameBounds($item[1])) ? Geometry::transformBounds($c, $item[2]) : null,
                'clip' => self::frameBounds($item[3]),
                'opacity' => self::frameBounds($item[1]),
                default => null,
            };
            if ($b !== null) {
                $b = [$b[0] + $x, $b[1] + $y, $b[2] + $x, $b[3] + $y];
                $box = $box === null ? $b : [min($box[0], $b[0]), min($box[1], $b[1]), max($box[2], $b[2]), max($box[3], $b[3])];
            }
        }
        return $box;
    }

    // ------------------------------------------------------------------ values

    /** A length in user units (px); percentages are of $ref. */
    private static function len(?string $v, float $ref): float
    {
        if ($v === null || !preg_match('/^\s*([-+]?(?:\d+\.?\d*|\.\d+)(?:[eE][-+]?\d+)?)\s*(px|pt|pc|mm|cm|in|em|ex|%)?\s*$/i', $v, $m)) {
            return 0.0;
        }
        $n = (float) $m[1];
        return match (strtolower($m[2] ?? '')) {
            'pt' => $n * 4 / 3,
            'pc' => $n * 16,
            'mm' => $n * 96 / 25.4,
            'cm' => $n * 96 / 2.54,
            'in' => $n * 96,
            'em' => $n * 16,
            'ex' => $n * 8,
            '%' => $n * $ref / 100,
            default => $n,
        };
    }

    /** The root width/height: a length, or null for missing or a percentage. */
    private static function size(string $v): ?float
    {
        $v = trim($v);
        return $v === '' || str_ends_with($v, '%') || $v === 'auto' ? null : self::len($v, 0);
    }

    /** An objectBoundingBox coordinate: a fraction or a percentage. */
    private static function fraction(string $v): float
    {
        $v = trim($v);
        return str_ends_with($v, '%') ? (float) $v / 100 : (float) $v;
    }

    /** A number or percentage clamped to 0..1 (opacities). */
    private static function unit(string $v): float
    {
        $v = trim($v);
        $f = str_ends_with($v, '%') ? (float) $v / 100 : (float) $v;
        return max(0.0, min(1.0, $f));
    }

    private static function diag(array $vp): float
    {
        return sqrt(($vp[0] ** 2 + $vp[1] ** 2) / 2);
    }

    /** @return array{0: float, 1: float, 2: float, 3: float}|null */
    private static function viewBox(DOMElement $el): ?array
    {
        preg_match_all('/[-+]?(?:\d+\.?\d*|\.\d+)(?:[eE][-+]?\d+)?/', $el->getAttribute('viewBox'), $n);
        $v = array_map('floatval', $n[0]);
        return count($v) === 4 && $v[2] > 0 && $v[3] > 0 ? $v : null;
    }

    /** The viewBox to viewport matrix for preserveAspectRatio. */
    private static function fit(array $vb, string $par, float $w, float $h): array
    {
        [$vx, $vy, $vw, $vh] = $vb;
        $p = preg_split('/\s+/', trim(str_replace('defer', '', $par)));
        $align = $p[0] !== '' ? $p[0] : 'xMidYMid';
        $sx = $w / $vw;
        $sy = $h / $vh;
        if ($align === 'none') {
            return [$sx, 0.0, 0.0, $sy, -$vx * $sx, -$vy * $sy];
        }
        $s = ($p[1] ?? 'meet') === 'slice' ? max($sx, $sy) : min($sx, $sy);
        $ax = str_contains($align, 'xMid') ? 0.5 : (str_contains($align, 'xMax') ? 1.0 : 0.0);
        $ay = str_contains($align, 'YMid') ? 0.5 : (str_contains($align, 'YMax') ? 1.0 : 0.0);
        return [$s, 0.0, 0.0, $s, -$vx * $s + ($w - $vw * $s) * $ax, -$vy * $s + ($h - $vh * $s) * $ay];
    }
}
