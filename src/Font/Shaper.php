<?php

declare(strict_types=1);

namespace YetiPdf\Write\Font;

/**
 * A font's substitutions (GSUB) applied as HarfBuzz applies them: the features a script and
 * language use, in stages, each stage's lookups in lookup order, every lookup over the whole
 * run, skipping what its flags say to skip (marks, ligatures, a mark class), with the joining
 * forms of Arabic-style scripts applied only to the letters that take them.
 *
 * One per face: the plans and the glyphs each lookup can start at are kept between runs.
 */
final class Shaper
{
    /** Joining-form features and their mask bits (the forms Arabic::actions() gives letters). */
    public const FORMS = ['isol' => 1, 'fina' => 2, 'fin2' => 4, 'fin3' => 8, 'medi' => 16, 'med2' => 32, 'init' => 64];

    /** Ignorable kinds (how HarfBuzz skips default ignorables when matching). */
    public const IGNORABLE = 1;
    public const ZWNJ = 2;
    public const ZWJ = 3;
    public const HIDDEN = 4;

    private const MAX_DEPTH = 32;

    private array $lookups;
    private array $firsts = [];
    private array $plans = [];
    private bool $gdef;

    // The run being shaped, as parallel lists.
    private array $g = [];
    private array $t = [];
    private array $c = [];
    private array $mask = [];
    private array $lid = [];
    private array $comp = [];
    private array $ign = [];
    /** @var list<bool> whether each glyph is a non-spacing mark by its character (follows the glyphs) */
    private array $u = [];
    private int $nextLig = 1;
    /** @var list<bool> whether each glyph was substituted (a default ignorable then is drawn) */
    private array $subst = [];
    private int $ops = 0;
    private int $maxOps = 0;

    // The lookup being applied.
    private int $flag = 0;
    private ?int $set = null;
    private int $lookupMask = 0;
    private bool $autoZwj = true;
    private int $depth = 0;

    public function __construct(private readonly array $gsub, private readonly array $classes, private readonly array $markClasses, private readonly array $markSets)
    {
        $this->lookups = $gsub['lookups'] ?? [];
        $this->gdef = $classes !== [];
    }

    /**
     * The stages for a script and language: lists of [lookup index, mask (0: every glyph),
     * whether a ZWJ is skipped when matching], in the order HarfBuzz applies them.
     *
     * @param list<string> $scripts script tags to try, in order (DFLT, dflt and latn are tried after)
     * @param list<string> $langs language system tags to try, in order (the default after)
     * @param list<string> $on features asked for beyond those always on (liga and clig, tnum, …)
     */
    public function plan(array $scripts, array $langs, array $on, bool $rtl, bool $joining): array
    {
        $key = implode(',', $scripts) . '|' . implode(',', $langs) . '|' . implode(',', $on) . '|' . (int) $rtl . (int) $joining;
        if (isset($this->plans[$key])) {
            return $this->plans[$key];
        }
        $sys = null;
        foreach ([...$scripts, 'DFLT', 'dflt', 'latn'] as $s) {
            if (isset($this->gsub['scripts'][$s])) {
                foreach ([...$langs, ''] as $l) {
                    if (isset($this->gsub['scripts'][$s][$l])) {
                        $sys = $this->gsub['scripts'][$s][$l];
                        break 2;
                    }
                }
                break;
            }
        }
        if ($sys === null) {
            return $this->plans[$key] = [];
        }
        // Each feature's stage and whether its lookups skip a ZWJ, as HarfBuzz's default and
        // Arabic shapers plan them. Features asked for go in the last stage.
        $dir = $rtl ? ['rtla', 'rtlm'] : ['ltra', 'ltrm'];
        if ($joining) {
            // (HarfBuzz pauses after calt, then adds rclt with the ligatures.)
            $stages = [$dir, ['ccmp', 'locl'], ['isol'], ['fina'], ['fin2'], ['fin3'], ['medi'], ['med2'], ['init'], ['rlig'], ['calt'], ['rclt', 'mset', ...$on]];
            $manual = ['ccmp', 'locl', 'isol', 'fina', 'fin2', 'fin3', 'medi', 'med2', 'init', 'rlig', 'calt', 'rclt', 'liga', 'clig', 'mset'];
        } else {
            $stages = [[...$dir, 'ccmp', 'locl', 'rlig', 'calt', 'rclt', ...$on]];
            $manual = [];
        }
        $stageOf = [];
        foreach ($stages as $i => $tags) {
            foreach ($tags as $tag) {
                $stageOf[$tag] ??= $i;
            }
        }
        [$required, $indices] = $sys;
        $byStage = [];
        $add = function (int $stage, array $lookups, int $mask, bool $autoZwj) use (&$byStage): void {
            foreach ($lookups as $li) {
                if (!isset($this->lookups[$li])) {
                    continue;
                }
                if (isset($byStage[$stage][$li])) {
                    [$m, $z] = $byStage[$stage][$li];
                    $byStage[$stage][$li] = [$m === 0 || $mask === 0 ? 0 : $m | $mask, $z && $autoZwj];
                } else {
                    $byStage[$stage][$li] = [$mask, $autoZwj];
                }
            }
        };
        if ($required >= 0 && isset($this->gsub['features'][$required])) {
            $add(0, $this->gsub['features'][$required][1], 0, true);
        }
        $seen = [];
        foreach ($indices as $fi) {
            [$tag, $lookups] = $this->gsub['features'][$fi] ?? [null, []];
            // A language system listing a tag twice uses the first, as HarfBuzz does.
            if ($tag === null || !isset($stageOf[$tag]) || isset($seen[$tag])) {
                continue;
            }
            $seen[$tag] = true;
            $add($stageOf[$tag], $lookups, $joining ? (self::FORMS[$tag] ?? 0) : 0, !in_array($tag, $manual, true));
        }
        ksort($byStage);
        $plan = [];
        foreach ($byStage as $lookups) {
            ksort($lookups);
            $stage = [];
            foreach ($lookups as $li => [$mask, $autoZwj]) {
                $stage[] = [$li, $mask, $autoZwj];
            }
            $plan[] = $stage;
        }
        return $this->plans[$key] = $plan;
    }

