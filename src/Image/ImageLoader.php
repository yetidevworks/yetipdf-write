<?php

declare(strict_types=1);

namespace YetiPdf\Write\Image;

use YetiPdf\Write\PdfException;
use YetiPdf\Write\Svg\Svg;
use YetiPdf\Write\Svg\SvgParser;

/**
 * Loads JPEG, PNG and SVG images for embedding, with as little pixel work as possible:
 *
 * - JPEG is embedded as-is (DCTDecode).
 * - PNG without alpha is embedded as-is too: its zlib data and row filters are exactly what a
 *   PDF FlateDecode stream with a PNG predictor expects. Colour-key transparency becomes /Mask.
 * - PNG with an alpha channel (or palette transparency) is decoded once to split the alpha into
 *   a soft mask; the result is cached on disk.
 *
 * - SVG is parsed into vector drawing items (see SvgParser) and written as a Form XObject.
 *
 * Sources are limited: a data: URI, an image registered from PHP, or a file inside one of the
 * allowed asset directories. Templates can't read arbitrary files.
 */
final class ImageLoader
{
    /** Largest side we accept, and the most pixels we decode in PHP (PNGs with transparency). */
    private const MAX_SIDE = 30000;
    private const MAX_DECODE_PIXELS = 25_000_000;

    /** @var array<string, Image|Svg> */
    private array $loaded = [];
    /** @var array<string, string> name => bytes */
    private array $registered = [];
    /** @var list<string> */
    private array $roots;

    /** @param list<string> $roots */
    /** Bumped whenever Image changes, so decoded images cached by an older version aren't reused. */
    private const CACHE_VERSION = 2;

    public function __construct(array $roots, private readonly ?string $cacheDir = null)
    {
        $this->roots = array_values(array_filter(array_map(fn ($r) => realpath($r) ?: null, $roots)));
    }

    public function register(string $name, string $bytes): void
    {
        $this->registered[$name] = $bytes;
        unset($this->loaded['@' . $name]);
    }

    public function load(string $src): Image|Svg
    {
        if (isset($this->loaded[$src])) {
            return $this->loaded[$src];
        }
        if (preg_match('#^data:image/svg\+xml(?:;(?!base64)[\w=-]+)*,(.*)$#s', $src, $m)) {
            $bytes = rawurldecode($m[1]); // SVG data URIs are often URL-encoded rather than base64
            $key = 'b' . md5($bytes);
        } elseif (str_starts_with($src, 'data:')) {
            if (!preg_match('#^data:image/[a-z+.-]+;base64,(.*)$#s', $src, $m) || ($bytes = base64_decode($m[1], true)) === false) {
                throw new PdfException('Invalid image data URI (expected data:image/...;base64,...)');
            }
            $key = 'b' . md5($bytes);
        } elseif (str_starts_with($src, '@')) {
            $bytes = $this->registered[substr($src, 1)] ?? throw new PdfException("No image registered as '" . substr($src, 1) . "'");
            $key = 'b' . md5($bytes);
        } else {
            $path = $this->resolve($src);
            $bytes = file_get_contents($path);
            $key = 'f' . md5($path . '|' . filemtime($path) . '|' . filesize($path));
        }
        $what = str_starts_with($src, 'data:') ? 'Image data URI' : "Image '$src'";
        return $this->loaded[$src] = \YetiPdf\Write\Guard::run($what, fn () => $this->decode($bytes, $key));
    }

    private function resolve(string $src): string
    {
        if (!$this->roots) {
            throw new PdfException("Image '$src': no asset directories are allowed (pass assets: [...] to YetiPDF Write)");
        }
        $candidates = str_starts_with($src, '/') ? [$src] : array_map(fn ($r) => $r . '/' . $src, $this->roots);
        foreach ($candidates as $c) {
            $real = realpath($c);
            if ($real === false || !is_file($real)) {
                continue;
            }
            foreach ($this->roots as $root) {
                if (str_starts_with($real, $root . DIRECTORY_SEPARATOR)) {
                    return $real;
                }
            }
            throw new PdfException("Image '$src' is outside the allowed asset directories");
        }
        throw new PdfException("Image not found: $src");
    }

    private function decode(string $bytes, string $key): Image|Svg
    {
        if (str_starts_with($bytes, "\xFF\xD8")) {
            return $this->jpeg($bytes, $key);
        }
        if (str_starts_with($bytes, "\x89PNG\r\n\x1a\n")) {
            return $this->png($bytes, $key);
        }
        if (preg_match('/^(?:\xEF\xBB\xBF)?\s*<(?:\?xml|!--|!DOCTYPE|svg[\s>])/i', $bytes) && str_contains($bytes, '<svg')) {
            return SvgParser::parse($bytes, $key);
        }
        throw new PdfException('Unsupported image format (JPEG, PNG and SVG are supported)');
    }

