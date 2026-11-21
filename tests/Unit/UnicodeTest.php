<?php

declare(strict_types=1);

namespace YetiPdf\Write\Tests\Unit;

use YetiPdf\Write\Text\Unicode;
use PHPUnit\Framework\TestCase;

/** Normalization for a font, as HarfBuzz does it before looking up glyphs. */
final class UnicodeTest extends TestCase
{
    private static function normalize(string $text, array $has): array
    {
        return Unicode::normalize(mb_str_split($text), fn (int $cp) => in_array($cp, $has, true));
    }

    public function testComposesWhenTheFontHasTheComposedCharacter(): void
    {
        $this->assertSame([[0xE1, "a\u{301}"]], self::normalize("a\u{301}", [0x61, 0x301, 0xE1]));
        $this->assertSame([[0x61, 'a'], [0x301, "\u{301}"]], self::normalize("a\u{301}", [0x61, 0x301]));
    }

    public function testDecomposesWhatTheFontLacks(): void
    {
        // The character's text goes with its first piece, so it copies out as typed.
        $this->assertSame([[0x61, "\u{E1}"], [0x301, '']], self::normalize("\u{E1}", [0x61, 0x301]));
        // Kept when the pieces are missing too.
        $this->assertSame([[0xE1, "\u{E1}"]], self::normalize("\u{E1}", [0x61]));
    }

    public function testMarksInHarfBuzzOrderWithTheTypedText(): void
    {
        // Dagesh (21) goes before sheva (10) in HarfBuzz's modified classes; the text stays as typed.
        $out = self::normalize("\u{5D1}\u{5B0}\u{5BC}", [0x5D1, 0x5B0, 0x5BC]);
        $this->assertSame([0x5D1, 0x5BC, 0x5B0], array_column($out, 0));
        $this->assertSame("\u{5D1}\u{5B0}\u{5BC}", implode('', array_column($out, 1)));
        $this->assertSame(12, Unicode::ccc(0x5BC));
        $this->assertSame(22, Unicode::ccc(0x5B0));
        $this->assertSame(230, Unicode::ccc(0x301));
    }

    public function testReorderedMarksKeepTheirTypedTextTogether(): void
    {
        // a + acute (230) + macron below (220): drawn below-mark first, copied out as typed, even
        // when the acute composes into the a.
        $out = self::normalize("a\u{301}\u{331}", [0x61, 0x301, 0x331, 0xE1]);
        $this->assertSame([0xE1, 0x331], array_column($out, 0));
        $this->assertSame("a\u{301}\u{331}", implode('', array_column($out, 1)));
    }

    public function testBlockedMarksDontCompose(): void
    {
        // a + dot below (220) + acute (230): the acute still reaches the a past the dot below…
        $this->assertSame([0x1EA1, 0x301], array_column(self::normalize("a\u{323}\u{301}", [0x61, 0x323, 0x301, 0x1EA1]), 0));
        // …but not past another mark of its own class.
        $this->assertSame([0xE1, 0x301], array_column(self::normalize("a\u{301}\u{301}", [0x61, 0x301, 0xE1]), 0));
    }
}
