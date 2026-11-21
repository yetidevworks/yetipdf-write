<?php

declare(strict_types=1);

namespace YetiPdf\Write;

/**
 * Parsing of attribute values: lengths, sizing tracks, colors, strokes, sides.
 *
 * Lengths are returned as [abs pt, em, ratio] triples so em and % resolve where they are used:
 *   "12pt" => [12, 0, 0], "0.5em" => [0, 0.5, 0], "50%" => [0, 0, 0.5], "1in" => [72, 0, 0].
 */
final class Value
{
    public const MAX_TRACKS = 1000;
    /** Lengths beyond this (about 3.5 m) can't be meant and break PDF number limits. */
    public const MAX_LENGTH = 10000.0;

    private const UNITS = ['pt' => 1.0, 'mm' => 72 / 25.4, 'cm' => 72 / 2.54, 'in' => 72.0, 'px' => 0.75];

    private const COLORS = [
        'black' => [0, 0, 0], 'white' => [1, 1, 1], 'gray' => [0.5, 0.5, 0.5], 'grey' => [0.5, 0.5, 0.5],
        'red' => [0.8, 0.13, 0.13], 'green' => [0.13, 0.55, 0.27], 'blue' => [0.13, 0.33, 0.8],
    ];

    /** @var array<string, array{0:float,1:float,2:float}> */
    private static array $lengthCache = [];
    /** @var array<string, array{0:float,1:float,2:float}> */
    private static array $colorCache = [];

    /** @return array{0:float,1:float,2:float} */
    public static function length(string $s): array
    {
        if (isset(self::$lengthCache[$s])) {
            return self::$lengthCache[$s];
        }
        $trimmed = trim($s);
        if (!preg_match('/^(-?(?:\d+\.?\d*|\.\d+))\s*(pt|mm|cm|in|px|em|%)?$/', $trimmed, $m)) {
            throw new PdfException("Invalid length '$trimmed'");
        }
        $v = (float) $m[1];
        $u = $m[2] ?? 'pt';
        if (!is_finite($v) || abs($v) > self::MAX_LENGTH * 10) {
            throw new PdfException("Length '$trimmed' is out of range");
        }
        $res = match ($u) {
            'em' => [0.0, $v, 0.0],
            '%' => [0.0, 0.0, $v / 100],
            default => [$v * self::UNITS[$u], 0.0, 0.0],
        };
        if (strlen($s) > 32) {
            return $res;
        }
        if (count(self::$lengthCache) >= 512) {
            self::$lengthCache = [];
        }
        return self::$lengthCache[$s] = $res;
    }

    /** Resolve a length triple against a font size and a base length (for %). */
    public static function resolve(array $l, float $size, float $base = 0.0): float
    {
        return $l[0] + $l[1] * $size + ($l[2] ? $l[2] * $base : 0.0);
    }

    /** Absolute length in pt (em against $size, % against $base). */
    public static function abs(string $s, float $size, float $base = 0.0): float
    {
        return self::resolve(self::length($s), $size, $base);
    }

    /**
     * Track sizing for grid columns/rows: list of ['auto'] | ['fr', n] | ['len', triple].
     * "3" means three auto tracks.
     */
    public static function tracks(string $s): array
    {
        $s = trim($s);
        if (preg_match('/^\d+$/', $s)) {
            $n = (int) $s;
            if ($n < 1 || $n > self::MAX_TRACKS) {
                throw new PdfException("Track count '$s' must be between 1 and " . self::MAX_TRACKS);
            }
            return array_fill(0, $n, ['auto']);
        }
        $out = [];
        $parts = preg_split('/[\s,]+/', $s, -1, PREG_SPLIT_NO_EMPTY);
        if (count($parts) > self::MAX_TRACKS) {
            throw new PdfException('More than ' . self::MAX_TRACKS . ' tracks');
        }
        foreach ($parts as $t) {
            if ($t === 'auto') {
                $out[] = ['auto'];
            } elseif (preg_match('/^(\d*\.?\d+)fr$/', $t, $m)) {
                $out[] = ['fr', (float) $m[1]];
            } else {
                $out[] = ['len', self::length($t)];
            }
        }
        return $out;
    }

    /** Angle in radians: "-32deg", "0.5rad", "0.25turn" (a bare number is degrees). */
    public static function angle(string $s): float
    {
        if (!preg_match('/^\s*(-?(?:\d+\.?\d*|\.\d+))\s*(deg|rad|turn)?\s*$/', $s, $m)) {
            throw new PdfException("Invalid angle '$s'");
        }
        $v = (float) $m[1];
        return match ($m[2] ?? 'deg') {
            'rad' => $v,
            'turn' => $v * 2 * M_PI,
            default => deg2rad($v),
        };
    }

