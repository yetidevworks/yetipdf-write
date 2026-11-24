<?php

declare(strict_types=1);

namespace YetiPdf\Write\Font;

use YetiPdf\Write\PdfException;

/**
 * One font file: its cached metrics plus the glyph lookup steps YetiPDF Write does itself
 * (cmap lookup, substitutions through Shaper, pair kerning, mark attachment).
 */
final class Face
{
    public readonly int $upem;
    public readonly string $family;
    public readonly int $weight;
    public readonly bool $italic;
    /** Advances per glyph id, in font units. */
    public readonly array $advances;

    private array $kernCache = [];
    /** Short positioned ASCII runs, bounded to 256 entries of at most 64 glyphs. */
    private array $asciiRuns = [];
    private ?float $lastAsciiSize = null;
    private ?float $lastAsciiTracking = null;
    private ?array $lastAsciiFeatures = null;
    private ?string $lastAsciiLang = null;
    private ?string $lastAsciiContext = null;
    private ?string $lastAsciiPrefix = null;
    private ?array $markLookups = null;

    public function __construct(public readonly array $m, public readonly string $path, private readonly ?string $cacheFile = null)
    {
        $this->upem = $m['unitsPerEm'];
        $this->family = $m['family'];
        $this->weight = $m['weight'];
        $this->italic = $m['italic'];
        $this->advances = $m['advances'];
    }

    public static function load(string $path, ?string $cacheDir): self
    {
        if (!is_file($path)) {
            throw new PdfException("Font file not found: $path");
        }
        $file = null;
        if ($cacheDir !== null) {
            $file = $cacheDir . '/font-' . md5(TrueTypeParser::VERSION . '|' . $path . '|' . filemtime($path) . '|' . filesize($path)) . '.php';
            if (is_file($file)) {
                $m = include $file;
                if (is_array($m)) {
                    return new self($m, $path, $file);
                }
            }
        }
        $m = \YetiPdf\Write\Guard::run('Font ' . basename($path), fn () => (new TrueTypeParser((string) file_get_contents($path)))->parse());
        if ($file !== null) {
            if (!is_dir($cacheDir)) {
                @mkdir($cacheDir, 0775, true);
            }
            self::store($file, $m);
        }
        return new self($m, $path, $file);
    }

    private static function store(string $file, array $data): void
    {
        $tmp = $file . '.' . bin2hex(random_bytes(6)) . '.tmp';
        if (@file_put_contents($tmp, '<?php return ' . self::export($data) . ";\n") !== false) {
            @rename($tmp, $file);
        }
    }

    /** Compact PHP literal serializer for font caches (smaller files and faster cold opcache compile than var_export). */
    private static function export(mixed $v): string
    {
        if (is_int($v)) {
            return (string) $v;
        }
        if (is_array($v)) {
            if ($v === []) {
                return '[]';
            }
            $parts = [];
            if (array_is_list($v)) {
                foreach ($v as $item) {
                    $parts[] = self::export($item);
                }
            } else {
                foreach ($v as $k => $item) {
                    $parts[] = (is_int($k) ? $k : var_export($k, true)) . '=>' . self::export($item);
                }
            }
            return '[' . implode(',', $parts) . ']';
        }
        if (is_bool($v)) {
            return $v ? 'true' : 'false';
        }
        if ($v === null) {
            return 'null';
        }
        return var_export($v, true);
    }

    /** The mark attachment lookups (TrueTypeParser::marks()), read when text first has marks; cached beside the metrics. */
    private function markLookups(): array
    {
        if ($this->markLookups !== null) {
            return $this->markLookups;
        }
        $file = $this->cacheFile === null ? null : substr($this->cacheFile, 0, -4) . '-marks.php';
        if ($file !== null && is_file($file) && is_array($cached = include $file)) {
            return $this->markLookups = $cached;
        }
        $lookups = \YetiPdf\Write\Guard::run('Font ' . basename($this->path), fn () => (new TrueTypeParser((string) file_get_contents($this->path)))->marks());
        if ($file !== null) {
            self::store($file, $lookups);
        }
        return $this->markLookups = $lookups;
    }

    public function capHeight(): float
    {
        return $this->m['capHeight'] / $this->upem;
    }

    public function ascender(): float
    {
        return $this->m['ascender'] / $this->upem;
    }

    public function descender(): float
    {
        return $this->m['descender'] / $this->upem;
    }

    private ?bool $ascii = null;

    /** Whether the face has a glyph for every printable ASCII character. */
    public function coversAscii(): bool
    {
        if ($this->ascii === null) {
            if (isset($this->m['coversAscii'])) {
                return $this->ascii = $this->m['coversAscii'];
            }
            $this->ascii = true;
            for ($c = 0x20; $c < 0x7F; $c++) {
                if (!isset($this->m['cmap'][$c])) {
                    $this->ascii = false;
                    break;
                }
            }
        }
        return $this->ascii;
    }

    public function glyph(int $cp): int
    {
        return $this->m['cmap'][$cp] ?? 0;
    }

