<?php

declare(strict_types=1);

namespace YetiPdf\Write\Tests\Support;

/** Word positions from a PDF via poppler's pdftotext -bbox: per page, list of [word, xMin, yMax]. */
final class Words
{
    public static function available(): bool
    {
        return trim((string) shell_exec('command -v pdftotext 2>/dev/null')) !== '';
    }

    /** @return list<list<array{0: string, 1: float, 2: float}>> */
    public static function read(string $pdf): array
    {
        $xml = (string) shell_exec('pdftotext -bbox ' . escapeshellarg($pdf) . ' - 2>/dev/null');
        $pages = [];
        foreach (preg_split('/<page /', $xml) as $i => $chunk) {
            if ($i === 0) {
                continue;
            }
            preg_match_all('/<word xMin="([\d.]+)" yMin="[\d.]+" xMax="[\d.]+" yMax="([\d.]+)">([^<]*)<\/word>/', $chunk, $m, PREG_SET_ORDER);
            // A typed soft hyphen at a break copies out twice from Typst (the character and the
            // hyphen drawn for it) and once from YetiPDF Write; that's copy text, not layout. The bidi
            // embeddings both insert around text set in the other direction land on whichever
            // neighbouring glyph each engine gives them, so they're left out.
            $pages[] = array_map(fn ($w) => [preg_replace(['/\x{AD}+/u', '/[\x{202A}-\x{202E}]/u'], ["\u{AD}", ''], html_entity_decode($w[3])), round((float) $w[1], 2), round((float) $w[2], 2)], $m);
        }
        return $pages;
    }
}
