<?php

declare(strict_types=1);

namespace YetiPdf\Write\Tests\Unit;

use YetiPdf\Write\Node;
use YetiPdf\Write\PdfException;
use YetiPdf\Write\Value;
use PHPUnit\Framework\TestCase;

final class ValueTest extends TestCase
{
    public function testLengths(): void
    {
        $this->assertEqualsWithDelta(12.0, Value::abs('12pt', 10), 1e-9);
        $this->assertEqualsWithDelta(72.0, Value::abs('1in', 10), 1e-9);
        $this->assertEqualsWithDelta(72 / 25.4 * 10, Value::abs('10mm', 10), 1e-9);
        $this->assertEqualsWithDelta(5.0, Value::abs('0.5em', 10), 1e-9);
        $this->assertEqualsWithDelta(50.0, Value::abs('25%', 10, 200), 1e-9);
        $this->assertEqualsWithDelta(-2.0, Value::abs('-2pt', 10), 1e-9);
        $this->assertEqualsWithDelta(7.0, Value::abs('7', 10), 1e-9);
    }

    public function testInvalidLength(): void
    {
        $this->expectException(PdfException::class);
        Value::length('12px solid');
    }

    public function testTracks(): void
    {
        $this->assertSame([['auto'], ['auto'], ['auto']], Value::tracks('3'));
        $t = Value::tracks('1fr auto 64pt 1.5fr');
        $this->assertSame(['fr', 1.0], $t[0]);
        $this->assertSame(['auto'], $t[1]);
        $this->assertSame('len', $t[2][0]);
        $this->assertSame(['fr', 1.5], $t[3]);
    }

    public function testColors(): void
    {
        $this->assertSame([1.0, 0.0, 0.0], array_map(fn ($v) => round($v, 3), Value::color('#f00')));
        $this->assertEqualsWithDelta(0x14 / 255, Value::color('#14161b')[0], 1e-9);
        $this->assertEqualsWithDelta(235 / 255, Value::color('luma(92%)')[0], 1e-9);
        $this->assertSame([0, 0, 0], Value::color('black'));
    }

    public function testStroke(): void
    {
        $s = Value::stroke('0.5pt #ff0000 dashed', 10);
        $this->assertEqualsWithDelta(0.5, $s['w'], 1e-9);
        $this->assertEqualsWithDelta(1.0, $s['color'][0], 1e-9);
        $this->assertNotNull($s['dash']);
        $this->assertNull(Value::stroke('none', 10));
    }

    public function testSides(): void
    {
        $n = new Node('block', ['inset' => '4pt 8pt', 'inset-top' => '1pt']);
        $this->assertSame([1.0, 8.0, 4.0, 8.0], Value::sides($n, 'inset', 10, 0));
    }

    public function testAngles(): void
    {
        $this->assertEqualsWithDelta(-M_PI / 2, Value::angle('-90deg'), 1e-9);
        $this->assertEqualsWithDelta(M_PI, Value::angle('0.5turn'), 1e-9);
    }
}