    // ------------------------------------------------------------------ JPEG

    private function jpeg(string $d, string $key): Image
    {
        $len = strlen($d);
        $pos = 2;
        $dpi = 72.0;
        $adobe = false;
        $orientation = 1;
        $w = $h = $comps = $bits = 0;
        while ($pos + 4 <= $len) {
            if ($d[$pos] !== "\xFF") {
                $pos++;
                continue;
            }
            $marker = ord($d[$pos + 1]);
            if ($marker === 0xFF) {
                $pos++;
                continue;
            }
            if ($marker === 0xD8 || ($marker >= 0xD0 && $marker <= 0xD7) || $marker === 0x01) {
                $pos += 2;
                continue;
            }
            $seg = unpack('n', $d, $pos + 2)[1];
            if ($seg < 2) {
                throw new PdfException('Corrupt JPEG segment');
            }
            if ($marker === 0xE0 && substr($d, $pos + 4, 5) === "JFIF\0") {
                $units = ord($d[$pos + 11]);
                $xd = unpack('n', $d, $pos + 12)[1];
                if ($xd > 0 && $units === 1) {
                    $dpi = (float) $xd;
                } elseif ($xd > 0 && $units === 2) {
                    $dpi = $xd * 2.54;
                }
            } elseif ($marker === 0xE1 && substr($d, $pos + 4, 6) === "Exif\0\0") {
                $orientation = self::exifOrientation(substr($d, $pos + 10, $seg - 8));
            } elseif ($marker === 0xEE && substr($d, $pos + 4, 5) === 'Adobe') {
                $adobe = true;
            } elseif ($marker >= 0xC0 && $marker <= 0xCF && !in_array($marker, [0xC4, 0xC8, 0xCC], true)) {
                if ($pos + 10 > $len) {
                    break;
                }
                $bits = ord($d[$pos + 4]);
                $h = unpack('n', $d, $pos + 5)[1];
                $w = unpack('n', $d, $pos + 7)[1];
                $comps = ord($d[$pos + 9]);
                break;
            }
            $pos += 2 + $seg;
        }
        if (!$w || !$h) {
            throw new PdfException('Could not read JPEG dimensions');
        }
        $this->checkSize($w, $h);
        $cs = match ($comps) {
            1 => '/DeviceGray',
            3 => '/DeviceRGB',
            4 => '/DeviceCMYK',
            default => throw new PdfException("Unsupported JPEG with $comps components"),
        };
        $dict = "/Filter /DCTDecode /ColorSpace $cs /BitsPerComponent $bits";
        if ($comps === 4 && $adobe) {
            $dict .= ' /Decode [1 0 1 0 1 0 1 0]';
        }
        return new Image($key, $w, $h, $dpi, $d, $dict, null, $orientation);
    }

    /** The orientation tag (0x0112) from a TIFF-structured EXIF block, 1 when absent or unreadable. */
    private static function exifOrientation(string $tiff): int
    {
        $order = substr($tiff, 0, 2);
        if ($order !== 'II' && $order !== 'MM' || strlen($tiff) < 8) {
            return 1;
        }
        $u16 = fn (int $at) => $at + 2 <= strlen($tiff) ? unpack($order === 'II' ? 'v' : 'n', $tiff, $at)[1] : null;
        $u32 = fn (int $at) => $at + 4 <= strlen($tiff) ? unpack($order === 'II' ? 'V' : 'N', $tiff, $at)[1] : null;
        $ifd = $u32(4);
        $count = $ifd === null ? null : $u16($ifd);
        for ($i = 0; $count !== null && $i < $count; $i++) {
            $entry = $ifd + 2 + $i * 12;
            if ($u16($entry) === 0x0112) {
                $value = $u16($entry + 8);
                return $value !== null && $value >= 1 && $value <= 8 ? $value : 1;
            }
        }
        return 1;
    }

    // ------------------------------------------------------------------ PNG

