<?php

declare(strict_types=1);

namespace YetiPdf\Write\Svg;

/**
 * Path data, transforms and bounds. Paths are lists of segments in y-down user space:
 * ['M', x, y], ['L', x, y], ['C', x1, y1, x2, y2, x, y], ['Z']. Quadratic curves and arcs
 * become cubic Béziers. Matrices are [a, b, c, d, e, f] with x' = a x + c y + e, y' = b x + d y + f.
 */
final class Geometry
{
    public const IDENTITY = [1.0, 0.0, 0.0, 1.0, 0.0, 0.0];

    private const NUMBER = '/\G[\s,]*([-+]?(?:\d+\.?\d*|\.\d+)(?:[eE][-+]?\d+)?)/';

    /** Parse SVG path data; on an error, the path up to that point is kept (as SVG specifies). */
    public static function path(string $d): array
    {
        $segs = [];
        $len = strlen($d);
        $pos = 0;
        $cx = $cy = $sx = $sy = 0.0;     // current point, subpath start
        $qx = $qy = $px = $py = null;     // last quadratic / cubic control point (for T and S)
        $cmd = '';
        $closed = false;
        $num = function () use ($d, &$pos): ?float {
            if (!preg_match(self::NUMBER, $d, $m, 0, $pos)) {
                return null;
            }
            $pos += strlen($m[0]);
            return (float) $m[1];
        };
        $flag = function () use ($d, &$pos, $len): ?bool {
            while ($pos < $len && ($d[$pos] === ' ' || $d[$pos] === ',' || $d[$pos] === "\n" || $d[$pos] === "\t" || $d[$pos] === "\r")) {
                $pos++;
            }
            if ($pos < $len && ($d[$pos] === '0' || $d[$pos] === '1')) {
                return $d[$pos++] === '1';
            }
            return null;
        };
        while (true) {
            while ($pos < $len && strpos(" \t\r\n,", $d[$pos]) !== false) {
                $pos++;
            }
            if ($pos >= $len) {
                break;
            }
            $ch = $d[$pos];
            if (ctype_alpha($ch)) {
                $cmd = $ch;
                $pos++;
            } elseif ($cmd === '' || $cmd === 'z' || $cmd === 'Z') {
                break; // numbers without a command
            }
            $rel = ctype_lower($cmd);
            $ox = $rel ? $cx : 0.0;
            $oy = $rel ? $cy : 0.0;
            $up = strtoupper($cmd);
            if ($up === 'Z') {
                $segs[] = ['Z'];
                $cx = $sx;
                $cy = $sy;
                $closed = true;
                $qx = $px = null;
                continue;
            }
            if (!$segs && $up !== 'M') {
                break; // path data must start with a moveto
            }
            if ($closed && $up !== 'M') {
                $segs[] = ['M', $cx, $cy];
            }
            $closed = false;
            switch ($up) {
                case 'M':
                case 'L':
                case 'T':
                    if (($x = $num()) === null || ($y = $num()) === null) {
                        break 2;
                    }
                    $x += $ox;
                    $y += $oy;
                    if ($up === 'M') {
                        $segs[] = ['M', $x, $y];
                        $sx = $x;
                        $sy = $y;
                        $cmd = $rel ? 'l' : 'L';
                        $qx = $px = null;
                    } elseif ($up === 'L') {
                        $segs[] = ['L', $x, $y];
                        $qx = $px = null;
                    } else {
                        $x1 = $qx === null ? $cx : 2 * $cx - $qx;
                        $y1 = $qx === null ? $cy : 2 * $cy - $qy;
                        $segs[] = self::quad($cx, $cy, $x1, $y1, $x, $y);
                        $qx = $x1;
                        $qy = $y1;
                        $px = null;
                    }
                    break;
                case 'H':
                case 'V':
                    if (($v = $num()) === null) {
                        break 2;
                    }
                    if ($up === 'H') {
                        $x = $v + $ox;
                        $y = $cy;
                    } else {
                        $x = $cx;
                        $y = $v + $oy;
                    }
                    $segs[] = ['L', $x, $y];
                    $qx = $px = null;
                    break;
                case 'C':
                case 'S':
                    if ($up === 'C') {
                        if (($x1 = $num()) === null || ($y1 = $num()) === null) {
                            break 2;
                        }
                        $x1 += $ox;
                        $y1 += $oy;
                    } else {
                        $x1 = $px === null ? $cx : 2 * $cx - $px;
                        $y1 = $px === null ? $cy : 2 * $cy - $py;
                    }
                    if (($x2 = $num()) === null || ($y2 = $num()) === null || ($x = $num()) === null || ($y = $num()) === null) {
                        break 2;
                    }
                    $x2 += $ox;
                    $y2 += $oy;
                    $x += $ox;
                    $y += $oy;
                    $segs[] = ['C', $x1, $y1, $x2, $y2, $x, $y];
                    $px = $x2;
                    $py = $y2;
                    $qx = null;
                    break;
                case 'Q':
                    if (($x1 = $num()) === null || ($y1 = $num()) === null || ($x = $num()) === null || ($y = $num()) === null) {
                        break 2;
                    }
                    $x1 += $ox;
                    $y1 += $oy;
                    $x += $ox;
                    $y += $oy;
                    $segs[] = self::quad($cx, $cy, $x1, $y1, $x, $y);
                    $qx = $x1;
                    $qy = $y1;
                    $px = null;
                    break;
                case 'A':
                    if (($rx = $num()) === null || ($ry = $num()) === null || ($rot = $num()) === null
                        || ($large = $flag()) === null || ($sweep = $flag()) === null || ($x = $num()) === null || ($y = $num()) === null) {
                        break 2;
                    }
                    $x += $ox;
                    $y += $oy;
                    array_push($segs, ...self::arc($cx, $cy, $rx, $ry, $rot, $large, $sweep, $x, $y));
                    $qx = $px = null;
                    break;
                default:
                    break 2; // unknown command
            }
            $cx = $x;
            $cy = $y;
        }
        return $segs;
    }

