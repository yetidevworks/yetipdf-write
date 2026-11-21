<?php

// Runs Unicode's full bidi conformance files against YetiPdf\Write\Text\Bidi:
//   php tests/fixtures/bidi/full.php <dir with BidiCharacterTest.txt and BidiTest.txt>

require dirname(__DIR__, 3) . '/vendor/autoload.php';
require dirname(__DIR__, 2) . '/Unit/BidiTest.php';

$dir = $argv[1] ?? exit("usage: php tests/fixtures/bidi/full.php <ucd dir>\n");
foreach (['characterCases' => 'BidiCharacterTest.txt', 'classCases' => 'BidiTest.txt'] as $method => $file) {
    $pass = $total = 0;
    foreach (YetiPdf\Write\Tests\Unit\BidiTest::$method("$dir/$file") as [$types, $cps, $base, $paragraph, $levels, $order]) {
        $total++;
        $pass += (int) (YetiPdf\Write\Tests\Unit\BidiTest::resolve($types, $cps, $base, $paragraph) === [$levels, $order]);
    }
    echo "$file: $pass / $total\n";
}
