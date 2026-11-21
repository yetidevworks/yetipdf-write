<?php

declare(strict_types=1);

namespace YetiPdf\Write\Text;

/**
 * The Unicode Bidirectional Algorithm (UAX #9): embedding levels for the characters of a
 * paragraph, and the visual order of a line. Bidi classes come from PCRE (\p{bc=...}); mirrored
 * pairs and paired brackets from Unicode::data().
 *
 * Levels are resolved for a whole paragraph, then each line is reordered on its own (rules L1
 * and L2), after line breaking, as the algorithm intends.
 */
final class Bidi
{
    private const MAX_DEPTH = 125;

    /** Classes X9 removes: they take no part in resolving levels. */
    private const REMOVED = ['RLE' => 1, 'LRE' => 1, 'RLO' => 1, 'LRO' => 1, 'PDF' => 1, 'BN' => 1];
    private const ISOLATE_INIT = ['LRI' => 1, 'RLI' => 1, 'FSI' => 1];
    /** Explicit formatting controls and BN: paragraphs without them take the fast path. */
    private const EXPLICIT = ['RLE' => 1, 'LRE' => 1, 'RLO' => 1, 'LRO' => 1, 'PDF' => 1, 'LRI' => 1, 'RLI' => 1, 'FSI' => 1, 'PDI' => 1, 'BN' => 1];
    /** Neutral and isolate types for rules N1 and N2. */
    private const NI = ['B' => 1, 'S' => 1, 'WS' => 1, 'ON' => 1, 'LRI' => 1, 'RLI' => 1, 'FSI' => 1, 'PDI' => 1];
    /** Strong direction of a resolved type for rules N0–N2. */
    private const STRONG_OF = ['L' => 'L', 'R' => 'R', 'EN' => 'R', 'AN' => 'R'];

    private const CLASSES = ['L', 'R', 'AL', 'EN', 'ES', 'ET', 'AN', 'CS', 'NSM', 'BN', 'B', 'S', 'WS', 'ON',
        'LRE', 'LRO', 'RLE', 'RLO', 'PDF', 'LRI', 'RLI', 'FSI', 'PDI'];

    private const ASCII_CLASS = [
        'BN', 'BN', 'BN', 'BN', 'BN', 'BN', 'BN', 'BN', 'BN', 'S', 'B', 'S', 'WS', 'B', 'BN', 'BN',
        'BN', 'BN', 'BN', 'BN', 'BN', 'BN', 'BN', 'BN', 'BN', 'BN', 'BN', 'BN', 'B', 'B', 'B', 'S',
        'WS', 'ON', 'ON', 'ET', 'ET', 'ET', 'ON', 'ON', 'ON', 'ON', 'ON', 'ES', 'CS', 'ES', 'CS', 'CS',
        'EN', 'EN', 'EN', 'EN', 'EN', 'EN', 'EN', 'EN', 'EN', 'EN', 'CS', 'ON', 'ON', 'ON', 'ON', 'ON',
        'ON', 'L', 'L', 'L', 'L', 'L', 'L', 'L', 'L', 'L', 'L', 'L', 'L', 'L', 'L', 'L',
        'L', 'L', 'L', 'L', 'L', 'L', 'L', 'L', 'L', 'L', 'L', 'ON', 'ON', 'ON', 'ON', 'ON',
        'ON', 'L', 'L', 'L', 'L', 'L', 'L', 'L', 'L', 'L', 'L', 'L', 'L', 'L', 'L', 'L',
        'L', 'L', 'L', 'L', 'L', 'L', 'L', 'L', 'L', 'L', 'L', 'ON', 'ON', 'ON', 'ON', 'BN',
    ];

    /** @var array<int, string> */
    private static array $cache = [];

    /** Whether text needs the algorithm at all in a left-to-right paragraph. */
    public static function needed(string $text): bool
    {
        return preg_match('/[^\x00-\x7F]/', $text) && preg_match('/[\p{bc=R}\p{bc=AL}\p{bc=AN}\p{bc=RLE}\p{bc=RLO}\p{bc=RLI}\p{bc=FSI}]/u', $text);
    }

