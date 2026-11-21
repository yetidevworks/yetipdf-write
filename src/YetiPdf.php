<?php

declare(strict_types=1);

namespace YetiPdf\Write;

use YetiPdf\Write\Font\FontBook;
use YetiPdf\Write\Image\ImageLoader;
use YetiPdf\Write\Layout\Frame;
use YetiPdf\Write\Layout\GlyphRun;
use YetiPdf\Write\Layout\Layouter;
use YetiPdf\Write\Layout\Regions;
use YetiPdf\Write\Pdf\PdfWriter;

/**
 * Renders YetiPDF markup to PDF.
 *
 *   $pdf = (new YetiPdf(fonts: [__DIR__ . '/fonts'], assets: [$imageDir], cache: $cacheDir))->render($markup);
 */
final class YetiPdf
{
    private const PAPER = [
        'a2' => [1190.55, 1683.78], 'a3' => [841.89, 1190.55], 'a4' => [595.28, 841.89], 'a5' => [419.53, 595.28], 'a6' => [297.64, 419.53],
        'b5' => [498.9, 708.66], 'tabloid' => [792.0, 1224.0], 'executive' => [522.0, 756.0],
        'letter' => [612.0, 792.0], 'us-letter' => [612.0, 792.0], 'legal' => [612.0, 1008.0],
    ];

    private FontBook $fonts;
    private ImageLoader $images;

    /**
     * @param list<string> $fonts font files or directories of .ttf files
     * @param list<string> $assets directories <image src> may read from (data: URIs and registered images always work)
     * @param string|null $cache directory for parsed font metrics and decoded images (strongly recommended)
     */
    public function __construct(array $fonts = [], array $assets = [], ?string $cache = null, private readonly bool $compress = true)
    {
        $this->fonts = new FontBook($cache);
        $this->images = new ImageLoader($assets, $cache);
        foreach ($fonts as $f) {
            is_dir($f) ? $this->fonts->addDirectory($f) : $this->fonts->addFile($f);
        }
    }

    public function fonts(): FontBook
    {
        return $this->fonts;
    }

    /** Make image bytes available to templates as <image src="@name">. */
    public function registerImage(string $name, string $bytes): self
    {
        $this->images->register($name, $bytes);
        return $this;
    }

