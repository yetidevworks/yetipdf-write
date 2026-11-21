<?php

declare(strict_types=1);

// Renders every capability case (cases.php) with every engine and checks the PDFs.
//   php benchmarks/capabilities/run.php [case ...]
// Writes out/<engine>/<case>.pdf, results.json and RESULTS.md (the table plus each check's detail).
// Needs poppler (pdftotext, pdffonts, pdfimages, pdftoppm), qpdf, ImageMagick, typst and Google
// Chrome; an engine that isn't installed is skipped.

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../../vendor/autoload.php';

$root = dirname(__DIR__, 2);
$here = __DIR__;
$assets = "$here/assets";
$fonts = "$root/fonts";
$dejavu = "$root/tests/fixtures/fonts";
$out = "$here/out";
$cache = "$root/benchmarks/cache";
$chrome = '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';

$cases = require "$here/cases.php";
if ($only = array_slice($argv, 1)) {
    $cases = array_intersect_key($cases, array_flip($only));
}

/** Reads a rendered PDF back: text, word boxes, fonts, images, links, colours. */
final class Out
{
    private ?array $words = null;
    private ?array $heights = null;

    public function __construct(public readonly string $pdf)
    {
    }

    public function text(): string
    {
        return (string) shell_exec('pdftotext -enc UTF-8 ' . escapeshellarg($this->pdf) . ' - 2>/dev/null');
    }

    /** @return list<string> text per page */
    public function pageTexts(): array
    {
        return array_slice(explode("\f", $this->text()), 0, count($this->words()));
    }

    /** @return list<list<array{0: float, 1: float, 2: float, 3: float, 4: string}>> words per page, top-left origin */
    public function words(): array
    {
        if ($this->words === null) {
            $html = (string) shell_exec('pdftotext -enc UTF-8 -bbox ' . escapeshellarg($this->pdf) . ' - 2>/dev/null');
            $this->words = [];
            $this->heights = [];
            foreach (preg_split('/<page /', $html) as $i => $chunk) {
                if ($i === 0) {
                    continue;
                }
                preg_match('/height="([\d.]+)"/', $chunk, $h);
                $this->heights[] = (float) $h[1];
                preg_match_all('/<word xMin="([\d.-]+)" yMin="([\d.-]+)" xMax="([\d.-]+)" yMax="([\d.-]+)">([^<]*)<\/word>/', $chunk, $m, PREG_SET_ORDER);
                $this->words[] = array_map(fn ($w) => [(float) $w[1], (float) $w[2], (float) $w[3], (float) $w[4], html_entity_decode($w[5])], $m);
            }
        }
        return $this->words;
    }

    public function pageHeight(int $page): float
    {
        $this->words();
        return $this->heights[$page] ?? 0.0;
    }

    /** The first word equal to $text: [x0, y0, x1, y1, text]. */
    public function word(string $text): ?array
    {
        $lig = ["\u{FB00}" => 'ff', "\u{FB01}" => 'fi', "\u{FB02}" => 'fl', "\u{FB03}" => 'ffi', "\u{FB04}" => 'ffl'];
        foreach ($this->words() as $page) {
            foreach ($page as $w) {
                if (strtr($w[4], $lig) === $text) {
                    return $w;
                }
            }
        }
        return null;
    }

    /** Lines on a page (words sharing a baseline): [x0, x1, y]. */
    public function lines(int $page): array
    {
        $lines = [];
        foreach ($this->words()[$page] ?? [] as [$x0, $y0, $x1, $y1, $t]) {
            $k = (string) round($y1, 0);
            $lines[$k] ??= ['x0' => $x0, 'x1' => $x1, 'y' => $y1, 'last' => $t];
            $lines[$k]['x0'] = min($lines[$k]['x0'], $x0);
            if ($x1 >= $lines[$k]['x1']) {
                $lines[$k]['x1'] = $x1;
                $lines[$k]['last'] = $t;
            }
        }
        usort($lines, fn ($a, $b) => $a['y'] <=> $b['y']);
        return $lines;
    }

    /** @return list<string> embedded font names */
    public function fonts(): array
    {
        $rows = array_slice(explode("\n", trim((string) shell_exec('pdffonts ' . escapeshellarg($this->pdf) . ' 2>/dev/null'))), 2);
        return array_map(fn ($r) => preg_split('/\s+/', trim($r))[0], array_filter($rows));
    }

