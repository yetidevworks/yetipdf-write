<?php

declare(strict_types=1);

namespace YetiPdf\Write\Tests\Unit;

use YetiPdf\Write\YetiPdf;
use YetiPdf\Write\PdfException;
use PHPUnit\Framework\TestCase;

final class DocumentTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';

    private function yetipdf(): YetiPdf
    {
        return new YetiPdf(fonts: [self::ROOT . '/fonts'], assets: [self::ROOT . '/tests/fixtures/images']);
    }

    private function render(string $body, string $attrs = ''): string
    {
        return $this->yetipdf()->render("<document font=\"Figtree\" $attrs>$body</document>");
    }

    public function testXrefOffsetsPointAtObjects(): void
    {
        $pdf = $this->render('<block>Hello <b>world</b></block><image src="rgba.png" width="50pt"/>', 'title="T"');
        $this->assertStringStartsWith('%PDF-1.7', $pdf);
        $this->assertMatchesRegularExpression('/startxref\n(\d+)\n%%EOF\n$/', $pdf);
        preg_match('/startxref\n(\d+)/', $pdf, $m);
        $xref = (int) $m[1];
        $this->assertSame('xref', substr($pdf, $xref, 4));
        preg_match('/xref\n0 (\d+)\n/', $pdf, $c, 0, $xref);
        $entries = array_slice(explode("\n", substr($pdf, $xref)), 2, (int) $c[1]);
        foreach ($entries as $i => $e) {
            if (str_ends_with(trim($e), 'n')) {
                $off = (int) substr($e, 0, 10);
                $this->assertSame("$i 0 obj", substr($pdf, $off, strlen("$i 0 obj")), "object $i offset");
            }
        }
        // Every indirect reference resolves to an object.
        preg_match_all('/(\d+) 0 R/', $pdf, $refs);
        foreach (array_unique($refs[1]) as $id) {
            $this->assertStringContainsString("\n$id 0 obj\n", $pdf);
        }
    }

    public function testMarkupErrorsNameTheLine(): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessageMatches('/line 2/');
        $this->yetipdf()->render("<document font=\"Figtree\">\n<block><unknown/></block></document>");
    }

    public function testUnknownStyle(): void
    {
        $this->expectExceptionMessageMatches("/Unknown style 'nope'/");
        $this->render('<block class="nope">x</block>');
    }

    public function testImagesCannotEscapeTheAssetDirectories(): void
    {
        $this->expectExceptionMessageMatches('/outside the allowed asset directories|not found/');
        $this->render('<image src="../../../composer.json"/>');
    }

    public function testImagesNeedAnAssetDirectory(): void
    {
        $this->expectExceptionMessageMatches('/no asset directories/');
        (new YetiPdf(fonts: [self::ROOT . '/fonts']))->render('<document><image src="rgba.png"/></document>');
    }

    public function testDataUriAndRegisteredImages(): void
    {
        $png = file_get_contents(self::ROOT . '/tests/fixtures/images/rgb.png');
        $p = (new YetiPdf(fonts: [self::ROOT . '/fonts']))->registerImage('logo', $png);
        $pdf = $p->render('<document><image src="@logo" width="40pt"/><image src="data:image/png;base64,' . base64_encode($png) . '" width="40pt"/></document>');
        $this->assertSame(1, preg_match_all('/\/Subtype \/Image/', $pdf), 'the same image bytes are embedded once');
    }

    public function testImageSizing(): void
    {
        // rgba.png is 240×120 px at 72 dpi: 240×120 pt naturally.
        $p = new YetiPdf(fonts: [self::ROOT . '/fonts'], assets: [self::ROOT . '/tests/fixtures/images'], compress: false);
        $pdf = $p->render('<document font="Figtree"><image src="rgba.png" height="60pt" max-width="80pt"/><image src="rgba.png" width="100pt"/></document>');
        preg_match_all('/q ([\d.]+) 0 0 ([\d.]+) [\d.]+ [\d.]+ cm \/Im1 Do Q/', $pdf, $m, PREG_SET_ORDER);
        $this->assertSame(['80', '40'], [$m[0][1], $m[0][2]], 'height 60pt would be 120pt wide; max-width brings it to 80×40');
        $this->assertSame(['100', '50'], [$m[1][1], $m[1][2]]);
    }

    public function testLinksBecomeAnnotations(): void
    {
        $p = new YetiPdf(fonts: [self::ROOT . '/fonts'], compress: false);
        $pdf = $p->render('<document>See <link href="https://getgrav.org">the Grav <b>website</b></link> or <a href="mailto:a@b.c">mail</a>.</document>');
        preg_match_all('/\/Subtype \/Link \/Rect \[([\d. ]+)\].*?\/URI <([0-9a-f]+)>/', $pdf, $m, PREG_SET_ORDER);
        $this->assertCount(2, $m, 'one area per link, even across style changes');
        $this->assertSame('https://getgrav.org', hex2bin($m[0][2]));
        $this->assertSame('mailto:a@b.c', hex2bin($m[1][2]));
        $this->assertMatchesRegularExpression('/\/Annots \[\d+ 0 R \d+ 0 R\]/', $pdf);
    }

    public function testLinksRejectOtherSchemes(): void
    {
        $this->expectExceptionMessageMatches('/needs an http, https, mailto or tel href/');
        $this->render('<link href="javascript:alert(1)">x</link>');
    }

    public function testPagesBreakAndCount(): void
    {
        $rows = str_repeat('<block>Line of text</block>', 200);
        $pdf = $this->render('<page paper="a6"><footer><page-number/>/<page-count/></footer></page>' . $rows);
        preg_match('/\/Count (\d+)/', $pdf, $m);
        $this->assertGreaterThan(5, (int) $m[1]);
    }

    /** @return list<string> text per page */
    private function pages(string $pdf): array
    {
        if (!trim((string) shell_exec('command -v pdftotext'))) {
            $this->markTestSkipped('pdftotext is not installed');
        }
        $file = tempnam(sys_get_temp_dir(), 'pdf');
        file_put_contents($file, $pdf);
        $text = (string) shell_exec('pdftotext -layout ' . escapeshellarg($file) . ' -');
        unlink($file);
        return array_values(array_filter(array_map(fn ($p) => preg_replace('/\s+/', ' ', trim($p)), explode("\f", $text)), fn ($p) => $p !== ''));
    }

    public function testKeptRowTallerThanAPageStillBreaks(): void
    {
        $lines = implode('<br/>', array_map(fn ($i) => sprintf('KEEP%02d', $i), range(1, 24)));
        $pdf = $this->yetipdf()->render('<document font="Figtree" size="10pt" leading="4pt"><page paper="200pt 120pt" margin="10pt"/>'
            . "<table columns=\"1fr\" inset=\"2pt\"><row keep=\"with-next\"><cell>$lines</cell></row><row><cell>NEXT</cell></row></table></document>");
        $all = implode(' ', $this->pages($pdf));
        for ($i = 1; $i <= 24; $i++) {
            $this->assertStringContainsString(sprintf('KEEP%02d', $i), $all);
        }
        $this->assertStringContainsString('NEXT', $all);
    }

    public function testRuleAfterTheLastRowClosesEveryPage(): void
    {
        $rows = implode('', array_map(fn ($i) => "<row><cell>ROW$i</cell></row>", range(1, 15)));
        $pdf = (new YetiPdf(fonts: [self::ROOT . '/fonts'], compress: false))->render('<document font="Figtree" size="10pt" leading="4pt"><page paper="200pt 120pt" margin="10pt"/>'
            . "<table columns=\"1fr\" inset=\"2pt\" stroke=\"none\">$rows<hline stroke=\"2pt #0000ff\"/></table></document>");
        $this->assertSame(2, preg_match_all('/0 0 1 RG/', $pdf), 'one blue rule per page');
    }

    /** A JPEG turned by its EXIF orientation displays upright, in either byte order. */
    public function testJpegExifOrientation(): void
    {
        $jpeg = file_get_contents(self::ROOT . '/tests/fixtures/images/rgb.jpg');
        [$w, $h] = getimagesizefromstring($jpeg);
        foreach (['II' => ['v', 'V'], 'MM' => ['n', 'N']] as $order => [$s, $l]) {
            $tiff = $order . pack($s, 42) . pack($l, 8) . pack($s, 1) . pack($s, 0x112) . pack($s, 3) . pack($l, 1) . pack($s, 6) . "\0\0" . pack($l, 0);
            $exif = "Exif\0\0" . $tiff;
            $turned = substr($jpeg, 0, 2) . "\xFF\xE1" . pack('n', strlen($exif) + 2) . $exif . substr($jpeg, 2);
            $pdf = (new YetiPdf(fonts: [self::ROOT . '/fonts'], compress: false))->registerImage('t', $turned)->render('<document><image src="@t"/></document>');
            $this->assertStringContainsString(sprintf('%s 0 0 %s ', $h, $w), $pdf, "$order: natural size swaps");
            $this->assertStringContainsString(' cm 0 -1 1 0 0 1 cm /', $pdf, "$order: drawn turned");
        }
    }

    public function testRulesStopAtRowSpans(): void
    {
        $pdf = (new YetiPdf(fonts: [self::ROOT . '/fonts'], compress: false))->render('<document font="Figtree" size="9pt"><page paper="200pt 80pt" margin="10pt"/>'
            . '<table columns="1fr 1fr 1fr" inset="2pt" stroke="none"><cell>A</cell><cell rowspan="2">SPAN</cell><cell>C</cell><hline stroke="2pt red"/><cell>D</cell><cell>F</cell></table></document>');
        preg_match_all('/(\S+) (\S+) m (\S+) \S+ l S/', $pdf, $m, PREG_SET_ORDER);
        $this->assertSame([['10', '70'], ['130', '190']], array_map(fn ($l) => [$l[1], $l[3]], $m));
    }

    public function testRuleResumesAtTheSpanningCellsEdge(): void
    {
        $pdf = (new YetiPdf(fonts: [self::ROOT . '/fonts'], compress: false))->render('<document font="Figtree" size="10pt"><page paper="240pt 100pt" margin="10pt"/>'
            . '<table columns="40pt 40pt 40pt" column-gutter="10pt" inset="2pt" stroke="none"><cell rowspan="2">SPAN</cell><cell>B</cell><cell>C</cell><hline stroke="2pt red"/><cell>E</cell><cell>F</cell></table></document>');
        preg_match_all('/(\S+) (\S+) m (\S+) \S+ l S/', $pdf, $m, PREG_SET_ORDER);
        $this->assertSame([['50', '150']], array_map(fn ($l) => [$l[1], $l[3]], $m));
    }

    /** An unbreakable spanning cell next to a tall one starts where it fits whole, and nothing is lost. */
    public function testUnbreakableSpanBesideATallSpan(): void
    {
        $long = implode('<br/>', array_map(fn ($i) => sprintf('LONG%02d', $i), range(1, 24)));
        $pre = implode('<br/>', array_map(fn ($i) => sprintf('PRE%02d', $i), range(1, 6)));
        $short = implode('<br/>', array_map(fn ($i) => sprintf('SHORT%02d', $i), range(1, 5)));
        $pages = $this->pages($this->yetipdf()->render('<document font="Figtree" size="10pt" leading="4pt" par-spacing="0pt"><page paper="240pt 120pt" margin="10pt"/>'
            . "<table columns=\"1fr 1fr\" inset=\"2pt\" stroke=\"0.5pt\"><header><cell>HEAD</cell><cell>HEADB</cell></header><cell rowspan=\"3\">$long</cell><cell>$pre</cell><cell rowspan=\"2\" breakable=\"false\">$short</cell></table></document>"));
        $this->assertStringNotContainsString('SHORT', $pages[0]);
        $this->assertStringNotContainsString('LONG07', $pages[0], 'the tall cell stops where its rows stop');
        for ($i = 1; $i <= 5; $i++) {
            $this->assertStringContainsString(sprintf('SHORT%02d', $i), $pages[1]);
        }
        $all = implode(' ', $pages);
        for ($i = 1; $i <= 24; $i++) {
            $this->assertSame(1, substr_count($all, sprintf('LONG%02d', $i)));
        }
    }

    /** An unbreakable spanning cell moves to a page where it fits whole even when an ordinary cell beside it would fit. */
    public function testUnbreakableSpanBesideAnOrdinaryCell(): void
    {
        $lines = fn (string $p, int $n) => implode('<br/>', array_map(fn ($i) => sprintf('%s%02d', $p, $i), range(1, $n)));
        $pages = $this->pages($this->yetipdf()->render('<document font="Figtree" size="10pt" leading="4pt" par-spacing="0pt"><page paper="240pt 120pt" margin="10pt"/>'
            . '<table columns="1fr 1fr 1fr" inset="2pt" stroke="0.5pt"><header><cell>HEAD</cell><cell>HEADB</cell><cell>HEADC</cell></header>'
            . '<cell rowspan="3">' . $lines('LONG', 24) . '</cell><cell>' . $lines('PRE', 6) . '</cell><cell>A</cell>'
            . '<cell rowspan="2" breakable="false">' . $lines('SHORT', 5) . '</cell><cell>SIDE01</cell><cell>END</cell></table></document>'));
        $this->assertStringNotContainsString('SHORT', $pages[0]);
        for ($i = 1; $i <= 5; $i++) {
            $this->assertStringContainsString(sprintf('SHORT%02d', $i), $pages[1]);
        }
        $all = implode(' ', $pages);
        for ($i = 1; $i <= 24; $i++) {
            $this->assertSame(1, substr_count($all, sprintf('LONG%02d', $i)));
        }
    }

    /** A spanning cell laid out again for a page its rows left early keeps every piece (a nested table here). */
    public function testRelaidSpanKeepsItsLastPiece(): void
    {
        $xml = file_get_contents(self::ROOT . '/tests/fixtures/nested-span-tail.xml');
        $all = implode(' ', $this->pages($this->yetipdf()->render($xml)));
        for ($i = 1; $i <= 27; $i++) {
            $this->assertSame(1, substr_count($all, sprintf('SPAN%02d', $i)), "SPAN$i");
        }
    }
    private function rtl(string $body): string
    {
        return (new YetiPdf(fonts: [self::ROOT . '/fonts', self::ROOT . '/tests/fixtures/fonts'], compress: false))
            ->render('<document font="DejaVu Sans" size="10pt" lang="he"><page paper="240pt 100pt" margin="10pt"/>' . $body . '</document>');
    }

    /** As in Typst: in a right-to-left table the columns run from the right, and so do the sides of a cell. */
    public function testRightToLeftTableMirrorsCellSides(): void
    {
        $pdf = $this->rtl('<table columns="60pt 60pt" stroke="none"><cell stroke-left="2pt red">א</cell><cell>ב</cell></table>');
        preg_match_all('/(\S+) (\S+) m (\S+) (\S+) l S/', $pdf, $m, PREG_SET_ORDER);
        // The table sits at the start (right) of the 220pt column: 110..230; its first cell is
        // the right one, and that cell's left stroke ends up on its outer edge.
        $this->assertSame([['230', '230']], array_map(fn ($l) => [$l[1], $l[3]], $m));
    }

    public function testRightToLeftTextIsMarkedForExtraction(): void
    {
        $pdf = $this->rtl('<block>שלום עולם</block><block>שָׁלוֹם</block>');
        $this->assertStringContainsString('/ReversedChars BMC', $pdf, 'right-to-left runs say their characters are reversed');
        // A letter and its points copy out together, as typed (qamats, then shin dot), though
        // they're drawn in HarfBuzz's order (shin dot first).
        $this->assertStringContainsString('/ActualText<feff05e905b805c1>', $pdf);
    }

    public function testDirIsLtrRtlOrAuto(): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessageMatches("/Invalid dir 'up'/");
        $this->render('<block dir="up">x</block>');
    }

    /** A bracket on its own in right-to-left text (beside numbers at another level) is still mirrored. */
    public function testLoneBracketsInRightToLeftTextAreMirrored(): void
    {
        $face = \YetiPdf\Write\Font\Face::load(self::ROOT . '/tests/fixtures/fonts/DejaVuSans.ttf', null);
        $lay = new \YetiPdf\Write\Layout\Layouter(new \YetiPdf\Write\Font\FontBook(null), []);
        $this->assertSame($face->glyph(0x29), $lay->glyphRun($face, '(', 12, [0, 0, 0], 0.0, [], 1)->glyphs[0][0]);
        $this->assertSame($face->glyph(0x28), $lay->glyphRun($face, '(', 12, [0, 0, 0], 0.0, [], 0)->glyphs[0][0]);
        $this->assertSame('(', $lay->glyphRun($face, '(', 12, [0, 0, 0], 0.0, [], 1)->glyphs[0][2], 'copies out as typed');
    }

    /** Text is written in reading order: a link's words don't join the words beside it on the line. */
    public function testRightToLeftLinkCopiesOutInReadingOrder(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'yetipdf');
        file_put_contents($file, $this->rtl('<block>אבג <link href="https://example.com/">דהו English זחט</link> מנע</block>'));
        $raw = (string) shell_exec('pdftotext -raw ' . escapeshellarg($file) . ' - 2>/dev/null');
        unlink($file);
        $words = preg_split('/\s+/u', preg_replace('/[\x{202A}-\x{202E}]/u', '', $raw), -1, PREG_SPLIT_NO_EMPTY);
        $this->assertSame(['אבג', 'דהו', 'English', 'זחט', 'מנע'], $words);
    }
}