    private array $planFirsts = [];
    private array $needsMarks = [];
    private array $lookupCreatesMarks = [];
    private ?array $markGlyphs = null;

    /**
     * The glyphs any lookup of a plan can start at (a run with none of them is left as it is),
     * leaving out, for a run without marks, the lookups whose every rule needs a mark (like
     * ccmp's dotless i before an accent).
     */
    public function firsts(array $plan, bool $marks = true): array
    {
        // (A plan that can turn a letter into a mark can't leave those lookups out.)
        $marks = $marks || $this->createsMarks($plan);
        $key = $marks ? '1' : '0';
        foreach ($plan as $stage) {
            foreach ($stage as [$li]) {
                $key .= ',' . $li;
            }
        }
        if (!isset($this->planFirsts[$key])) {
            $all = [];
            foreach ($plan as $stage) {
                foreach ($stage as [$li]) {
                    if ($marks || !$this->needsMarks($li)) {
                        $all += $this->firsts[$li] ??= $this->firstGlyphs($li);
                    }
                }
            }
            $this->planFirsts[$key] = $all;
        }
        return $this->planFirsts[$key];
    }

    /**
     * For text without marks: the plan as plain glyph-for-glyph maps, in order, when that's all
     * it can do to such text (no ligatures or contexts); null otherwise.
     */
    public function singleMaps(array $plan): ?array
    {
        if ($this->createsMarks($plan)) {
            return null;
        }
        $maps = [];
        foreach ($plan as $stage) {
            foreach ($stage as [$li, $mask]) {
                if ($this->needsMarks($li)) {
                    continue;
                }
                [$flag, , $subs] = $this->lookups[$li];
                // (Flags that pass over bases or ligatures need the full path.)
                if ($mask !== 0 || ($flag & 6) || ($subs[0][0] ?? 's') !== 's') {
                    return null;
                }
                // Each glyph takes the first subtable that covers it.
                if (count($subs) === 1) {
                    if ($subs[0][1]) {
                        $maps[] = $subs[0][1];
                    }
                } else {
                    $map = [];
                    foreach ($subs as $sub) {
                        $map += $sub[1];
                    }
                    if ($map) {
                        $maps[] = $map;
                    }
                }
            }
        }
        return $maps;
    }

    private function markGlyphs(): array
    {
        if ($this->markGlyphs === null) {
            $marks = [];
            foreach ($this->classes as $g => $c) {
                if ($c === 3) {
                    $marks[$g] = true;
                }
            }
            $this->markGlyphs = $marks;
        }
        return $this->markGlyphs;
    }