    private function png(string $d, string $key): Image
    {
        $pos = 8;
        $len = strlen($d);
        $idat = '';
        $plte = '';
        $trns = null;
        $dpi = 72.0;
        $ihdr = null;
        while ($pos + 8 <= $len) {
            $n = unpack('N', $d, $pos)[1];
            $type = substr($d, $pos + 4, 4);
            if ($pos + 12 + $n > $len) {
                throw new PdfException('Truncated PNG');
            }
            if ($type === 'IHDR' && $n !== 13) {
                throw new PdfException('Invalid PNG header');
            }
            $data = substr($d, $pos + 8, $n);
            $pos += 12 + $n;
            switch ($type) {
                case 'IHDR':
                    $ihdr = unpack('Nw/Nh/Cbits/Ccolor/Ccomp/Cfilter/Cinterlace', $data);
                    break;
                case 'PLTE':
                    $plte = $data;
                    break;
                case 'tRNS':
                    $trns = $data;
                    break;
                case 'pHYs':
                    $p = unpack('Nx/Ny/Cunit', $data);
                    if ($p['unit'] === 1 && $p['x'] > 0) {
                        $dpi = $p['x'] * 0.0254;
                    }
                    break;
                case 'IDAT':
                    $idat .= $data;
                    break;
                case 'IEND':
                    break 2;
            }
        }
        if ($ihdr === null || $idat === '') {
            throw new PdfException('Invalid PNG');
        }
        ['w' => $w, 'h' => $h, 'bits' => $bits, 'color' => $color, 'interlace' => $interlace] = $ihdr;
        $this->checkSize($w, $h);
        if (!in_array($bits, [1, 2, 4, 8, 16], true)) {
            throw new PdfException("Unsupported PNG bit depth $bits");
        }
        // The compressed data must at least start like a zlib stream (it is passed through as-is).
        if (strlen($idat) < 2 || (ord($idat[0]) & 0x0F) !== 8 || ((ord($idat[0]) << 8) | ord($idat[1])) % 31 !== 0) {
            throw new PdfException('Corrupt PNG data');
        }
        if ($interlace) {
            throw new PdfException('Interlaced PNGs are not supported; save the image without interlacing');
        }
        $channels = [0 => 1, 2 => 3, 3 => 1, 4 => 2, 6 => 4][$color] ?? throw new PdfException("Unsupported PNG colour type $color");

        $hasAlpha = $color === 4 || $color === 6 || ($color === 3 && $trns !== null);
        if (!$hasAlpha) {
            // Pass the compressed data straight through with a PNG predictor.
            $cs = match ($color) {
                0 => '/DeviceGray',
                2 => '/DeviceRGB',
                3 => '[/Indexed /DeviceRGB ' . (intdiv(strlen($plte), 3) - 1) . ' <' . bin2hex($plte) . '>]',
            };
            $dict = "/Filter /FlateDecode /ColorSpace $cs /BitsPerComponent $bits"
                . " /DecodeParms << /Predictor 15 /Colors $channels /BitsPerComponent $bits /Columns $w >>";
            if ($trns !== null && ($color === 0 || $color === 2)) {
                $vals = array_values(unpack('n*', $trns));
                $dict .= ' /Mask [' . implode(' ', array_merge(...array_map(fn ($v) => [$v, $v], $vals))) . ']';
            }
            return new Image($key, $w, $h, $dpi, $idat, $dict);
        }

        return $this->cached($key, fn () => $this->pngAlpha($idat, $w, $h, $bits, $color, $channels, $plte, $trns, $dpi, $key));
    }