    private static function quad(float $x0, float $y0, float $x1, float $y1, float $x, float $y): array
    {
        return ['C', $x0 + 2 / 3 * ($x1 - $x0), $y0 + 2 / 3 * ($y1 - $y0), $x + 2 / 3 * ($x1 - $x), $y + 2 / 3 * ($y1 - $y), $x, $y];
    }

    /** An elliptical arc as cubic Béziers (SVG implementation notes, F.6.5 and F.6.6). */
    public static function arc(float $x1, float $y1, float $rx, float $ry, float $rot, bool $large, bool $sweep, float $x2, float $y2): array
    {
        if ($x1 == $x2 && $y1 == $y2) {
            return [];
        }
        $rx = abs($rx);
        $ry = abs($ry);
        // Radii too small to square (or zero) make the arc a straight line, as the spec says.
        if ($rx * $rx == 0.0 || $ry * $ry == 0.0 || !is_finite($rx * $ry)) {
            return [['L', $x2, $y2]];
        }
        $phi = deg2rad(fmod($rot, 360));
        $cos = cos($phi);
        $sin = sin($phi);
        $dx = ($x1 - $x2) / 2;
        $dy = ($y1 - $y2) / 2;
        $x1p = $cos * $dx + $sin * $dy;
        $y1p = -$sin * $dx + $cos * $dy;
        $lambda = ($x1p * $x1p) / ($rx * $rx) + ($y1p * $y1p) / ($ry * $ry);
        if ($lambda > 1) {
            $rx *= sqrt($lambda);
            $ry *= sqrt($lambda);
        }
        $num = $rx * $rx * $ry * $ry - $rx * $rx * $y1p * $y1p - $ry * $ry * $x1p * $x1p;
        $den = $rx * $rx * $y1p * $y1p + $ry * $ry * $x1p * $x1p;
        if ($den == 0.0 || !is_finite($num / $den)) {
            return [['L', $x2, $y2]];
        }
        $co = sqrt(max(0.0, $num / $den)) * ($large === $sweep ? -1 : 1);
        $cxp = $co * $rx * $y1p / $ry;
        $cyp = -$co * $ry * $x1p / $rx;
        $cx = $cos * $cxp - $sin * $cyp + ($x1 + $x2) / 2;
        $cy = $sin * $cxp + $cos * $cyp + ($y1 + $y2) / 2;
        $ang = fn (float $ux, float $uy, float $vx, float $vy): float => atan2($ux * $vy - $uy * $vx, $ux * $vx + $uy * $vy);
        $t1 = $ang(1, 0, ($x1p - $cxp) / $rx, ($y1p - $cyp) / $ry);
        $dt = $ang(($x1p - $cxp) / $rx, ($y1p - $cyp) / $ry, (-$x1p - $cxp) / $rx, (-$y1p - $cyp) / $ry);
        if (!$sweep && $dt > 0) {
            $dt -= 2 * M_PI;
        } elseif ($sweep && $dt < 0) {
            $dt += 2 * M_PI;
        }
        $n = (int) ceil(abs($dt) / (M_PI / 2) - 1e-7);
        $step = $dt / max(1, $n);
        $k = 4 / 3 * tan($step / 4);
        $out = [];
        $pt = fn (float $t): array => [$cx + $rx * cos($t) * $cos - $ry * sin($t) * $sin, $cy + $rx * cos($t) * $sin + $ry * sin($t) * $cos];
        $dv = fn (float $t): array => [-$rx * sin($t) * $cos - $ry * cos($t) * $sin, -$rx * sin($t) * $sin + $ry * cos($t) * $cos];
        for ($i = 0; $i < $n; $i++) {
            $a = $t1 + $i * $step;
            $b = $a + $step;
            [$ax, $ay] = $pt($a);
            [$bx, $by] = $i === $n - 1 ? [$x2, $y2] : $pt($b);
            [$dax, $day] = $dv($a);
            [$dbx, $dby] = $dv($b);
            $out[] = ['C', $ax + $k * $dax, $ay + $k * $day, $bx - $k * $dbx, $by - $k * $dby, $bx, $by];
        }
        return $out;
    }