    /** Whether any substitution in a plan puts out a mark. */
    private function createsMarks(array $plan): bool
    {
        if (!$this->gdef) {
            return false;
        }
        $marks = $this->markGlyphs();
        if (!$marks) {
            return false;
        }
        foreach ($plan as $stage) {
            foreach ($stage as [$li]) {
                if ($this->lookupCreatesMarks[$li] ??= $this->lookupMakesMark($li, $marks)) {
                    return true;
                }
            }
        }
        return false;
    }

    private function lookupMakesMark(int $li, array $marks): bool
    {
        foreach ($this->lookups[$li][2] as $sub) {
            switch ($sub[0]) {
                case 's':
                    foreach ($sub[1] as $g) {
                        if (isset($marks[$g])) {
                            return true;
                        }
                    }
                    break;
                case 'm':
                    foreach ($sub[1] as $seq) {
                        foreach ($seq as $g) {
                            if (isset($marks[$g])) {
                                return true;
                            }
                        }
                    }
                    break;
                case 'l':
                    foreach ($sub[1] as $set) {
                        foreach ($set as [, $lig]) {
                            if (isset($marks[$lig])) {
                                return true;
                            }
                        }
                    }
                    break;
                case 'r':
                    foreach ($sub[4] as $g) {
                        if (isset($marks[$g])) {
                            return true;
                        }
                    }
                    break;
            }
        }
        return false;
    }

    /** Whether every rule of a lookup has a context item that only marks match. */
    private function needsMarks(int $li): bool
    {
        if (isset($this->needsMarks[$li])) {
            return $this->needsMarks[$li];
        }
        $subs = $this->lookups[$li][2];
        if (!$this->gdef || $subs === []) {
            return $this->needsMarks[$li] = false;
        }
        $marks = $this->markGlyphs();
        if (!$marks) {
            return $this->needsMarks[$li] = false;
        }
        $needs = true;
        foreach ($subs as $sub) {
            if ($sub[0] !== 'c') {
                return $this->needsMarks[$li] = false;
            }
            [, $fmt, , $btDef, $inDef, $laDef, $rules] = $sub;
            if ($fmt === 2) {
                $btMarks = $this->markOnlyClasses($btDef, $marks);
                $inMarks = $this->markOnlyClasses($inDef, $marks);
                $laMarks = $this->markOnlyClasses($laDef, $marks);
                foreach ($rules as $set) {
                    foreach ($set as [$bt, $in, $la]) {
                        $found = false;
                        foreach ($bt as $item) {
                            if (isset($btMarks[$item])) {
                                $found = true;
                                break;
                            }
                        }
                        if (!$found) {
                            foreach ($in as $item) {
                                if (isset($inMarks[$item])) {
                                    $found = true;
                                    break;
                                }
                            }
                        }
                        if (!$found) {
                            foreach ($la as $item) {
                                if (isset($laMarks[$item])) {
                                    $found = true;
                                    break;
                                }
                            }
                        }
                        if (!$found) {
                            return $this->needsMarks[$li] = false;
                        }
                    }
                }
            } elseif ($fmt === 1) {
                foreach ($rules as $set) {
                    foreach ($set as [$bt, $in, $la]) {
                        $found = false;
                        foreach ($bt as $item) {
                            if (isset($marks[$item])) {
                                $found = true;
                                break;
                            }
                        }
                        if (!$found) {
                            foreach ($in as $item) {
                                if (isset($marks[$item])) {
                                    $found = true;
                                    break;
                                }
                            }
                        }
                        if (!$found) {
                            foreach ($la as $item) {
                                if (isset($marks[$item])) {
                                    $found = true;
                                    break;
                                }
                            }
                        }
                        if (!$found) {
                            return $this->needsMarks[$li] = false;
                        }
                    }
                }
            } else {
                foreach ($rules as $set) {
                    foreach ($set as [$bt, $in, $la]) {
                        $found = false;
                        foreach ([$bt, $in, $la] as $items) {
                            foreach ($items as $item) {
                                if ($item === []) {
                                    continue;
                                }
                                $all = true;
                                foreach ($item as $g => $_) {
                                    if (!isset($marks[$g])) {
                                        $all = false;
                                        break;
                                    }
                                }
                                if ($all) {
                                    $found = true;
                                    break 2;
                                }
                            }
                        }
                        if (!$found) {
                            return $this->needsMarks[$li] = false;
                        }
                    }
                }
            }
        }
        return $this->needsMarks[$li] = $needs;
    }

