<?php

declare(strict_types=1);

// The capability cases: one feature each, written for every engine (YetiPDF markup, Typst, and
// HTML/CSS for Dompdf, mPDF, TCPDF and Chrome), and a check that reads only the PDF that came
// out. An engine gets a pass only if its PDF passes the check. Where an engine has its own
// documented way to do something (mPDF's {PAGENO} footers, TCPDF's Footer() hook), the case uses
// it. See run.php.

$para = 'Typesetting systems break paragraphs into lines by considering hyphenation opportunities, '
    . 'interword stretchability and the overall visual impression of consecutive lines. Justified '
    . 'text needs good hyphenation to avoid rivers of white space between words in narrow columns.';

// Widows: six sections, each pushed down by 0-5 filler lines, so page breaks land at every
// line of a paragraph in one section or another. Paragraphs run 3, 4 and 5 lines long.
$widowSections = [];
for ($k = 0; $k <= 5; $k++) {
    $pars = $k ? [array_map(fn ($l) => sprintf('S%dF%d', $k, $l), range(1, $k))] : [];
    for ($p = 1; $p <= 36; $p++) {
        $pars[] = array_map(fn ($l) => sprintf('S%dP%02dL%d', $k, $p, $l), range(1, 3 + $p % 3));
    }
    $widowSections[] = $pars;
}
$widowPars = array_merge(...array_map(fn ($sec) => array_values(array_filter($sec, fn ($p) => !str_contains($p[0], 'F'))), $widowSections));
$rows = range(1, 80);
$tall = array_map(fn ($i) => sprintf('LINE%02d', $i), range(1, 80));