    /** @return list<array{type: string}> images and soft masks, from pdfimages */
    public function images(): array
    {
        $rows = array_slice(explode("\n", trim((string) shell_exec('pdfimages -list ' . escapeshellarg($this->pdf) . ' 2>/dev/null'))), 2);
        return array_map(fn ($r) => ['type' => preg_split('/\s+/', trim($r))[2] ?? ''], array_filter($rows));
    }

    /** @return list<array{0: string, 1: array{0: float, 1: float, 2: float, 3: float}}> link URIs and their rectangles (top-left origin) */
    public function links(): array
    {
        $json = json_decode((string) shell_exec('qpdf --json --json-key=qpdf ' . escapeshellarg($this->pdf) . ' 2>/dev/null'), true);
        $objects = $json['qpdf'][1] ?? [];
        $resolve = fn ($v) => is_string($v) && preg_match('/^\d+ \d+ R$/', $v) ? ($objects['obj:' . $v]['value'] ?? null) : $v;
        $string = fn ($s) => is_string($s) ? (str_starts_with($s, 'u:') ? substr($s, 2) : (str_starts_with($s, 'b:') ? hex2bin(substr($s, 2)) : $s)) : '';
        $h = $this->pageHeight(0);
        $out = [];
        foreach ($objects as $o) {
            $v = $o['value'] ?? null;
            if (!is_array($v) || ($v['/Subtype'] ?? '') !== '/Link') {
                continue;
            }
            $a = $resolve($v['/A'] ?? null);
            $uri = $string($resolve($a['/URI'] ?? null));
            $r = array_map(fn ($n) => (float) $n, $v['/Rect'] ?? [0, 0, 0, 0]);
            $out[] = [$uri, [min($r[0], $r[2]), $h - max($r[1], $r[3]), max($r[0], $r[2]), $h - min($r[1], $r[3])]];
        }
        return $out;
    }

    /** Whether the page shows clearly red and clearly blue areas. */
    public function colours(int $page): array
    {
        $png = tempnam(sys_get_temp_dir(), 'cap');
        shell_exec(sprintf('pdftoppm -r 40 -f %d -l %d -png -singlefile %s %s', $page + 1, $page + 1, escapeshellarg($this->pdf), escapeshellarg($png)));
        $hist = (string) shell_exec('magick ' . escapeshellarg("$png.png") . ' -format %c histogram:info:- 2>/dev/null');
        @unlink($png);
        @unlink("$png.png");
        $red = $blue = 0;
        foreach (explode("\n", $hist) as $line) {
            if (preg_match('/^\s*(\d+): \((\d+),(\d+),(\d+)/', $line, $m)) {
                [, $n, $r, $g, $b] = array_map('intval', $m);
                $red += $r > 200 && $g < 70 && $b < 70 ? $n : 0;
                $blue += $b > 200 && $r < 70 && $g < 70 ? $n : 0;
            }
        }
        return [$red > 30, $blue > 30];
    }

    /** Widths of $text in DejaVu Sans at 40pt: [with the feature, without] (kerning, or ligatures). */
    public static function widths(string $text, bool $ligatures): array
    {
        $face = (new YetiPdf\Write\YetiPdf(fonts: [dirname(__DIR__, 2) . '/tests/fixtures/fonts']))->fonts()->faces('DejaVu Sans', 400, false)[0];
        $width = function (array $features, bool $kern) use ($face, $text) {
            $g = $face->glyphs($text, $features);
            $w = 0;
            foreach ($g as $i => [$gid]) {
                $w += $face->advances[$gid] + ($kern && isset($g[$i + 1]) ? $face->kern($gid, $g[$i + 1][0]) : 0);
            }
            return $w * 40 / $face->upem;
        };
        return $ligatures ? [$width(['liga'], true), $width([], true)] : [$width([], true), $width([], false)];
    }
}

// ---- engines