    /** @return array{0:float,1:float,2:float} rgb 0..1 */
    public static function color(string $s): array
    {
        if (isset(self::$colorCache[$s])) {
            return self::$colorCache[$s];
        }
        $low = strtolower(trim($s));
        if (isset(self::COLORS[$low])) {
            $res = self::COLORS[$low];
        } elseif (preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6})$/', $low, $m)) {
            $h = $m[1];
            if (strlen($h) === 3) {
                $h = $h[0] . $h[0] . $h[1] . $h[1] . $h[2] . $h[2];
            }
            $res = [hexdec(substr($h, 0, 2)) / 255, hexdec(substr($h, 2, 2)) / 255, hexdec(substr($h, 4, 2)) / 255];
        } elseif (preg_match('/^(?:luma|gray)\((\d*\.?\d+)%\)$/', $low, $m)) {
            // Quantised to 8 bits like any other colour (and like Typst).
            $v = round((float) $m[1] / 100 * 255) / 255;
            $res = [$v, $v, $v];
        } else {
            throw new PdfException("Invalid color '$low'");
        }
        if (strlen($s) > 32) {
            return $res;
        }
        if (count(self::$colorCache) >= 512) {
            self::$colorCache = [];
        }
        return self::$colorCache[$s] = $res;
    }

    /**
     * Stroke: "0.5pt #e3e5ea", "1pt dashed red", "none".
     *
     * @return null|array{w: float, color: array, dash: ?array}
     */
    public static function stroke(?string $s, float $size): ?array
    {
        if ($s === null || trim($s) === '' || trim($s) === 'none') {
            return null;
        }
        $w = 1.0;
        $color = [0.0, 0.0, 0.0];
        $dash = null;
        foreach (preg_split('/\s+/', trim($s)) as $part) {
            if ($part === 'dashed') {
                $dash = 'dashed';
            } elseif ($part === 'dotted') {
                $dash = 'dotted';
            } elseif (preg_match('/^-?[\d.]/', $part)) {
                $w = self::abs($part, $size);
            } else {
                $color = self::color($part);
            }
        }
        if ($dash !== null) {
            $dash = $dash === 'dashed' ? [3 * $w, 2 * $w] : [$w, 1.5 * $w];
        }
        return ['w' => $w, 'color' => $color, 'dash' => $dash];
    }

    /**
     * CSS-style sides shorthand ("6pt", "4pt 8pt", "t r b l") with per-side overrides
     * from "$prefix-top" etc. Returns [top, right, bottom, left] in pt.
     */
    public static function sides(Node $n, string $prefix, float $size, float $base, ?array $default = null): array
    {
        $out = $default ?? [0.0, 0.0, 0.0, 0.0];
        $attrs = $n->attrs;
        if ($attrs === []) {
            return $out;
        }
        if (isset($attrs[$prefix]) && $attrs[$prefix] !== '') {
            $v = array_map(fn ($p) => self::abs($p, $size, $base), preg_split('/\s+/', trim($attrs[$prefix])));
            $out = match (count($v)) {
                1 => [$v[0], $v[0], $v[0], $v[0]],
                2 => [$v[0], $v[1], $v[0], $v[1]],
                3 => [$v[0], $v[1], $v[2], $v[1]],
                default => [$v[0], $v[1], $v[2], $v[3]],
            };
        }
        if (($attrs["$prefix-x"] ?? '') !== '') {
            $out[1] = $out[3] = self::abs($attrs["$prefix-x"], $size, $base);
        }
        if (($attrs["$prefix-y"] ?? '') !== '') {
            $out[0] = $out[2] = self::abs($attrs["$prefix-y"], $size, $base);
        }
        if (($attrs["$prefix-top"] ?? '') !== '') {
            $out[0] = self::abs($attrs["$prefix-top"], $size, $base);
        }
        if (($attrs["$prefix-right"] ?? '') !== '') {
            $out[1] = self::abs($attrs["$prefix-right"], $size, $base);
        }
        if (($attrs["$prefix-bottom"] ?? '') !== '') {
            $out[2] = self::abs($attrs["$prefix-bottom"], $size, $base);
        }
        if (($attrs["$prefix-left"] ?? '') !== '') {
            $out[3] = self::abs($attrs["$prefix-left"], $size, $base);
        }
        return $out;
    }
}