    /** Decode a PNG with transparency into colour + soft mask streams. */
    private function pngAlpha(string $idat, int $w, int $h, int $bits, int $color, int $channels, string $plte, ?string $trns, float $dpi, string $key): Image
    {
        if ($w * $h > self::MAX_DECODE_PIXELS) {
            throw new PdfException("PNG with transparency is too large to decode ({$w}×{$h}); use a smaller image or JPEG");
        }
        $bpp = max(1, intdiv($channels * $bits, 8));
        $rowBytes = intdiv($w * $channels * $bits + 7, 8);
        $expected = $h * ($rowBytes + 1);
        // Never inflate past what the header says the image holds.
        $raw = @gzuncompress($idat, $expected);
        if ($raw === false || strlen($raw) < $expected) {
            throw new PdfException('Corrupt or truncated PNG data');
        }
        $rows = $this->unfilter($raw, $h, $rowBytes, $bpp);

        $rgb = '';
        $alpha = '';
        if ($color === 3) {
            // Palette with transparency: expand indices.
            $alphaTable = array_values(unpack('C*', $trns ?? '') ?: []);
            $pal = $plte;
            $perByte = intdiv(8, $bits);
            $mask = (1 << $bits) - 1;
            foreach ($rows as $row) {
                $x = 0;
                $bytes = unpack('C*', $row);
                foreach ($bytes as $byte) {
                    for ($k = $perByte - 1; $k >= 0 && $x < $w; $k--, $x++) {
                        $i = $bits === 8 ? $byte : ($byte >> ($k * $bits)) & $mask;
                        $rgb .= substr($pal, 3 * $i, 3);
                        $alpha .= chr($alphaTable[$i] ?? 255);
                    }
                }
            }
            $cs = '/DeviceRGB';
        } else {
            $cn = $channels - 1;
            $step = $bits === 16 ? 2 : 1;
            $px = $channels * $step;
            foreach ($rows as $row) {
                if ($step === 1 && $cn === 3) {
                    // RGBA 8-bit: the common case, split with string functions.
                    $alpha .= implode('', array_map(fn ($p) => $p[3], str_split($row, 4)));
                    $rgb .= implode('', array_map(fn ($p) => substr($p, 0, 3), str_split($row, 4)));
                    continue;
                }
                for ($x = 0, $n = strlen($row); $x < $n; $x += $px) {
                    for ($c = 0; $c < $cn; $c++) {
                        $rgb .= $row[$x + $c * $step];
                    }
                    $alpha .= $row[$x + $cn * $step];
                }
            }
            $cs = $cn === 1 ? '/DeviceGray' : '/DeviceRGB';
        }
        $smask = new Image($key . 'a', $w, $h, $dpi, gzcompress($alpha, 6), '/Filter /FlateDecode /ColorSpace /DeviceGray /BitsPerComponent 8');
        return new Image($key, $w, $h, $dpi, gzcompress($rgb, 6), "/Filter /FlateDecode /ColorSpace $cs /BitsPerComponent 8", $smask);
    }

    private function checkSize(int $w, int $h): void
    {
        if ($w < 1 || $h < 1 || $w > self::MAX_SIDE || $h > self::MAX_SIDE) {
            throw new PdfException("Image size {$w}×{$h} is out of range");
        }
    }

    /** Undo PNG row filters. @return list<string> */
    private function unfilter(string $raw, int $h, int $rowBytes, int $bpp): array
    {
        $rows = [];
        $prev = array_fill(0, $rowBytes, 0);
        $pos = 0;
        for ($y = 0; $y < $h; $y++) {
            $filter = ord($raw[$pos]);
            $line = substr($raw, $pos + 1, $rowBytes);
            $pos += $rowBytes + 1;
            if ($filter === 0) {
                $rows[] = $line;
                $prev = array_values(unpack('C*', $line));
                continue;
            }
            if ($filter === 2) {
                $cur = array_values(unpack('C*', $line));
                for ($i = 0; $i < $rowBytes; $i++) {
                    $cur[$i] = ($cur[$i] + $prev[$i]) & 0xFF;
                }
            } else {
                $cur = array_values(unpack('C*', $line));
                for ($i = 0; $i < $rowBytes; $i++) {
                    $a = $i >= $bpp ? $cur[$i - $bpp] : 0;
                    $cur[$i] = match ($filter) {
                        1 => ($cur[$i] + $a) & 0xFF,
                        3 => ($cur[$i] + (($a + $prev[$i]) >> 1)) & 0xFF,
                        4 => ($cur[$i] + self::paeth($a, $prev[$i], $i >= $bpp ? $prev[$i - $bpp] : 0)) & 0xFF,
                        default => throw new PdfException("Bad PNG filter $filter"),
                    };
                }
            }
            $rows[] = pack('C*', ...$cur);
            $prev = $cur;
        }
        return $rows;
    }

    private static function paeth(int $a, int $b, int $c): int
    {
        $p = $a + $b - $c;
        $pa = abs($p - $a);
        $pb = abs($p - $b);
        $pc = abs($p - $c);
        return ($pa <= $pb && $pa <= $pc) ? $a : ($pb <= $pc ? $b : $c);
    }

    /** @param callable(): Image $make */
    private function cached(string $key, callable $make): Image
    {
        if ($this->cacheDir === null) {
            return $make();
        }
        $file = $this->cacheDir . '/img' . self::CACHE_VERSION . '-' . $key . '.bin';
        if (is_file($file) && ($img = @unserialize(file_get_contents($file), ['allowed_classes' => [Image::class]])) instanceof Image) {
            return $img;
        }
        $img = $make();
        if (!is_dir($this->cacheDir)) {
            @mkdir($this->cacheDir, 0775, true);
        }
        $tmp = $file . '.' . getmypid() . '.tmp';
        if (@file_put_contents($tmp, serialize($img)) !== false) {
            @rename($tmp, $file);
        }
        return $img;
    }
}
