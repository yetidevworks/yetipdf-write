<?php

declare(strict_types=1);

namespace YetiPdf\Write;

/**
 * Inherited properties, Typst `set`-rule style: any element can set them and they apply to
 * its subtree. Named styles (<style name="eyebrow" .../>) are bundles applied with class="".
 *
 * A style is a plain array (copy-on-write makes inheritance cheap):
 *   font, weight (100–900), italic, size (pt), color [r,g,b], tracking (length triple),
 *   case (upper|lower|null), leading (triple), parSpacing (triple),
 *   align (start|end|left|center|right|justify: start and end follow the text direction),
 *   numbers (tabular|proportional|null), ligatures (bool), linebreaks (simple|optimized|null = auto),
 *   blockSpacing (triple|null: default spacing around blocks, falls back to parSpacing),
 *   hyphenate (bool|null = only when justified), lang (ISO 639-1, for hyphenation and direction),
 *   dir (ltr|rtl|null = from lang), link (href),
 *   scale (fit-to-width factor).
 */
final class Style
{
    public const PROPS = ['font', 'weight', 'italic', 'size', 'color', 'tracking', 'case', 'leading', 'par-spacing', 'align', 'numbers', 'ligatures', 'linebreaks', 'block-spacing', 'hyphenate', 'lang', 'dir', 'overhang'];
    public const PROP_MAP = [
        'font' => true, 'weight' => true, 'italic' => true, 'size' => true, 'color' => true, 'tracking' => true,
        'case' => true, 'leading' => true, 'par-spacing' => true, 'align' => true, 'numbers' => true,
        'ligatures' => true, 'linebreaks' => true, 'block-spacing' => true, 'hyphenate' => true, 'lang' => true,
        'dir' => true, 'overhang' => true,
    ];

    /** Languages written right to left (as in Typst): text in them is right to left unless dir says otherwise. */
    private const RTL_LANGS = ['ar' => true, 'dv' => true, 'fa' => true, 'he' => true, 'ks' => true, 'pa' => true, 'ps' => true, 'sd' => true, 'ug' => true, 'ur' => true, 'yi' => true];

    private const WEIGHTS = [
        'thin' => 100, 'extralight' => 200, 'light' => 300, 'regular' => 400, 'normal' => 400,
        'medium' => 500, 'semibold' => 600, 'bold' => 700, 'extrabold' => 800, 'black' => 900,
    ];

    private const HALIGN = ['left' => true, 'center' => true, 'right' => true, 'justify' => true, 'start' => true, 'end' => true];
    private const VALIGN = ['top' => 'top', 'horizon' => 'horizon', 'middle' => 'horizon', 'bottom' => 'bottom'];

    public static function base(string $font): array
    {
        return [
            'font' => $font,
            'weight' => 400,
            'italic' => false,
            'size' => 11.0,
            'color' => [0.0, 0.0, 0.0],
            'tracking' => [0.0, 0.0, 0.0],
            'case' => null,
            'leading' => [0.0, 0.65, 0.0],
            'parSpacing' => [0.0, 1.2, 0.0],
            'align' => 'start',
            'numbers' => null,
            'ligatures' => true,
            'linebreaks' => null,
            'blockSpacing' => null,
            'link' => null,
            'hyphenate' => null,
            'lang' => 'en',
            'dir' => null,
            'overhang' => true,
            'scale' => 1.0,
        ];
    }

    /** @param array<string, array<string,string>> $named */
    public static function apply(array $s, Node $n, array $named): array
    {
        if ($n->attrs === []) {
            return $s;
        }
        if ($n->has('class')) {
            foreach (preg_split('/\s+/', trim($n->attrs['class'])) as $cls) {
                if (!isset($named[$cls])) {
                    throw new PdfException("Unknown style '$cls' on <{$n->type}> (line {$n->line})");
                }
                $s = self::props($s, $named[$cls], $n);
            }
        }
        return self::props($s, $n->attrs, $n);
    }