    /** Parse a transform list into one matrix. */
    public static function transform(string $s): array
    {
        $m = self::IDENTITY;
        if (!preg_match_all('/(matrix|translate|scale|rotate|skewX|skewY)\s*\(([^)]*)\)/', $s, $all, PREG_SET_ORDER)) {
            return $m;
        }
        foreach ($all as [, $fn, $args]) {
            preg_match_all('/[-+]?(?:\d+\.?\d*|\.\d+)(?:[eE][-+]?\d+)?/', $args, $n);
            $v = array_map('floatval', $n[0]);
            $t = match ($fn) {
                'matrix' => count($v) === 6 ? $v : null,
                'translate' => isset($v[0]) ? [1.0, 0.0, 0.0, 1.0, $v[0], $v[1] ?? 0.0] : null,
                'scale' => isset($v[0]) ? [$v[0], 0.0, 0.0, $v[1] ?? $v[0], 0.0, 0.0] : null,
                'rotate' => isset($v[0]) ? self::rotate($v[0], $v[1] ?? 0.0, $v[2] ?? 0.0) : null,
                'skewX' => isset($v[0]) ? [1.0, 0.0, tan(deg2rad($v[0])), 1.0, 0.0, 0.0] : null,
                'skewY' => isset($v[0]) ? [1.0, tan(deg2rad($v[0])), 0.0, 1.0, 0.0, 0.0] : null,
            };
            if ($t === null) {
                return self::IDENTITY; // an invalid list is ignored as a whole
            }
            $m = self::mul($t, $m);
        }
        return $m;
    }

    private static function rotate(float $deg, float $cx, float $cy): array
    {
        $r = deg2rad($deg);
        $c = cos($r);
        $s = sin($r);
        return [$c, $s, -$s, $c, $cx - $c * $cx + $s * $cy, $cy - $s * $cx - $c * $cy];
    }

    /** The matrix that applies $m1 first, then $m2. */
    public static function mul(array $m1, array $m2): array
    {
        [$a1, $b1, $c1, $d1, $e1, $f1] = $m1;
        [$a2, $b2, $c2, $d2, $e2, $f2] = $m2;
        return [$a1 * $a2 + $b1 * $c2, $a1 * $b2 + $b1 * $d2, $c1 * $a2 + $d1 * $c2, $c1 * $b2 + $d1 * $d2, $e1 * $a2 + $f1 * $c2 + $e2, $e1 * $b2 + $f1 * $d2 + $f2];
    }

    public static function isIdentity(array $m): bool
    {
        return $m == self::IDENTITY;
    }

    /** Apply a matrix to every point of a path. */
    public static function apply(array $segs, array $m): array
    {
        [$a, $b, $c, $d, $e, $f] = $m;
        foreach ($segs as &$s) {
            for ($i = 1, $n = count($s); $i < $n; $i += 2) {
                $x = $s[$i];
                $y = $s[$i + 1];
                $s[$i] = $a * $x + $c * $y + $e;
                $s[$i + 1] = $b * $x + $d * $y + $f;
            }
        }
        return $segs;
    }

