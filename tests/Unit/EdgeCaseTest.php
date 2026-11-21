<?php

declare(strict_types=1);

namespace YetiPdf\Write\Tests\Unit;

use YetiPdf\Write\Font\FontBook;
use YetiPdf\Write\Layout\GlyphRun;
use YetiPdf\Write\Layout\Layouter;
use YetiPdf\Write\YetiPdf;
use YetiPdf\Write\PdfException;
use YetiPdf\Write\Style;
use PHPUnit\Framework\TestCase;

/** Inputs where shortcuts in the fast paths once gave different results from the full ones. */
final class EdgeCaseTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';

    private function render(string $body): string
    {
        return (new YetiPdf(fonts: [self::ROOT . '/fonts']))->render("<document font=\"Figtree\">$body</document>");
    }

    private static function strip(string $pdf): string
    {
        return preg_replace(['/CreationDate \(D:\d+Z\)/', '/\/ID \[<[0-9a-f]+> <[0-9a-f]+>\]/'], '', $pdf);
    }

    public function testEmptyAttributesAreIgnored(): void
    {
        $plain = self::strip($this->render('<block>Hello</block>'));
        foreach (['<block class="">Hello</block>', '<block inset="">Hello</block>', '<block inset-x="">Hello</block>', '<block inset-top="">Hello</block>'] as $body) {
            $this->assertSame($plain, self::strip($this->render($body)), $body);
        }
        $this->assertSame(self::strip($this->render('Hello')), self::strip($this->render('<page margin=""/>Hello')));
    }

    public function testClassListIsSplitOnWhitespace(): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage("Unknown style 'a'");
        $this->render('<style name="a b" color="blue"/><block class="a b">X</block>');
    }

    public function testEmptyRowClassLeavesCellClassAlone(): void
    {
        $a = $this->render('<style name="x" color="red"/><table columns="1"><row class="x"><cell class="">A</cell></row></table>');
        $b = $this->render('<style name="x" color="red"/><table columns="1"><row class="x"><cell>A</cell></row></table>');
        $this->assertSame(self::strip($b), self::strip($a));
    }

    public function testNumbersDoNotDependOnPrecisionSetting(): void
    {
        $default = self::strip($this->render('Hello'));
        $old = ini_get('precision');
        try {
            foreach (['3', '17'] as $p) {
                ini_set('precision', $p);
                $this->assertSame($default, self::strip($this->render('Hello')), "precision $p");
            }
        } finally {
            ini_set('precision', (string) $old);
        }
    }

    public function testOptimizedBreaksKeepTheirArithmetic(): void
    {
        // Costs computed with x*x*x instead of x**3 (or sums in another order) broke this differently.
        $book = new FontBook(null);
        $book->addFile(self::ROOT . '/fonts/Figtree-Regular.ttf');
        $lay = new Layouter($book, []);
        $st = ['size' => 5.5, 'hyphenate' => false, 'align' => 'justify', 'linebreaks' => 'optimized'] + Style::base('Figtree');
        $par = $lay->paragraph([['text', 'xx x xx x x x xx', $st]], $st, 6.583, true);
        $lines = array_map(function ($line) {
            $t = '';
            foreach ($line->items as [, , $it]) {
                if ($it instanceof GlyphRun) {
                    $t .= ($t === '' ? '' : ' ') . implode('', array_column($it->glyphs, 2));
                }
            }
            return $t;
        }, $par['lines']);
        $this->assertSame(['xx', 'x', 'xx', 'x', 'x x', 'xx'], $lines);
    }

    public function testEmptyParagraph(): void
    {
        $book = new FontBook(null);
        $book->addFile(self::ROOT . '/fonts/Figtree-Regular.ttf');
        $par = (new Layouter($book, []))->paragraph([], Style::base('Figtree'), 100, true);
        $this->assertCount(1, $par['lines']);
    }

    public function testGlyphMemoKeysDoNotCollide(): void
    {
        $face = (new FontBook(null))->addFile(self::ROOT . '/fonts/Figtree-Regular.ttf');
        $face->glyphs("\0é", [], context: 'A');
        $this->assertCount(1, $face->glyphs('é', [], context: "A\0"));
    }
}
