<?php

declare(strict_types=1);

namespace YetiPdf\Write\Tests\Unit;

use YetiPdf\Write\Text\Bidi;
use PHPUnit\Framework\TestCase;

/** Unicode's bidi conformance tests (a sample, see tests/fixtures/bidi) against Bidi. */
final class BidiTest extends TestCase
{
    private const DIR = __DIR__ . '/../fixtures/bidi';

    /**
     * BidiCharacterTest: code points; paragraph direction (0, 1, 2 = auto); resolved paragraph
     * level; levels (x = removed); visual order.
     *
     * @return iterable<array{0: list<string>, 1: list<int>, 2: ?int, 3: int, 4: string, 5: string}>
     */
    public static function characterCases(string $file = self::DIR . '/BidiCharacterTest-sample.txt'): iterable
    {
        foreach (file($file, FILE_IGNORE_NEW_LINES) as $line) {
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            [$cps, $dir, $paragraph, $levels, $order] = explode(';', $line);
            $cps = array_map('hexdec', explode(' ', $cps));
            yield [array_map(Bidi::classOf(...), $cps), $cps, $dir === '2' ? null : (int) $dir, (int) $paragraph, $levels, trim($order)];
        }
    }

    /** BidiTest: bidi classes; a bitset of paragraph directions (1 auto, 2 LTR, 4 RTL), under @Levels and @Reorder lines. */
    public static function classCases(string $file = self::DIR . '/BidiTest-sample.txt'): iterable
    {
        $levels = $order = '';
        foreach (file($file, FILE_IGNORE_NEW_LINES) as $line) {
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            if (str_starts_with($line, '@Levels:')) {
                $levels = trim(substr($line, 8));
                continue;
            }
            if (str_starts_with($line, '@Reorder:')) {
                $order = trim(substr($line, 9));
                continue;
            }
            [$types, $bits] = array_map('trim', explode(';', $line));
            $types = explode(' ', $types);
            foreach ([1 => null, 2 => 0, 4 => 1] as $bit => $base) {
                if ((int) $bits & $bit) {
                    $paragraph = $base ?? (Bidi::firstStrongIsRtl($types) ? 1 : 0);
                    yield [$types, array_fill(0, count($types), 0), $base, $paragraph, $levels, $order];
                }
            }
        }
    }

    /** @return array{0: string, 1: string} levels and visual order, as the test files write them */
    public static function resolve(array $types, array $cps, ?int $base, int $paragraph): array
    {
        $levels = Bidi::lineLevels($types, Bidi::levelsOf($types, $cps, $base), $paragraph);
        return [
            implode(' ', array_map(fn ($l) => $l === null ? 'x' : (string) $l, $levels)),
            implode(' ', Bidi::order($levels)),
        ];
    }

    public function testCharacterCases(): void
    {
        $this->check(self::characterCases(), 2000);
    }

    public function testClassCases(): void
    {
        $this->check(self::classCases(), 9000);
    }

    private function check(iterable $cases, int $atLeast): void
    {
        $n = 0;
        foreach ($cases as [$types, $cps, $base, $paragraph, $levels, $order]) {
            $n++;
            $this->assertSame([$levels, $order], self::resolve($types, $cps, $base, $paragraph), implode(' ', $types) . ' base ' . var_export($base, true));
        }
        $this->assertGreaterThanOrEqual($atLeast, $n);
    }

    public function testMirroring(): void
    {
        $this->assertSame(0x29, Bidi::mirror(0x28));
        $this->assertSame(0x3C, Bidi::mirror(0x3E));
        $this->assertNull(Bidi::mirror(0x41));
    }

    public function testNeededOnlyForRightToLeftText(): void
    {
        $this->assertFalse(Bidi::needed('Plain text, café — 123'));
        $this->assertTrue(Bidi::needed('Hello שלום'));
        $this->assertTrue(Bidi::needed('٣'));
    }
}
