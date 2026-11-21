<?php

declare(strict_types=1);

namespace YetiPdf\Write\Svg;

/**
 * CSS colour values as used by SVG: #rgb, #rgba, #rrggbb, #rrggbbaa, rgb()/rgba() (numbers or
 * percentages), hsl()/hsla(), the CSS named colours and `transparent`.
 */
final class Color
{
    private const NAMED = [
        'aliceblue' => 'f0f8ff', 'antiquewhite' => 'faebd7', 'aqua' => '00ffff', 'aquamarine' => '7fffd4', 'azure' => 'f0ffff',
        'beige' => 'f5f5dc', 'bisque' => 'ffe4c4', 'black' => '000000', 'blanchedalmond' => 'ffebcd', 'blue' => '0000ff',
        'blueviolet' => '8a2be2', 'brown' => 'a52a2a', 'burlywood' => 'deb887', 'cadetblue' => '5f9ea0', 'chartreuse' => '7fff00',
        'chocolate' => 'd2691e', 'coral' => 'ff7f50', 'cornflowerblue' => '6495ed', 'cornsilk' => 'fff8dc', 'crimson' => 'dc143c',
        'cyan' => '00ffff', 'darkblue' => '00008b', 'darkcyan' => '008b8b', 'darkgoldenrod' => 'b8860b', 'darkgray' => 'a9a9a9',
        'darkgreen' => '006400', 'darkgrey' => 'a9a9a9', 'darkkhaki' => 'bdb76b', 'darkmagenta' => '8b008b', 'darkolivegreen' => '556b2f',
        'darkorange' => 'ff8c00', 'darkorchid' => '9932cc', 'darkred' => '8b0000', 'darksalmon' => 'e9967a', 'darkseagreen' => '8fbc8f',
        'darkslateblue' => '483d8b', 'darkslategray' => '2f4f4f', 'darkslategrey' => '2f4f4f', 'darkturquoise' => '00ced1', 'darkviolet' => '9400d3',
        'deeppink' => 'ff1493', 'deepskyblue' => '00bfff', 'dimgray' => '696969', 'dimgrey' => '696969', 'dodgerblue' => '1e90ff',
        'firebrick' => 'b22222', 'floralwhite' => 'fffaf0', 'forestgreen' => '228b22', 'fuchsia' => 'ff00ff', 'gainsboro' => 'dcdcdc',
        'ghostwhite' => 'f8f8ff', 'gold' => 'ffd700', 'goldenrod' => 'daa520', 'gray' => '808080', 'grey' => '808080',
        'green' => '008000', 'greenyellow' => 'adff2f', 'honeydew' => 'f0fff0', 'hotpink' => 'ff69b4', 'indianred' => 'cd5c5c',
        'indigo' => '4b0082', 'ivory' => 'fffff0', 'khaki' => 'f0e68c', 'lavender' => 'e6e6fa', 'lavenderblush' => 'fff0f5',
        'lawngreen' => '7cfc00', 'lemonchiffon' => 'fffacd', 'lightblue' => 'add8e6', 'lightcoral' => 'f08080', 'lightcyan' => 'e0ffff',
        'lightgoldenrodyellow' => 'fafad2', 'lightgray' => 'd3d3d3', 'lightgreen' => '90ee90', 'lightgrey' => 'd3d3d3', 'lightpink' => 'ffb6c1',
        'lightsalmon' => 'ffa07a', 'lightseagreen' => '20b2aa', 'lightskyblue' => '87cefa', 'lightslategray' => '778899', 'lightslategrey' => '778899',
        'lightsteelblue' => 'b0c4de', 'lightyellow' => 'ffffe0', 'lime' => '00ff00', 'limegreen' => '32cd32', 'linen' => 'faf0e6',
        'magenta' => 'ff00ff', 'maroon' => '800000', 'mediumaquamarine' => '66cdaa', 'mediumblue' => '0000cd', 'mediumorchid' => 'ba55d3',
        'mediumpurple' => '9370db', 'mediumseagreen' => '3cb371', 'mediumslateblue' => '7b68ee', 'mediumspringgreen' => '00fa9a', 'mediumturquoise' => '48d1cc',
        'mediumvioletred' => 'c71585', 'midnightblue' => '191970', 'mintcream' => 'f5fffa', 'mistyrose' => 'ffe4e1', 'moccasin' => 'ffe4b5',
        'navajowhite' => 'ffdead', 'navy' => '000080', 'oldlace' => 'fdf5e6', 'olive' => '808000', 'olivedrab' => '6b8e23',
        'orange' => 'ffa500', 'orangered' => 'ff4500', 'orchid' => 'da70d6', 'palegoldenrod' => 'eee8aa', 'palegreen' => '98fb98',
        'paleturquoise' => 'afeeee', 'palevioletred' => 'db7093', 'papayawhip' => 'ffefd5', 'peachpuff' => 'ffdab9', 'peru' => 'cd853f',
        'pink' => 'ffc0cb', 'plum' => 'dda0dd', 'powderblue' => 'b0e0e6', 'purple' => '800080', 'rebeccapurple' => '663399',
        'red' => 'ff0000', 'rosybrown' => 'bc8f8f', 'royalblue' => '4169e1', 'saddlebrown' => '8b4513', 'salmon' => 'fa8072',
        'sandybrown' => 'f4a460', 'seagreen' => '2e8b57', 'seashell' => 'fff5ee', 'sienna' => 'a0522d', 'silver' => 'c0c0c0',
        'skyblue' => '87ceeb', 'slateblue' => '6a5acd', 'slategray' => '708090', 'slategrey' => '708090', 'snow' => 'fffafa',
        'springgreen' => '00ff7f', 'steelblue' => '4682b4', 'tan' => 'd2b48c', 'teal' => '008080', 'thistle' => 'd8bfd8',
        'tomato' => 'ff6347', 'turquoise' => '40e0d0', 'violet' => 'ee82ee', 'wheat' => 'f5deb3', 'white' => 'ffffff',
        'whitesmoke' => 'f5f5f5', 'yellow' => 'ffff00', 'yellowgreen' => '9acd32',
    ];

