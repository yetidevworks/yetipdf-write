<?php

declare(strict_types=1);

// Builds the data for the longer benchmark documents: benchmarks/docs/report.json (a long English
// report, justified and hyphenated) and benchmarks/docs/arabic.json (a right-to-left report in
// Arabic). The text is made up, from fixed word lists and sentences, so every run is the same.
//   php tools/bench-docs.php

$dir = dirname(__DIR__) . '/benchmarks/docs';
@mkdir($dir, 0775, true);
mt_srand(1912);
$pick = fn (array $a) => $a[mt_rand(0, count($a) - 1)];

// ---- The long report ----
$adj = ['regional', 'seasonal', 'reliable', 'independent', 'considerable', 'experimental', 'municipal', 'thorough', 'gradual', 'sustainable', 'temporary', 'unexpected', 'comprehensive', 'practical', 'collaborative', 'remarkable', 'administrative', 'environmental'];
$noun = ['committee', 'network', 'harbour', 'workshop', 'programme', 'infrastructure', 'survey', 'assessment', 'volunteers', 'cooperative', 'warehouse', 'neighbourhood', 'responsibilities', 'implementation', 'considerations', 'measurements', 'transportation', 'partnership', 'schedule', 'equipment', 'household', 'community', 'framework', 'agreement'];
$verb = ['improved', 'reviewed', 'extended', 'documented', 'reorganised', 'supported', 'simplified', 'established', 'evaluated', 'strengthened', 'coordinated', 'introduced', 'maintained', 'accelerated', 'reconsidered'];
$adv = ['steadily', 'carefully', 'considerably', 'unexpectedly', 'gradually', 'repeatedly', 'eventually', 'independently', 'substantially'];
$open = ['During the year', 'In the second quarter', 'After the spring survey', 'Across every district', 'Following the review', 'With the new schedule', 'Despite the delays', 'By the end of autumn'];
$sentence = function () use ($pick, $adj, $noun, $verb, $adv, $open): string {
    $s = match (mt_rand(0, 3)) {
        0 => sprintf('%s, the %s %s %s the %s %s', $pick($open), $pick($adj), $pick($noun), $pick($verb), $pick($adj), $pick($noun)),
        1 => sprintf('The %s %s was %s %s, and the %s %s followed', $pick($adj), $pick($noun), $pick($adv), $pick($verb), $pick($adj), $pick($noun)),
        2 => sprintf('Our %s %s %s the %s, while the %s %s', $pick($noun), $pick($adv), $pick($verb), $pick($noun), $pick($noun), $pick($verb)),
        default => sprintf('%s, %s %s were %s %s across the %s %s', $pick($open), mt_rand(12, 980), $pick($noun), $pick($adv), $pick($verb), $pick($adj), $pick($noun)),
    };
    return ucfirst($s) . '.';
};
$titles = ['Summary', 'Membership', 'Operations', 'Transport', 'Training', 'Partnerships', 'Finances', 'Housing', 'Water', 'Energy', 'Outreach', 'Facilities', 'Volunteers', 'Research', 'Planning'];
$sections = [];
for ($s = 0; $s < 54; $s++) {
    $paragraphs = [];
    for ($p = 0, $np = mt_rand(4, 6); $p < $np; $p++) {
        $paragraphs[] = implode(' ', array_map(fn () => $sentence(), range(1, mt_rand(5, 8))));
    }
    $table = null;
    if ($s % 3 === 2) {
        $table = ['head' => ['District', 'Spring', 'Summer', 'Autumn'], 'rows' => []];
        foreach (['North', 'Harbour', 'Old Town', 'Riverside', 'Hills'] as $d) {
            $table['rows'][] = [$d, number_format(mt_rand(1200, 98000)), number_format(mt_rand(1200, 98000)), number_format(mt_rand(1200, 98000))];
        }
    }
    $sections[] = ['title' => ($s + 1) . '. ' . $titles[$s % count($titles)] . ($s >= count($titles) ? ' (continued)' : ''), 'paragraphs' => $paragraphs, 'table' => $table];
}
$report = ['title' => 'Annual Report of the Fernvale Cooperative', 'subtitle' => 'A made-up organisation, for benchmarking only', 'sections' => $sections];
file_put_contents("$dir/report.json", json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n");

// ---- The Arabic report ----
$ar = [
    'تواصل الجمعية التعاونية توسيع خدماتها في المدن الساحلية والمناطق الريفية على حدٍّ سواء.',
    'ارتفع عدد الأعضاء المسجلين خلال العام الماضي بنسبة ملحوظة مقارنةً بالأعوام السابقة.',
    'ركّز فريق العمل على تحسين جودة المنتجات وتقليل تكاليف النقل والتخزين.',
    'أطلقنا برنامجًا تدريبيًا جديدًا للمزارعين الشباب يجمع بين المعرفة التقليدية والتقنيات الحديثة.',
    'ساهمت الشراكات مع المدارس المحلية في نشر الوعي بأهمية الغذاء الصحي.',
    'تشير الأرقام الأولية إلى نموّ المبيعات في الأسواق الأسبوعية وفي المتجر الإلكتروني.',
    'نعمل حاليًا على إنشاء مستودع جديد قرب الميناء لتسهيل التصدير إلى الدول المجاورة.',
    'يبقى الحفاظ على المياه أولويةً رئيسية في جميع مشاريعنا الزراعية.',
    'شارك أكثر من مئتي متطوع في حملات التشجير وتنظيف الشواطئ هذا الموسم.',
    'نشكر جميع الأعضاء والشركاء على ثقتهم ودعمهم المستمر.',
    'ستُعرض الخطة الاستراتيجية للسنوات الخمس القادمة على الجمعية العامة في الربيع المقبل.',
    'حرصنا على أن تكون التقارير المالية واضحةً ومتاحةً لكل من يرغب في الاطلاع عليها.',
];
$arTitles = ['الملخص', 'العضوية', 'الإنتاج', 'التدريب', 'الشراكات', 'المبيعات', 'المياه والبيئة', 'الخطة القادمة'];
$arSections = [];
foreach ($arTitles as $i => $t) {
    $paragraphs = [];
    for ($p = 0, $np = mt_rand(3, 5); $p < $np; $p++) {
        $paragraphs[] = implode(' ', array_map(fn () => $pick($ar), range(1, mt_rand(4, 7))));
    }
    $table = null;
    if ($i % 3 === 1) {
        $table = ['head' => ['المنطقة', 'الربيع', 'الصيف', 'الخريف'], 'rows' => []];
        foreach (['الشمال', 'الميناء', 'المدينة القديمة', 'ضفة النهر', 'التلال'] as $d) {
            $table['rows'][] = [$d, number_format(mt_rand(1200, 98000)), number_format(mt_rand(1200, 98000)), number_format(mt_rand(1200, 98000))];
        }
    }
    $arSections[] = ['title' => ($i + 1) . '. ' . $t, 'paragraphs' => $paragraphs, 'table' => $table];
}
$arabic = ['title' => 'التقرير السنوي لجمعية الوادي الأخضر التعاونية', 'subtitle' => 'جمعية من وحي الخيال، لقياس الأداء فقط', 'sections' => $arSections];
file_put_contents("$dir/arabic.json", json_encode($arabic, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n");

$words = fn (array $doc) => array_sum(array_map(fn ($s) => array_sum(array_map(fn ($p) => count(preg_split('/\s+/u', $p)), $s['paragraphs'])), $doc['sections']));
printf("report: %d sections, %d words; arabic: %d sections, %d words\n", count($sections), $words($report), count($arSections), $words($arabic));
