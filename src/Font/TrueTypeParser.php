<?php

declare(strict_types=1);

namespace YetiPdf\Write\Font;

use YetiPdf\Write\PdfException;

/**
 * Reads the parts of a TrueType font that layout and PDF embedding need, into a plain array
 * that can be cached with var_export (so opcache keeps it in shared memory).
 *
 * Covered: head, hhea, maxp, hmtx, OS/2, post, name, cmap (formats 4 and 12), legacy kern
 * (format 0), GPOS pair kerning (formats 1 and 2) and mark attachment, GSUB (every lookup type,
 * extensions resolved), GDEF classes and mark sets.
 */
final class TrueTypeParser
{
    /** Bumped whenever the parsed array changes, so cached metrics are rebuilt. */
    public const VERSION = 8;

    private array $tables = [];

    public function __construct(private readonly string $d)
    {
    }

    public function parse(): array
    {
        if (strlen($this->d) < 12) {
            throw new PdfException('Font is truncated');
        }
        $tag = substr($this->d, 0, 4);
        if ($tag === 'OTTO') {
            throw new PdfException('CFF-based OpenType fonts (.otf) are not supported yet; use a TrueType (.ttf) font');
        }
        if ($tag === 'ttcf') {
            throw new PdfException('Font collections (.ttc) are not supported');
        }
        if ($tag !== "\x00\x01\x00\x00" && $tag !== 'true') {
            throw new PdfException('Not a TrueType font');
        }
        $len = strlen($this->d);
        $n = $this->u16(4);
        if ($len < 12 + 16 * $n) {
            throw new PdfException('Font is truncated');
        }
        for ($i = 0; $i < $n; $i++) {
            $o = 12 + 16 * $i;
            [$off, $size] = [$this->u32($o + 8), $this->u32($o + 12)];
            if ($off + $size > $len) {
                throw new PdfException("Font table '" . substr($this->d, $o, 4) . "' runs past the end of the file");
            }
            $this->tables[substr($this->d, $o, 4)] = [$off, $size];
        }
        foreach (['head', 'hhea', 'maxp', 'hmtx', 'cmap', 'loca', 'glyf'] as $req) {
            if (!isset($this->tables[$req])) {
                throw new PdfException("Font is missing the required '$req' table");
            }
        }

        $m = [];
        $head = $this->tables['head'][0];
        $m['unitsPerEm'] = $this->u16($head + 18);
        if ($m['unitsPerEm'] < 16 || $m['unitsPerEm'] > 16384) {
            throw new PdfException('Font has an invalid unitsPerEm');
        }
        $m['bbox'] = [$this->i16($head + 36), $this->i16($head + 38), $this->i16($head + 40), $this->i16($head + 42)];
        $m['macStyle'] = $this->u16($head + 44);

        $hhea = $this->tables['hhea'][0];
        $m['ascender'] = $this->i16($hhea + 4);
        $m['descender'] = $this->i16($hhea + 6);
        $m['lineGap'] = $this->i16($hhea + 8);
        $numHMetrics = $this->u16($hhea + 34);

        $m['numGlyphs'] = $this->u16($this->tables['maxp'][0] + 4);

        $hmtx = $this->tables['hmtx'][0];
        $adv = [];
        $last = 0;
        for ($g = 0; $g < $m['numGlyphs']; $g++) {
            if ($g < $numHMetrics) {
                $last = $this->u16($hmtx + 4 * $g);
            }
            $adv[$g] = $last;
        }
        $m['advances'] = $adv;

        $m['weight'] = 400;
        $m['italic'] = ($m['macStyle'] & 2) !== 0;
        $m['capHeight'] = $m['ascender'];
        $m['xHeight'] = $m['ascender'];
        if (isset($this->tables['OS/2'])) {
            $os2 = $this->tables['OS/2'][0];
            $ver = $this->u16($os2);
            $m['weight'] = $this->u16($os2 + 4);
            $fsSel = $this->u16($os2 + 62);
            $m['italic'] = ($fsSel & 1) !== 0;
            // As Typst: the typographic ascender/descender whenever there's an OS/2 table, and
            // cap/x-height from OS/2 v2+, else the ascender.
            $m['ascender'] = $this->i16($os2 + 68) ?: $m['ascender'];
            $m['descender'] = $this->i16($os2 + 70) ?: $m['descender'];
            $m['lineGap'] = $this->i16($os2 + 72);
            $m['xHeight'] = $m['ascender'];
            $m['capHeight'] = $m['ascender'];
            if ($ver >= 2) {
                $m['xHeight'] = $this->i16($os2 + 86) > 0 ? $this->i16($os2 + 86) : $m['ascender'];
                $m['capHeight'] = $this->i16($os2 + 88) > 0 ? $this->i16($os2 + 88) : $m['ascender'];
            }
        }

        $m['italicAngle'] = 0.0;
        if (isset($this->tables['post'])) {
            $p = $this->tables['post'][0];
            $m['italicAngle'] = $this->i16($p + 4) + $this->u16($p + 6) / 65536;
        }

        $names = $this->names();
        $m['family'] = $names[16] ?? $names[1] ?? 'Unknown';
        $m['subfamily'] = $names[17] ?? $names[2] ?? 'Regular';
        $m['postScriptName'] = preg_replace('/[^A-Za-z0-9-]/', '', $names[6] ?? ($m['family'] . '-' . $m['subfamily']));

        $m['cmap'] = $this->cmap();
        // GPOS kerning when the font has the feature, else the kern table (as HarfBuzz picks).
        $m['kern'] = $this->gposKerning();
        $m['kernTable'] = $m['kern'] ? [] : $this->legacyKerning();
        $m['gpos'] = $this->gposScripts();
        $m['gsub'] = $this->gsub();
        [$m['classes'], $m['markClasses'], $m['markSets']] = $this->gdef();

        // Precompute face-level flags so Face::load() doesn't re-scan cmap/kern/gpos every request.
        $coversAscii = true;
        for ($c = 0x20; $c < 0x7F; $c++) {
            if (!isset($m['cmap'][$c])) {
                $coversAscii = false;
                break;
            }
        }
        $m['coversAscii'] = $coversAscii;

        $simpleKerning = $m['kernTable'] === [];
        if ($simpleKerning) {
            foreach ($m['kern'] as [, , $subs]) {
                foreach ($subs as $sub) {
                    $second = $sub[0] === 'p' ? $sub[2] : $sub[6];
                    if ($second) {
                        $simpleKerning = false;
                        break 2;
                    }
                    $values = $sub[0] === 'p' ? $sub[1] : $sub[5];
                    foreach ($values as $v) {
                        if (is_array($v)) {
                            $simpleKerning = false;
                            break 3;
                        }
                    }
                }
            }
        }
        $m['simpleKerning'] = $simpleKerning;

        $allKern = array_keys($m['kern']);
        $uniformKerning = $allKern !== [];
        $positionsMarks = false;
        foreach ($m['gpos'] as $langs) {
            foreach ($langs as $sys) {
                if ($uniformKerning && $sys['kern'] !== $allKern) {
                    $uniformKerning = false;
                }
                if ($sys['mark'] !== []) {
                    $positionsMarks = true;
                }
            }
        }
        $m['uniformKerning'] = $uniformKerning;
        $m['positionsMarks'] = $positionsMarks;

        return $m;
    }