$css = "@page { size: A4; margin: 40pt; } body { font-family: 'Figtree', 'DejaVu Sans'; font-size: 10pt; margin: 0; } p { margin: 0 0 6pt; }";
$faces = '';
foreach (['Regular' => 400, 'Medium' => 500, 'SemiBold' => 600, 'Bold' => 700] as $name => $w) {
    $faces .= "@font-face { font-family: 'Figtree'; font-weight: $w; src: url('file://$fonts/Figtree-$name.ttf'); }\n";
}
$faces .= "@font-face { font-family: 'DejaVu Sans'; src: url('file://$dejavu/DejaVuSans.ttf'); }\n";
$html = function (array $case, string $engine, string $src) use ($css, $faces) {
    $body = $case["html_$engine"] ?? $case['html'];
    $body = str_replace('src="', 'src="' . $src, $body);
    $extra = $case['css'] ?? '';
    $style = "$faces $css $extra";
    if ($engine === 'mpdf' || $engine === 'tcpdf') {
        // They take fonts and margins from their own config and mishandle @page rules.
        $style = preg_replace('/@(page|font-face)\s*\{(?:[^{}]|\{[^{}]*\})*\}/', '', $style);
    }
    return "<!DOCTYPE html><html><head><meta charset=\"utf-8\"><style>$style</style></head><body>$body</body></html>";
};

$engines = [
    'YetiPDF Write' => function (array $case) use ($fonts, $dejavu, $assets) {
        $page = $case['yetipdf_page'] ?? '<page paper="a4" margin="40pt"/>';
        $xml = '<document font="Figtree, DejaVu Sans" size="10pt" par-spacing="6pt">' . $page . $case['yetipdf'] . '</document>';
        return (new YetiPdf\Write\YetiPdf(fonts: [$fonts, $dejavu], assets: [$assets]))->render($xml);
    },
    'Typst' => function (array $case, string $name) use ($fonts, $dejavu, $here, $out) {
        $typ = "#set page(paper: \"a4\", margin: 40pt)\n" . ($case['typst_page'] ?? '') . "\n#set text(font: (\"Figtree\", \"DejaVu Sans\"), size: 10pt)\n#set par(spacing: 6pt)\n"
            . str_replace('"assets/', '"/assets/', $case['typst']);
        @mkdir("$out/Typst", 0775, true);
        file_put_contents("$out/Typst/$name.typ", $typ);
        $pdf = "$out/Typst/$name.pdf";
        exec(sprintf('typst compile --root %s --font-path %s --font-path %s --ignore-system-fonts --no-pdf-tags %s %s 2>&1',
            escapeshellarg($here), escapeshellarg($fonts), escapeshellarg($dejavu), escapeshellarg("$out/Typst/$name.typ"), escapeshellarg($pdf)), $o, $code);
        if ($code !== 0) {
            throw new RuntimeException(implode("\n", $o));
        }
        return file_get_contents($pdf);
    },
    'Chrome' => function (array $case, string $name) use ($html, $assets, $out, $chrome) {
        if (!is_file($chrome)) {
            throw new RuntimeException('Chrome not installed');
        }
        @mkdir("$out/Chrome", 0775, true);
        file_put_contents("$out/Chrome/$name.html", $html($case, 'chrome', "file://$assets/"));
        $pdf = "$out/Chrome/$name.pdf";
        @unlink($pdf);
        $profile = sys_get_temp_dir() . '/yetipdf-caps-chrome';
        exec(sprintf('%s --headless --disable-gpu --no-pdf-header-footer --user-data-dir=%s --print-to-pdf=%s %s 2>/dev/null',
            escapeshellarg($chrome), escapeshellarg($profile), escapeshellarg($pdf), escapeshellarg("file://$out/Chrome/$name.html")));
        if (!is_file($pdf)) {
            throw new RuntimeException('Chrome wrote no PDF');
        }
        return file_get_contents($pdf);
    },
    'mPDF' => function (array $case) use ($html, $fonts, $assets, $cache) {
        $defaults = (new Mpdf\Config\ConfigVariables())->getDefaults();
        $fontData = (new Mpdf\Config\FontVariables())->getDefaults()['fontdata'];
        $m = new Mpdf\Mpdf([
            'tempDir' => "$cache/mpdf", 'format' => 'A4',
            'margin_top' => 40 * 25.4 / 72, 'margin_bottom' => 40 * 25.4 / 72, 'margin_left' => 40 * 25.4 / 72, 'margin_right' => 40 * 25.4 / 72,
            'fontDir' => array_merge($defaults['fontDir'], [$fonts]),
            'fontdata' => $fontData + ['figtree' => ['R' => 'Figtree-Regular.ttf', 'B' => 'Figtree-Bold.ttf', 'useKerning' => true]],
            'default_font' => 'figtree', 'autoScriptToLang' => true, 'autoLangToFont' => true, 'useKerning' => true,
        ]);
        $m->WriteHTML($html($case, 'mpdf', "$assets/"));
        return $m->Output('', 'S');
    },
    'TCPDF' => function (array $case) use ($html, $fonts, $assets, $cache) {
        @mkdir("$cache/tcpdf", 0775, true);
        $font = function (string $ttf) use ($fonts, $cache) {
            $name = strtolower(preg_replace('/[^A-Za-z]/', '', basename($ttf, '.ttf')));
            if (!is_file("$cache/tcpdf/$name.php")) {
                TCPDF_FONTS::addTTFfont("$fonts/$ttf", 'TrueTypeUnicode', '', 32, "$cache/tcpdf/");
            }
            return "$cache/tcpdf/$name.php";
        };
        $pdf = new class ('P', 'pt', 'A4', true, 'UTF-8', false) extends TCPDF {
            public bool $numbers = false;

            public function noCredit(): void
            {
                $this->tcpdflink = false; // no "Powered by TCPDF" line (the constructor turns it on)
            }

            public function Footer(): void
            {
                if ($this->numbers) {
                    $this->SetY(-30);
                    $this->Cell(0, 10, 'Page ' . $this->getAliasNumPage() . ' of ' . $this->getAliasNbPages());
                }
            }
        };
        $pdf->noCredit();
        $pdf->numbers = !empty($case['tcpdf_footer']);
        $pdf->AddFont('figtree', '', $font('Figtree-Regular.ttf'));
        $pdf->AddFont('figtree', 'B', $font('Figtree-Bold.ttf'));
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter($pdf->numbers);
        $pdf->SetMargins(40, 40, 40);
        $pdf->SetAutoPageBreak(true, 40);
        $pdf->SetFont('figtree', '', 10);
        $pdf->AddPage();
        $pdf->writeHTML($html($case, 'tcpdf', "$assets/"));
        return $pdf->Output('', 'S');
    },
    'Dompdf' => function (array $case) use ($html, $root, $assets, $cache) {
        $o = new Dompdf\Options();
        $o->setChroot([$root]);
        $o->setFontDir("$cache/dompdf");
        $o->setFontCache("$cache/dompdf");
        $o->setIsRemoteEnabled(false);
        $o->setIsFontSubsettingEnabled(true);
        $d = new Dompdf\Dompdf($o);
        $d->loadHtml($html($case, 'dompdf', "$assets/"));
        $d->setPaper('A4');
        $d->render();
        if (!empty($case['dompdf_footer'])) {
            // Dompdf's documented way to print the page count.
            $d->getCanvas()->page_text(40, 800, 'Page {PAGE_NUM} of {PAGE_COUNT}', $d->getFontMetrics()->getFont('DejaVu Sans'), 10);
        }
        return $d->output();
    },
];

