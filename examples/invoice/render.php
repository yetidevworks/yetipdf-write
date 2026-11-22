<?php

declare(strict_types=1);

// Renders the example invoice: Twig builds the markup, YetiPDF Write lays it out and writes the PDF.
//   php examples/invoice/render.php [out.pdf]

require __DIR__ . '/../../vendor/autoload.php';

$root = dirname(__DIR__, 2);
$data = json_decode(file_get_contents(__DIR__ . '/invoice.json'), true);

$t0 = hrtime(true);
$twig = new Twig\Environment(new Twig\Loader\FilesystemLoader(__DIR__), ['autoescape' => 'html', 'cache' => $root . '/cache/twig', 'auto_reload' => true]);
$markup = $twig->render('invoice.xml.twig', ['d' => $data]);
$t1 = hrtime(true);
$pdf = (new YetiPdf\Write\YetiPdfWrite(fonts: [$root . '/fonts'], assets: [__DIR__], cache: $root . '/cache'))->render($markup);
$t2 = hrtime(true);

$out = $argv[1] ?? $root . '/out/invoice.pdf';
@mkdir(dirname($out), 0775, true);
file_put_contents($out, $pdf);
file_put_contents(preg_replace('/\.pdf$/', '.xml', $out), $markup);
printf("twig %.1f ms, yetipdf %.1f ms, %s KB, peak %.1f MB → %s\n", ($t1 - $t0) / 1e6, ($t2 - $t1) / 1e6, number_format(strlen($pdf) / 1024, 1), memory_get_peak_usage() / 1048576, $out);
