<?php

declare(strict_types=1);

// Regenerates the Typst reference renderings for tests/fixtures/svg, and reports how far YetiPDF Write is from them.
//   php tools/svg-reference.php [--compare] [--diff=<dir>] [name ...]
// Needs typst (which draws SVG through resvg) and poppler's pdftoppm. Each <name>.svg is placed at
// the same size by both engines; the Typst PDF is written to reference/<name>.pdf and SvgTest
// rasterises both and counts the differing pixels. --compare only measures (no typst run);
// --diff also writes side-by-side PNGs (Typst | YetiPDF Write | difference) with ImageMagick.

require __DIR__ . '/../vendor/autoload.php';

use YetiPdf\Write\Tests\Support\Raster;

$dir = dirname(__DIR__) . '/tests/fixtures/svg';
$args = array_slice($argv, 1);
$compare = in_array('--compare', $args, true);
$diffDir = null;
foreach ($args as $a) {
    if (str_starts_with($a, '--diff=')) {
        $diffDir = substr($a, 7);
        @mkdir($diffDir, 0777, true);
    }
}
$names = array_values(array_filter($args, fn ($a) => !str_starts_with($a, '--'))) ?: array_map(fn ($f) => basename($f, '.svg'), glob("$dir/*.svg"));
@mkdir("$dir/reference");
$tmp = sys_get_temp_dir();
foreach ($names as $name) {
    $ref = "$dir/reference/$name.pdf";
    if (!$compare) {
        $cmd = sprintf('typst compile --root %s - %s 2>&1', escapeshellarg($dir), escapeshellarg($ref));
        $p = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w']], $pipes);
        fwrite($pipes[0], Raster::typst("$dir/$name.svg"));
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]);
        if (proc_close($p) !== 0) {
            fwrite(STDERR, "$name: typst failed\n$out\n");
            exit(1);
        }
    }
    $mine = "$tmp/yetipdf-svg-$name.pdf";
    $t = microtime(true);
    Raster::yetipdf("$dir/$name.svg", $mine);
    $ms = (microtime(true) - $t) * 1000;
    $a = Raster::render($ref, 150);
    $b = Raster::render($mine, 150);
    $bad = Raster::diff($a, $b);
    printf("%-16s %4dx%-4d  %6d px differ (%.3f%%)  yetipdf %.1fms\n", $name, $a['w'], $a['h'], $bad, 100 * $bad / ($a['w'] * $a['h']), $ms);
    if ($diffDir !== null) {
        exec(sprintf('pdftoppm -r 150 -png -singlefile %s %s', escapeshellarg($ref), escapeshellarg("$tmp/ref-$name")));
        exec(sprintf('pdftoppm -r 150 -png -singlefile %s %s', escapeshellarg($mine), escapeshellarg("$tmp/mine-$name")));
        exec(sprintf('magick %1$s %2$s \( %1$s %2$s -compose difference -composite -negate \) +append -depth 8 PNG32:%3$s',
            escapeshellarg("$tmp/ref-$name.png"), escapeshellarg("$tmp/mine-$name.png"), escapeshellarg("$diffDir/$name.png")));
    }
}