    /** OpenType script tags for the scripts PCRE names (HarfBuzz's newer tag first). */
    private const SCRIPTS = [
        'Latin' => ['latn'], 'Greek' => ['grek'], 'Cyrillic' => ['cyrl'], 'Armenian' => ['armn'], 'Georgian' => ['geor'],
        'Hebrew' => ['hebr'], 'Arabic' => ['arab'], 'Syriac' => ['syrc'], 'Thaana' => ['thaa'], 'Nko' => ['nko '],
        'Mongolian' => ['mong'], 'Mandaic' => ['mand'], 'Manichaean' => ['mani'], 'Psalter_Pahlavi' => ['phlp'], 'Adlam' => ['adlm'],
        'Hanifi_Rohingya' => ['rohg'], 'Sogdian' => ['sogd'], 'Chorasmian' => ['chrs'], 'Thai' => ['thai'], 'Lao' => ['lao '],
        'Han' => ['hani'], 'Hiragana' => ['kana'], 'Katakana' => ['kana'], 'Hangul' => ['hang'], 'Devanagari' => ['dev2', 'deva'],
        'Bengali' => ['bng2', 'beng'], 'Gurmukhi' => ['gur2', 'guru'], 'Gujarati' => ['gjr2', 'gujr'], 'Tamil' => ['tml2', 'taml'],
        'Telugu' => ['tel2', 'telu'], 'Kannada' => ['knd2', 'knda'], 'Malayalam' => ['mlm2', 'mlym'], 'Ethiopic' => ['ethi'],
        'Tibetan' => ['tibt'], 'Khmer' => ['khmr'], 'Myanmar' => ['mym2', 'mymr'], 'Cherokee' => ['cher'], 'Tifinagh' => ['tfng'],
        'Canadian_Aboriginal' => ['cans'], 'Runic' => ['runr'], 'Ogham' => ['ogam'], 'Braille' => ['brai'],
    ];

    /** Scripts whose letters join (HarfBuzz's Arabic shaper). */
    private const JOINING = ['arab', 'syrc', 'nko ', 'mong', 'mand', 'mani', 'phlp', 'adlm', 'rohg', 'sogd', 'chrs'];

    /** OpenType language system tags for BCP 47 languages (HarfBuzz's, for the ones fonts tend to have). */
    private const LANGS = [
        'ar' => 'ARA ', 'fa' => 'FAR ', 'ur' => 'URD ', 'sd' => 'SND ', 'ku' => 'KUR ', 'ps' => 'PAS ', 'ug' => 'UYG ',
        'ks' => 'KSH ', 'pa' => 'PAN ', 'dv' => 'DIV ', 'he' => 'IWR ', 'yi' => 'JII ', 'sr' => 'SRB ', 'mk' => 'MKD ',
        'bg' => 'BGR ', 'ru' => 'RUS ', 'uk' => 'UKR ', 'be' => 'BEL ', 'tr' => 'TRK ', 'az' => 'AZE ', 'crh' => 'CRT ',
        'tt' => 'TAT ', 'kk' => 'KAZ ', 'ba' => 'BSH ', 'cv' => 'CHU ', 'ro' => 'ROM ', 'mo' => 'MOL ', 'ca' => 'CAT ',
        'es' => 'ESP ', 'gl' => 'GAL ', 'nl' => 'NLD ', 'de' => 'DEU ', 'fr' => 'FRA ', 'it' => 'ITA ', 'pl' => 'PLK ',
        'pt' => 'PTG ', 'sv' => 'SVE ', 'da' => 'DAN ', 'fi' => 'FIN ', 'nb' => 'NOR ', 'no' => 'NOR ', 'cs' => 'CSY ',
        'sk' => 'SKY ', 'hu' => 'HUN ', 'el' => 'ELL ', 'ja' => 'JAN ', 'ko' => 'KOR ', 'zh' => 'ZHS ', 'vi' => 'VIT ',
        'hi' => 'HIN ', 'mr' => 'MAR ', 'ne' => 'NEP ', 'en' => 'ENG ', 'se' => 'NSM ', 'smn' => 'ISM ', 'sms' => 'SKS ',
        'sma' => 'SSM ', 'smj' => 'LSM ', 'sjd' => 'KSM ', 'ga' => 'IRI ', 'cy' => 'WEL ', 'is' => 'ISL ', 'lt' => 'LTH ',
        'lv' => 'LVI ', 'et' => 'ETI ', 'hr' => 'HRV ', 'sl' => 'SLV ', 'bs' => 'BOS ', 'sq' => 'SQI ', 'mt' => 'MTS ',
    ];

    private ?Shaper $shaper = null;
    private array $asciiGlyphs = [];
    private array $asciiPlans = [];
    private array $fullGlyphs = [];
    /** @var array<string, list<string>> */
    private static array $charScript = [];
    /** @var array<int, bool> */
    private static array $isDI = [];
    /** @var array<int, bool> */
    private static array $isMn = [];

