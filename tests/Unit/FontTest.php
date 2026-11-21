<?php

declare(strict_types=1);

namespace YetiPdf\Write\Tests\Unit;

use YetiPdf\Write\Font\Face;
use YetiPdf\Write\Font\FontBook;
use YetiPdf\Write\Font\Subsetter;
use YetiPdf\Write\Font\TrueTypeParser;
use PHPUnit\Framework\TestCase;

final class FontTest extends TestCase
{
    private const FONTS = __DIR__ . '/../../fonts';

    public function testParsesFigtree(): void
    {
        $m = (new TrueTypeParser(file_get_contents(self::FONTS . '/Figtree-Regular.ttf')))->parse();
        $this->assertSame('Figtree', $m['family']);
        $this->assertSame(1000, $m['unitsPerEm']);
        $this->assertSame(400, $m['weight']);
        $this->assertSame(700, $m['capHeight']);
        $this->assertArrayHasKey(ord('A'), $m['cmap']);
        $this->assertNotEmpty($m['kern']);
        $this->assertContains('tnum', array_column($m['gsub']['features'], 0));
    }

    public function testKerningAndFeatures(): void
    {
        $f = Face::load(self::FONTS . '/Figtree-Regular.ttf', null);
        $this->assertSame(-43, $f->kern($f->glyph(ord('A')), $f->glyph(ord('V'))));
        $plain = $f->glyphs('1', []);
        $tab = $f->glyphs('1', ['tnum']);
        $this->assertNotSame($plain[0][0], $tab[0][0], 'tnum substitutes the figure');
    }

    public function testFontBookMatchesWeights(): void
    {
        $book = new FontBook();
        $book->addDirectory(self::FONTS);
        $this->assertSame(600, $book->select('figtree', 600, false)->weight);
        $this->assertSame(700, $book->select('Figtree', 800, false)->weight, 'heavier than available picks the heaviest');
        $this->assertSame(400, $book->select('Figtree', 300, false)->weight);
        $this->assertTrue($book->select('Figtree', 400, true)->italic);
    }

    public function testAsciiRunsKeepExactInputsAndIndependentGlyphs(): void
    {
        $face = Face::load(self::FONTS . '/Figtree-Regular.ttf', null);
        $precision = ini_get('precision');
        $serializePrecision = ini_get('serialize_precision');
        try {
            ini_set('precision', '3');
            ini_set('serialize_precision', '3');
            foreach ([
                ['AV office 123', ['liga'], null, null, 12.001, 0.0],
                ['AV office 123', ['liga'], null, null, 12.002, 0.0],
                ['AV office 123', ['liga'], null, null, 12.002, 0.00001],
                ['AV office 123', ['tnum'], 'en', 'a', 12.002, 0.00001],
                ['123', [], 'ar', 'ب', 12.0, 0.0],
                ['123', [], 'en', 'a', 12.0, 0.0],
            ] as $args) {
                $fresh = new Face($face->m, $face->path);
                $expected = $fresh->asciiGlyphRun(...$args);
                $actual = $face->asciiGlyphRun(...$args);
                $this->assertSame($expected, $actual);
                $actual[0][0][1] = -999.0;
                $this->assertSame($expected, $face->asciiGlyphRun(...$args));
            }
        } finally {
            ini_set('precision', $precision);
            ini_set('serialize_precision', $serializePrecision);
        }
    }

    public function testAsciiRunRetentionIsBounded(): void
    {
        $face = Face::load(self::FONTS . '/Figtree-Regular.ttf', null);
        $cache = new \ReflectionProperty(Face::class, 'asciiRuns');
        for ($i = 0; $i < 600; $i++) {
            $face->asciiGlyphRun("word$i", [], null, null, 12.0, 0.0);
            $this->assertLessThanOrEqual(256, count($cache->getValue($face)));
        }
        $before = $cache->getValue($face);
        $face->asciiGlyphRun(str_repeat('a', 65), [], null, null, 12.0, 0.0);
        $face->asciiGlyphRun('short', [], str_repeat('e', 33), null, 12.0, 0.0);
        $this->assertSame($before, $cache->getValue($face));
    }

    public function testSubsetIsAValidFontWithSameGlyphs(): void
    {
        $data = file_get_contents(self::FONTS . '/Figtree-Regular.ttf');
        $full = (new TrueTypeParser($data))->parse();
        $used = [$full['cmap'][ord('A')] => true, $full['cmap'][ord('g')] => true];
        $sub = (new Subsetter($data))->subset($used, $full['numGlyphs']);
        $this->assertLessThan(strlen($data) / 2, strlen($sub));
        // Read the subset's table directory directly (it has no cmap, so the full parser won't take it).
        $tables = [];
        for ($i = 0, $n = unpack('n', $sub, 4)[1]; $i < $n; $i++) {
            $tables[substr($sub, 12 + 16 * $i, 4)] = unpack('N2', $sub, 12 + 16 * $i + 8);
        }
        $this->assertSame(['OS/2', 'glyf', 'head', 'hhea', 'hmtx', 'loca', 'maxp'], array_values(array_intersect(array_keys($tables), ['OS/2', 'glyf', 'head', 'hhea', 'hmtx', 'loca', 'maxp'])));
        $this->assertSame($full['numGlyphs'], unpack('n', $sub, $tables['maxp'][1] + 4)[1], 'glyph ids stay the same');
        $sum = 0;
        foreach (unpack('N*', $sub . str_repeat("\0", (4 - strlen($sub) % 4) % 4)) as $v) {
            $sum = ($sum + $v) & 0xFFFFFFFF;
        }
        $this->assertSame(0xB1B0AFBA, $sum, 'whole-font checksum');
    }
}