    /** Classes in a ClassDef whose every glyph is a mark (class 0 excluded). */
    private function markOnlyClasses(array $def, array $marks): array
    {
        if (!$def) {
            return [];
        }
        $has = [];
        $nonMark = [];
        foreach ($def as $g => $c) {
            $has[$c] = true;
            if (!isset($marks[$g])) {
                $nonMark[$c] = true;
            }
        }
        unset($has[0]);
        foreach ($nonMark as $c => $_) {
            unset($has[$c]);
        }
        return $has;
    }

    /**
     * Apply a plan to a run.
     *
     * @param list<array{0: int, 1: string, 2: int, 3: int, 4: int}> $run [gid, text, ignorable kind, mask, class]
     * @return list<array{0: int, 1: string, 2: int, 3: int, 4: int, 5: int}> [gid, text, ignorable kind (0: none),
     *     class, ligature id, component]: class 1 base, 2 ligature, 3 mark; a ligature's component
     *     is its number of components, a mark's the component of the ligature it belongs to
     */
    public function shape(array $run, array $plan, array $unicodeMark = []): array
    {
        $this->u = $unicodeMark;
        $g = $t = $ign = $mask = $c = [];
        foreach ($run as $e) {
            $g[] = $e[0];
            $t[] = $e[1];
            $ign[] = $e[2];
            $mask[] = $e[3];
            $c[] = $e[4];
        }
        $this->g = $g;
        $this->t = $t;
        $this->ign = $ign;
        $this->mask = $mask;
        $this->c = $c;
        $this->run($plan);
        $out = [];
        $g = $this->g;
        $t = $this->t;
        $subst = $this->subst;
        $ign = $this->ign;
        $c = $this->c;
        $lid = $this->lid;
        $comp = $this->comp;
        $u = $this->u;
        for ($i = 0, $n = count($g); $i < $n; $i++) {
            // (A default ignorable the font substituted is drawn, as in HarfBuzz.)
            $out[] = [$g[$i], $t[$i], $subst[$i] ? 0 : $ign[$i], $c[$i], $lid[$i], $comp[$i], $u[$i] ?? false];
        }
        $this->g = $this->t = $this->ign = $this->mask = $this->c = $this->lid = $this->comp = $this->subst = $this->u = [];
        return $out;
    }

    /**
     * Apply a plan to plain text (no marks, ignorables or joining forms): [gid, text] in, the
     * same out; null when a glyph was deleted (glyphs() then takes the full path).
     */
    public function shapePlain(array $run, array $plan): ?array
    {
        $g = $t = $c = [];
        $gdef = $this->gdef;
        $classes = $this->classes;
        foreach ($run as $e) {
            $gid = $e[0];
            $g[] = $gid;
            $t[] = $e[1];
            $c[] = $gdef ? ($classes[$gid] ?? 0) : 1;
        }
        $n = count($g);
        $this->g = $g;
        $this->t = $t;
        $this->c = $c;
        $this->u = [];
        $this->ign = $this->mask = array_fill(0, $n, 0);
        $this->run($plan);
        $out = [];
        $g = $this->g;
        $t = $this->t;
        $ign = $this->ign;
        for ($i = 0, $n = count($g); $i < $n; $i++) {
            if ($ign[$i]) {
                $this->g = $this->t = $this->ign = $this->mask = $this->c = $this->lid = $this->comp = $this->subst = [];
                return null; // a glyph the font deleted: for the full path
            }
            $out[] = [$g[$i], $t[$i]];
        }
        $this->g = $this->t = $this->ign = $this->mask = $this->c = $this->lid = $this->comp = $this->subst = [];
        return $out;
    }

    private function run(array $plan): void
    {
        $n = count($this->g);
        $this->lid = $this->comp = array_fill(0, $n, 0);
        $this->subst = array_fill(0, $n, false);
        $this->nextLig = 1;
        // A budget of nested lookup calls, as HarfBuzz has, so a font whose contexts call each
        // other can't take forever.
        $this->ops = 0;
        $this->maxOps = max(1024, 64 * $n);
        foreach ($plan as $stage) {
            foreach ($stage as [$li, $mask, $autoZwj]) {
                $this->lookup($li, $mask, $autoZwj);
            }
        }
    }

    private function firstGlyphs(int $li): array
    {
        $first = [];
        foreach ($this->lookups[$li][2] as $sub) {
            $first += match ($sub[0]) {
                's', 'm', 'l' => $sub[1],
                'c', 'r' => $sub[$sub[0] === 'c' ? 2 : 1],
            };
        }
        return $first;
    }