    private function shaper(): Shaper
    {
        return $this->shaper ??= new Shaper($this->m['gsub'] ?? [], $this->m['classes'] ?? [], $this->m['markClasses'] ?? [], $this->m['markSets'] ?? []);
    }

    /**
     * The script tags to try for text: from its first letter that belongs to one script, else
     * from $context (the script of the text around it, as Typst's runs carry it).
     */
    private static function scriptTags(string $text, ?string $context = null): array
    {
        if (!preg_match('/[^\p{Common}\p{Inherited}]/u', $text, $m) && ($context === null || !preg_match('/[^\p{Common}\p{Inherited}]/u', $context, $m))) {
            return [];
        }
        $ch = $m[0];
        if (isset(self::$charScript[$ch])) {
            return self::$charScript[$ch];
        }
        foreach (self::SCRIPTS as $name => $tags) {
            if (preg_match('/^\p{' . $name . '}$/u', $ch)) {
                return self::$charScript[$ch] = $tags;
            }
        }
        return self::$charScript[$ch] = [];
    }

    private static function langTags(?string $lang): array
    {
        if ($lang === null) {
            return [];
        }
        $l = strtolower(explode('-', str_replace('_', '-', $lang))[0]);
        return isset(self::LANGS[$l]) ? [self::LANGS[$l]] : [];
    }

    /** The features YetiPDF Write asks for, as the shaper takes them: ligatures turn on liga and clig. */
    private static function on(array $features): array
    {
        $on = [];
        foreach ($features as $f) {
            if ($f === 'liga') {
                $on[] = 'liga';
                $on[] = 'clig';
            } else {
                $on[] = $f;
            }
        }
        return $on;
    }

    /**
     * Direct ASCII shaping + simple kerning: builds the positioned glyph list [gid, adv, ch]
     * in one pass without an intermediate glyphs() array or per-pair kern() calls.
     * Returns [glyphs, totalWidth], or null if the run must fall back to the full path.
     *
     * @param list<string> $features
     * @return ?array{0: list<array{0: int, 1: float, 2: string}>, 1: float}
     */
    public function asciiGlyphRun(string $text, array $features, ?string $lang, ?string $context, float $size, float $tracking): ?array
    {
        // Binary floats keep distinct sizes and tracking distinct at any PHP precision setting.
        $key = null;
        if (strlen($text) <= 64) {
            if ($size === $this->lastAsciiSize
                && $tracking === $this->lastAsciiTracking
                && $features === $this->lastAsciiFeatures
                && $lang === $this->lastAsciiLang
                && $context === $this->lastAsciiContext
            ) {
                $prefix = $this->lastAsciiPrefix;
            } else {
                $prefix = null;
                if (count($features) <= 8 && strlen($lang ?? '') <= 32 && strlen($context ?? '') <= 64) {
                    $fp = count($features) . ':';
                    foreach ($features as $f) {
                        $fp .= strlen($f) . ':' . $f;
                    }
                    $lp = $lang === null ? 'n' : 's' . strlen($lang) . ':' . $lang;
                    $cp = $context === null ? 'n' : 's' . strlen($context) . ':' . $context;
                    $meta = $fp . '|' . $lp . '|' . $cp;
                    if (strlen($meta) <= 448) {
                        $prefix = pack('dd', $size, $tracking) . $meta . ':';
                    }
                }
                $this->lastAsciiSize = $size;
                $this->lastAsciiTracking = $tracking;
                $this->lastAsciiFeatures = $features;
                $this->lastAsciiLang = $lang;
                $this->lastAsciiContext = $context;
                $this->lastAsciiPrefix = $prefix;
            }
            if ($prefix !== null) {
                $key = $prefix . $text;
                if (array_key_exists($key, $this->asciiRuns)) {
                    return $this->asciiRuns[$key];
                }
            }
        }
        $run = $this->computeAsciiGlyphRun($text, $features, $lang, $context, $size, $tracking);
        if ($key !== null && ($run === null || count($run[0]) <= 64)) {
            if (count($this->asciiRuns) >= 256) {
                $this->asciiRuns = [];
            }
            $this->asciiRuns[$key] = $run;
        }
        return $run;
    }

