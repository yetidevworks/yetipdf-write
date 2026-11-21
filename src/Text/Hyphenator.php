<?php

declare(strict_types=1);

namespace YetiPdf\Write\Text;

/**
 * Finds hyphenation points with Liang patterns, compiled into tries by the hypher crate (the
 * library Typst uses; MIT/Apache-2.0, files in resources/hyphenation). This is a port of
 * hypher's trie walk, so words break at the same points as in Typst.
 */
final class Hyphenator
{
    /** ISO 639-1 code => [trie file, min chars before a break, min chars after]. */
    private const LANGS = [
        'af' => ['af', 1, 2],
        'as' => ['as', 2, 2],
        'be' => ['be', 2, 2],
        'bg' => ['bg', 2, 2],
        'bn' => ['bn', 2, 2],
        'ca' => ['ca', 2, 2],
        'cs' => ['cs', 2, 2],
        'da' => ['da', 2, 2],
        'de' => ['de', 2, 2],
        'el' => ['el', 1, 1],
        'en' => ['en', 2, 3],
        'es' => ['es', 2, 2],
        'et' => ['et', 2, 3],
        'fi' => ['fi', 2, 2],
        'fr' => ['fr', 2, 2],
        'gl' => ['gl', 2, 2],
        'gu' => ['gu', 2, 2],
        'hi' => ['hi', 2, 2],
        'hr' => ['hr', 2, 2],
        'hu' => ['hu', 2, 2],
        'is' => ['is', 2, 2],
        'it' => ['it', 2, 2],
        'ka' => ['ka', 1, 2],
        'kn' => ['kn', 2, 2],
        'ku' => ['ku', 2, 2],
        'la' => ['la', 2, 2],
        'lt' => ['lt', 2, 2],
        'ml' => ['ml', 2, 2],
        'mn' => ['mn', 2, 2],
        'mr' => ['mr', 2, 2],
        'nb' => ['no', 2, 2],
        'nl' => ['nl', 2, 2],
        'nn' => ['no', 2, 2],
        'no' => ['no', 2, 2],
        'or' => ['or', 2, 2],
        'pa' => ['pa', 2, 2],
        'pl' => ['pl', 2, 2],
        'pt' => ['pt', 2, 3],
        'ru' => ['ru', 2, 2],
        'sa' => ['sa', 2, 2],
        'sk' => ['sk', 2, 3],
        'sl' => ['sl', 2, 2],
        'sq' => ['sq', 2, 2],
        'sr' => ['sr', 2, 2],
        'sv' => ['sv', 2, 2],
        'ta' => ['ta', 2, 2],
        'te' => ['te', 2, 2],
        'tk' => ['tk', 2, 2],
        'tr' => ['tr', 2, 2],
        'uk' => ['uk', 2, 2],
    ];

    /** @var array<string, string> */
    private static array $tries = [];
    /** @var array<string, list<int>> */
    private static array $memo = [];

    public static function supports(string $lang): bool
    {
        return isset(self::LANGS[strtolower($lang)]);
    }

    /** Shortest word length (in characters, hence a lower bound on bytes) that can hyphenate. */
    public static function minLength(string $lang): int
    {
        $cfg = self::LANGS[strtolower($lang)] ?? null;
        return $cfg === null ? PHP_INT_MAX : max(1, $cfg[1]) + max(1, $cfg[2]);
    }