    private function lookup(int $li, int $mask, bool $autoZwj): void
    {
        [$flag, $set, $subs] = $this->lookups[$li];
        if (!$subs) {
            return;
        }
        $first = $this->firsts[$li] ??= $this->firstGlyphs($li);
        $this->flag = $flag;
        $this->set = $set;
        $this->lookupMask = $mask;
        $this->autoZwj = $autoZwj;
        $flags = ($flag & 0xFF1E) !== 0;
        $kind = $subs[0][0];
        if ($kind === 's') {
            // One glyph for another (figures, small capitals, forms): straight through.
            $gdef = $this->gdef;
            $classes = $this->classes;
            if (count($subs) === 1) {
                $map = $subs[0][1];
                foreach ($this->g as $i => $g) {
                    if (isset($map[$g]) && ($mask === 0 || ($this->mask[$i] & $mask)) && (!$flags || !$this->ignored($i))) {
                        $new = $this->g[$i] = $map[$g];
                        $this->subst[$i] = true;
                        if ($gdef) {
                            $this->c[$i] = $classes[$new] ?? 0;
                        }
                    }
                }
            } else {
                foreach ($this->g as $i => $g) {
                    if (isset($first[$g]) && ($mask === 0 || ($this->mask[$i] & $mask)) && (!$flags || !$this->ignored($i))) {
                        foreach ($subs as $sub) {
                            if (isset($sub[1][$g])) {
                                $new = $this->g[$i] = $sub[1][$g];
                                $this->subst[$i] = true;
                                if ($gdef) {
                                    $this->c[$i] = $classes[$new] ?? 0;
                                }
                                break;
                            }
                        }
                    }
                }
            }
            return;
        }
        if ($kind === 'r') {
            // Reverse chaining: from the end, one glyph for another, so the run keeps its length.
            for ($i = count($this->g) - 1; $i >= 0; $i--) {
                if (isset($first[$this->g[$i]]) && ($mask === 0 || ($this->mask[$i] & $mask)) && (!$flags || !$this->ignored($i))) {
                    foreach ($subs as $sub) {
                        if ($this->reverse($sub, $i)) {
                            break;
                        }
                    }
                }
            }
            return;
        }
        $i = 0;
        $n = count($this->g);
        while ($i < $n) {
            if (isset($first[$this->g[$i]]) && ($mask === 0 || ($this->mask[$i] & $mask)) && (!$flags || !$this->ignored($i))) {
                foreach ($subs as $sub) {
                    if (($next = $this->apply($sub, $i)) !== null) {
                        $i = $next;
                        $n = count($this->g);
                        continue 2;
                    }
                }
            }
            $i++;
        }
    }

    /** Whether the lookup's flags pass over glyph $k (marks, ligatures, bases, other mark classes). */
    private function ignored(int $k): bool
    {
        $c = $this->c[$k];
        $f = $this->flag;
        if ($c === 3) {
            if ($f & 8) {
                return true;
            }
            if ($f & 0x10) {
                return !isset($this->markSets[$this->set][$this->g[$k]]);
            }
            return ($f & 0xFF00) !== 0 && ($this->markClasses[$this->g[$k]] ?? 0) !== $f >> 8;
        }
        return ($c === 1 && ($f & 2)) || ($c === 2 && ($f & 4));
    }

    /** The next glyph after $j (before it, going back) equal to $want, skipping what may be skipped; -1 if none. */
    private function seekGlyph(int $j, int $step, int $want, bool $context): int
    {
        $n = count($this->g);
        $flags = ($this->flag & 0xFF1E) !== 0;
        $lmask = $context ? 0 : $this->lookupMask;
        for ($j += $step; $j >= 0 && $j < $n; $j += $step) {
            if ($flags && $this->ignored($j)) {
                continue;
            }
            if (($lmask === 0 || ($this->mask[$j] & $lmask)) && $this->g[$j] === $want) {
                return $j;
            }
            $d = $this->ign[$j];
            if ($d === 0 || $d === self::HIDDEN || ($d === self::ZWNJ && !$context) || ($d === self::ZWJ && !$context && !$this->autoZwj)) {
                return -1;
            }
        }
        return -1;
    }

