<?php

declare(strict_types=1);

namespace YetiPdf\Write\Text;

/**
 * Unicode data PCRE doesn't offer (resources/unicode.php, from tools/unicode-data.php), and the
 * normalization a text engine does before looking up glyphs.
 */
final class Unicode
{
    private static ?array $data = null;

    /**
     * HarfBuzz's modified combining classes: Hebrew and Arabic points (and a few others) are
     * ordered the way they stack, not by their Unicode classes (dagesh before sheva, shin dot
     * before qamats, shadda before the vowels).
     */
    private const MODIFIED_CCC = [
        10 => 22, 11 => 15, 12 => 16, 13 => 17, 14 => 23, 15 => 18, 16 => 19, 17 => 20, 18 => 21,
        19 => 14, 20 => 24, 21 => 12, 22 => 25, 23 => 13, 24 => 10, 25 => 11, 26 => 26,
        27 => 28, 28 => 29, 29 => 30, 30 => 31, 31 => 32, 32 => 33, 33 => 27, 34 => 34, 35 => 35, 36 => 36,
        103 => 3, 130 => 132, 132 => 131,
    ];

    /** A character's combining class as HarfBuzz orders marks by (0 for a starter). */
    public static function ccc(int $cp): int
    {
        $c = self::data()['ccc'][$cp] ?? 0;
        return self::MODIFIED_CCC[$c] ?? $c;
    }

    /** @return array{mirror: array, brackets: array, decompose: array, compose: array, ccc: array} */
    public static function data(): array
    {
        return self::$data ??= require dirname(__DIR__, 2) . '/resources/unicode.php';
    }

    /** Arabic marks that modify the letter (hamza above and below, small high seen and the like). */
    private const MODIFIER_SET = [
        0x654 => true, 0x655 => true, 0x658 => true, 0x6DC => true, 0x6E3 => true, 0x6E7 => true, 0x6E8 => true,
        0x8CA => true, 0x8CB => true, 0x8CD => true, 0x8CE => true, 0x8CF => true, 0x8D3 => true, 0x8F3 => true,
    ];

    /** @var array<int, bool> */
    private static array $isMark = [];

    /**
     * HarfBuzz's Arabic mark reordering: in a sorted run of marks, modifier marks at the start of
     * the below (220) and above (230) groups move to the front of the run, so they're drawn
     * first, nearest the letter.
     *
     * @param list<int> $keys the run's order; $cps and $cccs in that order
     */
    private static function arabicModifiers(array $keys, array $cps, array $cccs): array
    {
        $hasMod = false;
        foreach ($cps as $cp) {
            if (isset(self::MODIFIER_SET[$cp])) {
                $hasMod = true;
                break;
            }
        }
        if (!$hasMod) {
            return $keys;
        }
        $n = count($keys);
        $start = 0;
        $i = 0;
        foreach ([220, 230] as $cc) {
            while ($i < $n && $cccs[$i] < $cc) {
                $i++;
            }
            if ($i === $n) {
                break;
            }
            if ($cccs[$i] > $cc) {
                continue;
            }
            $j = $i;
            while ($j < $n && $cccs[$j] === $cc && isset(self::MODIFIER_SET[$cps[$j]])) {
                $j++;
            }
            if ($i === $j) {
                continue;
            }
            foreach ([&$keys, &$cps, &$cccs] as &$list) {
                $list = [...array_slice($list, 0, $start), ...array_slice($list, $i, $j - $i), ...array_slice($list, $start, $i - $start), ...array_slice($list, $j)];
            }
            unset($list);
            $start += $j - $i;
            $i = $j;
        }
        return $keys;
    }

