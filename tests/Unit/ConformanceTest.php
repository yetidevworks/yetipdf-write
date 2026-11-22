<?php

declare(strict_types=1);

namespace YetiPdf\Write\Tests\Unit;

use YetiPdf\Write\YetiPdfWrite;
use YetiPdf\Write\Tests\Support\Raster;
use YetiPdf\Write\Tests\Support\Words;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Renders each tests/Conformance/<name>.xml and checks every word lands where Typst put it
 * for the matching <name>.typ (positions recorded by tools/conformance.php), within 0.1pt; or,
 * for a fixture recorded as raster, that it looks like Typst's <name>.pdf pixel for pixel.
 */
final class ConformanceTest extends TestCase
{
    private const TOLERANCE = 0.1;
    private const RASTER_TOLERANCE = 0.0002; // share of the pixels

    public static function fixtures(): iterable
    {
        foreach (glob(__DIR__ . '/../Conformance/*.json') as $json) {
            yield basename($json, '.json') => [basename($json, '.json')];
        }
    }

    #[DataProvider('fixtures')]
    public function testMatchesTypst(string $name): void
    {
        if (!Words::available()) {
            $this->markTestSkipped('pdftotext (poppler) is not installed');
        }
        $dir = __DIR__ . '/../Conformance';
        $root = dirname(__DIR__, 2);
        $json = json_decode(file_get_contents("$dir/$name.json"), true);
        $expected = $json['pages'];

        $yetipdf = new YetiPdfWrite(fonts: [$root . '/fonts', $root . '/tests/fixtures/fonts'], assets: [$root . '/tests/fixtures/images'], cache: sys_get_temp_dir() . '/yetipdf-test-cache');
        $pdf = sys_get_temp_dir() . "/yetipdf-test-$name.pdf";
        file_put_contents($pdf, $yetipdf->render(file_get_contents("$dir/$name.xml")));
        $actual = Words::read($pdf);

        $this->assertCount(count($expected), $actual, "$name: page count");
        if ($json['raster'] ?? false) {
            // Compared as pixels: almost none may differ.
            $ref = Raster::render("$dir/$name.pdf", 100);
            $mine = Raster::render($pdf, 100);
            $bad = Raster::diff($ref, $mine);
            $this->assertNotSame(-1, $bad, "$name: raster sizes differ");
            $this->assertLessThanOrEqual(self::RASTER_TOLERANCE * $ref['w'] * $ref['h'], $bad, "$name: $bad pixels differ from Typst");
            return;
        }
        foreach ($expected as $p => $words) {
            $a = array_column($words, 0);
            $b = array_column($actual[$p], 0);
            sort($a);
            sort($b);
            $this->assertSame($a, $b, "$name: words on page " . ($p + 1));
            // Each word is matched to one in the same place: the order pdftotext lists words in is
            // its own guess at reading order (it differs between the engines for right-to-left
            // text and table cells), not part of the layout.
            $left = $actual[$p];
            foreach ($words as [$word, $x, $y]) {
                $best = null;
                foreach ($left as $k => [$aw, $ax, $ay]) {
                    if ($aw === $word && ($best === null || abs($ax - $x) + abs($ay - $y) < abs($left[$best][1] - $x) + abs($left[$best][2] - $y))) {
                        $best = $k;
                    }
                }
                [, $ax, $ay] = $left[$best];
                unset($left[$best]);
                $this->assertEqualsWithDelta($x, $ax, self::TOLERANCE, "$name p" . ($p + 1) . " '$word' x");
                $this->assertEqualsWithDelta($y, $ay, self::TOLERANCE, "$name p" . ($p + 1) . " '$word' y");
            }
        }
    }
}