    /** The next glyph after $j (before it, going back) in class $want of $def; -1 if none. */
    private function seekClass(int $j, int $step, array $def, int $want, bool $context): int
    {
        $n = count($this->g);
        $flags = ($this->flag & 0xFF1E) !== 0;
        $lmask = $context ? 0 : $this->lookupMask;
        for ($j += $step; $j >= 0 && $j < $n; $j += $step) {
            if ($flags && $this->ignored($j)) {
                continue;
            }
            if (($lmask === 0 || ($this->mask[$j] & $lmask)) && ($def[$this->g[$j]] ?? 0) === $want) {
                return $j;
            }
            $d = $this->ign[$j];
            if ($d === 0 || $d === self::HIDDEN || ($d === self::ZWNJ && !$context) || ($d === self::ZWJ && !$context && !$this->autoZwj)) {
                return -1;
            }
        }
        return -1;
    }

    /** The next glyph after $j (before it, going back) in coverage $cov; -1 if none. */
    private function seekCov(int $j, int $step, array $cov, bool $context): int
    {
        $n = count($this->g);
        $flags = ($this->flag & 0xFF1E) !== 0;
        $lmask = $context ? 0 : $this->lookupMask;
        for ($j += $step; $j >= 0 && $j < $n; $j += $step) {
            if ($flags && $this->ignored($j)) {
                continue;
            }
            if (($lmask === 0 || ($this->mask[$j] & $lmask)) && isset($cov[$this->g[$j]])) {
                return $j;
            }
            $d = $this->ign[$j];
            if ($d === 0 || $d === self::HIDDEN || ($d === self::ZWNJ && !$context) || ($d === self::ZWJ && !$context && !$this->autoZwj)) {
                return -1;
            }
        }
        return -1;
    }

    /** Apply one subtable at glyph $i: the position to go on from, or null if it doesn't apply. */
    private function apply(array $sub, int $i): ?int
    {
        $g = $this->g[$i];
        switch ($sub[0]) {
            case 's':
                if (!isset($sub[1][$g])) {
                    return null;
                }
                $this->replace($i, $sub[1][$g]);
                return $i + 1;
            case 'm':
                if (!isset($sub[1][$g])) {
                    return null;
                }
                return $this->multiply($i, $sub[1][$g]);
            case 'l':
                foreach ($sub[1][$g] ?? [] as [$comps, $lig]) {
                    $pos = [$i];
                    $j = $i;
                    foreach ($comps as $want) {
                        $j = $this->seekGlyph($j, 1, $want, false);
                        if ($j < 0) {
                            continue 2;
                        }
                        $pos[] = $j;
                    }
                    return $this->ligate($pos, $lig);
                }
                return null;
            case 'c':
                [, $fmt, $cov, $btDef, $inDef, $laDef, $rules] = $sub;
                if (!isset($cov[$g])) {
                    return null;
                }
                if ($fmt === 1) {
                    foreach ($rules[$g] ?? [] as [$bt, $in, $la, $records]) {
                        $pos = [$i];
                        $j = $i;
                        foreach ($in as $item) {
                            $j = $this->seekGlyph($j, 1, $item, false);
                            if ($j < 0) {
                                continue 2;
                            }
                            $pos[] = $j;
                        }
                        $k = $i;
                        foreach ($bt as $item) {
                            $k = $this->seekGlyph($k, -1, $item, true);
                            if ($k < 0) {
                                continue 2;
                            }
                        }
                        $k = $j;
                        foreach ($la as $item) {
                            $k = $this->seekGlyph($k, 1, $item, true);
                            if ($k < 0) {
                                continue 2;
                            }
                        }
                        return $this->records($pos, $records);
                    }
                } elseif ($fmt === 2) {
                    foreach ($rules[$inDef[$g] ?? 0] ?? [] as [$bt, $in, $la, $records]) {
                        $pos = [$i];
                        $j = $i;
                        foreach ($in as $item) {
                            $j = $this->seekClass($j, 1, $inDef, $item, false);
                            if ($j < 0) {
                                continue 2;
                            }
                            $pos[] = $j;
                        }
                        $k = $i;
                        foreach ($bt as $item) {
                            $k = $this->seekClass($k, -1, $btDef, $item, true);
                            if ($k < 0) {
                                continue 2;
                            }
                        }
                        $k = $j;
                        foreach ($la as $item) {
                            $k = $this->seekClass($k, 1, $laDef, $item, true);
                            if ($k < 0) {
                                continue 2;
                            }
                        }
                        return $this->records($pos, $records);
                    }
                } else {
                    foreach ($rules[0] as [$bt, $in, $la, $records]) {
                        $pos = [$i];
                        $j = $i;
                        foreach ($in as $item) {
                            $j = $this->seekCov($j, 1, $item, false);
                            if ($j < 0) {
                                continue 2;
                            }
                            $pos[] = $j;
                        }
                        $k = $i;
                        foreach ($bt as $item) {
                            $k = $this->seekCov($k, -1, $item, true);
                            if ($k < 0) {
                                continue 2;
                            }
                        }
                        $k = $j;
                        foreach ($la as $item) {
                            $k = $this->seekCov($k, 1, $item, true);
                            if ($k < 0) {
                                continue 2;
                            }
                        }
                        return $this->records($pos, $records);
                    }
                }
                return null;
            case 'r':
                return $this->reverse($sub, $i) ? $i + 1 : null;
        }
        return null;
    }

