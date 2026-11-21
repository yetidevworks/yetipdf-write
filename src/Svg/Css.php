<?php

declare(strict_types=1);

namespace YetiPdf\Write\Svg;

use DOMElement;

/**
 * Just enough CSS for SVG files from design tools: `<style>` sheets with type, class, id and
 * universal selectors (compound, descendant and child combinators, comma lists) and
 * `style=""` declarations. At-rules, attribute selectors and pseudo-classes are skipped.
 */
final class Css
{
    /** @var list<array{sel: list<array{0: string, 1: array}>, spec: int, order: int, decls: array<string,string>}> */
    private array $rules = [];

    public function add(string $sheet): void
    {
        $sheet = preg_replace('#/\*.*?\*/#s', '', $sheet);
        $sheet = str_replace(['<![CDATA[', ']]>'], '', $sheet);
        $len = strlen($sheet);
        $pos = 0;
        while ($pos < $len) {
            $open = strpos($sheet, '{', $pos);
            if ($open === false) {
                break;
            }
            $prelude = trim(substr($sheet, $pos, $open - $pos));
            // Find the matching close brace (at-rules like @media nest blocks).
            $depth = 1;
            $i = $open + 1;
            while ($i < $len && $depth > 0) {
                $depth += $sheet[$i] === '{' ? 1 : ($sheet[$i] === '}' ? -1 : 0);
                $i++;
            }
            $body = substr($sheet, $open + 1, $i - $open - 2);
            $pos = $i;
            if ($prelude === '' || $prelude[0] === '@') {
                continue;
            }
            $decls = self::declarations($body);
            foreach (explode(',', $prelude) as $sel) {
                if (($parsed = self::selector(trim($sel))) !== null) {
                    $this->rules[] = ['sel' => $parsed[0], 'spec' => $parsed[1], 'order' => count($this->rules), 'decls' => $decls];
                }
            }
        }
        usort($this->rules, fn ($a, $b) => [$a['spec'], $a['order']] <=> [$b['spec'], $b['order']]);
    }

    public function empty(): bool
    {
        return !$this->rules;
    }

    /** Declarations of the matching rules, lowest specificity first. @return array<string,string> */
    public function match(DOMElement $el): array
    {
        $out = [];
        foreach ($this->rules as $r) {
            if (self::matches($el, $r['sel'])) {
                foreach ($r['decls'] as $k => $v) {
                    $out[$k] = $v;
                }
            }
        }
        return $out;
    }

    /** @return array<string,string> */
    public static function declarations(string $s): array
    {
        $out = [];
        foreach (explode(';', $s) as $d) {
            $c = strpos($d, ':');
            if ($c === false) {
                continue;
            }
            $k = strtolower(trim(substr($d, 0, $c)));
            $v = trim(preg_replace('/\s*!important\s*$/i', '', substr($d, $c + 1)));
            if ($k !== '' && $v !== '') {
                $out[$k] = $v;
            }
        }
        return $out;
    }

    /**
     * Parse a selector into compounds, rightmost last, each [combinator, [tag, ids, classes]].
     * @return array{0: list<array{0: string, 1: array}>, 1: int}|null
     */
    private static function selector(string $sel): ?array
    {
        if ($sel === '' || preg_match('/[\[:]/', $sel)) {
            return null;
        }
        $sel = preg_replace('/\s*>\s*/', ' > ', $sel);
        $parts = [];
        $spec = 0;
        $comb = ' ';
        foreach (preg_split('/\s+/', $sel) as $tok) {
            if ($tok === '>') {
                $comb = '>';
                continue;
            }
            if (!preg_match('/^(\*|[a-zA-Z][\w-]*)?((?:[.#][\w-]+)*)$/', $tok, $m) || ($m[1] === '' && $m[2] === '')) {
                return null;
            }
            $tag = $m[1] === '' || $m[1] === '*' ? null : $m[1];
            preg_match_all('/([.#])([\w-]+)/', $m[2], $bits, PREG_SET_ORDER);
            $ids = $classes = [];
            foreach ($bits as [, $kind, $name]) {
                $kind === '#' ? $ids[] = $name : $classes[] = $name;
            }
            $spec += count($ids) * 10000 + count($classes) * 100 + ($tag !== null ? 1 : 0);
            $parts[] = [$comb, [$tag, $ids, $classes]];
            $comb = ' ';
        }
        return $parts ? [$parts, $spec] : null;
    }

    private static function matches(DOMElement $el, array $sel): bool
    {
        $i = count($sel) - 1;
        if (!self::compound($el, $sel[$i][1])) {
            return false;
        }
        for (; $i > 0; $i--) {
            $comb = $sel[$i][0];
            $want = $sel[$i - 1][1];
            $el = $el->parentNode;
            if ($comb === '>') {
                if (!$el instanceof DOMElement || !self::compound($el, $want)) {
                    return false;
                }
                continue;
            }
            while ($el instanceof DOMElement && !self::compound($el, $want)) {
                $el = $el->parentNode;
            }
            if (!$el instanceof DOMElement) {
                return false;
            }
        }
        return true;
    }

    private static function compound(DOMElement $el, array $c): bool
    {
        [$tag, $ids, $classes] = $c;
        if ($tag !== null && $el->localName !== $tag) {
            return false;
        }
        foreach ($ids as $id) {
            if ($el->getAttribute('id') !== $id) {
                return false;
            }
        }
        if ($classes) {
            $have = preg_split('/\s+/', trim($el->getAttribute('class')));
            foreach ($classes as $cl) {
                if (!in_array($cl, $have, true)) {
                    return false;
                }
            }
        }
        return true;
    }
}
