<?php

declare(strict_types=1);

// Builds the example invoice's data and pictures: examples/invoice/invoice.json, logo.svg and
// chart.svg. Everything in it is made up (the studio, the client, the work and the numbers).
//   php tools/invoice-example.php

$dir = dirname(__DIR__) . '/examples/invoice';
mt_srand(2026);

$accent = '#0f766e';
$categories = [
    'design' => ['label' => 'Design', 'color' => $accent],
    'build' => ['label' => 'Development', 'color' => '#4fae9f'],
    'care' => ['label' => 'Support', 'color' => '#b5ddd5'],
];
$services = [
    ['Storefront redesign', 'design'],
    ['Gift card checkout', 'build'],
    ['Shipping rate rules', 'build'],
    ['Product search tuning', 'build'],
    ['Email template refresh', 'design'],
    ['Payment retry handling', 'build'],
    ['Monthly support plan', 'care'],
    ['Page speed audit', 'care'],
    ['Product photo retouching', 'design'],
    ['Wholesale order form', 'build'],
];
$rates = [90, 125, 160];
$weeks = ['Oct 1–4', 'Oct 5–11', 'Oct 12–18', 'Oct 19–25', 'Oct 26–31'];

$money = fn (float $v) => '$' . number_format($v, 2);
$lines = [];
$subtotal = 0.0;
$byWeek = array_fill(0, count($weeks), array_fill_keys(array_keys($categories), 0.0));
for ($i = 0; $i < 40; $i++) {
    [$name, $cat] = $services[$i % 10];
    $part = intdiv($i, 10) + 1;
    $qty = mt_rand(6, 120) / 4;
    $rate = $rates[$i % 3];
    $amount = $qty * $rate;
    $subtotal += $amount;
    $byWeek[intdiv($i, 8)][$cat] += $qty; // the lines run in order, eight a week
    $lines[] = [
        'description' => $name . ($part > 1 ? " (part $part)" : ''),
        'detail' => $i % 4 === 0 ? 'Includes review, testing and launch support' : null,
        'qty' => number_format($qty, 2),
        'unit' => 'h',
        'price' => $money($rate),
        'amount' => $money($amount),
        'raw' => $amount,
    ];
}

$totalHours = 0.0;
$chartWeeks = [];
foreach ($weeks as $w => $label) {
    $hours = array_sum($byWeek[$w]);
    $totalHours += $hours;
    $chartWeeks[] = ['label' => $label, 'hours' => number_format($hours, 2) . ' h'];
}
$legend = [];
foreach ($categories as $key => $c) {
    $legend[] = ['label' => $c['label'], 'color' => $c['color'], 'hours' => number_format(array_sum(array_column($byWeek, $key)), 2) . ' h'];
}

$data = [
    'number' => 'QS-2026-0417',
    'client' => 'Brightwater Outfitters',
    'paper' => 'a4',
    'generated' => 'Nov 20, 2026',
    'brand' => [
        'name' => 'Quillmoor Studio',
        'logo' => 'logo.svg',
        'accent' => $accent,
        'accent_text' => '#0d6b63',
        'details' => ['Quillmoor Studio LLC', '88 Lantern Row', 'Fernvale, OR 97399', 'billing@quillmoor.example'],
        'note' => ['Thank you for your business.', 'Payment due within 30 days.'],
    ],
    'bill_to' => ['Brightwater Outfitters', 'Attn: Accounts Payable', '2600 Riverside Parkway', 'Lakeport, WA 98012'],
    'email' => 'ap@brightwater.example',
    'mark' => ['label' => 'Overdue', 'tone' => 'overdue'],
    'facts' => [
        ['label' => 'Issued', 'value' => 'Oct 31, 2026', 'sub' => '', 'strong' => false, 'tone' => null],
        ['label' => 'Due', 'value' => 'Nov 30, 2026', 'sub' => 'Net 30', 'strong' => false, 'tone' => null],
        ['label' => 'Period', 'value' => 'Oct 1 – Oct 31, 2026', 'sub' => '31 days', 'strong' => false, 'tone' => null],
        ['label' => 'Amount due', 'value' => $money($subtotal), 'sub' => 'USD', 'strong' => true, 'tone' => 'overdue'],
    ],
    'chart' => [
        'title' => 'Hours by week',
        'total' => number_format($totalHours, 2) . ' hours',
        'src' => 'chart.svg',
        'weeks' => $chartWeeks,
        'legend' => $legend,
    ],
    'lines' => $lines,
    'totals' => [
        ['label' => 'Subtotal', 'value' => $money($subtotal), 'kind' => 'line'],
        ['label' => 'Tax (0%)', 'value' => '$0.00', 'kind' => 'line'],
        ['label' => 'Total', 'value' => $money($subtotal), 'kind' => 'total'],
    ],
    'balance' => ['label' => 'Balance due', 'value' => $money($subtotal), 'sub' => 'Pay online at pay.example.com/i/qs0417', 'tone' => 'overdue'],
    'notes' => ['Hours are rounded to the nearest quarter hour.', 'Questions? Reply to this email.'],
    'watermark' => null,
];
file_put_contents("$dir/invoice.json", json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");

// The logo: a quill on a rounded square (the studio's name is set as text beside it).
file_put_contents("$dir/logo.svg", <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" width="64" height="64" viewBox="0 0 64 64">
  <rect width="64" height="64" rx="15" fill="$accent"/>
  <path d="M48 12C30 14 19 27 17 47c13-1 27-12 31-35z" fill="#ffffff"/>
  <path d="M17 47L36 28" stroke="$accent" stroke-width="2.6" stroke-linecap="round" fill="none"/>
  <path d="M13 51l5-5" stroke="#ffffff" stroke-width="3.4" stroke-linecap="round" fill="none"/>
</svg>

SVG);

// The chart: one stacked column per week, by kind of work. Labels and the legend are set as text
// in the document, under and beside it (text inside SVG isn't drawn by every engine).
$w = 600;
$h = 96;
$base = 92;
$slot = $w / count($weeks);
$bar = 64;
$max = max(array_map('array_sum', $byWeek));
$scale = 80 / $max;
$svg = "<svg xmlns=\"http://www.w3.org/2000/svg\" width=\"$w\" height=\"$h\" viewBox=\"0 0 $w $h\">\n";
foreach ([0.25, 0.5, 0.75, 1.0] as $f) {
    $y = round($base - 80 * $f, 2);
    $svg .= "  <line x1=\"0\" y1=\"$y\" x2=\"$w\" y2=\"$y\" stroke=\"#e3e5ea\" stroke-width=\"1\" stroke-dasharray=\"3 3\"/>\n";
}
foreach ($byWeek as $i => $hours) {
    $x = round($i * $slot + ($slot - $bar) / 2, 2);
    $y = $base;
    foreach ($categories as $key => $c) {
        $hh = round($hours[$key] * $scale, 2);
        if ($hh <= 0) {
            continue;
        }
        $y = round($y - $hh, 2);
        $svg .= "  <rect x=\"$x\" y=\"$y\" width=\"$bar\" height=\"$hh\" rx=\"2\" fill=\"{$c['color']}\" stroke=\"#ffffff\" stroke-width=\"1\"/>\n";
    }
}
$svg .= "  <line x1=\"0\" y1=\"$base\" x2=\"$w\" y2=\"$base\" stroke=\"#c9ccd3\" stroke-width=\"1.2\"/>\n</svg>\n";
file_put_contents("$dir/chart.svg", $svg);

printf("%d lines, %s, %.1f hours\n", count($lines), $money($subtotal), $totalHours);