    /**
     * Character offsets inside $word where it may be hyphenated (0 < offset < length).
     *
     * @return list<int>
     */
    public static function points(string $word, string $lang): array
    {
        $lang = strtolower($lang);
        $key = $lang . ':' . $word;
        if (isset(self::$memo[$key])) {
            return self::$memo[$key];
        }
        if (!isset(self::LANGS[$lang]) || $word === '') {
            return self::$memo[$key] = [];
        }
        [$file, $leftMin, $rightMin] = self::LANGS[$lang];
        $data = self::$tries[$file] ??= (string) file_get_contents(__DIR__ . '/../../resources/hyphenation/' . $file . '.bin');

        // Lowercase (only where that keeps the byte length) and add dots at both ends.
        $chars = mb_str_split($word);
        $dotted = '.';
        foreach ($chars as $c) {
            $l = mb_strtolower($c);
            $dotted .= strlen($l) === strlen($c) ? $l : $c;
        }
        $dotted .= '.';
        $wordLen = strlen($word);
        $minIdx = 1 + strlen(implode('', array_slice($chars, 0, max(1, $leftMin))));
        $maxIdx = 1 + $wordLen - strlen(implode('', array_slice($chars, -max(1, $rightMin))));
        $levels = array_fill(0, max(0, $wordLen - 1), 0);

        $root = unpack('N', $data, 0)[1];
        $n = strlen($dotted);
        for ($start = 0; $start < $n; $start++) {
            if ((ord($dotted[$start]) & 0xC0) === 0x80) {
                continue; // not a character boundary
            }
            $addr = $root;
            for ($k = $start; $k < $n; $k++) {
                $addr = self::transition($data, $addr, ord($dotted[$k]));
                if ($addr === null) {
                    break;
                }
                foreach (self::levels($data, $addr) as [$offset, $level]) {
                    $split = $start + $offset;
                    if ($split >= $minIdx && $split <= $maxIdx && $levels[$split - 2] < $level) {
                        $levels[$split - 2] = $level;
                    }
                }
            }
        }

        // Odd levels mark breaks; convert byte positions in the word to character offsets.
        $points = [];
        $byte = 0;
        $charIndex = 0;
        $breakAfterByte = [];
        foreach ($levels as $i => $lvl) {
            if ($lvl % 2 === 1) {
                $breakAfterByte[$i + 1] = true;
            }
        }
        foreach ($chars as $c) {
            $byte += strlen($c);
            $charIndex++;
            if (isset($breakAfterByte[$byte]) && $charIndex < count($chars)) {
                $points[] = $charIndex;
            }
        }
        if (count(self::$memo) > 4096) {
            self::$memo = [];
        }
        return self::$memo[$key] = $points;
    }

    /** Decode a trie node: [has levels, stride, transitions, targets offset, levels offset, levels length]. */
    private static function node(string $d, int $addr): array
    {
        $b = ord($d[$addr]);
        $pos = $addr + 1;
        $count = $b & 31;
        if ($count === 31) {
            $count = ord($d[$pos++]);
        }
        $lvOff = 0;
        $lvLen = 0;
        if ($b >> 7) {
            $hi = ord($d[$pos]);
            $lo = ord($d[$pos + 1]);
            $lvOff = ($hi << 4) | ($lo >> 4);
            $lvLen = $lo & 15;
            $pos += 2;
        }
        return [($b >> 5) & 3, substr($d, $pos, $count), $pos + $count, $lvOff, $lvLen];
    }

    private static function transition(string $d, int $addr, int $byte): ?int
    {
        [$stride, $trans, $targets] = self::node($d, $addr);
        $idx = strpos($trans, chr($byte));
        if ($idx === false) {
            return null;
        }
        $at = $targets + $stride * $idx;
        $delta = match ($stride) {
            1 => ($v = ord($d[$at])) >= 0x80 ? $v - 0x100 : $v,
            2 => ($v = (ord($d[$at]) << 8) | ord($d[$at + 1])) >= 0x8000 ? $v - 0x10000 : $v,
            default => ((ord($d[$at]) << 16) | (ord($d[$at + 1]) << 8) | ord($d[$at + 2])) - (1 << 23),
        };
        return $addr + $delta;
    }

    /** @return list<array{0: int, 1: int}> [offset, level] pairs stored on the node */
    private static function levels(string $d, int $addr): array
    {
        [, , , $off, $len] = self::node($d, $addr);
        $out = [];
        $offset = 0;
        for ($i = 0; $i < $len; $i++) {
            $packed = ord($d[$off + $i]);
            $offset += intdiv($packed, 10);
            $out[] = [$offset, $packed % 10];
        }
        return $out;
    }
}