    private function reverse(array $sub, int $i): bool
    {
        [, $cov, $bt, $la, $map] = $sub;
        $g = $this->g[$i];
        if (!isset($cov[$g], $map[$g])) {
            return false;
        }
        $k = $i;
        foreach ($bt as $item) {
            if (($k = $this->seekCov($k, -1, $item, true)) < 0) {
                return false;
            }
        }
        $k = $i;
        foreach ($la as $item) {
            if (($k = $this->seekCov($k, 1, $item, true)) < 0) {
                return false;
            }
        }
        $this->replace($i, $map[$g]);
        return true;
    }

    /** A context's substitutions, each a lookup applied at one of the matched glyphs (as HarfBuzz keeps the positions right as the run grows or shrinks). */
    private function records(array $pos, array $records): int
    {
        $count = count($pos);
        $end = $pos[$count - 1] + 1;
        foreach ($records as [$idx, $li]) {
            if ($idx >= $count || !isset($this->lookups[$li]) || $this->depth >= self::MAX_DEPTH) {
                continue;
            }
            $before = count($this->g);
            if (++$this->ops > $this->maxOps || !$this->nested($li, $pos[$idx])) {
                continue;
            }
            $delta = count($this->g) - $before;
            if ($delta === 0) {
                continue;
            }
            $end += $delta;
            if ($end < $pos[$idx]) {
                $delta += $pos[$idx] - $end;
                $end = $pos[$idx];
            }
            $next = $idx + 1;
            if ($delta < 0) {
                $delta = max($delta, $next - $count);
                $next -= $delta;
            }
            $moved = array_slice($pos, $next, $count - $next);
            $pos = array_slice($pos, 0, $next + $delta);
            $pos = array_pad($pos, $next + $delta, 0);
            array_push($pos, ...$moved);
            $next += $delta;
            $count += $delta;
            for ($j = $idx + 1; $j < $next; $j++) {
                $pos[$j] = $pos[$j - 1] + 1;
            }
            for (; $next < $count; $next++) {
                $pos[$next] += $delta;
            }
        }
        return $end;
    }

    /** A lookup called from a context rule, at one glyph: its first subtable that applies there. */
    private function nested(int $li, int $at): bool
    {
        [$flag, $set, $subs] = $this->lookups[$li];
        $saved = [$this->flag, $this->set];
        [$this->flag, $this->set] = [$flag, $set];
        $this->depth++;
        $done = false;
        foreach ($subs as $sub) {
            if ($this->apply($sub, $at) !== null) {
                $done = true;
                break;
            }
        }
        $this->depth--;
        [$this->flag, $this->set] = $saved;
        return $done;
    }

    private function classOf(int $g, int $guess): int
    {
        // (With GDEF, a glyph it doesn't classify is class 0, which no flag passes over.)
        return $this->gdef ? ($this->classes[$g] ?? 0) : $guess;
    }

    private function replace(int $i, int $g): void
    {
        $this->g[$i] = $g;
        $this->c[$i] = $this->classOf($g, $this->c[$i]);
        $this->subst[$i] = true;
    }

    /** One glyph for several (or none): the text goes with the first. */
    private function multiply(int $i, array $seq): int
    {
        if (!$seq) {
            // Deleted: its text joins the glyph before (or after).
            if ($i > 0) {
                $this->t[$i - 1] .= $this->t[$i];
            } elseif (count($this->g) > 1) {
                $this->t[1] = $this->t[0] . $this->t[1];
            } else {
                // The only glyph: kept for its text, but drawn as nothing.
                $this->ign[$i] = self::IGNORABLE;
                return $i + 1;
            }
            foreach (['g', 't', 'c', 'mask', 'lid', 'comp', 'ign', 'subst', 'u'] as $p) {
                array_splice($this->$p, $i, 1);
            }
            return $i;
        }
        $n = count($seq);
        $c = $this->c[$i];
        array_splice($this->g, $i, 1, $seq);
        array_splice($this->t, $i, 1, [$this->t[$i], ...array_fill(0, $n - 1, '')]);
        array_splice($this->c, $i, 1, array_map(fn ($g) => $this->classOf($g, $c), $seq));
        foreach (['mask', 'lid', 'comp', 'ign'] as $p) {
            array_splice($this->$p, $i, 1, array_fill(0, $n, $this->$p[$i]));
        }
        array_splice($this->subst, $i, 1, array_fill(0, $n, true));
        if ($this->u) {
            array_splice($this->u, $i, 1, array_fill(0, $n, $this->u[$i]));
        }
        return $i + $n;
    }

