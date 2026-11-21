<?php

declare(strict_types=1);

namespace YetiPdf\Write\Tests\Unit;

use YetiPdf\Write\Font\Shaper;
use PHPUnit\Framework\TestCase;

/** The substitution engine on hand-made lookup tables (as TrueTypeParser::gsub() gives them). */
final class ShaperTest extends TestCase
{
    /** A run of [gid, text] in, glyph ids out. */
    private static function shape(array $gsub, array $gids, array $classes = [], array $on = ['liga']): array
    {
        $gsub += ['scripts' => ['latn' => ['' => [-1, array_keys($gsub['features'])]]]];
        $s = new Shaper($gsub, $classes, [], []);
        $plan = $s->plan(['latn'], [], $on, false, false);
        $run = array_map(fn ($g) => [$g, 't', 0, 0, $classes ? ($classes[$g] ?? 0) : 1], $gids);
        return array_column($s->shape($run, $plan), 0);
    }

    public function testContextsCallingThemselvesStopWithinABudget(): void
    {
        // Lookup 0: a context on glyph 1 that calls itself twice at the same place (each call
        // doubling the work, as a hostile font can).
        $gsub = [
            'features' => [0 => ['liga', [0]]],
            'lookups' => [0 => [0, null, [['c', 3, [1 => 0], null, null, null, [[[[], [], [], [[0, 0], [0, 0]]]]]]]]],
        ];
        $t = microtime(true);
        $this->assertSame([1], self::shape($gsub, [1]));
        $this->assertLessThan(1.0, microtime(true) - $t);
    }

    public function testATagListedTwiceUsesTheFirstFeature(): void
    {
        $gsub = [
            'features' => [0 => ['liga', [0]], 1 => ['liga', [1]]],
            'lookups' => [0 => [0, null, [['s', [1 => 2]]]], 1 => [0, null, [['s', [2 => 3]]]]],
        ];
        $this->assertSame([2], self::shape($gsub, [1]));
    }

    public function testGlyphsGdefDoesntClassifyAreNotPassedOverAsBases(): void
    {
        // IgnoreBaseGlyphs (flag 2) passes over bases (class 1) only, not unclassified glyphs
        // (here glyph 5, which a first lookup puts in).
        $gsub = [
            'features' => [0 => ['liga', [0, 1]]],
            'lookups' => [0 => [0, null, [['s', [4 => 5]]]], 1 => [2, null, [['s', [5 => 6, 7 => 8]]]]],
        ];
        $this->assertSame([6, 7], self::shape($gsub, [4, 7], [7 => 1, 9 => 3]));
    }

    public function testADeletedOnlyGlyphIsDrawnAsNothing(): void
    {
        $gsub = [
            'features' => [0 => ['liga', [0]]],
            'lookups' => [0 => [0, null, [['m', [1 => []]]]]],
        ];
        $s = new Shaper($gsub + ['scripts' => ['latn' => ['' => [-1, [0]]]]], [], [], []);
        $out = $s->shape([[1, 'a', 0, 0, 1]], $s->plan(['latn'], [], ['liga'], false, false));
        $this->assertSame(Shaper::IGNORABLE, $out[0][2], 'kept for its text, drawn as nothing');
        $this->assertNull($s->shapePlain([[1, 'a']], $s->plan(['latn'], [], ['liga'], false, false)));
    }
}