// ---- run

$results = [];
foreach ($engines as $engine => $render) {
    @mkdir("$out/$engine", 0775, true);
    foreach ($cases as $name => $case) {
        $file = "$out/$engine/$name.pdf";
        try {
            set_error_handler(fn () => true);
            $bytes = $render($case, $name);
            restore_error_handler();
            file_put_contents($file, $bytes);
            [$ok, $note] = ($case['check'])(new Out($file));
            exec('qpdf --check ' . escapeshellarg($file) . ' >/dev/null 2>&1', $_, $qpdf);
            $results[$name][$engine] = ['ok' => $ok, 'note' => $note, 'valid' => in_array($qpdf, [0, 3], true)];
        } catch (Throwable $e) {
            restore_error_handler();
            $results[$name][$engine] = ['ok' => false, 'note' => 'failed: ' . strtok($e->getMessage(), "\n"), 'valid' => false];
        }
        printf("%-8s %-14s %s  %s\n", $engine, $name, match ($results[$name][$engine]['ok']) { true => 'pass', false => 'FAIL', null => ' -- ' }, $results[$name][$engine]['note']);
    }
}

// Valid PDF: every file an engine produced passes qpdf --check.
foreach (array_keys($engines) as $engine) {
    $bad = array_keys(array_filter($results, fn ($r) => isset($r[$engine]) && !$r[$engine]['valid']));
    $results['valid'][$engine] = ['ok' => $bad === [], 'note' => $bad === [] ? 'qpdf --check passes on every file' : 'qpdf fails: ' . implode(', ', $bad)];
}
$labels = array_map(fn ($c) => $c['label'], $cases) + ['valid' => 'Valid PDF (qpdf --check)'];
file_put_contents("$here/results.json", json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");

$names = array_keys($engines);
$md = "| Feature | " . implode(' | ', $names) . " |\n|---|" . str_repeat('---|', count($names)) . "\n";
// What each engine is, since that decides how it gets installed and run.
$kind = ['YetiPDF Write' => 'PHP library', 'Typst' => 'Rust CLI', 'Chrome' => 'Headless browser (C++)', 'mPDF' => 'PHP library', 'TCPDF' => 'PHP library', 'Dompdf' => 'PHP library'];
$md .= '| *What it is* |' . implode('', array_map(fn ($e) => ' ' . ($kind[$e] ?? '') . ' |', $names)) . "\n";
$score = array_fill_keys($names, 0);
foreach ($labels as $case => $label) {
    $md .= "| $label |";
    foreach ($names as $e) {
        $ok = $results[$case][$e]['ok'] ?? null;
        $score[$e] += $ok === true ? 1 : 0;
        $md .= ' ' . match ($ok) { true => '✓', false => '✗', null => '–' } . ' |';
    }
    $md .= "\n";
}
$md .= '| **Passed** |' . implode('', array_map(fn ($e) => " **{$score[$e]} / " . count($labels) . '** |', $names)) . "\n";
$detail = "\n## Details\n\n";
foreach ($labels as $case => $label) {
    $detail .= "**$label**\n\n";
    foreach ($names as $e) {
        $r = $results[$case][$e] ?? null;
        $detail .= "- $e: " . ($r === null ? 'not run' : match ($r['ok']) { true => 'pass', false => 'fail', null => 'not judged' } . ' — ' . $r['note']) . "\n";
    }
    $detail .= "\n";
}
$notes = <<<'MD'

## How it's judged

Every engine renders each case from its own input: YetiPDF markup, a Typst file, or HTML and CSS for Chrome, mPDF, TCPDF and Dompdf. Where an engine has its own documented way to do something the HTML can't express to it (mPDF's `<htmlpagefooter>`, TCPDF's `Footer()`, Dompdf's `page_text()` for the page count), that's what the case uses, so a cross means the engine can't do it, not that the HTML was written for someone else. The checks read the PDF alone, with `pdftotext -bbox`, `pdffonts`, `pdfimages` and `qpdf --json`: which fonts are embedded, where each word sits, whether a word was broken with a hyphen, whether an image has a soft mask, whether a link annotation points at the right URL.

