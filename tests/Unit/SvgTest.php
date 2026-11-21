<?php

declare(strict_types=1);

namespace YetiPdf\Write\Tests\Unit;

use YetiPdf\Write\Layout\Frame;
use YetiPdf\Write\YetiPdf;
use YetiPdf\Write\PdfException;
use YetiPdf\Write\Svg\Color;
use YetiPdf\Write\Svg\Geometry;
use YetiPdf\Write\Svg\SvgParser;
use YetiPdf\Write\Tests\Support\Raster;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SvgTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';
    private const FIXTURES = self::ROOT . '/tests/fixtures/svg';
    /** Share of pixels allowed to differ from Typst (by more than 25% in a channel) at 100 dpi. */
    private const TOLERANCE = 0.005;

    private static function svg(string $body, string $attrs = 'width="100" height="100"'): string
    {
        return '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" ' . $attrs . '>' . $body . '</svg>';
    }

    /** Every path item with its accumulated matrix. @return list<array{0: array, 1: array}> */
    private static function paths(Frame $f, array $m = Geometry::IDENTITY): array
    {
        $out = [];
        foreach ($f->items as [$x, $y, $item]) {
            $at = Geometry::mul([1.0, 0.0, 0.0, 1.0, $x, $y], $m);
            $out = match ($item[0]) {
                'path' => [...$out, [$item, $at]],
                'group' => [...$out, ...self::paths($item[1], Geometry::mul($item[2], $at))],
                'clip' => [...$out, ...self::paths($item[3], $at)],
                'opacity' => [...$out, ...self::paths($item[1], $at)],
                default => $out,
            };
        }
        return $out;
    }

    private static function fills(string $svg): array
    {
        return array_map(fn ($p) => $p[0][2] === null ? null : array_map(fn ($v) => round($v, 3), $p[0][2]['c']), self::paths(SvgParser::parse($svg, 't')->frame));
    }

    private function assertPoint(array $expected, array $seg, int $i = 1): void
    {
        $this->assertEqualsWithDelta($expected[0], $seg[$i], 1e-6);
        $this->assertEqualsWithDelta($expected[1], $seg[$i + 1], 1e-6);
    }

    // ------------------------------------------------------------------ path data

    public function testCompactNumbersAndImplicitCommands(): void
    {
        $segs = Geometry::path('M1.5.5l2-1.5.25E1,1 10 10h-5v2zm1 1');
        $this->assertSame(['M', 1.5, 0.5], $segs[0]);
        $this->assertPoint([3.5, -1.0], $segs[1]);
        $this->assertPoint([6.0, 0.0], $segs[2]);   // .25E1 = 2.5, implicit lineto
        $this->assertPoint([16.0, 10.0], $segs[3]);
        $this->assertPoint([11.0, 10.0], $segs[4]);
        $this->assertPoint([11.0, 12.0], $segs[5]);
        $this->assertSame(['Z'], $segs[6]);
        $this->assertPoint([2.5, 1.5], $segs[7]);   // relative to the closed subpath's start
    }

    public function testSmoothAndQuadraticCurvesBecomeCubics(): void
    {
        $segs = Geometry::path('M0 0 Q 10 10 20 0 T 40 0 C 40 10 50 10 50 0 S 60 -10 60 0');
        $this->assertSame('C', $segs[1][0]);
        $this->assertPoint([20 / 3, 20 / 3], $segs[1]);           // 2/3 of the way to the control point
        $this->assertPoint([20 + 20 / 3, -20 / 3], $segs[2]);     // T reflects the last control point
        $this->assertPoint([50.0, -10.0], $segs[4]);              // S reflects (50, 10) about (50, 0)
    }

    public function testArcsWithCompactFlags(): void
    {
        // A half circle of radius 10 from (0,0) to (20,0), sweep flag 1: bulges up (y < 0) in y-down space.
        $segs = Geometry::path('M0 0a10 10 0 0120 0');
        $this->assertCount(3, $segs);
        $this->assertPoint([20.0, 0.0], $segs[2], 5);
        $this->assertPoint([10.0, -10.0], $segs[1], 5);           // the quarter point lies on the circle
        $b = Geometry::bounds($segs);
        $this->assertEqualsWithDelta(-10.0, $b[1], 1e-3);
        // Flags packed against the next number: "00.5.5" is large=0 sweep=0 x=.5 y=.5.
        $segs = Geometry::path('M0 0a5 5 0 00.5.5');
        $this->assertPoint([0.5, 0.5], end($segs), 5);
        // Radii too small are scaled up so the arc reaches its end point.
        $segs = Geometry::path('M0 0 A1 1 0 0 1 30 0');
        $this->assertPoint([30.0, 0.0], end($segs), 5);
        $this->assertEqualsWithDelta(-15.0, Geometry::bounds($segs)[1], 1e-3);
    }

    public function testBadPathDataKeepsWhatCameBefore(): void
    {
        $this->assertSame([['M', 10.0, 10.0], ['L', 20.0, 20.0]], Geometry::path('M10 10 L20 20 L30'));
        $this->assertSame([], Geometry::path('L10 10'));
        $this->assertSame([], Geometry::path(''));
    }

    // ------------------------------------------------------------------ transforms, colours

    public function testTransformLists(): void
    {
        $m = Geometry::transform('rotate(90 10 10)');
        $p = Geometry::apply([['M', 20.0, 10.0]], $m)[0];
        $this->assertPoint([10.0, 20.0], $p);
        // "translate scale" applies the scale first.
        $p = Geometry::apply([['M', 1.0, 1.0]], Geometry::transform('translate(10, 20) scale(2 3)'))[0];
        $this->assertPoint([12.0, 23.0], $p);
        $p = Geometry::apply([['M', 0.0, 10.0]], Geometry::transform('skewX(45)'))[0];
        $this->assertPoint([10.0, 10.0], $p);
        $this->assertSame(Geometry::IDENTITY, Geometry::transform('scale() translate(1 1)'));
    }

    public function testColours(): void
    {
        $this->assertSame([1.0, 0.0, 0.0, 1.0], Color::parse('red'));
        $this->assertEqualsWithDelta([0xaa / 255, 0xbb / 255, 0xcc / 255, 1.0], Color::parse('#ABC'), 1e-9);
        $this->assertEqualsWithDelta([0.0, 0.0, 1.0, 0x80 / 255], Color::parse('#0000ff80'), 1e-9);
        $this->assertEqualsWithDelta([1.0, 0.5, 0.0, 0.25], Color::parse('rgba(255, 50%, 0, .25)'), 1e-9);
        $this->assertEqualsWithDelta([0.4, 0.2, 0.6, 1.0], Color::parse('RebeccaPurple'), 1e-9);
        $this->assertEqualsWithDelta([0.0, 1.0, 0.0, 1.0], Color::parse('hsl(120, 100%, 50%)'), 1e-9);
        $this->assertNull(Color::parse('#12'));
        $this->assertNull(Color::parse('notacolour'));
    }

    // ------------------------------------------------------------------ cascade

    public function testStyleCascade(): void
    {
        $svg = self::svg('<style>.a{fill:#00f} #x{fill:#0f0} rect{fill:#f00} g > .c, .d {fill:#ff0}</style>'
            . '<rect width="1" height="1" class="a" fill="#000"/>'       // class beats the attribute
            . '<rect width="1" height="1" class="a" id="x"/>'            // id beats class
            . '<rect width="1" height="1" class="a" style="fill:#fff"/>' // style="" beats both
            . '<rect width="1" height="1"/>'                             // element selector
            . '<g fill="#0ff"><circle r="1" class="c"/><circle r="1"/></g>' // child combinator; inheritance
            . '<circle r="1" class="c"/>');                              // no match outside g
        $this->assertSame([[0.0, 0.0, 1.0], [0.0, 1.0, 0.0], [1.0, 1.0, 1.0], [1.0, 0.0, 0.0], [1.0, 1.0, 0.0], [0.0, 1.0, 1.0], [0.0, 0.0, 0.0]], self::fills($svg));
    }

    public function testInheritanceCurrentColorAndNone(): void
    {
        $svg = self::svg('<g fill="currentColor" color="#f00" stroke="blue"><rect width="1" height="1"/>'
            . '<rect width="1" height="1" fill="inherit" color="#0f0"/><rect width="1" height="1" fill="none"/></g>');
        $paths = self::paths(SvgParser::parse($svg, 't')->frame);
        $this->assertSame([1.0, 0.0, 0.0], $paths[0][0][2]['c']);
        $this->assertSame([0.0, 1.0, 0.0], $paths[1][0][2]['c']); // currentColor follows the element's own color
        $this->assertNull($paths[2][0][2]);
        $this->assertSame([0.0, 0.0, 1.0], $paths[2][0][3]['c']);
    }

    public function testNaturalSizeAndViewBox(): void
    {
        $s = SvgParser::parse(self::svg('<rect width="10" height="10"/>', 'width="200" height="100"'), 't');
        $this->assertSame([150.0, 75.0], [$s->width(), $s->height()]); // 96 dpi px to pt
        $s = SvgParser::parse(self::svg('<rect width="10" height="10"/>', 'viewBox="0 0 40 20" width="80"'), 't');
        $this->assertSame([60.0, 30.0], [$s->width(), $s->height()]);
        $s = SvgParser::parse(self::svg('<rect width="10" height="10"/>', 'viewBox="0 0 40 20" width="100%"'), 't');
        $this->assertSame([30.0, 15.0], [$s->width(), $s->height()]);
        $s = SvgParser::parse(self::svg('<rect width="1" height="1"/>', 'width="1in" height="72pt"'), 't');
        $this->assertSame([72.0, 72.0], [$s->width(), $s->height()]);
    }

    // ------------------------------------------------------------------ safety

    public function testExternalEntitiesAreRefused(): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessageMatches('/Invalid SVG/');
        SvgParser::parse('<?xml version="1.0"?><!DOCTYPE svg [<!ENTITY x SYSTEM "file:///etc/passwd">]><svg xmlns="http://www.w3.org/2000/svg"><rect width="1" height="1" fill="&x;"/></svg>', 't');
    }

    public function testEntityExpansionBombsAreRefused(): void
    {
        $e = '<!ENTITY a "aaaaaaaaaa">';
        foreach (range('b', 'h') as $i => $n) {
            $prev = chr(ord('a') + $i);
            $e .= "<!ENTITY $n \"" . str_repeat("&$prev;", 10) . '">';
        }
        $this->expectException(PdfException::class);
        SvgParser::parse("<?xml version=\"1.0\"?><!DOCTYPE svg [$e]><svg xmlns=\"http://www.w3.org/2000/svg\"><rect width=\"1\" height=\"1\" class=\"&h;\"/></svg>", 't');
    }

    public function testInternalEntitiesFromIllustratorStillWork(): void
    {
        $svg = '<?xml version="1.0"?><!DOCTYPE svg [<!ENTITY ns_svg "http://www.w3.org/2000/svg"><!ENTITY c "#00ff00">]><svg xmlns="&ns_svg;" width="10" height="10"><rect width="1" height="1" fill="&c;"/></svg>';
        $this->assertSame([[0.0, 1.0, 0.0]], self::fills($svg));
    }

    public function testExternalReferencesAreNotFollowed(): void
    {
        $dir = self::ROOT . '/tests/fixtures/images';
        $svg = self::svg('<image href="rgb.png" width="10" height="10"/><image xlink:href="file://' . realpath($dir) . '/rgb.png" width="10" height="10"/>'
            . '<use href="other.svg#a"/><use xlink:href="http://example.com/a.svg#b"/><rect width="1" height="1" fill="url(other.svg#g) #f00"/>'
            . '<style>@import url("http://example.com/x.css");</style><text x="1" y="1">text is skipped</text><foreignObject><div/></foreignObject><script>x</script>');
        $this->assertSame([[1.0, 0.0, 0.0]], self::fills($svg));
        $pdf = (new YetiPdf(fonts: [self::ROOT . '/fonts'], assets: [$dir]))->registerImage('s', $svg)->render('<document><image src="@s"/></document>');
        $this->assertSame(0, preg_match('#/Subtype /Image#', $pdf));
    }

    public function testUseExpansionIsBounded(): void
    {
        // Ten levels of ten uses each would be 10^10 copies.
        $defs = '<rect id="l0" width="1" height="1"/>';
        for ($i = 1; $i <= 10; $i++) {
            $defs .= "<g id=\"l$i\">" . str_repeat('<use href="#l' . ($i - 1) . '"/>', 10) . '</g>';
        }
        $t = microtime(true);
        SvgParser::parse(self::svg("<defs>$defs</defs><use href=\"#l10\"/><use href=\"#self\" id=\"self\"/>"), 't');
        $this->assertLessThan(2.0, microtime(true) - $t);
    }

    public function testMalformedInputDoesNotCrash(): void
    {
        $svg = self::svg('<path d="M 1e999 0 L NaN 5 Z"/><rect width="-5" height="1"/><circle r="0"/><polyline points="1"/>'
            . '<g transform="scale(0)"><rect width="1" height="1"/></g><rect width="1" height="1" stroke="red" stroke-dasharray="-1 2"/>'
            . '<linearGradient id="g"/><rect width="1" height="1" fill="url(#g)"/><unknown><rect width="1" height="1"/></unknown>'
            . '<svg viewBox="0 0 0 0"><rect width="1" height="1"/></svg><clipPath id="c"/><rect width="1" height="1" clip-path="url(#c)"/>', 'viewBox="0 0 0 0"');
        $pdf = (new YetiPdf(fonts: [self::ROOT . '/fonts'], compress: false))->registerImage('s', $svg)->render('<document><image src="@s" width="50pt"/></document>');
        $this->assertStringStartsWith('%PDF-1.7', $pdf);
        $this->assertDoesNotMatchRegularExpression('/\b(inf|nan)\b/i', $pdf);
    }

    public function testNotAnSvg(): void
    {
        $this->expectExceptionMessageMatches('/Invalid SVG/');
        (new YetiPdf(fonts: [self::ROOT . '/fonts']))->registerImage('s', '<svg><rect></svg>')->render('<document><image src="@s"/></document>');
    }

    // ------------------------------------------------------------------ writer

    public function testSameSvgIsEmbeddedOnceAsAForm(): void
    {
        $svg = file_get_contents(self::FIXTURES . '/logo-figma.svg');
        $p = (new YetiPdf(fonts: [self::ROOT . '/fonts'], assets: [self::FIXTURES], compress: false))->registerImage('logo', $svg);
        $pdf = $p->render('<document><image src="@logo" width="40pt"/><image src="@logo" height="10pt"/>'
            . '<image src="data:image/svg+xml;base64,' . base64_encode($svg) . '" width="60pt"/>'
            . '<image src="data:image/svg+xml,' . rawurlencode($svg) . '" width="60pt"/><image src="logo-inkscape.svg" width="50pt"/></document>');
        $this->assertSame(2, preg_match_all('#/Subtype /Form /BBox \[0 0#', $pdf), 'one form per distinct SVG');
        $this->assertSame(4, preg_match_all('#/Fm1 Do#', $pdf));
    }

    public function testImageSizingMatchesRasterImages(): void
    {
        $svg = self::svg('<rect width="200" height="100" fill="#f00"/>', 'width="200" height="100"');
        $p = (new YetiPdf(fonts: [self::ROOT . '/fonts'], compress: false))->registerImage('s', $svg);
        $pdf = $p->render('<document><page paper="300pt 300pt" margin="0pt"/><image src="@s"/><image src="@s" height="30pt"/>'
            . '<image src="@s" width="100pt" height="100pt" fit="cover"/><image src="@s" max-width="50pt"/></document>');
        preg_match_all('#([\d.]+) 0 0 ([\d.]+) [\d.-]+ [\d.-]+ cm /Fm1 Do#', $pdf, $m);
        $this->assertSame(['150', '60', '200', '50'], $m[1]);
        $this->assertSame(['75', '30', '100', '25'], $m[2]);
    }

    // ------------------------------------------------------------------ against Typst

    public static function fixtures(): iterable
    {
        foreach (glob(self::FIXTURES . '/*.svg') as $svg) {
            yield basename($svg, '.svg') => [basename($svg, '.svg')];
        }
    }

    /** Each fixture rasterised next to Typst's rendering of it (tests/fixtures/svg/reference, from tools/svg-reference.php). */
    #[DataProvider('fixtures')]
    public function testMatchesTypst(string $name): void
    {
        if (!Raster::available()) {
            $this->markTestSkipped('pdftoppm (poppler) is not installed');
        }
        $pdf = sys_get_temp_dir() . "/yetipdf-svgtest-$name.pdf";
        Raster::yetipdf(self::FIXTURES . "/$name.svg", $pdf);
        $ref = Raster::render(self::FIXTURES . "/reference/$name.pdf", 100);
        $mine = Raster::render($pdf, 100);
        $bad = Raster::diff($ref, $mine);
        $this->assertNotSame(-1, $bad, "$name: raster sizes differ");
        $this->assertLessThanOrEqual(self::TOLERANCE * $ref['w'] * $ref['h'], $bad, "$name: $bad pixels differ from Typst");
    }
}
