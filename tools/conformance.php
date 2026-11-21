<?php

declare(strict_types=1);

// Regenerates the expected word positions for tests/Conformance from Typst, the reference.
//   php tools/conformance.php [name ...]
// Needs typst and pdftotext. Each <name>.typ is compiled with the repo's fonts and its words
// are written to <name>.json; ConformanceTest renders <name>.xml and compares. A .typ that
// starts with "// raster" keeps Typst's PDF as <name>.pdf, and the test compares pixels instead
// (for text whose copy text the engines write differently, like vowelled Arabic).

require __DIR__ . '/../vendor/autoload.php';

use YetiPdf\Write\Tests\Support\Words;

$root = dirname(__DIR__);
$dir = $root . '/tests/Conformance';
$names = array_slice($argv, 1) ?: array_map(fn ($f) => basename($f, '.typ'), glob("$dir/*.typ"));
foreach ($names as $name) {
    $pdf = sys_get_temp_dir() . "/yetipdf-conformance-$name.pdf";
    $cmd = sprintf('typst compile --root %s --font-path %s --font-path %s --ignore-system-fonts --ignore-embedded-fonts --no-pdf-tags %s %s 2>&1',
        escapeshellarg($root . '/tests'), escapeshellarg($root . '/fonts'), escapeshellarg($root . '/tests/fixtures/fonts'), escapeshellarg("$dir/$name.typ"), escapeshellarg($pdf));
    exec($cmd, $out, $code);
    if ($code !== 0) {
        fwrite(STDERR, "$name: typst failed\n" . implode("\n", $out) . "\n");
        exit(1);
    }
    $words = Words::read($pdf);
    $raster = str_starts_with((string) file_get_contents("$dir/$name.typ"), '// raster');
    if ($raster) {
        copy($pdf, "$dir/$name.pdf");
    }
    file_put_contents("$dir/$name.json", json_encode(['source' => 'typst ' . trim((string) shell_exec('typst --version')), 'raster' => $raster, 'pages' => $words], JSON_UNESCAPED_UNICODE) . "\n");
    printf("%-22s %d page(s), %d words\n", $name, count($words), array_sum(array_map('count', $words)));
}
