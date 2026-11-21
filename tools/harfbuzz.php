<?php

declare(strict_types=1);

// Records how HarfBuzz shapes tests/fixtures/shaping/corpus.txt (script, tab, text per line) in
// DejaVu Sans, and the Latin lines in Figtree too, into tests/fixtures/shaping/harfbuzz.json;
// ShapingTest checks YetiPDF Write gives the same glyphs, advances and mark offsets.
//   php tools/harfbuzz.php      (needs hb-shape)

$root = dirname(__DIR__);
$dir = "$root/tests/fixtures/shaping";
$fonts = ['DejaVuSans' => "$root/tests/fixtures/fonts/DejaVuSans.ttf", 'Figtree-Regular' => "$root/fonts/Figtree-Regular.ttf"];
// Script and language as hb-shape takes them, and the direction.
$props = ['arab' => ['Arab', 'ar', 'rtl'], 'hebr' => ['Hebr', 'he', 'rtl'], 'nko' => ['Nkoo', 'nqo', 'rtl'], 'latn' => ['Latn', 'en', 'ltr']];
$out = ['source' => trim((string) shell_exec('hb-shape --version | head -1')), 'cases' => []];
foreach (file("$dir/corpus.txt", FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    [$script, $text] = explode("\t", $line, 2);
    foreach ($fonts as $name => $font) {
        if ($name !== 'DejaVuSans' && $script !== 'latn') {
            continue;
        }
        [$sc, $lang, $dir2] = $props[$script];
        $json = shell_exec(sprintf('hb-shape --no-glyph-names --output-format=json --direction=%s --script=%s --language=%s %s %s',
            $dir2, $sc, $lang, escapeshellarg($font), escapeshellarg($text)));
        $glyphs = array_map(fn ($g) => [$g['g'], $g['ax'], $g['dx'], $g['dy']], json_decode((string) $json, true) ?? []);
        $out['cases'][] = ['font' => $name, 'script' => $script, 'text' => $text, 'glyphs' => $glyphs];
    }
}
file_put_contents("$dir/harfbuzz.json", json_encode($out, JSON_UNESCAPED_UNICODE) . "\n");
printf("%d cases from %s\n", count($out['cases']), $out['source']);