    public function render(string|Node $doc): string
    {
        $root = $doc instanceof Node ? $doc : Markup::parse($doc);
        if ($root->type !== 'document') {
            throw new PdfException("The root element must be <document>, not <{$root->type}>");
        }

        $named = [];
        foreach ($root->children as $c) {
            if ($c instanceof Node && $c->type === 'style') {
                if (!$c->has('name')) {
                    throw new PdfException("<style> needs a name (line {$c->line})");
                }
                $attrs = $c->attrs;
                unset($attrs['name']);
                $named[$c->attrs['name']] = $attrs;
            }
        }

        $font = $root->attr('font') ?? $this->defaultFamily();
        $style = Style::apply(Style::base($font), $root, $named);
        $page = $root->child('page') ?? new Node('page');
        [$pw, $ph] = $this->paper($page);
        $size = $style['size'];
        $defMargin = 2.5 / 21 * min($pw, $ph);
        [$mt, $mr, $mb, $ml] = Value::sides($page, 'margin', $size, $pw, [$defMargin, $defMargin, $defMargin, $defMargin]);
        $bodyW = $pw - $ml - $mr;
        $bodyH = $ph - $mt - $mb;
        $ascent = $page->has('header-ascent') ? Value::abs($page->attrs['header-ascent'], $size, $mt) : 0.3 * $mt;
        $descent = $page->has('footer-descent') ? Value::abs($page->attrs['footer-descent'], $size, $mb) : 0.3 * $mb;
        $pageStyle = Style::apply($style, $page, $named);

        $header = $page->child('header');
        $footer = $page->child('footer');
        $background = $page->child('background');
        $meta = [
            'title' => $root->attr('title'),
            'author' => $root->attr('author'),
            'subject' => $root->attr('subject'),
        ];

        $lay = new Layouter($this->fonts, $named, $this->images);
        $bodyRegions = new Regions($bodyW, $bodyH, $bodyH, true, true);
        // The furniture around a page's body: background and header under it, footer over it.
        $furniture = function (int $i) use ($lay, $background, $header, $footer, $pageStyle, $named, $pw, $ph, $ml, $mt, $mb, $bodyW, $ascent, $descent): array {
            $lay->pageNumber = $i + 1;
            $under = new Frame($pw, $ph);
            if ($background && $this->shows($background, $i)) {
                $bg = $lay->flow($background->children, Style::apply($pageStyle, $background, $named), new Regions($pw, $ph, null, true, true));
                $under->place(0, 0, $bg[0]);
            }
            if ($header && $this->shows($header, $i)) {
                $h = $lay->flow($header->children, Style::apply($pageStyle, $header, $named), Regions::unbounded($bodyW, true))[0];
                $under->place($ml, $mt - $ascent - $h->h, $h);
            }
            $over = new Frame($pw, $ph);
            if ($footer && $this->shows($footer, $i)) {
                $f = $lay->flow($footer->children, Style::apply($pageStyle, $footer, $named), Regions::unbounded($bodyW, true))[0];
                $over->place($ml, $ph - $mb + $descent, $f);
            }
            return [$under, $over];
        };

        // First pass: each page's body is written as soon as it's laid out, so a long document
        // never holds more than a page of layout. Its background, header and footer are added
        // once the page count is known.
        $writer = new PdfWriter($meta, $this->compress);
        $bodies = [];
        $found = [];
        $lay->flow($root->children, $style, $bodyRegions, function (Frame $frame) use (&$bodies, &$found, $writer, $pw, $ph, $ml, $mt) {
            $this->markers($frame, count($bodies) + 1, $found);
            $p = new Frame($pw, $ph);
            $p->place($ml, $mt, $frame);
            $bodies[] = $writer->contents($p);
        });
        if ($found === [] && (!$lay->countInBody || $lay->pageCount === count($bodies))) {
            $root = null;
            $page = null;
            $lay->pageCount = count($bodies);
            $lay->furniture = true;
            foreach ($bodies as $i => $body) {
                $bodies[$i] = null;
                [$under, $over] = $furniture($i);
                $writer->addLayeredPage($pw, $ph, [$under, $body, $over]);
            }
            return $writer->output();
        }

        // Page numbers or counts in the body need to know where things land: lay out, look, and
        // lay out again until nothing moves (Typst iterates the same way). The pages are kept
        // until then and written afterwards.
        $lay->pageOf = $found;
        $lay->pageCount = count($bodies);
        $bodies = null;
        for ($pass = 1; ; $pass++) {
            $body = $lay->flow($root->children, $style, $bodyRegions);
            $found = [];
            foreach ($body as $i => $frame) {
                $this->markers($frame, $i + 1, $found);
            }
            $stable = $found == $lay->pageOf && (!$lay->countInBody || $lay->pageCount === count($body));
            if ($stable || $pass >= 3) {
                break;
            }
            $lay->pageOf = $found;
            $lay->pageCount = count($body);
        }
        $root = null;
        $page = null;
        $lay->pageCount = count($body);
        $lay->furniture = true;

        $writer = new PdfWriter($meta, $this->compress);
        foreach ($body as $i => $frame) {
            $body[$i] = null;
            [$under, $over] = $furniture($i);
            $p = new Frame($pw, $ph);
            $p->place(0, 0, $under);
            $p->place($ml, $mt, $frame);
            $p->place(0, 0, $over);
            $writer->addPage($p);
        }
        $body = null;
        $lay = null;
        return $writer->output();
    }

    private function markers(Frame $frame, int $page, array &$found): void
    {
        foreach ($frame->items as [, , $item]) {
            if ($item instanceof GlyphRun && $item->marker !== null) {
                $found[$item->marker] = $page;
            } elseif (is_array($item) && $item[0] === 'group') {
                $this->markers($item[1], $page, $found);
            }
        }
    }

    /** Header/footer/background visibility: pages="all" (default), "first", "not-first". */
    private function shows(Node $n, int $index): bool
    {
        return match ($n->attr('pages', 'all')) {
            'first' => $index === 0,
            'not-first', 'rest' => $index > 0,
            default => true,
        };
    }

    private function paper(Node $page): array
    {
        $size = strtolower($page->attr('paper', 'a4'));
        if (isset(self::PAPER[$size])) {
            [$w, $h] = self::PAPER[$size];
        } else {
            $parts = preg_split('/\s+/', trim($size));
            if (count($parts) !== 2) {
                throw new PdfException("Unknown page size '$size'");
            }
            $w = Value::abs($parts[0], 11);
            $h = Value::abs($parts[1], 11);
        }
        // PDF viewers handle 3pt to 14400pt (200 in) per side.
        if (!($w >= 3 && $w <= 14400 && $h >= 3 && $h <= 14400)) {
            throw new PdfException("Page size '$size' is out of range (3pt to 14400pt per side)");
        }
        if ($page->attr('orientation') === 'landscape' && $w < $h) {
            [$w, $h] = [$h, $w];
        }
        return [$w, $h];
    }

    private function defaultFamily(): string
    {
        foreach (['Figtree', 'Inter', 'DejaVu Sans'] as $f) {
            if ($this->fonts->has($f)) {
                return $f;
            }
        }
        throw new PdfException('Set a font on <document font="..."> (no default family found)');
    }
}