    private function u16(int $o): int
    {
        return (ord($this->d[$o]) << 8) | ord($this->d[$o + 1]);
    }

    private function i16(int $o): int
    {
        $v = (ord($this->d[$o]) << 8) | ord($this->d[$o + 1]);
        return $v >= 0x8000 ? $v - 0x10000 : $v;
    }

    private function u32(int $o): int
    {
        return unpack('N', $this->d, $o)[1];
    }

    /** @return array<int,string> nameID => string */
    private function names(): array
    {
        if (!isset($this->tables['name'])) {
            return [];
        }
        $t = $this->tables['name'][0];
        $count = $this->u16($t + 2);
        $strings = $t + $this->u16($t + 4);
        $out = [];
        for ($i = 0; $i < $count; $i++) {
            $r = $t + 6 + 12 * $i;
            $platform = $this->u16($r);
            $lang = $this->u16($r + 4);
            $id = $this->u16($r + 6);
            $raw = substr($this->d, $strings + $this->u16($r + 10), $this->u16($r + 8));
            if ($platform === 3 && ($lang === 0x409 || !isset($out[$id]))) {
                $out[$id] = mb_convert_encoding($raw, 'UTF-8', 'UTF-16BE');
            } elseif ($platform === 1 && !isset($out[$id])) {
                $out[$id] = mb_convert_encoding($raw, 'UTF-8', 'ISO-8859-1');
            }
        }
        return $out;
    }