    private function computeAsciiGlyphRun(string $text, array $features, ?string $lang, ?string $context, float $size, float $tracking): ?array
    {
        $n = strlen($text);
        if ($n === 0) {
            return [[], 0.0];
        }
        $cmap = $this->m['cmap'];
        $gsub = ($this->m['gsub'] ?? []) !== [];
        $scale = $size / $this->upem;
        $adv = $this->advances;

        // Fast-path: determine glyph ids directly via pre-folded ASCII map when possible.
        $gids = null;
        $shaped = null;
        if (!$gsub) {
            $gids = [];
            for ($i = 0; $i < $n; $i++) {
                $gids[] = $cmap[ord($text[$i])] ?? 0;
            }
        } else {
            $letters = strpbrk($text, 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz') !== false;
            $fk = match (count($features)) {
                0 => '',
                1 => $features[0],
                2 => $features[0] . ',' . $features[1],
                default => implode(',', $features),
            };
            $pk = ($letters ? "\0" : (string) $context) . '|' . $fk . '|' . $lang;
            if (!isset($this->asciiPlans[$pk])) {
                $scripts = $letters ? ['latn'] : self::scriptTags('', $context);
                $shaper = $this->shaper();
                $plan = $shaper->plan($scripts, self::langTags($lang), self::on($features), false, false);
                $maps = $shaper->singleMaps($plan);
                if ($maps !== null) {
                    $asciiMap = [];
                    for ($c = 0x20; $c < 0x7F; $c++) {
                        $g = $cmap[$c] ?? 0;
                        foreach ($maps as $map) {
                            if (isset($map[$g])) {
                                $g = $map[$g];
                            }
                        }
                        $asciiMap[$c] = $g;
                    }
                    $this->asciiPlans[$pk] = [$plan, null, $asciiMap];
                } else {
                    $this->asciiPlans[$pk] = [$plan, $shaper->firsts($plan, false), null];
                }
            }
            [$plan, $firsts, $asciiMap] = $this->asciiPlans[$pk];
            if ($asciiMap !== null) {
                $gids = [];
                for ($i = 0; $i < $n; $i++) {
                    $gids[] = $asciiMap[ord($text[$i])] ?? 0;
                }
            } else {
                $gids = [];
                $touched = false;
                for ($i = 0; $i < $n; $i++) {
                    $g = $cmap[ord($text[$i])] ?? 0;
                    $gids[] = $g;
                    if (!$touched && isset($firsts[$g])) {
                        $touched = true;
                    }
                }
                if ($touched) {
                    $memo = $text . "\0" . $pk;
                    if (count($this->asciiGlyphs) >= 4096) {
                        $this->asciiGlyphs = [];
                    }
                    if (!array_key_exists($memo, $this->asciiGlyphs)) {
                        $in = [];
                        for ($i = 0; $i < $n; $i++) {
                            $in[] = [$gids[$i], $text[$i]];
                        }
                        $this->asciiGlyphs[$memo] = $this->shaper()->shapePlain($in, $plan);
                    }
                    $shaped = $this->asciiGlyphs[$memo];
                    if ($shaped === null) {
                        return null;
                    }
                    $gids = null;
                }
            }
        }

        // Kerning
        if ($this->uniformKerning()) {
            $kernLookups = $this->m['kern'];
            $key = '*';
        } else {
            [$kernLookups, , $key] = $this->plainPositioning($text, $lang, $context);
        }

        $out = [];
        $w = 0.0;
        if ($gids !== null) {
            if (!$kernLookups || $n === 1) {
                for ($i = 0; $i < $n; $i++) {
                    $g = $gids[$i];
                    $a = $adv[$g] * $scale + $tracking;
                    $out[] = [$g, $a, $text[$i]];
                    $w += $a;
                }
            } elseif ($key === '*') {
                $kc = &$this->kernCache;
                $last = $n - 1;
                for ($i = 0; $i < $n; $i++) {
                    $g = $gids[$i];
                    $a = $adv[$g] * $scale + $tracking;
                    if ($i < $last) {
                        $r = $gids[$i + 1];
                        $k = $kc[($g << 16) | $r] ?? $this->kern($g, $r);
                        if ($k !== 0) {
                            $a += $k * $scale;
                        }
                    }
                    $out[] = [$g, $a, $text[$i]];
                    $w += $a;
                }
            } else {
                $kc = &$this->kernIn[$key];
                $last = $n - 1;
                for ($i = 0; $i < $n; $i++) {
                    $g = $gids[$i];
                    $a = $adv[$g] * $scale + $tracking;
                    if ($i < $last) {
                        $r = $gids[$i + 1];
                        $k = $kc[($g << 16) | $r] ?? $this->kernIn($key, $kernLookups, $g, $r);
                        if ($k !== 0) {
                            $a += $k * $scale;
                        }
                    }
                    $out[] = [$g, $a, $text[$i]];
                    $w += $a;
                }
            }
            return [$out, $w];
        }

        $sn = count($shaped);
        $last = $sn - 1;
        for ($i = 0; $i < $sn; $i++) {
            $g = $shaped[$i][0];
            $a = $adv[$g] * $scale + $tracking;
            if ($i < $last && $kernLookups) {
                $r = $shaped[$i + 1][0];
                $k = $key === '*'
                    ? ($this->kernCache[($g << 16) | $r] ?? $this->kern($g, $r))
                    : ($this->kernIn[$key][($g << 16) | $r] ?? $this->kernIn($key, $kernLookups, $g, $r));
                if ($k !== 0) {
                    $a += $k * $scale;
                }
            }
            $out[] = [$g, $a, $shaped[$i][1]];
            $w += $a;
        }
        return [$out, $w];
    }

    /**
     * Glyph ids and their source text for a string: for plain ASCII, list of [gid, text];
     * otherwise list of [gid, text, ignorable, class, ligature id, component] (see Shaper::shape()).
     * The font's substitutions for the text's script and language are applied, as HarfBuzz does.
     *
     * @param list<string> $features GSUB features asked for (liga, tnum, pnum, …)
     */
    public function glyphs(string $text, array $features, bool $rtl = false, ?bool $ascii = null, ?string $lang = null, ?string $context = null): array
    {
        $cmap = $this->m['cmap'];
        $gsub = ($this->m['gsub'] ?? []) !== [];
        if (!$rtl && ($ascii ?? !preg_match('/[^\x20-\x7E]/', $text))) {
            if (!$gsub) {
                $out = [];
                for ($i = 0, $n = strlen($text); $i < $n; $i++) {
                    $ch = $text[$i];
                    $out[] = [$cmap[ord($ch)] ?? 0, $ch];
                }
                return $out;
            }
            // Most words have no glyph any lookup starts at: checked against the plan's glyphs.
            // (Digits and punctuation take the script of the text around them.)
            $letters = strpbrk($text, 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz') !== false;
            $pk = ($letters ? "\0" : (string) $context) . '|' . implode(',', $features) . '|' . $lang;
            if (!isset($this->asciiPlans[$pk])) {
                $scripts = $letters ? ['latn'] : self::scriptTags('', $context);
                $shaper = $this->shaper();
                $plan = $shaper->plan($scripts, self::langTags($lang), self::on($features), false, false);
                $maps = $shaper->singleMaps($plan);
                $asciiMap = null;
                if ($maps !== null) {
                    $asciiMap = [];
                    for ($c = 0x20; $c < 0x7F; $c++) {
                        $g = $cmap[$c] ?? 0;
                        foreach ($maps as $map) {
                            if (isset($map[$g])) {
                                $g = $map[$g];
                            }
                        }
                        $asciiMap[$c] = $g;
                    }
                    $this->asciiPlans[$pk] = [$plan, null, $asciiMap];
                } else {
                    $this->asciiPlans[$pk] = [$plan, $shaper->firsts($plan, false), null];
                }
            }
            [$plan, $firsts, $asciiMap] = $this->asciiPlans[$pk];
            if ($asciiMap !== null) {
                // Only glyph-for-glyph substitutions (tabular figures, say): pre-folded into ASCII cmap.
                $out = [];
                for ($i = 0, $n = strlen($text); $i < $n; $i++) {
                    $ch = $text[$i];
                    $out[] = [$asciiMap[ord($ch)] ?? 0, $ch];
                }
                return $out;
            }
            $out = [];
            $touched = false;
            for ($i = 0, $n = strlen($text); $i < $n; $i++) {
                $ch = $text[$i];
                $g = $cmap[ord($ch)] ?? 0;
                $out[] = [$g, $ch];
                if (!$touched && isset($firsts[$g])) {
                    $touched = true;
                }
            }
            if (!$touched) {
                return $out;
            }
            // Words come back again and again: their glyphs are kept.
            $memo = $text . "\0" . $pk;
            if (count($this->asciiGlyphs) >= 4096) {
                $this->asciiGlyphs = [];
            }
            if (!array_key_exists($memo, $this->asciiGlyphs)) {
                $this->asciiGlyphs[$memo] = $this->shaper()->shapePlain($out, $plan);
            }
            if ($this->asciiGlyphs[$memo] !== null) {
                return $this->asciiGlyphs[$memo];
            }
        }
        // Words are memoized (short ones only, so the cache stays small); every field but the
        // last is length-prefixed so no two inputs share a key.
        $fk = null;
        if (strlen($text) <= 32) {
            $fk = ($rtl ? '1' : '0') . strlen($fs = implode("\0", $features)) . ':' . $fs . strlen((string) $lang) . ':' . $lang . strlen((string) $context) . ':' . $context . $text;
            if (isset($this->fullGlyphs[$fk])) {
                return $this->fullGlyphs[$fk];
            }
        }
        $scripts = self::scriptTags($text, $context);
        $joining = $scripts !== [] && in_array($scripts[0], self::JOINING, true);
        $chars = \YetiPdf\Write\Text\Unicode::normalize(mb_str_split($text), fn (int $cp) => isset($cmap[$cp]), $this->positionsMarks(), $joining);
        if ($joining) {
            $cps = [];
            foreach ($chars as $ce) {
                $cps[] = $ce[0];
            }
            $forms = \YetiPdf\Write\Text\Joining::forms($cps);
        } else {
            $forms = [];
        }
        $classes = $this->m['classes'] ?? [];
        $hasClasses = $classes !== [];
        $run = [];
        $unicodeMark = [];
        $hasMarks = false;
        foreach ($chars as $i => $ce) {
            $cp = $ce[0];
            $ch = $ce[1];
            $ign = 0;
            if ($cp >= 0x80) {
                if (self::$isDI[$cp] ??= preg_match('/^\p{DI}$/u', mb_chr($cp)) === 1) {
                    // Default ignorables (joiners, direction marks, variation selectors) draw nothing;
                    // matching passes over them as HarfBuzz does (see Shaper::skip()).
                    // (A CGJ is only kept from matching where it keeps two marks apart: see
                    // Unicode::normalize().)
                    $ign = match (true) {
                        $cp === 0x200C => Shaper::ZWNJ,
                        $cp === 0x200D => Shaper::ZWJ,
                        isset($ce[2]), $cp >= 0x180B && $cp <= 0x180F, $cp >= 0xE0020 && $cp <= 0xE007F => Shaper::HIDDEN,
                        default => Shaper::IGNORABLE,
                    };
                } elseif ($rtl && ($m = \YetiPdf\Write\Text\Bidi::mirror($cp)) !== null && isset($cmap[$m])) {
                    // Right-to-left text uses mirrored brackets and the like, when the font has them.
                    $cp = $m;
                }
            } elseif ($rtl && ($m = \YetiPdf\Write\Text\Bidi::mirror($cp)) !== null && isset($cmap[$m])) {
                $cp = $m;
            }
            $g = $cmap[$cp] ?? 0;
            $isMn = $ign === 0 && $cp >= 0x80 && (self::$isMn[$cp] ??= preg_match('/^\p{Mn}$/u', mb_chr($cp)) === 1);
            // A glyph's class from the font's GDEF, else from its character (non-spacing marks).
            $class = $hasClasses ? ($classes[$g] ?? 0) : ($isMn ? 3 : 1);
            if ($class === 3) {
                $hasMarks = true;
            }
            $run[] = [$g, $ch, $ign, Shaper::FORMS[$forms[$i] ?? ''] ?? 0, $class];
            $unicodeMark[] = $isMn;
        }
        $plan = $gsub ? $this->shaper()->plan($scripts, self::langTags($lang), self::on($features), $rtl, $joining) : [];
        // Index 6: whether a glyph is a non-spacing mark by its character (what a font that
        // doesn't position marks gets them zeroed by; see Layouter::glyphRun()).
        $touched = false;
        if ($plan) {
            $firsts = $this->shaper()->firsts($plan, $hasMarks);
            foreach ($run as $e) {
                if (isset($firsts[$e[0]])) {
                    $touched = true;
                    break;
                }
            }
        }
        if ($touched) {
            $res = $this->shaper()->shape($run, $plan, $unicodeMark);
        } else {
            $res = [];
            foreach ($run as $i => $e) {
                $res[] = [$e[0], $e[1], $e[2], $e[4], 0, 0, $unicodeMark[$i]];
            }
        }
        if ($fk === null) {
            return $res;
        }
        if (count($this->fullGlyphs) >= 512) {
            $this->fullGlyphs = [];
        }
        return $this->fullGlyphs[$fk] = $res;
    }

    /**
     * Mark attachment (GPOS mark-to-base, mark-to-ligature and mark-to-mark), as HarfBuzz does
     * it: for each attached mark, [index of the glyph it attaches to, x, y], the offset in font
     * units that puts its anchor on that glyph's anchor. A mark on a ligature goes on the
     * component it was typed after; two marks stack only when they sit on the same base (or
     * component).
     *
     * @param list<array> $glyphs from glyphs() (not the plain ASCII kind)
     * @param list<array> $lookups the mark lookups from positioning()
     * @return array<int, array{0: int, 1: int, 2: int}>
     */
    public function attach(array $glyphs, array $lookups): array
    {
        $markIndices = [];
        foreach ($glyphs as $i => $gl) {
            if ($gl[3] === 3 && $i > 0) {
                $markIndices[] = $i;
            }
        }
        if (!$markIndices || !$lookups) {
            return [];
        }
        $out = [];
        $markClasses = $this->m['markClasses'] ?? [];
        $markSets = $this->m['markSets'] ?? [];
        foreach ($lookups as [$flag, $set, $lookup]) {
            if ($flag & 8) {
                continue;
            }
            $useSet = ($flag & 0x10) !== 0;
            $mSet = $useSet ? ($markSets[$set] ?? []) : null;
            $mCls = !$useSet ? ($flag >> 8) : 0;
            $filter = $useSet || $mCls !== 0;
            foreach ($markIndices as $i) {
                [$g, , , , $lid, $comp] = $glyphs[$i];
                if ($filter && ($useSet ? !isset($mSet[$g]) : ($markClasses[$g] ?? 0) !== $mCls)) {
                    continue;
                }
                foreach ($lookup as [$kind, $marks, $other]) {
                    if (!isset($marks[$g])) {
                        continue;
                    }
                    [$cls, $mx, $my] = $marks[$g];
                    $j = $i - 1;
                    if ($kind === 'm') {
                        // The mark before (past ignorables but a ZWJ, and marks the lookup leaves out).
                        while ($j >= 0) {
                            $gj = $glyphs[$j];
                            if ($gj[2] && $gj[2] !== Shaper::ZWJ) {
                                $j--;
                                continue;
                            }
                            if ($gj[3] === 3 && $filter && ($useSet ? !isset($mSet[$gj[0]]) : ($markClasses[$gj[0]] ?? 0) !== $mCls)) {
                                $j--;
                                continue;
                            }
                            break;
                        }
                        if ($j < 0 || $glyphs[$j][3] !== 3) {
                            break;
                        }
                        [, , , , $lid2, $comp2] = $glyphs[$j];
                        if (!($lid === $lid2 ? $lid === 0 || $comp === $comp2 : ($lid > 0 && !$comp) || ($lid2 > 0 && !$comp2))) {
                            break;
                        }
                        $a = $other[$glyphs[$j][0]][$cls] ?? null;
                    } else {
                        // The base: the glyph before that isn't a mark (or an ignorable). A ZWJ stops
                        // the search (the mark features handle joiners themselves, in HarfBuzz).
                        while ($j >= 0 && ($glyphs[$j][3] === 3 || ($glyphs[$j][2] && $glyphs[$j][2] !== Shaper::ZWJ))) {
                            $j--;
                        }
                        if ($j < 0 || $glyphs[$j][2] === Shaper::ZWJ) {
                            break;
                        }
                        if ($kind === 'l') {
                            $comps = $other[$glyphs[$j][0]] ?? null;
                            if (!$comps) {
                                continue;
                            }
                            $n = count($comps);
                            $a = $comps[$lid && $lid === $glyphs[$j][4] && $comp > 0 ? min($n, $comp) - 1 : $n - 1][$cls] ?? null;
                        } else {
                            $a = $other[$glyphs[$j][0]][$cls] ?? null;
                        }
                    }
                    if ($a !== null) {
                        $ax = $a >> 16;
                        $ay = $a & 0xFFFF;
                        if ($ay >= 0x8000) {
                            $ay -= 0x10000;
                        }
                        $out[$i] = [$j, $ax - $mx, $ay - $my];
                        break;
                    }
                }
            }
        }
        return $out;
    }

    /**
     * Pair kerning between two glyphs, in font units, from every kerning lookup: the first
     * glyph's x advance (for the places a pair is re-kerned at a break).
     */
    public function kern(int $l, int $r): int
    {
        $key = $l << 16 | $r;
        if (isset($this->kernCache[$key])) {
            return $this->kernCache[$key];
        }
        $total = 0;
        foreach ($this->m['kern'] ?: $this->m['kernTable'] ?? [] as [, , $subs]) {
            $v = self::pair($subs, $l, $r) ?? 0;
            $total += is_array($v) ? $v[0] : $v;
        }
        return $this->kernCache[$key] = $total;
    }

    private array $kernIn = [];

    /** The first glyph's x advance adjustment for a pair from some kerning lookups (positioning()'s), cached under $key. */
    public function kernIn(string $key, array $lookups, int $l, int $r): int
    {
        if ($key === '*') {
            return $this->kern($l, $r);
        }
        $pair = $l << 16 | $r;
        if (isset($this->kernIn[$key][$pair])) {
            return $this->kernIn[$key][$pair];
        }
        $total = 0;
        foreach ($lookups as [, , $subs]) {
            $v = self::pair($subs, $l, $r) ?? 0;
            $total += is_array($v) ? $v[0] : $v;
        }
        return $this->kernIn[$key][$pair] = $total;
    }

    private ?bool $simpleKerning = null;

    /**
     * Whether the font's kerning only ever moves the first glyph's advance (no placements, no
     * second value record): plain runs can then be kerned pair by pair from a cache.
     */
    public function simpleKerning(): bool
    {
        if ($this->simpleKerning === null) {
            if (isset($this->m['simpleKerning'])) {
                return $this->simpleKerning = $this->m['simpleKerning'];
            }
            // (The kern table's pairs are split between the two glyphs, as HarfBuzz does.)
            $this->simpleKerning = ($this->m['kernTable'] ?? []) === [];
            foreach ($this->m['kern'] as [, , $subs]) {
                foreach ($subs as $sub) {
                    $second = $sub[0] === 'p' ? $sub[2] : $sub[6];
                    if ($second) {
                        $this->simpleKerning = false;
                        break 2;
                    }
                    $values = $sub[0] === 'p' ? $sub[1] : $sub[5];
                    foreach ($values as $v) {
                        if (is_array($v)) {
                            $this->simpleKerning = false;
                            break 3;
                        }
                    }
                }
            }
        }
        return $this->simpleKerning;
    }

    /**
     * One kerning lookup's adjustment for a pair (an int or a full value, see
     * TrueTypeParser::gposKerning()): null when no subtable covers it. $second says whether the
     * subtable has a second value record.
     */
    public static function pair(array $subs, int $l, int $r, ?bool &$second = null): int|array|null
    {
        $pairKey = ($l << 16) | $r;
        foreach ($subs as $sub) {
            if ($sub[0] === 'p') {
                if (isset($sub[1][$pairKey])) {
                    $second = $sub[2];
                    return $sub[1][$pairKey];
                }
            } elseif (isset($sub[1][$l])) {
                $second = $sub[6];
                return $sub[5][($sub[2][$l] ?? 0) * $sub[4] + ($sub[3][$r] ?? 0)] ?? 0;
            }
        }
        return null;
    }

    /** Whether a lookup's flags pass over a glyph from glyphs() (by its class, or mark class). */
    public function skips(int $flag, array $glyph, ?int $set = null): bool
    {
        $c = $glyph[3];
        if ($c === 3) {
            if ($flag & 8) {
                return true;
            }
            if ($flag & 0x10) {
                return !isset($this->m['markSets'][$set][$glyph[0]]);
            }
            return ($flag & 0xFF00) !== 0 && ($this->m['markClasses'][$glyph[0]] ?? 0) !== $flag >> 8;
        }
        return ($c === 1 && ($flag & 2)) || ($c === 2 && ($flag & 4));
    }

    private array $plainPositioning = [];

    /** positioning() for plain ASCII text, which only needs to know whether it has letters. */
    public function plainPositioning(string $text, ?string $lang, ?string $context): array
    {
        if ($this->uniformKerning()) {
            return [$this->m['kern'], [], '*', true];
        }
        $letters = strpbrk($text, 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz') !== false;
        return $this->plainPositioning[($letters ? "\0" : (string) $context) . '|' . $lang] ??= $this->positioning($letters ? 'a' : '', $lang, $context);
    }

    private ?bool $uniformKerning = null;

    /** Whether every script and language of the font kerns with all its kerning lookups (most fonts): then the plain cache serves. */
    private function uniformKerning(): bool
    {
        if ($this->uniformKerning === null) {
            if (isset($this->m['uniformKerning'])) {
                return $this->uniformKerning = $this->m['uniformKerning'];
            }
            $all = array_keys($this->m['kern']);
            $this->uniformKerning = $all !== [];
            foreach ($this->m['gpos'] ?? [] as $langs) {
                foreach ($langs as $sys) {
                    $this->uniformKerning = $this->uniformKerning && $sys['kern'] === $all;
                }
            }
        }
        return $this->uniformKerning;
    }

    private ?bool $positionsMarks = null;

    /** Whether the font attaches marks itself (GPOS mark features): HarfBuzz then decomposes all it can. */
    private function positionsMarks(): bool
    {
        if ($this->positionsMarks === null) {
            if (isset($this->m['positionsMarks'])) {
                return $this->positionsMarks = $this->m['positionsMarks'];
            }
            $this->positionsMarks = false;
            foreach ($this->m['gpos'] ?? [] as $langs) {
                foreach ($langs as $sys) {
                    $this->positionsMarks = $this->positionsMarks || $sys['mark'] !== [];
                }
            }
        }
        return $this->positionsMarks;
    }

    private array $positioning = [];

    /**
     * The kerning and mark attachment lookups for text's script and language, as HarfBuzz picks
     * them: [kerning: list of [flag, mark set, subtables], marks: list of [flag, mark set,
     * subtables], a key naming that choice, whether the script has mark positioning at all]
     * (the marks only when $marks: they're read from their own cache file when first needed).
     */
    public function positioning(string $text, ?string $lang, ?string $context = null, bool $marks = false): array
    {
        $scripts = self::scriptTags($text, $context);
        $langs = self::langTags($lang);
        $key = implode(',', $scripts) . '|' . implode(',', $langs) . '|' . (int) $marks;
        if (isset($this->positioning[$key])) {
            return $this->positioning[$key];
        }
        $gpos = $this->m['gpos'] ?? [];
        // A font without GPOS kerning is kerned from its kern table (lookups marked 'table').
        $table = null;
        $kernTable = function () use (&$table): array {
            if ($table === null) {
                $table = [];
                foreach ($this->m['kernTable'] ?? [] as $l) {
                    $l['table'] = true;
                    $table[] = $l;
                }
            }
            return $table;
        };
        if ($gpos === []) {
            return $this->positioning[$key] = [$kernTable(), [], $key, false];
        }
        $sys = ['kern' => [], 'mark' => []];
        foreach ([...$scripts, 'DFLT', 'dflt', 'latn'] as $s) {
            if (isset($gpos[$s])) {
                foreach ([...$langs, ''] as $l) {
                    if (isset($gpos[$s][$l])) {
                        $sys = $gpos[$s][$l];
                        break 2;
                    }
                }
                break;
            }
        }
        $kern = $this->m['kern'] ? [] : $kernTable();
        foreach ($sys['kern'] as $li) {
            if (isset($this->m['kern'][$li])) {
                $kern[] = $this->m['kern'][$li];
            }
        }
        $list = [];
        if ($marks && $sys['mark']) {
            $all = $this->markLookups();
            foreach ($sys['mark'] as $li) {
                if (isset($all[$li])) {
                    $list[] = $all[$li];
                }
            }
        }
        return $this->positioning[$key] = [$kern, $list, $key, $sys['mark'] !== []];
    }
}
