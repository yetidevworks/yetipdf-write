<?php

declare(strict_types=1);

namespace YetiPdf\Write\Tests\Unit;

use YetiPdf\Write\Font\Face;
use YetiPdf\Write\Font\FontBook;
use YetiPdf\Write\Layout\Layouter;
use YetiPdf\Write\Text\Joining;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Shaping as HarfBuzz does it: joining forms, ligatures over marks, and hb-shape's own output. */
final class ShapingTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';
    private const SCRIPTS = ['arab' => 'ب', 'hebr' => 'א', 'nko' => 'ߊ', 'latn' => 'a'];

    private static array $faces = [];

    private static function face(string $name): Face
    {
        $path = $name === 'DejaVuSans' ? self::ROOT . '/tests/fixtures/fonts/DejaVuSans.ttf' : self::ROOT . "/fonts/$name.ttf";
        return self::$faces[$name] ??= Face::load($path, null);
    }

    public function testJoiningForms(): void
    {
        $forms = fn (string $s) => Joining::forms(array_map('mb_ord', mb_str_split($s)));
        // beh alef lam ain alef lam meem: alef only joins to the letter before it.
        $this->assertSame(['init', 'fina', 'init', 'medi', 'fina', 'init', 'fina'], $forms('بالعالم'));
        // A vowel is passed over; ZWJ joins, ZWNJ doesn't; tatweel joins both ways.
        $this->assertSame(['init', '', 'fina'], $forms("ل\u{64E}ا"));
        $this->assertSame(['init', 'fina'], $forms("ب\u{200D}"));
        $this->assertSame(['isol', '', 'isol'], $forms("ب\u{200C}ب"));
        $this->assertSame(['init', 'medi', 'fina'], $forms('ـبـ'));
    }

    public function testLamAlefTakesItsMarkOnTheLam(): void
    {
        $face = self::face('DejaVuSans');
        // lam, fatha, alef: the ligature forms over the fatha, which stays on the lam's half.
        $gl = $face->glyphs("ل\u{64E}ا", ['liga'], true, false, 'ar');
        $this->assertCount(2, $gl);
        $this->assertSame([2, 3], [$gl[0][3], $gl[1][3]], 'a ligature, then a mark');
        $this->assertSame([$gl[0][4], 1], [$gl[1][4], $gl[1][5]], 'the mark belongs to its first component');
        $this->assertSame("ل\u{64E}ا", $gl[0][1] . $gl[1][1], 'copies out as typed');
    }

    /** Fixed strings shaped by hb-shape (tests/fixtures/shaping, recorded by tools/harfbuzz.php). */
    public static function harfbuzz(): iterable
    {
        $json = json_decode(file_get_contents(self::ROOT . '/tests/fixtures/shaping/harfbuzz.json'), true);
        foreach ($json['cases'] as $i => $case) {
            yield "$i {$case['font']} {$case['script']}" => [$case];
        }
    }

    #[DataProvider('harfbuzz')]
    public function testMatchesHarfBuzz(array $case): void
    {
        $face = self::face($case['font']);
        $rtl = $case['script'] !== 'latn';
        $lang = ['arab' => 'ar', 'hebr' => 'he', 'nko' => 'nqo', 'latn' => 'en'][$case['script']];
        $lay = new Layouter(new FontBook(null), []);
        $run = $lay->glyphRun($face, $case['text'], $face->upem, [0, 0, 0], 0.0, ['liga'], $rtl ? 1 : 0, $lang, self::SCRIPTS[$case['script']]);
        $mine = array_map(fn ($g) => [$g[0], (int) round($g[1]), (int) round($g[3] ?? 0), (int) round($g[4] ?? 0)], $rtl ? array_reverse($run->glyphs) : $run->glyphs);
        // HarfBuzz keeps default ignorables as empty glyphs; YetiPDF Write drops them (their text goes
        // with a neighbour).
        $hidden = [$face->glyph(32), $face->glyph(0x200C), $face->glyph(0x200D), $face->glyph(0x34F)];
        $hb = array_values(array_filter($case['glyphs'], fn ($g) => !($g[1] === 0 && in_array($g[0], $hidden, true))));
        $this->assertSame($hb, $mine, json_encode($case['text']));
    }
}