    /**
     * Parse a colour into [r, g, b, alpha] with components 0..1, or null when it isn't one.
     * @return array{0: float, 1: float, 2: float, 3: float}|null
     */
    public static function parse(string $s): ?array
    {
        $s = strtolower(trim($s));
        if ($s === '') {
            return null;
        }
        if ($s[0] === '#') {
            $h = substr($s, 1);
            if (!ctype_xdigit($h)) {
                return null;
            }
            $n = strlen($h);
            if ($n === 3 || $n === 4) {
                $h = preg_replace('/./', '$0$0', $h);
                $n *= 2;
            }
            if ($n !== 6 && $n !== 8) {
                return null;
            }
            return [hexdec(substr($h, 0, 2)) / 255.0, hexdec(substr($h, 2, 2)) / 255.0, hexdec(substr($h, 4, 2)) / 255.0, $n === 8 ? hexdec(substr($h, 6, 2)) / 255.0 : 1.0];
        }
        if (isset(self::NAMED[$s])) {
            return self::parse('#' . self::NAMED[$s]);
        }
        if ($s === 'transparent') {
            return [0.0, 0.0, 0.0, 0.0];
        }
        if (preg_match('/^(rgba?|hsla?)\(\s*([^)]*)\)$/', $s, $m)) {
            $p = preg_split('/\s*[,\/]\s*|\s+/', trim($m[2]));
            if (count($p) < 3) {
                return null;
            }
            $alpha = isset($p[3]) ? self::unit($p[3], 1.0) : 1.0;
            if ($m[1][0] === 'r') {
                return [self::unit($p[0], 255), self::unit($p[1], 255), self::unit($p[2], 255), $alpha];
            }
            [$r, $g, $b] = self::hsl(fmod(fmod((float) $p[0], 360) + 360, 360) / 360, self::unit($p[1], 100), self::unit($p[2], 100));
            return [$r, $g, $b, $alpha];
        }
        return null;
    }

    /** A number or percentage scaled to 0..1. */
    private static function unit(string $v, float $max): float
    {
        $f = str_ends_with($v, '%') ? (float) $v / 100 : (float) $v / $max;
        return max(0.0, min(1.0, $f));
    }

    /** @return array{0: float, 1: float, 2: float} */
    private static function hsl(float $h, float $s, float $l): array
    {
        if ($s == 0) {
            return [$l, $l, $l];
        }
        $q = $l < 0.5 ? $l * (1 + $s) : $l + $s - $l * $s;
        $p = 2 * $l - $q;
        $f = function (float $t) use ($p, $q): float {
            $t = $t < 0 ? $t + 1 : ($t > 1 ? $t - 1 : $t);
            return $t < 1 / 6 ? $p + ($q - $p) * 6 * $t : ($t < 0.5 ? $q : ($t < 2 / 3 ? $p + ($q - $p) * (2 / 3 - $t) * 6 : $p));
        };
        return [$f($h + 1 / 3), $f($h), $f($h - 1 / 3)];
    }
}
