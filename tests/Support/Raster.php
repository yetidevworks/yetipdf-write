<?php

declare(strict_types=1);

namespace YetiPdf\Write\Tests\Support;

use YetiPdf\Write\Image\ImageLoader;
use YetiPdf\Write\YetiPdf;

/**
 * Pixel comparisons for the SVG tests: each fixture is drawn by YetiPDF Write and by Typst (resvg) on
 * a page of exactly the image's size, both rasterised with poppler's pdftoppm, and the pixels
 * whose colour differs by more than a threshold are counted.
 */
final class Raster
{
    public const WIDTH = 220.0; // points

    public static function available(): bool
    {
        return trim((string) shell_exec('command -v pdftoppm 2>/dev/null')) !== '';
    }

    /** Page size for a fixture: WIDTH wide, height from the SVG's aspect ratio. @return array{0: float, 1: float} */
    public static function pageSize(string $svg): array
    {
        $img = (new ImageLoader([dirname($svg)]))->load(basename($svg));
        return [self::WIDTH, round(self::WIDTH * $img->height() / $img->width(), 3)];
    }

    /** The YetiPDF Write rendering of a fixture. */
    public static function yetipdf(string $svg, string $out): void
    {
        [$w, $h] = self::pageSize($svg);
        $pdf = (new YetiPdf(fonts: [dirname(__DIR__, 2) . '/fonts'], assets: [dirname($svg)]))->render(sprintf(
            '<document><page paper="%spt %spt" margin="0pt"/><image src="%s" width="%spt"/></document>', $w, $h, basename($svg), $w));
        file_put_contents($out, $pdf);
    }

    /** The Typst source for a fixture, compiled with --root at the fixture's directory. */
    public static function typst(string $svg): string
    {
        [$w, $h] = self::pageSize($svg);
        return sprintf("#set page(width: %spt, height: %spt, margin: 0pt)\n#image(\"%s\", width: %spt)\n", $w, $h, basename($svg), $w);
    }

    /** Rasterise page 1 as RGB. @return array{w: int, h: int, data: string} */
    public static function render(string $pdf, int $dpi): array
    {
        $ppm = (string) shell_exec(sprintf('pdftoppm -r %d -f 1 -l 1 %s 2>/dev/null', $dpi, escapeshellarg($pdf)));
        if (!preg_match('/^P6\s+(\d+)\s+(\d+)\s+255\s/', $ppm, $m)) {
            throw new \RuntimeException("pdftoppm failed for $pdf");
        }
        return ['w' => (int) $m[1], 'h' => (int) $m[2], 'data' => substr($ppm, strlen($m[0]))];
    }

    /** Pixels whose largest channel difference exceeds $threshold (0..255), or -1 if the sizes differ. */
    public static function diff(array $a, array $b, int $threshold = 64): int
    {
        if ($a['w'] !== $b['w'] || $a['h'] !== $b['h']) {
            return -1;
        }
        $pa = unpack('C*', $a['data']);
        $pb = unpack('C*', $b['data']);
        $bad = 0;
        for ($i = 1, $n = count($pa); $i <= $n; $i += 3) {
            if (abs($pa[$i] - $pb[$i]) > $threshold || abs($pa[$i + 1] - $pb[$i + 1]) > $threshold || abs($pa[$i + 2] - $pb[$i + 2]) > $threshold) {
                $bad++;
            }
        }
        return $bad;
    }
}