    public static function classOf(int $cp): string
    {
        if ($cp < 128 && $cp >= 0) {
            return self::ASCII_CLASS[$cp];
        }
        if (isset(self::$cache[$cp])) {
            return self::$cache[$cp];
        }
        $c = mb_chr($cp, 'UTF-8');
        foreach (self::CLASSES as $class) {
            if ($c !== false && preg_match("/^\\p{bc=$class}$/u", $c)) {
                return self::$cache[$cp] = $class;
            }
        }
        return self::$cache[$cp] = 'L';
    }

    /** The mirrored character for right-to-left text (")" for "("), or null. */
    public static function mirror(int $cp): ?int
    {
        return Unicode::data()['mirror'][$cp] ?? null;
    }

    /** Whether the paragraph's first strong character is right to left (rules P2 and P3). */
    public static function firstStrongIsRtl(array $types, int $from = 0, ?int $to = null): ?bool
    {
        $to ??= count($types);
        $depth = 0;
        for ($i = $from; $i < $to; $i++) {
            $t = $types[$i];
            if (isset(self::ISOLATE_INIT[$t])) {
                $depth++;
            } elseif ($t === 'PDI') {
                if ($depth > 0) {
                    $depth--;
                } elseif ($from > 0) {
                    break; // the end of the isolate being looked into (for FSI)
                }
            } elseif ($t === 'B') {
                break;
            } elseif ($depth === 0 && ($t === 'L' || $t === 'R' || $t === 'AL')) {
                return $t !== 'L';
            }
        }
        return null;
    }

    /**
     * Resolved embedding levels, one per code point, for text that may hold several paragraphs
     * (split at paragraph separators, each starting at $base). $base null finds each paragraph's
     * direction from its first strong character. Characters X9 removes get null.
     *
     * @param list<int> $cps
     * @return list<?int>
     */
    public static function levels(array $cps, ?int $base): array
    {
        $types = [];
        foreach ($cps as $cp) {
            if ($cp < 128 && $cp >= 0) {
                $types[] = self::ASCII_CLASS[$cp];
            } elseif (isset(self::$cache[$cp])) {
                $types[] = self::$cache[$cp];
            } else {
                $types[] = self::classOf($cp);
            }
        }
        return self::levelsOf($types, $cps, $base);
    }

    /**
     * levels() from bidi classes ($cps only matter for bracket pairs).
     *
     * @param list<string> $types
     * @param list<int> $cps
     * @return list<?int>
     */
    public static function levelsOf(array $types, array $cps, ?int $base): array
    {
        $n = count($types);
        $hasB = false;
        for ($i = 0; $i < $n; $i++) {
            if ($types[$i] === 'B') {
                $hasB = true;
                break;
            }
        }
        if (!$hasB) {
            if ($n === 0) {
                return [];
            }
            $level = $base ?? (self::firstStrongIsRtl($types) ? 1 : 0);
            return self::paragraph($types, $cps, 0, $level);
        }
        $levels = [];
        $start = 0;
        for ($i = 0; $i <= $n; $i++) {
            if ($i === $n || $types[$i] === 'B') {
                $end = min($i + 1, $n);
                if ($end > $start) {
                    $para = array_slice($types, $start, $end - $start);
                    $level = $base ?? (self::firstStrongIsRtl($para) ? 1 : 0);
                    foreach (self::paragraph($para, $cps, $start, $level) as $l) {
                        $levels[] = $l;
                    }
                }
                $start = $end;
            }
        }
        return $levels;
    }

    /**
     * Rule L1 for one line: whitespace (and isolate controls) at the end of the line, and before
     * a segment or paragraph separator, goes back to the paragraph level.
     *
     * @param list<string> $types the original bidi classes of the line's characters
     * @param list<?int> $levels
     * @return list<?int>
     */
    public static function lineLevels(array $types, array $levels, int $base): array
    {
        $trailing = true;
        for ($i = count($types) - 1; $i >= 0; $i--) {
            $t = $types[$i];
            if ($t === 'S' || $t === 'B') {
                $levels[$i] = $base;
                $trailing = true;
            } elseif ($trailing && ($t === 'WS' || isset(self::ISOLATE_INIT[$t]) || $t === 'PDI' || isset(self::REMOVED[$t]))) {
                $levels[$i] = $levels[$i] === null ? null : $base;
            } else {
                $trailing = false;
            }
        }
        return $levels;
    }