    /** @param array<string,string> $a */
    private static function props(array $s, array $a, Node $n): array
    {
        try {
            if (isset($a['size']) && $a['size'] !== '') {
                // em and % are relative to the inherited size.
                $l = Value::length($a['size']);
                $s['size'] = $l[0] + ($l[1] + $l[2]) * $s['size'];
                if (!($s['size'] > 0 && $s['size'] <= 10000)) {
                    throw new PdfException("Text size '{$a['size']}' must be above 0pt and at most 10000pt");
                }
            }
            if (isset($a['font']) && $a['font'] !== '') {
                $s['font'] = $a['font'];
            }
            if (isset($a['weight']) && $a['weight'] !== '') {
                $w = strtolower($a['weight']);
                $s['weight'] = self::WEIGHTS[$w] ?? (is_numeric($w) ? (int) $w : throw new PdfException("Invalid weight '$w'"));
            }
            if (isset($a['italic'])) {
                $s['italic'] = $n->bool('italic') || $a['italic'] === 'true';
            }
            if (isset($a['color']) && $a['color'] !== '') {
                $s['color'] = Value::color($a['color']);
            }
            if (isset($a['tracking']) && $a['tracking'] !== '') {
                $s['tracking'] = Value::length($a['tracking']);
            }
            if (isset($a['case'])) {
                $s['case'] = in_array($a['case'], ['upper', 'lower'], true) ? $a['case'] : null;
            }
            if (isset($a['leading']) && $a['leading'] !== '') {
                $s['leading'] = Value::length($a['leading']);
            }
            if (isset($a['par-spacing']) && $a['par-spacing'] !== '') {
                $s['parSpacing'] = Value::length($a['par-spacing']);
            }
            if (isset($a['align']) && $a['align'] !== '') {
                $al = $a['align'];
                if (isset(self::HALIGN[$al])) {
                    $s['align'] = $al;
                } else {
                    foreach (preg_split('/[\s+]+/', $al) as $tok) {
                        if (isset(self::HALIGN[$tok])) {
                            $s['align'] = $tok;
                        }
                    }
                }
            }
            if (isset($a['numbers'])) {
                $s['numbers'] = $a['numbers'] ?: null;
            }
            if (isset($a['hyphenate'])) {
                $s['hyphenate'] = $a['hyphenate'] === 'auto' || $a['hyphenate'] === '' ? null : $n->bool('hyphenate');
            }
            if (isset($a['lang']) && $a['lang'] !== '') {
                $s['lang'] = strtolower(substr($a['lang'], 0, 2));
            }
            if (isset($a['dir'])) {
                $s['dir'] = match ($a['dir']) {
                    'ltr', 'rtl' => $a['dir'],
                    'auto', '' => null,
                    default => throw new PdfException("Invalid dir '{$a['dir']}' (ltr, rtl or auto)"),
                };
            }
            if (isset($a['overhang'])) {
                $s['overhang'] = $n->bool('overhang', true);
            }
            if (isset($a['linebreaks'])) {
                $s['linebreaks'] = in_array($a['linebreaks'], ['simple', 'optimized'], true) ? $a['linebreaks'] : null;
            }
            if (isset($a['block-spacing'])) {
                $s['blockSpacing'] = $a['block-spacing'] === '' ? null : Value::length($a['block-spacing']);
            }
            if (isset($a['ligatures'])) {
                $s['ligatures'] = !in_array($a['ligatures'], ['false', '0', 'no', 'off'], true);
            }
        } catch (PdfException $e) {
            throw new PdfException($e->getMessage() . " on <{$n->type}> (line {$n->line})", 0, $e);
        }
        return $s;
    }

    /** Whether text in this style runs right to left. */
    public static function rtl(array $s): bool
    {
        return $s['dir'] === 'rtl' || ($s['dir'] === null && isset(self::RTL_LANGS[$s['lang']]));
    }

    /** The horizontal alignment with start and end resolved: left|center|right|justify. */
    public static function halign(array $s): string
    {
        return self::side($s['align'], self::rtl($s));
    }

    /** start and end as left or right for a direction; other values unchanged. */
    public static function side(string $align, bool $rtl): string
    {
        return match ($align) {
            'start' => $rtl ? 'right' : 'left',
            'end' => $rtl ? 'left' : 'right',
            default => $align,
        };
    }

    /** Vertical alignment token from an align attribute: top|horizon|bottom, or null. */
    public static function valign(?string $align): ?string
    {
        if ($align === null || $align === '' || isset(self::HALIGN[$align])) {
            return null;
        }
        if (isset(self::VALIGN[$align])) {
            return self::VALIGN[$align];
        }
        foreach (preg_split('/[\s+]+/', $align) as $tok) {
            if (isset(self::VALIGN[$tok])) {
                return self::VALIGN[$tok];
            }
        }
        return null;
    }
}