return [
    'unicode' => [
        'label' => 'Text in other scripts (font fallback)',
        'yetipdf' => '<block size="14pt">Grüße Ελληνικά Русский Čeština Đorđe</block>',
        'typst' => '#text(size: 14pt)[Grüße Ελληνικά Русский Čeština Đorđe]',
        'html' => '<p style="font-size: 14pt">Grüße Ελληνικά Русский Čeština Đorđe</p>',
        'check' => function (Out $o) {
            $missing = array_filter(['Grüße', 'Ελληνικά', 'Русский', 'Čeština', 'Đorđe'], fn ($w) => !str_contains($o->text(), $w));
            return [$missing === [], $missing ? 'missing: ' . implode(', ', $missing) : 'all five words'];
        },
    ],
    'weights' => [
        'label' => 'Medium and semibold weights',
        'yetipdf' => '<block weight="regular">Regular</block><block weight="medium">Medium</block><block weight="semibold">Semibold</block><block weight="bold">Bold</block>',
        'typst' => "#text(weight: \"regular\")[Regular]\n\n#text(weight: \"medium\")[Medium]\n\n#text(weight: \"semibold\")[Semibold]\n\n#text(weight: \"bold\")[Bold]",
        'html' => '<p style="font-weight: 400">Regular</p><p style="font-weight: 500">Medium</p><p style="font-weight: 600">Semibold</p><p style="font-weight: 700">Bold</p>',
        'check' => function (Out $o) {
            $fonts = $o->fonts();
            $have = array_filter(['Medium', 'SemiBold'], fn ($w) => (bool) preg_grep('/Figtree.*' . $w . '/i', $fonts));
            return [count($have) === 2, 'fonts: ' . implode(', ', array_unique(array_map(fn ($f) => preg_replace('/^[A-Z]{6}\+/', '', $f), $fonts)))];
        },
    ],
    'kerning' => [
        'label' => 'Kerning',
        'yetipdf' => '<block font="DejaVu Sans" size="40pt">AVAVAVAV</block>',
        'typst' => '#text(font: "DejaVu Sans", size: 40pt)[AVAVAVAV]',
        'html' => '<p style="font-family: \'DejaVu Sans\'; font-size: 40pt; font-kerning: normal">AVAVAVAV</p>',
        'check' => function (Out $o) {
            [$kerned, $plain] = Out::widths('AVAVAVAV', false);
            $w = $o->word('AVAVAVAV');
            if ($w === null) {
                return [false, 'word not found'];
            }
            $got = $w[2] - $w[0];
            return [abs($got - $kerned) < abs($got - $plain) && abs($got - $kerned) < 1.5, sprintf('width %.1fpt (kerned %.1f, unkerned %.1f)', $got, $kerned, $plain)];
        },
    ],
    'ligatures' => [
        'label' => 'Ligatures',
        'yetipdf' => '<block font="DejaVu Sans" size="40pt">office</block>',
        'typst' => '#text(font: "DejaVu Sans", size: 40pt)[office]',
        'html' => '<p style="font-family: \'DejaVu Sans\'; font-size: 40pt; font-variant-ligatures: common-ligatures">office</p>',
        'check' => function (Out $o) {
            [$liga, $plain] = Out::widths('office', true);
            $w = $o->word('office');
            if ($w === null) {
                return [false, 'word not found (copy text broken?)'];
            }
            $got = $w[2] - $w[0];
            return [abs($got - $liga) < abs($got - $plain) && abs($got - $liga) < 1.5, sprintf('width %.1fpt (with ligature %.1f, without %.1f)', $got, $liga, $plain)];
        },
    ],
    'hyphenation' => [
        'label' => 'Hyphenation',
        'yetipdf' => '<block width="160pt" align="justify" lang="en">' . $para . '</block>',
        'typst' => '#block(width: 160pt)[#set par(justify: true)' . "\n" . '#set text(lang: "en", hyphenate: true)' . "\n" . $para . ']',
        'html' => '<div lang="en" style="width: 160pt; text-align: justify; hyphens: auto; -webkit-hyphens: auto">' . $para . '</div>',
        'check' => function (Out $o) use ($para) {
            $source = array_flip(preg_split('/\W+/u', strtolower($para)));
            foreach ($o->words() as $page) {
                foreach ($page as [, , , , $t]) {
                    $bare = preg_replace('/[-\x{AD}\x{2010}]+$/u', '', $t);
                    if ($bare !== $t && $bare !== '' && !isset($source[strtolower($bare)])) {
                        return [true, "breaks words, e.g. \"$t\""];
                    }
                }
            }
            return [false, 'no word broken with a hyphen'];
        },
    ],
    'justify' => [
        'label' => 'Justified text',
        'yetipdf' => '<block width="200pt" align="justify">' . $para . '</block>',
        'typst' => '#block(width: 200pt)[#set par(justify: true)' . "\n" . $para . ']',
        'html' => '<div style="width: 200pt; text-align: justify">' . $para . '</div>',
        'check' => function (Out $o) {
            $lines = $o->lines(0);
            if (count($lines) < 3) {
                return [false, 'fewer than three lines'];
            }
            array_pop($lines);
            // Lines ending in a letter set the edge; a hyphen or punctuation may hang past it
            // (hanging punctuation, as Typst and YetiPDF Write do), but nothing may stop short.
            $letters = array_filter($lines, fn ($l) => preg_match('/\p{L}$/u', $l['last']));
            $right = $letters ? min(array_map(fn ($l) => $l['x1'], $letters)) : max(array_map(fn ($l) => $l['x1'], $lines));
            $short = max(array_map(fn ($l) => $right - $l['x1'], $lines));
            $long = max(array_map(fn ($l) => preg_match('/\p{L}$/u', $l['last']) ? $l['x1'] - $right : 0.0, $lines));
            $off = max($short, $long);
            return [$off < 1.0, sprintf('%d full lines, ragged by up to %.1fpt', count($lines), $off)];
        },
    ],
    'page-numbers' => [
        'label' => '"Page X of Y" footers',
        'yetipdf_page' => '<page paper="a4" margin="40pt"><footer>Page <page-number/> of <page-count/></footer></page>',
        'yetipdf' => 'First<pagebreak/>Second<pagebreak/>Third',
        'typst_page' => '#set page(footer: context [Page #counter(page).display() of #counter(page).final().first()])',
        'typst' => "First\n#pagebreak()\nSecond\n#pagebreak()\nThird",
        'html' => '<div style="page-break-after: always">First</div><div style="page-break-after: always">Second</div><div>Third</div>',
        'css' => '@page { @bottom-center { content: "Page " counter(page) " of " counter(pages); } }',
        'dompdf_footer' => true,
        'html_mpdf' => '<htmlpagefooter name="f"><div>Page {PAGENO} of {nbpg}</div></htmlpagefooter><sethtmlpagefooter name="f" value="on" />'
            . '<div style="page-break-after: always">First</div><div style="page-break-after: always">Second</div><div>Third</div>',
        'tcpdf_footer' => true,
        'check' => function (Out $o) {
            $pages = $o->pageTexts();
            if (count($pages) !== 3) {
                return [false, count($pages) . ' pages'];
            }
            foreach ($pages as $i => $t) {
                if (!preg_match('/Page\s+' . ($i + 1) . '\s+of\s+3/', $t)) {
                    return [false, 'page ' . ($i + 1) . ' footer: "' . trim(preg_replace('/\s+/', ' ', $t)) . '"'];
                }
            }
            return [true, 'Page 1 of 3 … Page 3 of 3'];
        },
    ],
    'table-header' => [
        'label' => 'Table header repeated on each page',
        'yetipdf' => '<table columns="1fr 80pt"><header><cell>DESCRIPTION</cell><cell>AMOUNT</cell></header>'
            . implode('', array_map(fn ($i) => "<cell>Item $i</cell><cell>$i.00</cell>", $rows)) . '</table>',
        'typst' => '#table(columns: (1fr, 80pt), table.header([DESCRIPTION], [AMOUNT]), ' . implode(', ', array_map(fn ($i) => "[Item $i], [$i.00]", $rows)) . ')',
        'html' => '<table style="width: 100%; border-collapse: collapse"><thead><tr><th style="text-align: left">DESCRIPTION</th><th style="width: 80pt; text-align: left">AMOUNT</th></tr></thead><tbody>'
            . implode('', array_map(fn ($i) => "<tr><td style=\"padding: 5pt\">Item $i</td><td style=\"padding: 5pt\">$i.00</td></tr>", $rows)) . '</tbody></table>',
        'check' => function (Out $o) {
            $pages = $o->pageTexts();
            $with = count(array_filter($pages, fn ($t) => str_contains($t, 'DESCRIPTION')));
            return [count($pages) > 1 && $with === count($pages), "header on $with of " . count($pages) . ' pages'];
        },
    ],
    'row-break' => [
        'label' => 'Table rows that break across pages',
        'yetipdf' => '<table columns="1fr 1fr"><cell>' . implode('<br/>', $tall) . '</cell><cell>SIDE</cell></table>',
        'typst' => '#table(columns: (1fr, 1fr), [' . implode(' \\ ', $tall) . '], [SIDE])',
        'html' => '<table style="width: 100%"><tr><td>' . implode('<br>', $tall) . '</td><td style="vertical-align: top">SIDE</td></tr></table>',
        'check' => function (Out $o) use ($tall) {
            $seen = [];
            $off = 0;
            foreach ($o->words() as $i => $page) {
                foreach ($page as [$x0, $y0, $x1, $y1, $t]) {
                    if (str_starts_with($t, 'LINE')) {
                        $seen[$t][] = $i;
                        $off += $y1 > $o->pageHeight($i) ? 1 : 0;
                    }
                }
            }
            $pages = count(array_unique(array_merge(...array_values($seen ?: [[]]))));
            $lost = count(array_diff($tall, array_keys($seen)));
            return [$lost === 0 && $off === 0 && $pages > 1, "$lost of 80 lines lost, $off off the page, over $pages page(s)"];
        },
    ],
    'spans' => [
        'label' => 'Column and row spans',
        'yetipdf' => '<table columns="150pt 150pt 150pt"><cell colspan="2">SPANNING TWO COLUMNS OF THIS TABLE</cell><cell>C1</cell><cell rowspan="2">TALL</cell><cell>B2</cell><cell>C2</cell><cell>B3</cell><cell>C3</cell></table>',
        'typst' => '#table(columns: (150pt, 150pt, 150pt), table.cell(colspan: 2)[SPANNING TWO COLUMNS OF THIS TABLE], [C1], table.cell(rowspan: 2)[TALL], [B2], [C2], [B3], [C3])',
        'html' => '<table style="border-collapse: collapse; table-layout: fixed; width: 450pt"><tr><td colspan="2" style="width: 300pt">SPANNING TWO COLUMNS OF THIS TABLE</td><td style="width: 150pt">C1</td></tr>'
            . '<tr><td rowspan="2" style="width: 150pt">TALL</td><td style="width: 150pt">B2</td><td>C2</td></tr><tr><td>B3</td><td>C3</td></tr></table>',
        'check' => function (Out $o) {
            $span = array_filter($o->words()[0] ?? [], fn ($w) => in_array($w[4], ['SPANNING', 'TABLE'], true));
            $ys = array_unique(array_map(fn ($w) => round($w[1]), $span));
            $b2 = $o->word('B2');
            $b3 = $o->word('B3');
            $oneLine = count($span) === 2 && count($ys) === 1;
            $rowspan = $b2 !== null && $b3 !== null && abs($b2[0] - $b3[0]) < 2;
            return [$oneLine && $rowspan, ($oneLine ? 'colspan ok' : 'colspan text wraps') . ', ' . ($rowspan ? 'rowspan ok' : 'rowspan ignored')];
        },
    ],
    'links' => [
        'label' => 'Links',
        'yetipdf' => 'Visit <link href="https://example.com/page">Example link</link> now.',
        'typst' => 'Visit #link("https://example.com/page")[Example link] now.',
        'html' => '<p>Visit <a href="https://example.com/page">Example link</a> now.</p>',
        'check' => function (Out $o) {
            $w = $o->word('Example');
            foreach ($o->links() as [$uri, $rect]) {
                if ($uri === 'https://example.com/page' && $w !== null && $rect[0] <= $w[0] + 1 && $rect[2] >= $w[2] - 1 && $rect[1] <= $w[3] && $rect[3] >= $w[1]) {
                    return [true, 'clickable over the text'];
                }
            }
            return [false, count($o->links()) . ' link annotation(s), none over "Example link"'];
        },
    ],
    'images' => [
        'label' => 'JPEG and PNG with transparency',
        'yetipdf' => '<image src="photo.jpg" width="150pt"/><image src="alpha.png" width="150pt"/>',
        'typst' => '#image("assets/photo.jpg", width: 150pt)' . "\n" . '#image("assets/alpha.png", width: 150pt)',
        'html' => '<p><img src="photo.jpg" style="width: 150pt"></p><p><img src="alpha.png" style="width: 150pt"></p>',
        'check' => function (Out $o) {
            $types = array_count_values(array_column($o->images(), 'type'));
            return [($types['image'] ?? 0) >= 2 && ($types['smask'] ?? 0) >= 1, ($types['image'] ?? 0) . ' image(s), ' . ($types['smask'] ?? 0) . ' soft mask(s)'];
        },
    ],
    'svg' => [
        'label' => 'SVG drawn as vectors',
        'yetipdf' => '<image src="shapes.svg" width="240pt"/>',
        'typst' => '#image("assets/shapes.svg", width: 240pt)',
        'html' => '<p><img src="shapes.svg" style="width: 240pt"></p>',
        'check' => function (Out $o) {
            $images = count($o->images());
            [$red, $blue] = $o->colours(0);
            return [$images === 0 && $red && $blue, ($red && $blue ? 'drawn' : 'not drawn') . ", $images raster image(s)"];
        },
    ],
    'widows' => [
        'label' => 'Widow and orphan control',
        'yetipdf' => implode('<pagebreak/>', array_map(fn ($sec) => implode('', array_map(fn ($p) => '<block>' . implode('<br/>', $p) . '</block>', $sec)), $widowSections)),
        'typst' => implode("\n#pagebreak()\n", array_map(fn ($sec) => implode("\n\n", array_map(fn ($p) => implode(' \\ ', $p), $sec)), $widowSections)),
        'html' => implode('', array_map(fn ($i, $sec) => '<div' . ($i ? ' style="page-break-before: always"' : '') . '>' . implode('', array_map(fn ($p) => '<p>' . implode('<br>', $p) . '</p>', $sec)) . '</div>', array_keys($widowSections), $widowSections)),
        'css' => 'p { widows: 2; orphans: 2; }',
        'check' => function (Out $o) use ($widowPars) {
            $pageOf = [];
            foreach ($o->words() as $i => $page) {
                foreach ($page as [, , , , $t]) {
                    $pageOf[$t] = $i;
                }
            }
            $splits = 0;
            foreach ($widowPars as $p) {
                $byPage = array_count_values(array_map(fn ($l) => $pageOf[$l] ?? -1, $p));
                if (count($byPage) > 1) {
                    $splits++;
                    if (min($byPage) < 2 || isset($byPage[-1])) {
                        return [false, sprintf('a %d-line paragraph (%s) splits %s', count($p), preg_replace('/L\d+$/', '', $p[0]), implode(' + ', $byPage))];
                    }
                }
            }
            return [$splits > 0 ? true : null, $splits > 0 ? "$splits paragraph(s) split, none leaving a line alone" : 'no paragraph split, nothing to judge'];
        },
    ],
    'copy-text' => [
        'label' => 'Copy and paste gives the text back',
        'yetipdf' => '<block font="DejaVu Sans">The office staff — Ægir, Zoë and François — affirmed the AVATAR waffle.</block>',
        'typst' => '#text(font: "DejaVu Sans")[The office staff — Ægir, Zoë and François — affirmed the AVATAR waffle.]',
        'html' => '<p style="font-family: \'DejaVu Sans\'">The office staff — Ægir, Zoë and François — affirmed the AVATAR waffle.</p>',
        'check' => function (Out $o) {
            $want = 'The office staff — Ægir, Zoë and François — affirmed the AVATAR waffle.';
            $got = trim(preg_replace('/\s+/u', ' ', $o->text()));
            return [$got === $want, $got === $want ? 'exact' : "got \"$got\""];
        },
    ],
    'rtl' => [
        'label' => 'Right-to-left text (Hebrew)',
        'yetipdf' => '<block font="DejaVu Sans" size="14pt" lang="he">שלום עולם</block>',
        'typst' => '#text(font: "DejaVu Sans", size: 14pt, lang: "he")[שלום עולם]',
        'html' => '<p dir="rtl" lang="he" style="font-family: \'DejaVu Sans\'; font-size: 14pt">שלום עולם</p>',
        'check' => function (Out $o) {
            // שלום (the first word) must sit to the right of עולם; letters within a word read right to left.
            $words = $o->words()[0] ?? [];
            $first = $second = null;
            foreach ($words as $w) {
                if (str_contains($w[4], 'ש')) {
                    $first = $w;
                } elseif (str_contains($w[4], 'ע')) {
                    $second = $w;
                }
            }
            if ($first === null || $second === null) {
                return [false, 'words not found'];
            }
            return [$first[0] > $second[0], $first[0] > $second[0] ? 'reads right to left' : 'laid out left to right'];
        },
    ],
    'arabic' => [
        'label' => 'Arabic letters joined',
        'yetipdf' => '<block font="DejaVu Sans" size="40pt" lang="ar">بالعالم</block>',
        'typst' => '#text(font: "DejaVu Sans", size: 40pt, lang: "ar")[بالعالم]',
        'html' => '<p dir="rtl" lang="ar" style="font-family: \'DejaVu Sans\'; font-size: 40pt">بالعالم</p>',
        'check' => function (Out $o) {
            // Joined, the letters take their initial, medial and final forms, which are narrower
            // than the isolated ones drawn when they don't join.
            // (The page holds just this word; some engines copy it out as presentation forms.)
            $w = null;
            foreach ($o->words()[0] ?? [] as $word) {
                if ($w === null || $word[2] - $word[0] > $w[2] - $w[0]) {
                    $w = $word;
                }
            }
            if ($w === null) {
                return [false, 'no text found'];
            }
            $face = (new YetiPdf\Write\YetiPdf(fonts: [dirname(__DIR__, 2) . '/tests/fixtures/fonts']))->fonts()->faces('DejaVu Sans', 400, false)[0];
            $joined = (new YetiPdf\Write\Layout\Layouter(new YetiPdf\Write\Font\FontBook(null), []))->glyphRun($face, 'بالعالم', 40, [0, 0, 0], 0.0, [], 1, 'ar')->width;
            $isolated = 0;
            foreach (mb_str_split('بالعالم') as $ch) {
                $isolated += $face->advances[$face->glyph(mb_ord($ch))] * 40 / $face->upem;
            }
            $got = $w[2] - $w[0];
            $detail = sprintf('width %.1fpt (joined %.1f, isolated letters %.1f)', $got, $joined, $isolated);
            if (abs($got - $joined) < 1.5) {
                return [true, $detail];
            }
            // An engine that sets Arabic in a font of its own can't match DejaVu's widths, but
            // joined letters still come out far narrower than isolated ones.
            $font = (string) shell_exec('pdffonts ' . escapeshellarg($o->pdf) . ' 2>/dev/null');
            $own = !str_contains($font, 'DejaVu');
            return [$own && $got < 0.75 * $isolated, $detail . ($own ? ', in its own Arabic font' : '')];
        },
    ],
];