    /**
     * Normalize characters for a font, as HarfBuzz does: a character the font lacks is split
     * into its canonical decomposition, marks are put in order (by HarfBuzz's modified combining
     * classes, and for Arabic-style scripts, $arabic, modifier marks first), and a character
     * followed by a mark becomes their composed form when the font has it ("a" + U+0301 is drawn
     * with the font's "á"). A CGJ that keeps two marks from being reordered is flagged (index
     * 2): matching mustn't pass over it.
     *
     * @param list<string> $chars
     * @param callable(int): bool $has whether the font has a glyph for a code point
     * @return list<array{0: int, 1: string, 2?: true}> [code point, the text it stands for]
     */
    public static function normalize(array $chars, callable $has, bool $full = false, bool $arabic = false): array
    {
        $cps = [];
        $inert = true;
        foreach ($chars as $ch) {
            $cp = strlen($ch) === 1 ? ord($ch) : mb_ord($ch);
            $cps[] = $cp;
            if ($inert && $cp >= 0xC0 && !($cp >= 0x2002 && $cp <= 0x206F) && !($cp >= 0x20A0 && $cp <= 0x20CF)) {
                $inert = false;
            }
        }
        // Code points in 0x00..0xBF, 0x2002..0x206F and 0x20A0..0x20CF never decompose, have
        // combining class 0, are never CGJ and are never marks: nothing to normalize, and
        // resources/unicode.php doesn't need to be loaded for common Latin punctuation.
        if ($inert) {
            $out = [];
            foreach ($chars as $i => $ch) {
                $out[] = [$cps[$i], $ch];
            }
            return $out;
        }

        $d = self::data();
        $decomposeMap = $d['decompose'];
        $composeMap = $d['compose'];
        $cccMap = $d['ccc'];
        $out = [];
        $decompose = null;
        $hasCgj = false;
        foreach ($chars as $i => $ch) {
            $cp = $cps[$i];
            if (!isset($decomposeMap[$cp])) {
                if ($cp === 0x034F) {
                    $hasCgj = true;
                }
                $out[] = [$cp, $ch];
                continue;
            }
            // Decomposed as far as the font allows when marks follow (or always, $full: for a
            // font that positions marks), else only when the font lacks the character, as
            // HarfBuzz does; what can be is composed again below. A decomposed character's text
            // goes with its first piece.
            $next = $chars[$i + 1] ?? '';
            $keep = !$full && ($next === '' || strlen($next) === 1 || !preg_match('/^\p{M}/u', $next));
            if ($keep && $has($cp)) {
                $out[] = [$cp, $ch];
                continue;
            }
            $decompose ??= function (int $cp, bool $keep) use (&$decompose, $decomposeMap, $has): array {
                if (($keep && $has($cp)) || !isset($decomposeMap[$cp])) {
                    return [$cp];
                }
                $parts = [];
                foreach ($decomposeMap[$cp] as $p) {
                    foreach ($decompose($p, $keep) as $sub) {
                        $parts[] = $sub;
                    }
                }
                // Only worth it when the font has every piece.
                foreach ($parts as $p) {
                    if (!$has($p)) {
                        return [$cp];
                    }
                }
                return $parts;
            };
            foreach ($decompose($cp, $keep) as $k => $p) {
                if ($p === 0x034F) {
                    $hasCgj = true;
                }
                $out[] = [$p, $k === 0 ? $ch : ''];
            }
        }
        // Within a run of marks, sorted by combining class (stable). When that changes their
        // order, the run's text goes with its first mark, in the order it was typed (the rest
        // get none), so the cluster still copies out as the source text.
        $n = count($out);
        $ccc = [];
        $hasMarks = false;
        for ($i = 0; $i < $n; $i++) {
            $raw = $cccMap[$out[$i][0]] ?? 0;
            if ($raw !== 0) {
                $ccc[] = self::MODIFIED_CCC[$raw] ?? $raw;
                $hasMarks = true;
            } else {
                $ccc[] = 0;
            }
        }
        if ($hasMarks) {
            for ($i = 0; $i < $n; $i++) {
                if ($ccc[$i] === 0) {
                    continue;
                }
                $j = $i;
                while ($j + 1 < $n && $ccc[$j + 1] !== 0) {
                    $j++;
                }
                if ($j > $i) {
                    $len = $j - $i + 1;
                    $keys = range(0, $len - 1);
                    $origKeys = $keys;
                    usort($keys, fn ($a, $b) => ($ccc[$i + $a] <=> $ccc[$i + $b]) ?: ($a <=> $b));
                    if ($arabic) {
                        $rcps = [];
                        $rcccs = [];
                        foreach ($keys as $k) {
                            $rcps[] = $out[$i + $k][0];
                            $rcccs[] = $ccc[$i + $k];
                        }
                        $keys = self::arabicModifiers($keys, $rcps, $rcccs);
                    }
                    if ($keys !== $origKeys) {
                        $run = array_slice($out, $i, $len);
                        $runCcc = array_slice($ccc, $i, $len);
                        $text = '';
                        foreach ($run as $re) {
                            $text .= $re[1];
                        }
                        foreach ($keys as $at => $k) {
                            $out[$i + $at] = [$run[$k][0], $at === 0 ? $text : ''];
                            $ccc[$i + $at] = $runCcc[$k];
                        }
                    }
                }
                $i = $j;
            }
        }
        // A CGJ between marks that would otherwise be reordered stays in the way (HarfBuzz
        // decides this before composing).
        if ($hasCgj) {
            for ($i = 1; $i + 1 < $n; $i++) {
                if ($out[$i][0] === 0x034F && ($next = $ccc[$i + 1]) !== 0 && $ccc[$i - 1] > $next) {
                    $out[$i][2] = true;
                }
            }
        }
        // Compose each mark into the character before it when nothing blocks it and the font
        // has the result.
        $result = [];
        $resCount = 0;
        $starter = null;
        $starterCp = 0;
        $last = 0; // combining class of the last mark kept after the starter
        for ($i = 0; $i < $n; $i++) {
            $e = $out[$i];
            $cp = $e[0];
            $c = $ccc[$i];
            if ($starter !== null && ($last === 0 ? $resCount - 1 === $starter || $c > 0 : $last < $c)
                && ($composite = $composeMap[$starterCp . ' ' . $cp] ?? null) !== null
                && (self::$isMark[$cp] ??= preg_match('/^\p{M}$/u', mb_chr($cp)) === 1)
                && $has($composite)) {
                $result[$starter] = [$composite, $result[$starter][1] . $e[1]];
                $starterCp = $composite;
                continue;
            }
            $result[] = $e;
            $resCount++;
            if ($c === 0) {
                $starter = $resCount - 1;
                $starterCp = $cp;
                $last = 0;
            } else {
                $last = $c;
            }
        }
        return $result;
    }
}