    /** @return array<int,int> codepoint => glyph id */
    private function cmap(): array
    {
        $t = $this->tables['cmap'][0];
        $n = $this->u16($t + 2);
        $best = null;
        $bestRank = 0;
        for ($i = 0; $i < $n; $i++) {
            $r = $t + 4 + 8 * $i;
            $pid = $this->u16($r);
            $eid = $this->u16($r + 2);
            $off = $t + $this->u32($r + 4);
            $fmt = $this->u16($off);
            $rank = match (true) {
                $fmt === 12 && ($pid === 3 && $eid === 10 || $pid === 0) => 3,
                $fmt === 4 && ($pid === 3 && $eid === 1 || $pid === 0) => 2,
                $fmt === 4 => 1,
                default => 0,
            };
            if ($rank > $bestRank) {
                $best = $off;
                $bestRank = $rank;
            }
        }
        if ($best === null) {
            throw new PdfException('Font has no usable Unicode cmap');
        }
        $map = [];
        if ($this->u16($best) === 12) {
            $groups = $this->u32($best + 12);
            for ($i = 0; $i < $groups; $i++) {
                $g = $best + 16 + 12 * $i;
                $start = $this->u32($g);
                $end = $this->u32($g + 4);
                $gid = $this->u32($g + 8);
                for ($c = $start; $c <= $end; $c++) {
                    $map[$c] = $gid++;
                }
            }
            return $map;
        }
        $segX2 = $this->u16($best + 6);
        $ends = $best + 14;
        $starts = $ends + $segX2 + 2;
        $deltas = $starts + $segX2;
        $ranges = $deltas + $segX2;
        for ($s = 0; $s < $segX2; $s += 2) {
            $end = $this->u16($ends + $s);
            $start = $this->u16($starts + $s);
            $delta = $this->u16($deltas + $s);
            $ro = $this->u16($ranges + $s);
            for ($c = $start; $c <= $end && $c !== 0xFFFF; $c++) {
                if ($ro === 0) {
                    $gid = ($c + $delta) & 0xFFFF;
                } else {
                    $gid = $this->u16($ranges + $s + $ro + 2 * ($c - $start));
                    if ($gid !== 0) {
                        $gid = ($gid + $delta) & 0xFFFF;
                    }
                }
                if ($gid !== 0) {
                    $map[$c] = $gid;
                }
            }
        }
        return $map;
    }

    /** @return array<int,int> glyph id => coverage index */
    private function coverage(int $o): array
    {
        $out = [];
        if ($this->u16($o) === 1) {
            $n = $this->u16($o + 2);
            for ($i = 0; $i < $n; $i++) {
                $out[$this->u16($o + 4 + 2 * $i)] = $i;
            }
        } else {
            $n = $this->u16($o + 2);
            for ($i = 0; $i < $n; $i++) {
                $r = $o + 4 + 6 * $i;
                $start = $this->u16($r);
                $end = $this->u16($r + 2);
                $idx = $this->u16($r + 4);
                for ($g = $start; $g <= $end; $g++) {
                    $out[$g] = $idx++;
                }
            }
        }
        return $out;
    }

    /** @return array<int,int> glyph id => class (class 0 omitted) */
    private function classDef(int $o): array
    {
        $out = [];
        if ($this->u16($o) === 1) {
            $start = $this->u16($o + 2);
            $n = $this->u16($o + 4);
            for ($i = 0; $i < $n; $i++) {
                $c = $this->u16($o + 6 + 2 * $i);
                if ($c) {
                    $out[$start + $i] = $c;
                }
            }
        } else {
            $n = $this->u16($o + 2);
            for ($i = 0; $i < $n; $i++) {
                $r = $o + 4 + 6 * $i;
                $c = $this->u16($r + 4);
                if ($c) {
                    for ($g = $this->u16($r), $end = $this->u16($r + 2); $g <= $end; $g++) {
                        $out[$g] = $c;
                    }
                }
            }
        }
        return $out;
    }

    /**
     * Lookup offsets for the given feature tags, per tag, in lookup-list order.
     *
     * @return array{0: array<string,list<int>>, 1: int} [tag => lookup indices, lookup list offset]
     */
    private function featureLookups(string $table, array $tags): array
    {
        $t = $this->tables[$table][0];
        $features = $t + $this->u16($t + 6);
        $lookups = $t + $this->u16($t + 8);
        $out = [];
        $n = $this->u16($features);
        for ($i = 0; $i < $n; $i++) {
            $r = $features + 2 + 6 * $i;
            $tag = substr($this->d, $r, 4);
            if (!in_array($tag, $tags, true)) {
                continue;
            }
            $f = $features + $this->u16($r + 4);
            $count = $this->u16($f + 2);
            for ($j = 0; $j < $count; $j++) {
                $out[$tag][] = $this->u16($f + 4 + 2 * $j);
            }
        }
        foreach ($out as $tag => $list) {
            $list = array_values(array_unique($list));
            sort($list);
            $out[$tag] = $list;
        }
        return [$out, $lookups];
    }