    /**
     * Rule L2: the visual order of a line, as indices into $levels (null entries left out).
     *
     * @param list<?int> $levels
     * @return list<int>
     */
    public static function order(array $levels): array
    {
        $idx = [];
        $lv = [];
        foreach ($levels as $i => $l) {
            if ($l !== null) {
                $idx[] = $i;
                $lv[] = $l;
            }
        }
        if (!$lv) {
            return [];
        }
        $max = max($lv);
        $minOdd = PHP_INT_MAX;
        foreach ($lv as $l) {
            if ($l % 2 === 1 && $l < $minOdd) {
                $minOdd = $l;
            }
        }
        $n = count($lv);
        for ($level = $max; $level >= $minOdd; $level--) {
            for ($i = 0; $i < $n; $i++) {
                if ($lv[$i] >= $level) {
                    $j = $i;
                    while ($j + 1 < $n && $lv[$j + 1] >= $level) {
                        $j++;
                    }
                    for ($a = $i, $b = $j; $a < $b; $a++, $b--) {
                        $tmp = $idx[$a];
                        $idx[$a] = $idx[$b];
                        $idx[$b] = $tmp;
                        $tmp = $lv[$a];
                        $lv[$a] = $lv[$b];
                        $lv[$b] = $tmp;
                    }
                    $i = $j;
                }
            }
        }
        return $idx;
    }