    /**
     * Exact bounds [x0, y0, x1, y1] of a path (curve extrema, not control points), or null if empty.
     * @return array{0: float, 1: float, 2: float, 3: float}|null
     */
    public static function bounds(array $segs): ?array
    {
        $x0 = $y0 = INF;
        $x1 = $y1 = -INF;
        $cx = $cy = 0.0;
        $add = function (float $x, float $y) use (&$x0, &$y0, &$x1, &$y1): void {
            $x0 = min($x0, $x);
            $y0 = min($y0, $y);
            $x1 = max($x1, $x);
            $y1 = max($y1, $y);
        };
        foreach ($segs as $s) {
            if ($s[0] === 'Z') {
                continue;
            }
            if ($s[0] === 'C') {
                foreach (self::extrema($cx, $s[1], $s[3], $s[5]) as $t) {
                    $add(self::cubic($cx, $s[1], $s[3], $s[5], $t), self::cubic($cy, $s[2], $s[4], $s[6], $t));
                }
                foreach (self::extrema($cy, $s[2], $s[4], $s[6]) as $t) {
                    $add(self::cubic($cx, $s[1], $s[3], $s[5], $t), self::cubic($cy, $s[2], $s[4], $s[6], $t));
                }
                $cx = $s[5];
                $cy = $s[6];
            } else {
                $cx = $s[1];
                $cy = $s[2];
            }
            $add($cx, $cy);
        }
        return $x0 === INF ? null : [$x0, $y0, $x1, $y1];
    }

    private static function cubic(float $p0, float $p1, float $p2, float $p3, float $t): float
    {
        $u = 1 - $t;
        return $u * $u * $u * $p0 + 3 * $u * $u * $t * $p1 + 3 * $u * $t * $t * $p2 + $t * $t * $t * $p3;
    }

    /** Parameters in (0, 1) where a cubic's derivative is zero. @return list<float> */
    private static function extrema(float $p0, float $p1, float $p2, float $p3): array
    {
        $a = -$p0 + 3 * $p1 - 3 * $p2 + $p3;
        $b = 2 * ($p0 - 2 * $p1 + $p2);
        $c = $p1 - $p0;
        if (abs($a) < 1e-12) {
            return abs($b) < 1e-12 ? [] : array_filter([-$c / $b], fn ($t) => $t > 0 && $t < 1);
        }
        $disc = $b * $b - 4 * $a * $c;
        if ($disc < 0) {
            return [];
        }
        $r = sqrt($disc);
        return array_filter([(-$b + $r) / (2 * $a), (-$b - $r) / (2 * $a)], fn ($t) => $t > 0 && $t < 1);
    }

    /** Bounds of a box after a matrix. */
    public static function transformBounds(array $box, array $m): array
    {
        [$x0, $y0, $x1, $y1] = $box;
        $pts = self::apply([['M', $x0, $y0], ['L', $x1, $y0], ['L', $x1, $y1], ['L', $x0, $y1]], $m);
        $xs = array_column($pts, 1);
        $ys = array_column($pts, 2);
        return [min($xs), min($ys), max($xs), max($ys)];
    }

    public static function rect(float $x, float $y, float $w, float $h, float $rx, float $ry): array
    {
        if ($rx <= 0 || $ry <= 0) {
            return [['M', $x, $y], ['L', $x + $w, $y], ['L', $x + $w, $y + $h], ['L', $x, $y + $h], ['Z']];
        }
        $rx = min($rx, $w / 2);
        $ry = min($ry, $h / 2);
        $k = 0.5522847498;
        $kx = $k * $rx;
        $ky = $k * $ry;
        $x1 = $x + $w;
        $y1 = $y + $h;
        return [
            ['M', $x + $rx, $y], ['L', $x1 - $rx, $y],
            ['C', $x1 - $rx + $kx, $y, $x1, $y + $ry - $ky, $x1, $y + $ry], ['L', $x1, $y1 - $ry],
            ['C', $x1, $y1 - $ry + $ky, $x1 - $rx + $kx, $y1, $x1 - $rx, $y1], ['L', $x + $rx, $y1],
            ['C', $x + $rx - $kx, $y1, $x, $y1 - $ry + $ky, $x, $y1 - $ry], ['L', $x, $y + $ry],
            ['C', $x, $y + $ry - $ky, $x + $rx - $kx, $y, $x + $rx, $y], ['Z'],
        ];
    }

    public static function ellipse(float $cx, float $cy, float $rx, float $ry): array
    {
        $k = 0.5522847498;
        $kx = $k * $rx;
        $ky = $k * $ry;
        return [
            ['M', $cx + $rx, $cy],
            ['C', $cx + $rx, $cy + $ky, $cx + $kx, $cy + $ry, $cx, $cy + $ry],
            ['C', $cx - $kx, $cy + $ry, $cx - $rx, $cy + $ky, $cx - $rx, $cy],
            ['C', $cx - $rx, $cy - $ky, $cx - $kx, $cy - $ry, $cx, $cy - $ry],
            ['C', $cx + $kx, $cy - $ry, $cx + $rx, $cy - $ky, $cx + $rx, $cy],
            ['Z'],
        ];
    }
}
