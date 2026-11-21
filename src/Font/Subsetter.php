<?php

declare(strict_types=1);

namespace YetiPdf\Write\Font;

/**
 * Builds a TrueType subset for embedding. Glyph ids stay the same (the PDF uses Identity
 * CID-to-GID mapping), so unused glyphs simply become empty; composite glyphs pull in their parts.
 * Only the tables a PDF renderer needs are kept.
 */
final class Subsetter
{
    private const KEEP = ['cvt ', 'fpgm', 'prep', 'head', 'hhea', 'hmtx', 'maxp', 'loca', 'glyf', 'OS/2'];

    private string $d;
    private array $tables = [];

    public function __construct(string $data)
    {
        $this->d = $data;
        $n = $this->u16(4);
        for ($i = 0; $i < $n; $i++) {
            $o = 12 + 16 * $i;
            $this->tables[substr($data, $o, 4)] = [$this->u32($o + 8), $this->u32($o + 12)];
        }
    }

    private function u16(int $o): int
    {
        return (ord($this->d[$o]) << 8) | ord($this->d[$o + 1]);
    }

    private function u32(int $o): int
    {
        return unpack('N', $this->d, $o)[1];
    }

    /** @param array<int,true> $used glyph ids */
    public function subset(array $used, int $numGlyphs): string
    {
        $used[0] = true;
        $d = $this->d;
        $head = $this->tables['head'][0];
        $long = ((ord($d[$head + 50]) << 8) | ord($d[$head + 51])) === 1;
        $loca = $this->tables['loca'][0];
        $glyf = $this->tables['glyf'][0];
        // Unpack the entire loca table once in C (1-indexed: entry $g+1 is glyph $g's offset).
        $locaTable = $long
            ? unpack('N*', substr($d, $loca, 4 * ($numGlyphs + 1)))
            : unpack('n*', substr($d, $loca, 2 * ($numGlyphs + 1)));

        // Close over composite glyph components.
        $queue = array_keys($used);
        while ($queue) {
            $g = array_pop($queue);
            if ($g >= $numGlyphs) {
                continue;
            }
            $start = $long ? $locaTable[$g + 1] : ($locaTable[$g + 1] << 1);
            $end = $long ? $locaTable[$g + 2] : ($locaTable[$g + 2] << 1);
            if ($end - $start < 10) {
                continue;
            }
            $p = $glyf + $start;
            if ((ord($d[$p]) & 0x80) === 0) {
                continue; // simple glyph (numberOfContours >= 0)
            }
            $p += 10;
            do {
                $flags = (ord($d[$p]) << 8) | ord($d[$p + 1]);
                $comp = (ord($d[$p + 2]) << 8) | ord($d[$p + 3]);
                if (!isset($used[$comp])) {
                    $used[$comp] = true;
                    $queue[] = $comp;
                }
                $p += 4 + (($flags & 0x0001) ? 4 : 2);
                if ($flags & 0x0008) {
                    $p += 2;
                } elseif ($flags & 0x0040) {
                    $p += 4;
                } elseif ($flags & 0x0080) {
                    $p += 8;
                }
            } while ($flags & 0x0020);
        }

        $glyfParts = [];
        $glyfLen = 0;
        $newLocaVals = [];
        for ($g = 0; $g < $numGlyphs; $g++) {
            $newLocaVals[] = $glyfLen;
            if (isset($used[$g])) {
                $start = $long ? $locaTable[$g + 1] : ($locaTable[$g + 1] << 1);
                $len = ($long ? $locaTable[$g + 2] : ($locaTable[$g + 2] << 1)) - $start;
                if ($len > 0) {
                    $glyfParts[] = substr($d, $glyf + $start, $len);
                    $glyfLen += $len;
                    $rem = $glyfLen & 3;
                    if ($rem !== 0) {
                        $pad = 4 - $rem;
                        $glyfParts[] = str_repeat("\0", $pad);
                        $glyfLen += $pad;
                    }
                }
            }
        }
        $newLocaVals[] = $glyfLen;
        $locaTable = null;
        $newGlyf = implode('', $glyfParts);
        $glyfParts = null;
        $newLoca = pack('N*', ...$newLocaVals);
        $newLocaVals = null;

        $out = [];
        foreach (self::KEEP as $tag) {
            if (!isset($this->tables[$tag])) {
                continue;
            }
            [$o, $l] = $this->tables[$tag];
            $out[$tag] = match ($tag) {
                'glyf' => $newGlyf,
                'loca' => $newLoca,
                'head' => substr_replace(substr_replace(substr($d, $o, $l), "\0\0\0\0", 8, 4), "\0\x01", 50, 2),
                default => substr($d, $o, $l),
            };
        }
        return $this->assemble($out);
    }

    private function assemble(array $tables): string
    {
        ksort($tables, SORT_STRING);
        $n = count($tables);
        $es = (int) floor(log($n, 2));
        $sr = (2 ** $es) * 16;
        $dir = pack('Nnnnn', 0x00010000, $n, $sr, $es, $n * 16 - $sr);
        $bodyParts = [];
        $bodyLen = 0;
        $bodySum = 0;
        $offset = 12 + 16 * $n;
        $headAt = 0;
        foreach ($tables as $tag => $data) {
            $len = strlen($data);
            $pad = (4 - ($len & 3)) & 3;
            $padded = $pad ? $data . str_repeat("\0", $pad) : $data;
            if ($tag === 'head') {
                $headAt = $offset + $bodyLen;
            }
            $cs = self::checksum($padded);
            $bodySum = ($bodySum + $cs) & 0xFFFFFFFF;
            $dir .= $tag . pack('NNN', $cs, $offset + $bodyLen, $len);
            $bodyParts[] = $padded;
            $bodyLen += $len + $pad;
        }
        $totalSum = (self::checksum($dir) + $bodySum) & 0xFFFFFFFF;
        $adj = (0xB1B0AFBA - $totalSum) & 0xFFFFFFFF;
        $font = $dir . implode('', $bodyParts);
        return substr_replace($font, pack('N', $adj), $headAt + 8, 4);
    }

    private static function checksum(string $data): int
    {
        $len = strlen($data);
        $pad = (4 - ($len & 3)) & 3;
        if ($pad !== 0) {
            $data .= str_repeat("\0", $pad);
            $len += $pad;
        }
        if ($len <= 4_000_000) {
            return array_sum(unpack('N*', $data)) & 0xFFFFFFFF;
        }
        $sum = 0;
        for ($o = 0; $o < $len; $o += 4_000_000) {
            $sum = ($sum + array_sum(unpack('N*', substr($data, $o, 4_000_000)))) & 0xFFFFFFFF;
        }
        return $sum;
    }
}