A few results need a word:

- Weights: mPDF and TCPDF take one regular and one bold per family, so medium and semibold fall back (TCPDF to Helvetica).
- Kerning and ligatures: measured as the width of `AVAVAVAV` and `office` against the font's kerned and unkerned widths. TCPDF and Dompdf place glyphs from their advance widths alone.
- Hyphenation: TCPDF has a `hyphenateText()` helper that inserts soft hyphens from TeX patterns before the HTML is written; neither it nor Dompdf breaks words on its own, which is what the case asks for.
- Table rows: mPDF can't break a row, so it shrinks the whole table to fit one page (nothing is lost, but the text is tiny). Dompdf starts the row on a new page and cuts it off at the bottom (30 of 80 lines missing).
- Widows and orphans: six sections, each pushed down by 0 to 5 filler lines, so a page break falls at every line of a 3, 4 or 5 line paragraph in one section or another. Chrome honours CSS `widows` and `orphans`; mPDF, TCPDF and Dompdf don't.
- Copy and paste: mPDF maps ligature glyphs to the ligature characters (`ﬃ`), so a copied "office" doesn't match a search for it.
- Right-to-left: Dompdf has no bidirectional text support; the Hebrew comes out in the wrong order.
MD;
file_put_contents("$here/RESULTS.md", "# Capabilities\n\nGenerated by `php benchmarks/capabilities/run.php`. Each feature is rendered by every engine and checked from the PDF alone.\n\n" . $md . $notes . $detail);
echo "\n" . $md;
