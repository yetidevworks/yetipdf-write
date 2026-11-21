<?php

declare(strict_types=1);

namespace YetiPdf\Write\Tests\Unit;

use YetiPdf\Write\Text\Hyphenator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** hypher's own test cases (the hyphenation library Typst uses), run against the PHP port. */
final class HyphenatorTest extends TestCase
{
    public static function cases(): iterable
    {
        foreach ([
        ['en', 'hi'],
        ['en', 'wel-come'],
        ['en', 'walk-ing'],
        ['en', 'cap-tiVe'],
        ['en', 'pur-sue'],
        ['en', 'wHaT-eVeR'],
        ['en', 'bro-ken'],
        ['en', 'ex-ten-sive'],
        ['en', 'Prob-a-bil-ity'],
        ['en', 'rec-og-nize'],
        ['de', 'Baum'],
        ['de', 'ge-hen'],
        ['de', 'Ap-fel'],
        ['de', 'To-ma-te'],
        ['de', 'Ein-ga-be-auf-for-de-rung'],
        ['de', 'Fort-pflan-zungs-lem-ma'],
        ['de', 'stra-te-gie-er-hal-ten-den'],
        ['de', 'hübsch'],
        ['de', 'häss-lich'],
        ['de', 'über-zeu-gen-der'],
        ['fi', 'ää-ni-oi-keus'],
        ['fi', 'yli-o-pis-to'],
        ['fi', 'ra-vin-to-la'],
        ['fi', 'kah-vi'],
        ['fi', 'sa-na-kir-ja'],
        ['fi', 'lai-va'],
        ['fi', 'tie-to-ko-ne'],
        ['fi', 'kaa-ri'],
        ['el', 'δια-με-ρί-σμα-τα'],
        ['el', 'λα-τρευ-τός'],
        ['el', 'κά-τοι-κος'],
        ['ka', 'თა-რო'],
        ['ka', 'შეყ-ვა-ნა'],
        ['ka', 'კარ-ტო-ფი-ლი'],
        ['pl', 'wy-kształ-ciu-chy'],
        ['cs', 'po-ví-dá-me'],
        ['cs', 'nej-ja-s-něj-ší'],
        ['cs', 'br-něn-ský'],
        ['ml', 'വി-ദ്യാർ-ത്ഥി'],
        ['ml', 'പഠി-ക്കുക'],
        ['hi', 'वि-द्या-र्थी'],
        ['hi', 'पढ़-ना'],
        ] as [$lang, $hyphenated]) {
            yield "$lang $hyphenated" => [$lang, $hyphenated];
        }
    }

    #[DataProvider('cases')]
    public function testMatchesHypher(string $lang, string $hyphenated): void
    {
        $word = str_replace('-', '', $hyphenated);
        $chars = mb_str_split($word);
        $out = '';
        $points = Hyphenator::points($word, $lang);
        foreach ($chars as $i => $c) {
            $out .= (in_array($i, $points, true) ? '-' : '') . $c;
        }
        $this->assertSame($hyphenated, $out);
    }
}
