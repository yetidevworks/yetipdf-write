<?php

declare(strict_types=1);

// One engine, one fresh process: php runner.php <yetipdf|dompdf|mpdf|tcpdf> <iterations> [out.pdf|-] [invoice|report|arabic]
// Prints JSON: first render time (what one web request pays), warm median, peak memory, size.

require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/../vendor/autoload.php';

[$engine, $iterations] = [$argv[1] ?? 'yetipdf', (int) ($argv[2] ?? 10)];
$out = ($argv[3] ?? '-') === '-' ? null : $argv[3];
$docName = $argv[4] ?? 'invoice';
$root = dirname(__DIR__);
$fonts = $root . '/fonts';
$cache = __DIR__ . '/cache';
@mkdir($cache . '/dompdf', 0775, true);
@mkdir($cache . '/mpdf', 0775, true);
@mkdir($cache . '/tcpdf', 0775, true);

if ($docName !== 'invoice') {
    require __DIR__ . '/docs/runner.php';
    exit;
}

$data = json_decode(file_get_contents($root . '/examples/invoice/invoice.json'), true);
$twig = new Twig\Environment(new Twig\Loader\FilesystemLoader([__DIR__, $root . '/examples/invoice']), ['autoescape' => 'html', 'cache' => $cache . '/twig', 'auto_reload' => true]);
$doc = $engine === 'yetipdf'
    ? $twig->render('invoice.xml.twig', ['d' => $data])
    : $twig->render('invoice.html.twig', ['d' => $data, 'fonts' => $fonts, 'assets' => $root . '/examples/invoice']);

$render = match ($engine) {
    'yetipdf' => function () use ($doc, $fonts, $cache, $root) {
        return (new YetiPdf\Write\YetiPdfWrite(fonts: [$fonts], assets: [$root . '/examples/invoice'], cache: $cache . '/yetipdf'))->render($doc);
    },
    'dompdf' => function () use ($doc, $fonts, $cache, $root) {
        $o = new Dompdf\Options();
        $o->setChroot([$fonts, __DIR__, $root . '/examples/invoice']);
        $o->setFontDir($cache . '/dompdf');
        $o->setFontCache($cache . '/dompdf');
        $o->setIsRemoteEnabled(false);
        $o->setDefaultFont('Figtree');
        $d = new Dompdf\Dompdf($o);
        $d->loadHtml($doc);
        $d->setPaper('A4');
        $d->render();
        return $d->output();
    },
    'mpdf' => function () use ($doc, $fonts, $cache) {
        $defaults = (new Mpdf\Config\ConfigVariables())->getDefaults();
        $fontData = (new Mpdf\Config\FontVariables())->getDefaults()['fontdata'];
        $m = new Mpdf\Mpdf([
            'tempDir' => $cache . '/mpdf',
            'fontDir' => array_merge($defaults['fontDir'], [$fonts]),
            'fontdata' => $fontData + ['figtree' => ['R' => 'Figtree-Regular.ttf', 'B' => 'Figtree-Bold.ttf', 'I' => 'Figtree-Italic.ttf']],
            'default_font' => 'figtree',
            'format' => 'A4',
            // mPDF mis-handles the @page rule, so the same margins (44/46/60pt) go in its config.
            'margin_top' => 44 * 25.4 / 72, 'margin_left' => 46 * 25.4 / 72, 'margin_right' => 46 * 25.4 / 72,
            'margin_bottom' => 60 * 25.4 / 72, 'margin_footer' => 14 * 25.4 / 72,
        ]);
        $doc = preg_replace('/@page\s*\{[^}]*\}/', '', $doc);
        // mPDF has no position: fixed, so the footer goes through its own footer API.
        $footer = '';
        $body = preg_replace_callback('#<div class="footer">.*?</table>\s*</div>#s', function ($mm) use (&$footer) {
            $footer = $mm[0];
            return '';
        }, $doc);
        $m->SetHTMLFooter(str_replace('class="footer"', 'style="font-size: 6.8pt; color: #7a7f89; border-top: 0.4pt solid #e3e5ea; padding-top: 5pt"', $footer));
        $m->WriteHTML($body);
        return $m->Output('', 'S');
    },
    'tcpdf' => function () use ($doc, $fonts, $cache) {
        // TCPDF needs fonts converted to its own format first (done once, then cached), and only
        // knows regular and bold. It has no @font-face, @page or position: fixed, so the margins
        // go through its API and the footer through its Footer() hook.
        $font = function (string $ttf) use ($fonts, $cache) {
            $name = strtolower(preg_replace('/[^A-Za-z]/', '', basename($ttf, '.ttf')));
            if (!is_file("$cache/tcpdf/$name.php")) {
                TCPDF_FONTS::addTTFfont("$fonts/$ttf", 'TrueTypeUnicode', '', 32, "$cache/tcpdf/");
            }
            return "$cache/tcpdf/$name.php";
        };
        $footer = '';
        $body = preg_replace_callback('#<div class="footer">(.*?</table>)\s*</div>#s', function ($m) use (&$footer) {
            $footer = $m[1];
            return '';
        }, preg_replace(['/@font-face\s*\{[^}]*\}/', '/@page\s*\{[^}]*\}/'], '', $doc));
        $pdf = new class ('P', 'pt', 'A4', true, 'UTF-8', false) extends TCPDF {
            public string $footerHtml = '';

            public function Footer(): void
            {
                $this->SetY(-46);
                $this->SetFont('figtree', '', 6.8);
                $this->SetTextColor(0x7a, 0x7f, 0x89);
                $this->writeHTML('<div style="border-top: 0.4pt solid #e3e5ea">' . $this->footerHtml . '</div>', false, false, true);
            }
        };
        $pdf->footerHtml = $footer;
        $pdf->AddFont('figtree', '', $font('Figtree-Regular.ttf'));
        $pdf->AddFont('figtree', 'B', $font('Figtree-Bold.ttf'));
        $pdf->setPrintHeader(false);
        $pdf->SetMargins(46, 44, 46);
        $pdf->SetAutoPageBreak(true, 60);
        $pdf->SetFont('figtree', '', 9);
        $pdf->AddPage();
        $pdf->writeHTML($body);
        return $pdf->Output('', 'S');
    },
};

$base = memory_get_usage();
$t = hrtime(true);
$pdf = $render();
$first = (hrtime(true) - $t) / 1e6;
$peak = (memory_get_peak_usage() - $base) / 1048576;

$times = [];
for ($i = 0; $i < $iterations; $i++) {
    $t = hrtime(true);
    $render();
    $times[] = (hrtime(true) - $t) / 1e6;
}
sort($times);
$tmp = $out ?? tempnam(sys_get_temp_dir(), 'yetipdf');
file_put_contents($tmp, $pdf);
$pages = (int) preg_replace('/.*Pages:\s+(\d+).*/s', '$1', (string) shell_exec('pdfinfo ' . escapeshellarg($tmp) . ' 2>/dev/null'));
if (!$out) {
    unlink($tmp);
}
echo json_encode([
    'engine' => $engine,
    'first_ms' => round($first, 1),
    'warm_ms' => $times ? round($times[intdiv(count($times), 2)], 1) : null,
    'peak_mb' => round($peak, 1),
    'kb' => round(strlen($pdf) / 1024, 1),
    'pages' => $pages,
]), "\n";
