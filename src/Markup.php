<?php

declare(strict_types=1);

namespace YetiPdf\Write;

/**
 * Parses YetiPDF markup (XML elements, usually produced by a Twig template) into a Node tree.
 * libxml does the parsing in C; external entities and network access stay off.
 */
final class Markup
{
    /** Deeper than this is almost certainly a runaway template; real documents stay under 30. */
    private const MAX_DEPTH = 200;

    public static function parse(string $xml): Node
    {
        if (trim($xml) === '') {
            throw new PdfException('Markup error: the document is empty');
        }
        // Templates never need a DTD, and refusing one rules out entity expansion entirely.
        if (preg_match('/<!(DOCTYPE|ENTITY)/i', $xml)) {
            throw new PdfException('Markup error: DOCTYPE and ENTITY declarations are not allowed');
        }
        $prev = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $dom = new \DOMDocument();
        $ok = $dom->loadXML($xml, LIBXML_NONET | LIBXML_NOCDATA | LIBXML_COMPACT);
        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        if (!$ok || !$dom->documentElement) {
            $e = $errors[0] ?? null;
            $msg = $e ? trim($e->message) . " on line {$e->line}" : 'unknown error';
            if ($e && str_contains($e->message, 'Excessive depth')) {
                $msg = 'elements are nested more than ' . self::MAX_DEPTH . " deep (line {$e->line})";
            }
            throw new PdfException("Markup error: $msg");
        }
        return self::node($dom->documentElement, 0);
    }

    private static function node(\DOMElement $el, int $depth): Node
    {
        if ($depth > self::MAX_DEPTH) {
            throw new PdfException('Markup error: elements are nested more than ' . self::MAX_DEPTH . ' deep (line ' . $el->getLineNo() . ')');
        }
        $attrs = [];
        foreach ($el->attributes as $a) {
            $attrs[$a->nodeName] = $a->nodeValue;
        }
        $children = [];
        foreach ($el->childNodes as $c) {
            if ($c instanceof \DOMElement) {
                $children[] = self::node($c, $depth + 1);
            } elseif ($c instanceof \DOMText) {
                $children[] = $c->nodeValue;
            }
        }
        return new Node($el->nodeName, $attrs, $children, $el->getLineNo());
    }
}
