<?php

declare(strict_types=1);

namespace YetiPdf\Write\Tests\Unit;

use YetiPdf\Write\YetiPdf;
use YetiPdf\Write\PdfException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Malformed or hostile input ends in a PdfException with a clear message, never a PHP
 * warning, fatal error or a broken PDF. Cases from the Codex review of 2026-11-20.
 */
final class RobustnessTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';

    private static function png(int $w, int $h, int $bits, int $color, string $raw, ?string $idat = null): string
    {
        $chunk = fn (string $t, string $d) => pack('N', strlen($d)) . $t . $d . pack('N', crc32($t . $d));
        return "\x89PNG\r\n\x1a\n" . $chunk('IHDR', pack('NNCCCCC', $w, $h, $bits, $color, 0, 0, 0))
            . $chunk('IDAT', $idat ?? gzcompress($raw)) . $chunk('IEND', '');
    }

    public static function failures(): iterable
    {
        yield 'empty markup' => ['', '/document is empty/'];
        yield 'entity declarations' => ['<!DOCTYPE document [<!ENTITY a "x">]><document>&a;</document>', '/DOCTYPE and ENTITY/'];
        yield 'huge column count' => ['<document><table columns="10000000"><cell>x</cell></table></document>', '/between 1 and 1000/'];
        yield 'deep nesting' => ['<document>' . str_repeat('<block>', 300) . 'x' . str_repeat('</block>', 300) . '</document>', '/nested more than 200 deep/'];
        yield 'nesting past our own limit' => ['<document>' . str_repeat('<block>', 220) . 'x' . str_repeat('</block>', 220) . '</document>', '/nested more than 200 deep/'];
        yield 'zero text size' => ['<document size="0pt">x</document>', '/must be above 0pt/'];
        yield 'giant length' => ['<document><page paper="' . str_repeat('9', 300) . 'pt 300pt"/>x</document>', '/out of range/'];
        yield 'zero paper' => ['<document><page paper="0pt 0pt"/>x</document>', '/out of range/'];
        yield 'zero image width' => ['<document><image src="rgb.png" width="0pt"/></document>', '/above zero/'];
        yield 'overlapping spans' => ['<document><table columns="2"><cell>a</cell><cell rowspan="2">b</cell><cell colspan="2">c</cell></table></document>', '/span a previously placed cell/'];
    }

    #[DataProvider('failures')]
    public function testRefusesClearly(string $xml, string $message): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessageMatches($message);
        (new YetiPdf(fonts: [self::ROOT . '/fonts'], assets: [self::ROOT . '/tests/fixtures/images']))->render($xml);
    }

    public static function badImages(): iterable
    {
        yield 'truncated png header' => ["\x89PNG\r\n\x1a\n" . pack('N', 1) . 'IHDR' . "\0" . pack('N', 0)];
        yield 'truncated png rows' => [self::png(1, 2, 8, 6, "\0\xff\0\0\xff")];
        yield 'zero-width png' => [self::png(0, 1, 8, 2, "\0")];
        yield 'png with bad zlib' => [self::png(1, 1, 8, 2, '', str_repeat('x', 12))];
        yield 'png that inflates past its size' => [self::png(1, 1, 8, 6, '', gzcompress(str_repeat("\0", 5_000_000)))];
        yield 'truncated jpeg' => ["\xff\xd8\xff\xc0\0\x02"];
        yield 'svg with no size' => ['<svg xmlns="http://www.w3.org/2000/svg" width="0" height="0"/>'];
        yield 'not an image' => ['hello'];
    }

    #[DataProvider('badImages')]
    public function testBadImagesThrow(string $bytes): void
    {
        $errors = [];
        set_error_handler(function (int $no, string $msg) use (&$errors) {
            $errors[] = $msg;
            return true;
        });
        try {
            (new YetiPdf(fonts: [self::ROOT . '/fonts']))->registerImage('x', $bytes)->render('<document><image src="@x"/></document>');
            $this->fail('expected an exception');
        } catch (PdfException $e) {
            $this->assertNotSame('', $e->getMessage());
        } finally {
            restore_error_handler();
        }
        $this->assertSame([], $errors, 'no PHP warnings on the way');
    }

    public function testTinySvgArcBecomesALine(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20"><path d="M0 0 A1e-200 1e-200 0 0 1 10 10" stroke="black"/></svg>';
        $pdf = (new YetiPdf(fonts: [self::ROOT . '/fonts']))->registerImage('x', $svg)->render('<document><image src="@x"/></document>');
        $this->assertStringStartsWith('%PDF', $pdf);
    }

    public function testTruncatedFont(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'font') . '.ttf';
        file_put_contents($tmp, "\0\1\0\0\0\5");
        try {
            $this->expectException(PdfException::class);
            new YetiPdf(fonts: [$tmp]);
        } finally {
            @unlink($tmp);
        }
    }

    public function testKeptRowsMoveTheTableToAFreshPage(): void
    {
        $p = new YetiPdf(fonts: [self::ROOT . '/fonts'], compress: false);
        $pdf = $p->render('<document><page paper="200pt 120pt" margin="10pt"/><block height="45pt">INTRO</block>'
            . '<table columns="1fr" inset="0pt"><row keep="with-next"><cell>KEEP</cell></row><row><cell><block height="55pt">NEXT</block></cell></row></table></document>');
        $file = tempnam(sys_get_temp_dir(), 'pdf');
        file_put_contents($file, $pdf);
        if (!trim((string) shell_exec('command -v pdftotext'))) {
            $this->markTestSkipped('pdftotext is not installed');
        }
        $page2 = (string) shell_exec('pdftotext -f 2 -l 2 ' . escapeshellarg($file) . ' -');
        $this->assertStringContainsString('KEEP', $page2);
        $this->assertStringContainsString('NEXT', $page2);
    }
}