    /**
     * One paragraph: explicit levels (X1–X10), then weak types, brackets, neutrals and implicit
     * levels per isolating run sequence (W1–W7, N0–N2, I1–I2).
     *
     * @param list<string> $orig bidi classes
     * @param list<int> $cps the whole text's code points ($at is where this paragraph starts)
     * @return list<?int>
     */
    private static function paragraph(array $orig, array $cps, int $at, int $base): array
    {
        $n = count($orig);
        $hasExplicit = false;
        for ($i = 0; $i < $n; $i++) {
            if (isset(self::EXPLICIT[$orig[$i]])) {
                $hasExplicit = true;
                break;
            }
        }
        if (!$hasExplicit) {
            // Fast path: no explicit embeddings, overrides, isolates or BN characters.
            // Every character stays at explicit level $base in a single contiguous run sequence.
            $types = $orig;
            $dir = ($base & 1) ? 'R' : 'L';
            self::resolveContiguous($types, $orig, $cps, $at, $n, $base, $dir);
            $levels = [];
            if (($base & 1) === 0) {
                for ($i = 0; $i < $n; $i++) {
                    $t = $types[$i];
                    $levels[] = $base + ($t === 'R' ? 1 : ($t === 'AN' || $t === 'EN' ? 2 : 0));
                }
            } else {
                for ($i = 0; $i < $n; $i++) {
                    $t = $types[$i];
                    $levels[] = ($t === 'L' || $t === 'EN' || $t === 'AN') ? $base + 1 : $base;
                }
            }
            return $levels;
        }
        $types = $orig;
        $levels = array_fill(0, $n, $base);

        // BD9: each isolate initiator's matching PDI.
        $match = [];
        $open = [];
        for ($i = 0; $i < $n; $i++) {
            if (isset(self::ISOLATE_INIT[$orig[$i]])) {
                $open[] = $i;
            } elseif ($orig[$i] === 'PDI' && $open) {
                $match[array_pop($open)] = $i;
            }
        }

        // X1–X8: explicit embeddings, overrides and isolates.
        $stack = [[$base, null, false]]; // [level, override (L|R|null), isolate]
        $overflowIsolate = 0;
        $overflowEmbedding = 0;
        $validIsolate = 0;
        for ($i = 0; $i < $n; $i++) {
            $t = $orig[$i];
            [$level, $override] = $stack[count($stack) - 1];
            switch ($t) {
                case 'RLE':
                case 'LRE':
                case 'RLO':
                case 'LRO':
                    $rtl = $t === 'RLE' || $t === 'RLO';
                    $new = $rtl ? ($level + 1) | 1 : ($level + 2) & ~1;
                    if ($new <= self::MAX_DEPTH && $overflowIsolate === 0 && $overflowEmbedding === 0) {
                        $stack[] = [$new, $t === 'RLO' ? 'R' : ($t === 'LRO' ? 'L' : null), false];
                    } elseif ($overflowIsolate === 0) {
                        $overflowEmbedding++;
                    }
                    $levels[$i] = $level;
                    break;
                case 'RLI':
                case 'LRI':
                case 'FSI':
                    $levels[$i] = $level;
                    if ($override !== null) {
                        $types[$i] = $override;
                    }
                    $rtl = $t === 'RLI';
                    if ($t === 'FSI') {
                        $rtl = (bool) self::firstStrongIsRtl($orig, $i + 1, $match[$i] ?? $n);
                    }
                    $new = $rtl ? ($level + 1) | 1 : ($level + 2) & ~1;
                    if ($new <= self::MAX_DEPTH && $overflowIsolate === 0 && $overflowEmbedding === 0) {
                        $validIsolate++;
                        $stack[] = [$new, null, true];
                    } else {
                        $overflowIsolate++;
                    }
                    break;
                case 'PDI':
                    if ($overflowIsolate > 0) {
                        $overflowIsolate--;
                    } elseif ($validIsolate > 0) {
                        $overflowEmbedding = 0;
                        while (!$stack[count($stack) - 1][2]) {
                            array_pop($stack);
                        }
                        array_pop($stack);
                        $validIsolate--;
                    }
                    [$level, $override] = $stack[count($stack) - 1];
                    $levels[$i] = $level;
                    if ($override !== null) {
                        $types[$i] = $override;
                    }
                    break;
                case 'PDF':
                    if ($overflowIsolate === 0) {
                        if ($overflowEmbedding > 0) {
                            $overflowEmbedding--;
                        } elseif (!$stack[count($stack) - 1][2] && count($stack) >= 2) {
                            array_pop($stack);
                        }
                    }
                    $levels[$i] = $level;
                    break;
                case 'B':
                    $levels[$i] = $base;
                    break;
                case 'BN':
                    $levels[$i] = $level;
                    break;
                default:
                    $levels[$i] = $level;
                    if ($override !== null) {
                        $types[$i] = $override;
                    }
            }
        }

        // X9: the removed characters sit out; X10: level runs joined into isolating run sequences.
        $kept = [];
        for ($i = 0; $i < $n; $i++) {
            if (!isset(self::REMOVED[$orig[$i]])) {
                $kept[] = $i;
            }
        }
        $runs = [];
        foreach ($kept as $i) {
            if ($runs && $levels[$i] === $levels[$runs[count($runs) - 1][0]]) {
                $runs[count($runs) - 1][] = $i;
            } else {
                $runs[] = [$i];
            }
        }
        $runOf = [];
        foreach ($runs as $r => $run) {
            $runOf[$run[0]] = $r;
        }
        $matched = array_flip($match); // PDI => its initiator
        $sequences = [];
        foreach ($runs as $run) {
            if ($orig[$run[0]] === 'PDI' && isset($matched[$run[0]])) {
                continue; // continues the sequence its initiator ends
            }
            $seq = $run;
            while (true) {
                $last = $seq[count($seq) - 1];
                if (!isset(self::ISOLATE_INIT[$orig[$last]], $match[$last], $runOf[$match[$last]])) {
                    break;
                }
                array_push($seq, ...$runs[$runOf[$match[$last]]]);
            }
            $sequences[] = $seq;
        }
        $pos = array_flip($kept);
        $explicit = $levels; // sos and eos compare explicit levels, before any sequence is resolved
        foreach ($sequences as $seq) {
            $first = $seq[0];
            $last = $seq[count($seq) - 1];
            $level = $levels[$first];
            $p = $pos[$first];
            $prev = $p > 0 ? $explicit[$kept[$p - 1]] : $base;
            $q = $pos[$last];
            $next = isset(self::ISOLATE_INIT[$orig[$last]]) || !isset($kept[$q + 1]) ? $base : $explicit[$kept[$q + 1]];
            $sos = max($level, $prev) % 2 ? 'R' : 'L';
            $eos = max($level, $next) % 2 ? 'R' : 'L';
            self::resolve($seq, $types, $orig, $cps, $at, $level, $sos, $eos);
            foreach ($seq as $i) {
                $t = $types[$i];
                if ($level % 2 === 0) {
                    $levels[$i] += $t === 'R' ? 1 : ($t === 'AN' || $t === 'EN' ? 2 : 0);
                } elseif ($t === 'L' || $t === 'EN' || $t === 'AN') {
                    $levels[$i]++;
                }
            }
        }

        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $out[] = isset(self::REMOVED[$orig[$i]]) ? null : $levels[$i];
        }
        return $out;
    }

    /** W1–W7, N0–N2 on one isolating run sequence ($seq: indices, in order). */
    private static function resolve(array $seq, array &$types, array $orig, array $cps, int $at, int $level, string $sos, string $eos): void
    {
        $m = count($seq);
        // W1: non-spacing marks take the type before them (ON after an isolate control).
        $prev = $sos;
        foreach ($seq as $i) {
            if ($types[$i] === 'NSM') {
                $types[$i] = isset(self::ISOLATE_INIT[$prev]) || $prev === 'PDI' ? 'ON' : $prev;
            }
            $prev = $types[$i];
        }
        // W2, W3: European numbers after Arabic letters are Arabic numbers; AL becomes R.
        $strong = $sos;
        foreach ($seq as $i) {
            $t = $types[$i];
            if ($t === 'L' || $t === 'R' || $t === 'AL') {
                $strong = $t;
            } elseif ($t === 'EN' && $strong === 'AL') {
                $types[$i] = 'AN';
            }
        }
        foreach ($seq as $i) {
            if ($types[$i] === 'AL') {
                $types[$i] = 'R';
            }
        }
        // W4: a single separator between two numbers of the same kind joins them.
        for ($k = 1; $k < $m - 1; $k++) {
            $t = $types[$seq[$k]];
            $a = $types[$seq[$k - 1]];
            $b = $types[$seq[$k + 1]];
            if ($t === 'ES' && $a === 'EN' && $b === 'EN') {
                $types[$seq[$k]] = 'EN';
            } elseif ($t === 'CS' && $a === $b && ($a === 'EN' || $a === 'AN')) {
                $types[$seq[$k]] = $a;
            }
        }
        // W5: terminators next to European numbers become European numbers.
        for ($k = 0; $k < $m; $k++) {
            if ($types[$seq[$k]] !== 'ET') {
                continue;
            }
            $e = $k;
            while ($e + 1 < $m && $types[$seq[$e + 1]] === 'ET') {
                $e++;
            }
            if (($k > 0 && $types[$seq[$k - 1]] === 'EN') || ($e + 1 < $m && $types[$seq[$e + 1]] === 'EN')) {
                for ($x = $k; $x <= $e; $x++) {
                    $types[$seq[$x]] = 'EN';
                }
            }
            $k = $e;
        }
        // W6: other separators and terminators are neutral.
        foreach ($seq as $i) {
            if ($types[$i] === 'ES' || $types[$i] === 'ET' || $types[$i] === 'CS') {
                $types[$i] = 'ON';
            }
        }
        // W7: European numbers after left-to-right text are left to right.
        $strong = $sos;
        foreach ($seq as $i) {
            $t = $types[$i];
            if ($t === 'L' || $t === 'R') {
                $strong = $t;
            } elseif ($t === 'EN' && $strong === 'L') {
                $types[$i] = 'L';
            }
        }

        // N0: paired brackets take the direction of what they enclose (or the context).
        $e = $level % 2 ? 'R' : 'L';
        foreach (self::bracketPairs($seq, $types, $cps, $at) as [$o, $c]) {
            $found = null;
            for ($k = $o + 1; $k < $c; $k++) {
                $s = self::STRONG_OF[$types[$seq[$k]]] ?? null;
                if ($s === $e) {
                    $found = $e;
                    break;
                }
                if ($s !== null) {
                    $found = $s;
                }
            }
            if ($found === null) {
                continue;
            }
            $dir = $found;
            if ($found !== $e) {
                $before = $sos;
                for ($k = $o - 1; $k >= 0; $k--) {
                    if (($s = self::STRONG_OF[$types[$seq[$k]]] ?? null) !== null) {
                        $before = $s;
                        break;
                    }
                }
                $dir = $before === $found ? $found : $e;
            }
            foreach ([$o, $c] as $k) {
                $types[$seq[$k]] = $dir;
                // Marks that followed the bracket follow its new direction.
                for ($x = $k + 1; $x < $m && $orig[$seq[$x]] === 'NSM'; $x++) {
                    $types[$seq[$x]] = $dir;
                }
            }
        }

        // N1, N2: neutrals between two runs of the same direction take it, others the embedding's.
        for ($k = 0; $k < $m; $k++) {
            if (!isset(self::NI[$types[$seq[$k]]])) {
                continue;
            }
            $end = $k;
            while ($end + 1 < $m && isset(self::NI[$types[$seq[$end + 1]]])) {
                $end++;
            }
            $before = $k > 0 ? (self::STRONG_OF[$types[$seq[$k - 1]]] ?? null) : $sos;
            $after = $end + 1 < $m ? (self::STRONG_OF[$types[$seq[$end + 1]]] ?? null) : $eos;
            $dir = $before !== null && $before === $after ? $before : $e;
            for ($x = $k; $x <= $end; $x++) {
                $types[$seq[$x]] = $dir;
            }
            $k = $end;
        }
    }

    /** W1–W7, N0–N2 on a contiguous 0..$m-1 paragraph (no explicit controls or BN). */
    private static function resolveContiguous(array &$types, array $orig, array $cps, int $at, int $m, int $level, string $e): void
    {
        // W1 + W2 + W3: non-spacing marks take the type before them; EN after AL -> AN; AL -> R.
        $prev = $e;
        $strong = $e;
        for ($i = 0; $i < $m; $i++) {
            $t = $types[$i];
            if ($t === 'NSM') {
                $t = $types[$i] = $prev;
            }
            $prev = $t;
            if ($t === 'AL') {
                $strong = 'AL';
                $types[$i] = 'R';
            } elseif ($t === 'L' || $t === 'R') {
                $strong = $t;
            } elseif ($t === 'EN' && $strong === 'AL') {
                $types[$i] = 'AN';
            }
        }
        // W4: a single separator between two numbers of the same kind joins them.
        for ($k = 1; $k < $m - 1; $k++) {
            $t = $types[$k];
            if ($t === 'ES') {
                if ($types[$k - 1] === 'EN' && $types[$k + 1] === 'EN') {
                    $types[$k] = 'EN';
                }
            } elseif ($t === 'CS') {
                $a = $types[$k - 1];
                if ($a === $types[$k + 1] && ($a === 'EN' || $a === 'AN')) {
                    $types[$k] = $a;
                }
            }
        }
        // W5: terminators next to European numbers become European numbers.
        for ($k = 0; $k < $m; $k++) {
            if ($types[$k] !== 'ET') {
                continue;
            }
            $end = $k;
            while ($end + 1 < $m && $types[$end + 1] === 'ET') {
                $end++;
            }
            if (($k > 0 && $types[$k - 1] === 'EN') || ($end + 1 < $m && $types[$end + 1] === 'EN')) {
                for ($x = $k; $x <= $end; $x++) {
                    $types[$x] = 'EN';
                }
            }
            $k = $end;
        }
        // W6 + W7: remaining separators/terminators become ON; EN after L becomes L.
        $strong = $e;
        $hasOn = false;
        for ($i = 0; $i < $m; $i++) {
            $t = $types[$i];
            if ($t === 'ES' || $t === 'ET' || $t === 'CS') {
                $types[$i] = 'ON';
                $hasOn = true;
            } elseif ($t === 'ON') {
                $hasOn = true;
            } elseif ($t === 'L' || $t === 'R') {
                $strong = $t;
            } elseif ($t === 'EN' && $strong === 'L') {
                $types[$i] = 'L';
            }
        }

        // N0: paired brackets take the direction of what they enclose (or the context).
        if ($hasOn) {
            $brackets = Unicode::data()['brackets'];
            $canon = [0x2329 => 0x3008, 0x232A => 0x3009];
            $stack = [];
            $pairs = [];
            for ($k = 0; $k < $m; $k++) {
                if ($types[$k] !== 'ON') {
                    continue;
                }
                $cp = $cps[$at + $k];
                if (!isset($brackets[$cp])) {
                    continue;
                }
                [$pair, $opening] = $brackets[$cp];
                if ($opening) {
                    if (count($stack) === 63) {
                        break;
                    }
                    $stack[] = [$canon[$pair] ?? $pair, $k];
                    continue;
                }
                $cp = $canon[$cp] ?? $cp;
                for ($s = count($stack) - 1; $s >= 0; $s--) {
                    if ($stack[$s][0] === $cp) {
                        $pairs[] = [$stack[$s][1], $k];
                        array_splice($stack, $s);
                        break;
                    }
                }
            }
            if ($pairs) {
                usort($pairs, fn ($a, $b) => $a[0] <=> $b[0]);
                foreach ($pairs as [$o, $c]) {
                    $found = null;
                    for ($k = $o + 1; $k < $c; $k++) {
                        $s = self::STRONG_OF[$types[$k]] ?? null;
                        if ($s === $e) {
                            $found = $e;
                            break;
                        }
                        if ($s !== null) {
                            $found = $s;
                        }
                    }
                    if ($found === null) {
                        continue;
                    }
                    $dir = $found;
                    if ($found !== $e) {
                        $before = $e;
                        for ($k = $o - 1; $k >= 0; $k--) {
                            if (($s = self::STRONG_OF[$types[$k]] ?? null) !== null) {
                                $before = $s;
                                break;
                            }
                        }
                        $dir = $before === $found ? $found : $e;
                    }
                    $types[$o] = $dir;
                    for ($x = $o + 1; $x < $m && $orig[$x] === 'NSM'; $x++) {
                        $types[$x] = $dir;
                    }
                    $types[$c] = $dir;
                    for ($x = $c + 1; $x < $m && $orig[$x] === 'NSM'; $x++) {
                        $types[$x] = $dir;
                    }
                }
            }
        }

        // N1, N2: neutrals between two runs of the same direction take it, others the embedding's.
        for ($k = 0; $k < $m; $k++) {
            if (!isset(self::NI[$types[$k]])) {
                continue;
            }
            $end = $k;
            while ($end + 1 < $m && isset(self::NI[$types[$end + 1]])) {
                $end++;
            }
            $before = $k > 0 ? (self::STRONG_OF[$types[$k - 1]] ?? null) : $e;
            $after = $end + 1 < $m ? (self::STRONG_OF[$types[$end + 1]] ?? null) : $e;
            $dir = $before !== null && $before === $after ? $before : $e;
            for ($x = $k; $x <= $end; $x++) {
                $types[$x] = $dir;
            }
            $k = $end;
        }
    }

    /**
     * BD16: the paired brackets of a sequence, as positions in it [opening, closing], in order of
     * the opening bracket. Only brackets still neutral (ON) pair up.
     *
     * @return list<array{0: int, 1: int}>
     */
    private static function bracketPairs(array $seq, array $types, array $cps, int $at): array
    {
        $brackets = Unicode::data()['brackets'];
        $canon = [0x2329 => 0x3008, 0x232A => 0x3009]; // canonical equivalents
        $stack = [];
        $pairs = [];
        foreach ($seq as $k => $i) {
            $cp = $cps[$at + $i];
            if ($types[$i] !== 'ON' || !isset($brackets[$cp])) {
                continue;
            }
            [$pair, $opening] = $brackets[$cp];
            if ($opening) {
                if (count($stack) === 63) {
                    break;
                }
                $stack[] = [$canon[$pair] ?? $pair, $k];
                continue;
            }
            $cp = $canon[$cp] ?? $cp;
            for ($s = count($stack) - 1; $s >= 0; $s--) {
                if ($stack[$s][0] === $cp) {
                    $pairs[] = [$stack[$s][1], $k];
                    array_splice($stack, $s);
                    break;
                }
            }
        }
        usort($pairs, fn ($a, $b) => $a[0] <=> $b[0]);
        return $pairs;
    }
}