    /** @return list<array{0:int,1:int}> [lookup type, subtable offset], extensions resolved */
    private function subtables(int $lookupList, int $index, int $extType): array
    {
        $l = $lookupList + $this->u16($lookupList + 2 + 2 * $index);
        $type = $this->u16($l);
        $n = $this->u16($l + 4);
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $s = $l + $this->u16($l + 6 + 2 * $i);
            if ($type === $extType) {
                $out[] = [$this->u16($s + 2), $s + $this->u32($s + 4)];
            } else {
                $out[] = [$type, $s];
            }
        }
        return $out;
    }

    private function valueSize(int $format): int
    {
        return 2 * substr_count(decbin($format & 0xFF), '1');
    }

    /** A value record's x placement, y placement and x advance (0 for those it hasn't). */
    private function valueRecord(int $o, int $format): array
    {
        $out = [0, 0, 0];
        foreach ([1 => 0, 2 => 1, 4 => 2] as $bit => $k) {
            if ($format & $bit) {
                $out[$k] = $this->i16($o);
                $o += 2;
            }
        }
        return $out;
    }

    /**
     * The adjustment for a pair: just the first glyph's x advance, as an int, when that's all
     * there is; else [x advance 1, x placement 1, y placement 1, x advance 2, x placement 2,
     * y placement 2].
     */
    private static function pairValue(array $v1, array $v2): int|array
    {
        return $v1[0] === 0 && $v1[1] === 0 && $v2 === [0, 0, 0] ? $v1[2] : [$v1[2], $v1[0], $v1[1], $v2[2], $v2[0], $v2[1]];
    }

    /**
     * Kerning lookups (pair positioning), [lookup index => [flag, mark filtering set, subtables]],
     * the subtables tried in order:
     *   ['p', [left => [right => value]], second]  pairs
     *   ['c', coverage, classDef1, classDef2, class2Count, [c1*count+c2 => value], second]  classes
     * with values as pairValue() gives them; second is whether the pair has a second value
     * record (the next pair then starts after the second glyph, as in HarfBuzz).
     */
    private function gposKerning(): array
    {
        if (!isset($this->tables['GPOS'])) {
            return [];
        }
        [$features, $lookupList] = $this->featureLookups('GPOS', ['kern']);
        $out = [];
        foreach ($features['kern'] ?? [] as $li) {
            $lookup = [];
            $l = $lookupList + $this->u16($lookupList + 2 + 2 * $li);
            $flag = $this->u16($l + 2);
            foreach ($this->subtables($lookupList, $li, 9) as [$type, $s]) {
                if ($type !== 2) {
                    continue;
                }
                $fmt = $this->u16($s);
                $cov = $this->coverage($s + $this->u16($s + 2));
                $vf1 = $this->u16($s + 4);
                $vf2 = $this->u16($s + 6);
                $size1 = $this->valueSize($vf1);
                $rec = $size1 + $this->valueSize($vf2);
                if ($fmt === 1) {
                    $pairs = [];
                    $setCount = $this->u16($s + 8);
                    foreach ($cov as $first => $ci) {
                        if ($ci >= $setCount) {
                            continue;
                        }
                        $ps = $s + $this->u16($s + 10 + 2 * $ci);
                        $pc = $this->u16($ps);
                        $firstHi = $first << 16;
                        for ($k = 0; $k < $pc; $k++) {
                            $r = $ps + 2 + $k * (2 + $rec);
                            $pairs[$firstHi | $this->u16($r)] = self::pairValue($this->valueRecord($r + 2, $vf1), $this->valueRecord($r + 2 + $size1, $vf2));
                        }
                    }
                    $lookup[] = ['p', $pairs, $vf2 !== 0];
                } elseif ($fmt === 2) {
                    $cd1 = $this->classDef($s + $this->u16($s + 8));
                    $cd2 = $this->classDef($s + $this->u16($s + 10));
                    $c1n = $this->u16($s + 12);
                    $c2n = $this->u16($s + 14);
                    $matrix = [];
                    for ($c1 = 0; $c1 < $c1n; $c1++) {
                        for ($c2 = 0; $c2 < $c2n; $c2++) {
                            $r = $s + 16 + ($c1 * $c2n + $c2) * $rec;
                            $v = self::pairValue($this->valueRecord($r, $vf1), $this->valueRecord($r + $size1, $vf2));
                            if ($v !== 0) {
                                $matrix[$c1 * $c2n + $c2] = $v;
                            }
                        }
                    }
                    $lookup[] = ['c', array_fill_keys(array_keys($cov), true), $cd1, $cd2, $c2n, $matrix, $vf2 !== 0];
                }
            }
            if ($lookup) {
                $out[$li] = [$flag, $flag & 0x10 ? $this->u16($l + 6 + 2 * $this->u16($l + 4)) : null, $lookup];
            }
        }
        return $out;
    }

    /**
     * The positioning lookups (GPOS) each script and language uses: [script tag => [language tag
     * ('' for the default) => ['kern' => [lookup index, …], 'mark' => [lookup index, …]]]], 'mark'
     * being mark, mkmk, abvm and blwm.
     */
    private function gposScripts(): array
    {
        if (!isset($this->tables['GPOS'])) {
            return [];
        }
        $t = $this->tables['GPOS'][0];
        $scriptList = $t + $this->u16($t + 4);
        $featureList = $t + $this->u16($t + 6);
        $langSys = function (int $o) use ($featureList): array {
            $out = ['kern' => [], 'mark' => []];
            $req = $this->u16($o + 2);
            $n = $this->u16($o + 4);
            $indices = [];
            for ($j = 0; $j < $n; $j++) {
                $indices[] = $this->u16($o + 6 + 2 * $j);
            }
            if ($req !== 0xFFFF) {
                $indices[] = $req;
            }
            foreach ($indices as $i) {
                $r = $featureList + 2 + 6 * $i;
                $tag = substr($this->d, $r, 4);
                $kind = match ($tag) { 'kern' => 'kern', 'mark', 'mkmk', 'abvm', 'blwm' => 'mark', default => null };
                if ($kind === null) {
                    continue;
                }
                $f = $featureList + $this->u16($r + 4);
                for ($j = 0, $m = $this->u16($f + 2); $j < $m; $j++) {
                    $out[$kind][] = $this->u16($f + 4 + 2 * $j);
                }
            }
            foreach ($out as $k => $list) {
                $list = array_values(array_unique($list));
                sort($list);
                $out[$k] = $list;
            }
            return $out;
        };
        $scripts = [];
        for ($i = 0, $n = $this->u16($scriptList); $i < $n; $i++) {
            $r = $scriptList + 2 + 6 * $i;
            $sc = $scriptList + $this->u16($r + 4);
            $tag = substr($this->d, $r, 4);
            if ($def = $this->u16($sc)) {
                $scripts[$tag][''] = $langSys($sc + $def);
            }
            for ($j = 0, $m = $this->u16($sc + 2); $j < $m; $j++) {
                $lr = $sc + 4 + 6 * $j;
                $scripts[$tag][substr($this->d, $lr, 4)] = $langSys($sc + $this->u16($lr + 4));
            }
        }
        return $scripts;
    }

    /** Whether any lookup of a table has one of the flag bits set. */
    private function flagged(string $table, int $bits): bool
    {
        if (!isset($this->tables[$table])) {
            return false;
        }
        $t = $this->tables[$table][0];
        $list = $t + $this->u16($t + 8);
        for ($i = 0, $n = $this->u16($list); $i < $n; $i++) {
            if ($this->u16($list + $this->u16($list + 2 + 2 * $i) + 2) & $bits) {
                return true;
            }
        }
        return false;
    }

    /**
     * From GDEF: glyph classes (ligatures, 2, and marks, 3; bases, 1, when a lookup asks to pass
     * over them; any other glyph is class 0), mark attachment
     * classes [gid => class] and mark glyph sets [set => [gid => true]].
     */
    private function gdef(): array
    {
        if (!isset($this->tables['GDEF'])) {
            return [[], [], []];
        }
        $g = $this->tables['GDEF'][0];
        $cd = $this->u16($g + 4);
        // Bases (1) only matter to a lookup that passes over them, so they're kept only then.
        $bases = $this->flagged('GSUB', 2) || $this->flagged('GPOS', 2);
        $classes = $cd ? array_filter($this->classDef($g + $cd), fn ($c) => $c === 2 || $c === 3 || ($bases && $c === 1)) : [];
        $attach = $this->u16($g + 10);
        $sets = [];
        if ($this->u16($g + 2) >= 2 && ($ms = $this->u16($g + 12))) {
            for ($i = 0, $n = $this->u16($g + $ms + 2); $i < $n; $i++) {
                $sets[$i] = array_fill_keys(array_keys($this->coverage($g + $ms + $this->u32($g + $ms + 4 + 4 * $i))), true);
            }
        }
        return [$classes, $attach ? $this->classDef($g + $attach) : [], $sets];
    }

    /**
     * The mark attachment lookups of the 'mark', 'mkmk', 'abvm' and 'blwm' features, [lookup index
     * => [flag, mark filtering set, subtables]] (kept apart from parse(): only text with marks
     * needs them). The subtables:
     *   ['b', marks, bases]  mark to base:     marks [gid => [class, x, y]], bases [gid => [class => [x, y]]]
     *   ['l', marks, ligs]   mark to ligature: ligs [gid => [component => [class => [x, y]]]]
     *   ['m', marks, marks2] mark to mark:     marks2 like bases
     *
     * @return array<int, array{0: int, 1: ?int, 2: list<array>}>
     */
    public function marks(): array
    {
        if ($this->tables === []) {
            $this->parse();
        }
        if (!isset($this->tables['GPOS'])) {
            return [];
        }
        [$features, $lookupList] = $this->featureLookups('GPOS', ['mark', 'mkmk', 'abvm', 'blwm']);
        $indices = array_values(array_unique(array_merge(...array_values($features))));
        sort($indices);
        $anchor = fn (int $o): array => [$this->i16($o + 2), $this->i16($o + 4)];
        // Anchors on bases/ligatures/mark2s are packed into one signed int: (x << 16) | (y & 0xFFFF).
        $packedAnchor = fn (int $o): int => ($this->i16($o + 2) << 16) | ($this->i16($o + 4) & 0xFFFF);
        $markArray = function (int $o, array $cov) use ($anchor): array {
            $out = [];
            $n = $this->u16($o);
            foreach ($cov as $gid => $i) {
                if ($i < $n) {
                    $r = $o + 2 + 4 * $i;
                    $out[$gid] = [$this->u16($r), ...$anchor($o + $this->u16($r + 2))];
                }
            }
            return $out;
        };
        // A base or mark2 array: per covered glyph, a packed anchor per mark class (0 offsets = none).
        $anchorArray = function (int $o, array $cov, int $classCount) use ($packedAnchor): array {
            $out = [];
            $n = $this->u16($o);
            foreach ($cov as $gid => $i) {
                if ($i >= $n) {
                    continue;
                }
                for ($c = 0; $c < $classCount; $c++) {
                    $a = $this->u16($o + 2 + 2 * ($i * $classCount + $c));
                    if ($a) {
                        $out[$gid][$c] = $packedAnchor($o + $a);
                    }
                }
            }
            return $out;
        };
        $lookups = [];
        foreach ($indices as $li) {
            $lookup = [];
            $l = $lookupList + $this->u16($lookupList + 2 + 2 * $li);
            $flag = $this->u16($l + 2);
            foreach ($this->subtables($lookupList, $li, 9) as [$type, $s]) {
                if ($type < 4 || $type > 6 || $this->u16($s) !== 1) {
                    continue;
                }
                $markCov = $this->coverage($s + $this->u16($s + 2));
                $otherCov = $this->coverage($s + $this->u16($s + 4));
                $classCount = $this->u16($s + 6);
                $marks = $markArray($s + $this->u16($s + 8), $markCov);
                $other = $s + $this->u16($s + 10);
                if ($type === 5) {
                    $ligs = [];
                    $n = $this->u16($other);
                    foreach ($otherCov as $gid => $i) {
                        if ($i >= $n) {
                            continue;
                        }
                        $at = $other + $this->u16($other + 2 + 2 * $i);
                        $comps = $this->u16($at);
                        $ligs[$gid] = array_fill(0, $comps, []);
                        for ($k = 0; $k < $comps; $k++) {
                            for ($c = 0; $c < $classCount; $c++) {
                                $a = $this->u16($at + 2 + 2 * ($k * $classCount + $c));
                                if ($a) {
                                    $ligs[$gid][$k][$c] = $packedAnchor($at + $a);
                                }
                            }
                        }
                    }
                    $lookup[] = ['l', $marks, $ligs];
                } else {
                    $lookup[] = [$type === 4 ? 'b' : 'm', $marks, $anchorArray($other, $otherCov, $classCount)];
                }
            }
            if ($lookup) {
                $lookups[$li] = [$flag, $flag & 0x10 ? $this->u16($l + 6 + 2 * $this->u16($l + 4)) : null, $lookup];
            }
        }
        return $lookups;
    }

    private function legacyKerning(): array
    {
        if (!isset($this->tables['kern'])) {
            return [];
        }
        $t = $this->tables['kern'][0];
        $n = $this->u16($t + 2);
        $o = $t + 4;
        $pairs = [];
        for ($i = 0; $i < $n; $i++) {
            $len = $this->u16($o + 2);
            $coverage = $this->u16($o + 4);
            if (($coverage >> 8) === 0 && ($coverage & 1)) {
                $np = $this->u16($o + 6);
                for ($k = 0; $k < $np; $k++) {
                    $r = $o + 14 + 6 * $k;
                    $pairs[($this->u16($r) << 16) | $this->u16($r + 2)] = $this->i16($r + 4);
                }
            }
            $o += $len;
        }
        // (Applied passing over marks, as HarfBuzz applies the kern table.)
        return $pairs ? [[8, null, [['p', $pairs, false]]]] : [];
    }

    /**
     * The GSUB features YetiPDF Write can turn on: the ones HarfBuzz (so Typst) turns on by default, the
     * Arabic-style joining forms, and those text styles ask for. Lookups only other features use
     * aren't read.
     */
    private const GSUB_FEATURES = ['ccmp', 'locl', 'rlig', 'calt', 'rclt', 'liga', 'clig', 'mset', 'rtla', 'rtlm', 'ltra', 'ltrm',
        'isol', 'fina', 'fin2', 'fin3', 'medi', 'med2', 'init', 'tnum', 'pnum', 'lnum', 'onum', 'case', 'smcp'];

    /**
     * The substitutions (GSUB), as HarfBuzz applies them:
     *   scripts  [script tag => [language tag ('' for the default) => [required feature, [feature index, …]]]]
     *   features [feature index => [tag, [lookup index, …]]]
     *   lookups  [lookup index => [flag, mark filtering set, [subtable, …]]]
     * with each subtable one of
     *   ['s', [gid => gid]]                             single (and alternate: the first alternate)
     *   ['m', [gid => [gid, …]]]                        multiple
     *   ['l', [first => [[[gid, …], ligature], …]]]     ligature
     *   ['c', format, coverage, backtrack classes, input classes, lookahead classes, rules]
     *                                                   (chained) context: rules per first glyph (format 1)
     *                                                   or input class (2), each [backtrack, input, lookahead,
     *                                                   [[sequence index, lookup], …]] in glyphs, classes or
     *                                                   coverages (3: one rule)
     *   ['r', coverage, backtrack coverages, lookahead coverages, [gid => gid]]  reverse chaining single
     */
    private function gsub(): array
    {
        if (!isset($this->tables['GSUB'])) {
            return [];
        }
        $t = $this->tables['GSUB'][0];
        $scriptList = $t + $this->u16($t + 4);
        $featureList = $t + $this->u16($t + 6);
        $lookupList = $t + $this->u16($t + 8);
        $features = [];
        $lookups = [];
        $todo = [];
        $feature = function (int $i) use (&$features, &$todo, $featureList): void {
            if (isset($features[$i])) {
                return;
            }
            $r = $featureList + 2 + 6 * $i;
            $f = $featureList + $this->u16($r + 4);
            $list = [];
            for ($j = 0, $n = $this->u16($f + 2); $j < $n; $j++) {
                $list[] = $todo[] = $this->u16($f + 4 + 2 * $j);
            }
            $features[$i] = [substr($this->d, $r, 4), $list];
        };
        $langSys = function (int $o) use ($feature, $featureList): array {
            $req = $this->u16($o + 2);
            $list = [];
            for ($j = 0, $n = $this->u16($o + 4); $j < $n; $j++) {
                $i = $this->u16($o + 6 + 2 * $j);
                if (in_array(substr($this->d, $featureList + 2 + 6 * $i, 4), self::GSUB_FEATURES, true)) {
                    $feature($i);
                    $list[] = $i;
                }
            }
            if ($req !== 0xFFFF) {
                $feature($req);
            }
            return [$req === 0xFFFF ? -1 : $req, $list];
        };
        $scripts = [];
        for ($i = 0, $n = $this->u16($scriptList); $i < $n; $i++) {
            $r = $scriptList + 2 + 6 * $i;
            $s = $scriptList + $this->u16($r + 4);
            $tag = substr($this->d, $r, 4);
            if ($def = $this->u16($s)) {
                $scripts[$tag][''] = $langSys($s + $def);
            }
            for ($j = 0, $m = $this->u16($s + 2); $j < $m; $j++) {
                $lr = $s + 4 + 6 * $j;
                $scripts[$tag][substr($this->d, $lr, 4)] = $langSys($s + $this->u16($lr + 4));
            }
        }
        // Every lookup the features use, and those their contextual rules call.
        while ($todo) {
            $li = array_pop($todo);
            if (isset($lookups[$li]) || $li >= $this->u16($lookupList)) {
                continue;
            }
            $l = $lookupList + $this->u16($lookupList + 2 + 2 * $li);
            $flag = $this->u16($l + 2);
            $n = $this->u16($l + 4);
            $subs = [];
            foreach ($this->subtables($lookupList, $li, 7) as [$type, $s]) {
                if (($sub = $this->gsubSubtable($type, $s)) !== null) {
                    $subs[] = $sub;
                    if ($sub[0] === 'c') {
                        foreach ($sub[6] as $rules) {
                            foreach ($rules as $rule) {
                                foreach ($rule[3] as [, $called]) {
                                    $todo[] = $called;
                                }
                            }
                        }
                    }
                }
            }
            $lookups[$li] = [$flag, $flag & 0x10 ? $this->u16($l + 6 + 2 * $n) : null, $subs];
        }
        ksort($features);
        ksort($lookups);
        return ['scripts' => $scripts, 'features' => $features, 'lookups' => $lookups];
    }

    private function gsubSubtable(int $type, int $s): ?array
    {
        $fmt = $this->u16($s);
        switch ($type) {
            case 1:
                $cov = $this->coverage($s + $this->u16($s + 2));
                $map = [];
                foreach ($cov as $g => $ci) {
                    $map[$g] = $fmt === 1 ? ($g + $this->u16($s + 4)) & 0xFFFF : $this->u16($s + 6 + 2 * $ci);
                }
                return ['s', $map];
            case 2:
            case 3:
                $cov = $this->coverage($s + $this->u16($s + 2));
                $map = [];
                foreach ($cov as $g => $ci) {
                    $seq = $s + $this->u16($s + 6 + 2 * $ci);
                    $list = [];
                    for ($k = 0, $n = $this->u16($seq); $k < $n; $k++) {
                        $list[] = $this->u16($seq + 2 + 2 * $k);
                    }
                    if ($type === 2) {
                        $map[$g] = $list;
                    } elseif ($list) {
                        $map[$g] = $list[0];
                    }
                }
                return [$type === 2 ? 'm' : 's', $map];
            case 4:
                $cov = $this->coverage($s + $this->u16($s + 2));
                $ligs = [];
                foreach ($cov as $first => $ci) {
                    $set = $s + $this->u16($s + 6 + 2 * $ci);
                    for ($k = 0, $lc = $this->u16($set); $k < $lc; $k++) {
                        $lig = $set + $this->u16($set + 2 + 2 * $k);
                        $comps = [];
                        for ($c = 1, $cc = $this->u16($lig + 2); $c < $cc; $c++) {
                            $comps[] = $this->u16($lig + 2 + 2 * $c);
                        }
                        $ligs[$first][] = [$comps, $this->u16($lig)];
                    }
                }
                return ['l', $ligs];
            case 5:
            case 6:
                return $this->contextSubtable($type === 6, $fmt, $s);
            case 8:
                $cov = $this->coverage($s + $this->u16($s + 2));
                $o = $s + 4;
                $bt = $this->coverages($o, $s);
                $o += 2 + 2 * $this->u16($o);
                $la = $this->coverages($o, $s);
                $o += 2 + 2 * $this->u16($o);
                $map = [];
                foreach ($cov as $g => $ci) {
                    $map[$g] = $this->u16($o + 2 + 2 * $ci);
                }
                return ['r', $cov, $bt, $la, $map];
        }
        return null;
    }

    /** A list of coverage tables: a count, then offsets from $base (the subtable). */
    private function coverages(int $o, int $base): array
    {
        $out = [];
        for ($k = 0, $n = $this->u16($o); $k < $n; $k++) {
            $out[] = $this->coverage($base + $this->u16($o + 2 + 2 * $k));
        }
        return $out;
    }

    /** Context (type 5) and chained context (type 6) substitution, formats 1 to 3. */
    private function contextSubtable(bool $chain, int $fmt, int $s): ?array
    {
        $records = function (int $o, int $n): array {
            $out = [];
            for ($k = 0; $k < $n; $k++) {
                $out[] = [$this->u16($o + 4 * $k), $this->u16($o + 4 * $k + 2)];
            }
            return $out;
        };
        $list = function (int $o, int $n): array {
            $out = [];
            for ($k = 0; $k < $n; $k++) {
                $out[] = $this->u16($o + 2 * $k);
            }
            return $out;
        };
        // One rule of format 1 or 2: [backtrack, input after the first, lookahead, records].
        $rule = function (int $r) use ($chain, $records, $list): array {
            if (!$chain) {
                $in = $this->u16($r);
                $n = $this->u16($r + 2);
                return [[], $list($r + 4, $in - 1), [], $records($r + 4 + 2 * ($in - 1), $n)];
            }
            $bt = $list($r + 2, $this->u16($r));
            $o = $r + 2 + 2 * count($bt);
            $in = $list($o + 2, max(0, $this->u16($o) - 1));
            $o += 2 + 2 * count($in);
            $la = $list($o + 2, $this->u16($o));
            $o += 2 + 2 * count($la);
            return [$bt, $in, $la, $records($o + 2, $this->u16($o))];
        };
        $sets = function (int $o, int $n) use ($s, $rule): array {
            $out = [];
            for ($k = 0; $k < $n; $k++) {
                $off = $this->u16($o + 2 * $k);
                if ($off === 0) {
                    continue;
                }
                $set = $s + $off;
                for ($j = 0, $m = $this->u16($set); $j < $m; $j++) {
                    $out[$k][] = $rule($set + $this->u16($set + 2 + 2 * $j));
                }
            }
            return $out;
        };
        if ($fmt === 1) {
            $cov = $this->coverage($s + $this->u16($s + 2));
            $byIndex = $sets($s + 6, $this->u16($s + 4));
            $rules = [];
            foreach ($cov as $g => $ci) {
                if (isset($byIndex[$ci])) {
                    $rules[$g] = $byIndex[$ci];
                }
            }
            return ['c', 1, $cov, null, null, null, $rules];
        }
        if ($fmt === 2) {
            $cov = $this->coverage($s + $this->u16($s + 2));
            $def = fn (int $o) => $o ? $this->classDef($s + $o) : [];
            if ($chain) {
                return ['c', 2, $cov, $def($this->u16($s + 4)), $def($this->u16($s + 6)), $def($this->u16($s + 8)), $sets($s + 12, $this->u16($s + 10))];
            }
            return ['c', 2, $cov, [], $def($this->u16($s + 4)), [], $sets($s + 8, $this->u16($s + 6))];
        }
        if ($fmt === 3) {
            if (!$chain) {
                $n = $this->u16($s + 2);
                $in = [];
                for ($k = 0; $k < $n; $k++) {
                    $in[] = $this->coverage($s + $this->u16($s + 6 + 2 * $k));
                }
                return $in ? ['c', 3, $in[0], null, null, null, [[[[], array_slice($in, 1), [], $records($s + 6 + 2 * $n, $this->u16($s + 4))]]]] : null;
            }
            $o = $s + 2;
            $bt = $this->coverages($o, $s);
            $o += 2 + 2 * count($bt);
            $in = $this->coverages($o, $s);
            $o += 2 + 2 * count($in);
            $la = $this->coverages($o, $s);
            $o += 2 + 2 * count($la);
            return $in ? ['c', 3, $in[0], null, null, null, [[[$bt, array_slice($in, 1), $la, $records($o + 2, $this->u16($o))]]]] : null;
        }
        return null;
    }
}
