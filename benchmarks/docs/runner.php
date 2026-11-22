<?php

declare(strict_types=1);

// The longer benchmark documents (report, arabic), included by ../runner.php with $engine,
// $iterations, $out, $docName, $root, $fonts and $cache set. Plain documents with no running
// headers or footers, so each engine gets its simplest path.

$data = json_decode(file_get_contents(__DIR__ . "/$docName.json"), true);
$arabic = $docName === 'arabic';
$twig = new Twig\Environment(new Twig\Loader\FilesystemLoader(__DIR__), ['autoescape' => 'html', 'cache' => $cache . '/twig', 'auto_reload' => true]);
$doc = $twig->render($engine === 'yetipdf' ? "$docName.xml.twig" : "$docName.html.twig", ['d' => $data, 'fonts' => $fonts]);
$dejavu = $root . '/tests/fixtures/fonts';

$render = match ($engine) {
    'yetipdf' => fn () => (new YetiPdf\Write\YetiPdfWrite(fonts: [$fonts, $dejavu], cache: $cache . '/yetipdf'))->render($doc),
    'dompdf' => function () use ($doc, $fonts, $cache, $arabic) {
        $o = new Dompdf\Options();
        $o->setChroot([$fonts, __DIR__]);
        $o->setFontDir($cache . '/dompdf');
        $o->setFontCache($cache . '/dompdf');
        $o->setIsRemoteEnabled(false);
        $o->setDefaultFont($arabic ? 'DejaVu Sans' : 'Figtree');
        $d = new Dompdf\Dompdf($o);
        $d->loadHtml($doc);
        $d->setPaper('A4');
        $d->render();
        return $d->output();
    },
    'mpdf' => function () use ($doc, $fonts, $cache, $arabic) {
        $defaults = (new Mpdf\Config\ConfigVariables())->getDefaults();
        $fontData = (new Mpdf\Config\FontVariables())->getDefaults()['fontdata'];
        $m = new Mpdf\Mpdf([
            'tempDir' => $cache . '/mpdf',
            'fontDir' => array_merge($defaults['fontDir'], [$fonts]),
            'fontdata' => $fontData + ['figtree' => ['R' => 'Figtree-Regular.ttf', 'B' => 'Figtree-Bold.ttf']],
            'default_font' => $arabic ? 'dejavusans' : 'figtree',
            'format' => 'A4',
            'margin_top' => 56 * 25.4 / 72, 'margin_left' => 56 * 25.4 / 72, 'margin_right' => 56 * 25.4 / 72, 'margin_bottom' => 56 * 25.4 / 72,
        ]);
        $m->WriteHTML(preg_replace('/@page\s*\{[^}]*\}/', '', $doc));
        return $m->Output('', 'S');
    },
    'tcpdf' => function () use ($doc, $fonts, $cache, $arabic) {
        $pdf = new TCPDF('P', 'pt', 'A4', true, 'UTF-8', false);
        if ($arabic) {
            $pdf->SetFont('dejavusans', '', 11);
            $pdf->setRTL(true);
        } else {
            $font = function (string $ttf) use ($fonts, $cache) {
                $name = strtolower(preg_replace('/[^A-Za-z]/', '', basename($ttf, '.ttf')));
                if (!is_file("$cache/tcpdf/$name.php")) {
                    TCPDF_FONTS::addTTFfont("$fonts/$ttf", 'TrueTypeUnicode', '', 32, "$cache/tcpdf/");
                }
                return "$cache/tcpdf/$name.php";
            };
            $pdf->AddFont('figtree', '', $font('Figtree-Regular.ttf'));
            $pdf->AddFont('figtree', 'B', $font('Figtree-Bold.ttf'));
            $pdf->SetFont('figtree', '', 10);
        }
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(56, 56, 56);
        $pdf->SetAutoPageBreak(true, 56);
        $pdf->AddPage();
        $pdf->writeHTML(preg_replace(['/@font-face\s*\{[^}]*\}/', '/@page\s*\{[^}]*\}/'], '', $doc));
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
    'doc' => $docName,
    'first_ms' => round($first, 1),
    'warm_ms' => $times ? round($times[intdiv(count($times), 2)], 1) : null,
    'peak_mb' => round($peak, 1),
    'kb' => round(strlen($pdf) / 1024, 1),
    'pages' => $pages,
]), "\n";