    /**
     * Several glyphs as one: the ligature takes their text and the place of the first; glyphs
     * passed over between them (marks) follow it, each noting which component it sat on.
     */
    private function ligate(array $pos, int $lig): int
    {
        $first = $pos[0];
        $last = $pos[count($pos) - 1];
        $markLig = true;
        foreach ($pos as $k) {
            $markLig = $markLig && $this->c[$k] === 3;
        }
        $id = $markLig ? 0 : $this->nextLig++;
        $text = '';
        $kept = [];
        $comps = 0;
        $lastNum = 1;
        $lastLid = 0;
        $p = 0;
        for ($k = $first; $k <= $last; $k++) {
            $text .= $this->t[$k];
            if ($k === $pos[$p]) {
                $lastLid = $this->lid[$k];
                $lastNum = $this->c[$k] === 2 && $lastLid ? max(1, $this->comp[$k]) : 1;
                $comps += $lastNum;
                $p++;
                continue;
            }
            $e = [$this->g[$k], '', $this->c[$k], $this->mask[$k], $this->lid[$k], $this->comp[$k], $this->ign[$k], $this->subst[$k], $this->u[$k] ?? false];
            if ($id && $this->c[$k] === 3) {
                $own = $lastLid && $this->lid[$k] === $lastLid ? $this->comp[$k] : 0;
                $e[4] = $id;
                $e[5] = $comps - $lastNum + min($own ?: $lastNum, $lastNum);
            }
            $kept[] = $e;
        }
        // Marks after the last component that sat on one of its own components.
        if ($id && $lastLid && $lastNum > 1) {
            for ($k = $last + 1, $n = count($this->g); $k < $n && $this->lid[$k] === $lastLid && $this->comp[$k]; $k++) {
                $this->lid[$k] = $id;
                $this->comp[$k] = $comps - $lastNum + min($this->comp[$k], $lastNum);
            }
        }
        $len = $last - $first + 1;
        $ligClass = $this->classOf($lig, $markLig ? 3 : 2);
        $ligComp = $markLig ? 0 : $comps;
        if (!$kept) {
            array_splice($this->g, $first, $len, [$lig]);
            array_splice($this->t, $first, $len, [$text]);
            array_splice($this->c, $first, $len, [$ligClass]);
            array_splice($this->mask, $first, $len, [$this->mask[$first]]);
            array_splice($this->lid, $first, $len, [$id]);
            array_splice($this->comp, $first, $len, [$ligComp]);
            array_splice($this->ign, $first, $len, [0]);
            array_splice($this->subst, $first, $len, [true]);
            if ($this->u) {
                array_splice($this->u, $first, $len, [false]);
            }
            return $first + 1;
        }
        $kg = [$lig];
        $kt = [$text];
        $kc = [$ligClass];
        $kmask = [$this->mask[$first]];
        $klid = [$id];
        $kcomp = [$ligComp];
        $kign = [0];
        $ksubst = [true];
        $ku = [false];
        foreach ($kept as $e) {
            $kg[] = $e[0];
            $kt[] = $e[1];
            $kc[] = $e[2];
            $kmask[] = $e[3];
            $klid[] = $e[4];
            $kcomp[] = $e[5];
            $kign[] = $e[6];
            $ksubst[] = $e[7];
            $ku[] = $e[8];
        }
        array_splice($this->g, $first, $len, $kg);
        array_splice($this->t, $first, $len, $kt);
        array_splice($this->c, $first, $len, $kc);
        array_splice($this->mask, $first, $len, $kmask);
        array_splice($this->lid, $first, $len, $klid);
        array_splice($this->comp, $first, $len, $kcomp);
        array_splice($this->ign, $first, $len, $kign);
        array_splice($this->subst, $first, $len, $ksubst);
        if ($this->u) {
            array_splice($this->u, $first, $len, $ku);
        }
        return $first + 1 + count($kept);
    }
}
