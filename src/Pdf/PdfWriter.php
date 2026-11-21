<?php

declare(strict_types=1);

namespace YetiPdf\Write\Pdf;

use YetiPdf\Write\Font\Face;
use YetiPdf\Write\Font\Subsetter;
use YetiPdf\Write\Image\Image;
use YetiPdf\Write\Layout\Frame;
use YetiPdf\Write\Layout\GlyphRun;
use YetiPdf\Write\Svg\Svg;

/**
 * Writes frames as a PDF 1.7 file: one content stream per page, fonts embedded as subset
 * CIDFontType2 with Identity-H encoding and a ToUnicode map (so text copies and searches).
 */
final class PdfWriter
{
    use VectorPainting;

    /** @var array<int,string> object id => body */
    private array $objects = [];
    private int $next = 3; // 1 = catalog, 2 = pages
    private array $pages = [];
    /**
     * Fonts by file. CIDs are handed out per (glyph, text) pair, so one glyph can extract as
     * different text (an inserted hyphen as a soft hyphen, a plain hyphen as itself).
     *
     * @var array<string, array{id:int, name:string, face:Face, cids:array<int|string,string>, next:int, used:array<int,array{0:int,1:string}>}>
     */
    private array $fonts = [];
    private bool $compress;
    /** @var array<string, array{id:int, name:string}> image key => XObject */
    private array $images = [];
    /** @var array<string, bool> */
    private static array $startsWithMark = [];
    /** @var array<string, string> */
    private array $actualTextHex = [];

    public function __construct(private readonly array $info = [], bool $compress = true)
    {
        $this->compress = $compress && function_exists('gzcompress');
    }

    private function alloc(): int
    {
        return $this->next++;
    }

    private function stream(string $data, string $dict = ''): string
    {
        if ($this->compress && strlen($data) > 64) {
            $data = gzcompress($data, 6);
            $dict .= ' /Filter /FlateDecode';
        }
        return '<<' . $dict . ' /Length ' . strlen($data) . ">>\nstream\n" . $data . "\nendstream";
    }

    private static function n(float $v): string
    {
        if ($v > -0.0005 && $v < 0.0005) {
            return '0';
        }
        $s = number_format($v, 3, '.', '');
        return rtrim(rtrim($s, '0'), '.');
    }

    public function addPage(Frame $page): void
    {
        $this->addLayeredPage($page->w, $page->h, [$page]);
    }

    /**
     * Write a frame (page-sized, y down) to a content stream of its own, for a page added later
     * with addLayeredPage(): how a page's body is written as soon as it's laid out.
     *
     * @return array{0: int, 1: array} the stream's object id and the resources it uses
     */
    public function contents(Frame $frame): array
    {
        $ops = [];
        $res = ['font' => [], 'image' => [], 'links' => []];
        $state = ['font' => null, 'size' => null, 'color' => null, 'inText' => false];
        $this->emit($ops, $state, $res, $frame->items, 0.0, $frame->h);
        if ($state['inText']) {
            $ops[] = 'ET';
        }
        $content = $this->alloc();
        $stream = implode("\n", $ops);
        $ops = [];
        $this->objects[$content] = $this->stream($stream);
        return [$content, $res];
    }

    /**
     * A page drawn from layers in order, each a Frame (written now) or a stream from contents().
     * Empty frames are left out.
     *
     * @param list<Frame|array{0: int, 1: array}> $layers
     */
    public function addLayeredPage(float $w, float $h, array $layers): void
    {
        $ids = [];
        $res = ['font' => [], 'image' => [], 'links' => []];
        foreach ($layers as $layer) {
            if ($layer instanceof Frame) {
                if ($layer->items === [] && count($layers) > 1) {
                    continue;
                }
                $layer = $this->contents($layer);
            }
            [$id, $r] = $layer;
            $ids[] = $id;
            foreach ($r as $k => $v) {
                $res[$k] = $k === 'links' ? [...($res[$k] ?? []), ...$v] : ($res[$k] ?? []) + $v;
            }
        }
        $dict = '/Font << ';
        foreach ($res['font'] as $name => $id) {
            $dict .= "/$name $id 0 R ";
        }
        $dict .= '>>';
        if ($res['image']) {
            $dict .= ' /XObject << ';
            foreach ($res['image'] as $name => $id) {
                $dict .= "/$name $id 0 R ";
            }
            $dict .= '>>';
        }
        $dict .= $this->vectorResources($res);
        $annots = '';
        foreach ($res['links'] as [$x0, $y0, $x1, $y1, $href]) {
            $aid = $this->alloc();
            $this->objects[$aid] = '<< /Type /Annot /Subtype /Link /Rect [' . self::n($x0) . ' ' . self::n($y0) . ' ' . self::n($x1) . ' ' . self::n($y1) . ']'
                . ' /Border [0 0 0] /A << /S /URI /URI <' . bin2hex($href) . '> >> >>';
            $annots .= "$aid 0 R ";
        }
        $contents = count($ids) === 1 ? $ids[0] . ' 0 R' : '[' . implode(' ', array_map(fn ($id) => "$id 0 R", $ids)) . ']';
        $pid = $this->alloc();
        $this->objects[$pid] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ' . self::n($w) . ' ' . self::n($h) . ']'
            . ' /Resources << ' . $dict . ' >> /Contents ' . $contents
            . ($annots !== '' ? ' /Annots [' . rtrim($annots) . ']' : '') . ' >>';
        $this->pages[] = $pid;
    }

    /**
     * Write items whose y axis points down, with (bx, by) the PDF position of their origin:
     * an item at (x, y) lands at (bx + x, by - y).
     */
    private function emit(array &$ops, array &$state, array &$res, array $items, float $bx, float $by, bool $page = true): void
    {
        foreach ($items as [$x, $y, $item]) {
            if ($item instanceof GlyphRun) {
                $this->text($ops, $state, $res, $bx + $x, $by - $y, $item);
                continue;
            }
            if ($state['inText']) {
                $ops[] = 'ET';
                $state['inText'] = false;
            }
            if ($item[0] === 'group') {
                [, $frame, [$a, $b, $c, $d, $e, $f]] = $item;
                // y-down matrix to PDF: [a, -b, -c, d, px + e, py - f], then children draw with y negated.
                $m = [$a, -$b, -$c, $d, $bx + $x + $e, $by - $y - $f];
                $ops[] = 'q ' . implode(' ', array_map(fn ($v) => self::nf($v, 6), $m)) . ' cm';
                $inner = ['font' => null, 'size' => null, 'color' => null, 'inText' => false, 'ctm' => self::mul($m, $state['ctm'] ?? [1, 0, 0, 1, 0, 0])];
                $this->emit($ops, $inner, $res, $frame->items, 0.0, 0.0, false);
                if ($inner['inText']) {
                    $ops[] = 'ET';
                }
                $ops[] = 'Q';
                continue;
            }
            if ($item[0] === 'link') {
                // Annotations live in page space; inside a rotated group they're skipped.
                if ($page) {
                    $res['links'][] = [$bx + $x, $by - $y - $item[2], $bx + $x + $item[1], $by - $y, $item[3]];
                }
                continue;
            }
            if ($item[0] === 'image') {
                $this->image($ops, $res, $bx + $x, $by - $y, $item);
                continue;
            }
            if ($item[0] === 'path' || $item[0] === 'clip' || $item[0] === 'opacity') {
                $this->vector($ops, $state, $res, $item, $bx + $x, $by - $y);
                continue;
            }
            $this->drawing($ops, $bx + $x, $by - $y, $item);
        }
    }

    private function image(array &$ops, array &$res, float $x, float $y, array $item): void
    {
        [, $img, $w, $h, $dx, $dy, $iw, $ih, $clip] = $item;
        $xo = $img instanceof Svg ? $this->form($img) : $this->xobject($img);
        $res['image'][$xo['name']] = $xo['id'];
        $op = 'q ';
        if ($clip) {
            $op .= self::n($x) . ' ' . self::n($y - $h) . ' ' . self::n($w) . ' ' . self::n($h) . ' re W n ';
        }
        $op .= self::n($iw) . ' 0 0 ' . self::n($ih) . ' ' . self::n($x + $dx) . ' ' . self::n($y - $dy - $ih) . ' cm ';
        if ($img instanceof Image && $img->orientation !== 1) {
            $op .= implode(' ', $img->orient()) . ' cm ';
        }
        $op .= '/' . $xo['name'] . ' Do Q';
        $ops[] = $op;
    }

    /** @return array{id:int, name:string} */
    private function xobject(Image $img): array
    {
        if (isset($this->images[$img->key])) {
            return $this->images[$img->key];
        }
        $id = $this->alloc();
        $dict = ' /Type /XObject /Subtype /Image /Width ' . $img->px . ' /Height ' . $img->py . ' ' . $img->dict;
        if ($img->smask !== null) {
            $sid = $this->alloc();
            $m = $img->smask;
            $this->objects[$sid] = '<< /Type /XObject /Subtype /Image /Width ' . $m->px . ' /Height ' . $m->py . ' ' . $m->dict
                . ' /Length ' . strlen($m->data) . ">>\nstream\n" . $m->data . "\nendstream";
            $dict .= " /SMask $sid 0 R";
        }
        $this->objects[$id] = '<<' . $dict . ' /Length ' . strlen($img->data) . ">>\nstream\n" . $img->data . "\nendstream";
        return $this->images[$img->key] = ['id' => $id, 'name' => 'Im' . (count($this->images) + 1)];
    }

    private function text(array &$ops, array &$state, array &$res, float $x, float $y, GlyphRun $run): void
    {
        $key = $run->face->path;
        if (!isset($this->fonts[$key])) {
            $this->fonts[$key] = ['id' => $this->alloc(), 'name' => 'F' . (count($this->fonts) + 1), 'face' => $run->face, 'cids' => [], 'next' => 0, 'used' => []];
        }
        $font = &$this->fonts[$key];
        $fname = $font['name'];
        $res['font'][$fname] = $font['id'];
        if (!$state['inText']) {
            $ops[] = 'BT';
            $state['inText'] = true;
            $state['font'] = null;
            $state['size'] = null;
        }
        $size = $run->size;
        if ($state['font'] !== $fname || $state['size'] !== $size) {
            $ops[] = '/' . $fname . ' ' . self::n($size) . ' Tf';
            $state['font'] = $fname;
            $state['size'] = $size;
        }
        $c = $run->color;
        $sc = $state['color'];
        if ($sc === null || $sc[0] != $c[0] || $sc[1] != $c[1] || $sc[2] != $c[2]) {
            $ops[] = self::n($c[0]) . ' ' . self::n($c[1]) . ' ' . self::n($c[2]) . ' rg';
            $state['color'] = $c;
        }
        // Right-to-left runs hold their glyphs in display order: marked as reversed, so text
        // extraction reads them back in reading order.
        $rtl = ($run->level & 1) === 1;
        if ($rtl) {
            $ops[] = '/ReversedChars BMC';
        }
        $ops[] = '1 0 0 1 ' . self::n($x) . ' ' . self::n($y) . ' Tm';
        $adv = $run->face->advances;
        $upem = $run->face->upem;
        $glyphs = $run->glyphs;
        $cids = &$font['cids'];
        $used = &$font['used'];
        if (!$run->marks) {
            // No marks: no offsets or clusters to look after.
            $tj = '';
            $hex = '';
            foreach ($glyphs as [$g, $a, $t]) {
                $ck = strlen($t) === 1 ? (($g << 8) | ord($t)) : ($g . "\0" . $t);
                if (isset($cids[$ck])) {
                    $hex .= $cids[$ck];
                } else {
                    $cid = ++$font['next'];
                    $used[$cid] = [$g, $t];
                    $h = sprintf('%04x', $cid);
                    $cids[$ck] = $h;
                    $hex .= $h;
                }
                // TJ adjustment: difference between the font's advance and the laid-out advance.
                $adj = ($adv[$g] / $upem - $a / $size) * 1000;
                if ($adj > 0.05 || $adj < -0.05) {
                    $tj .= '<' . $hex . '> ' . self::n($adj) . ' ';
                    $hex = '';
                }
            }
            $ops[] = '[' . $tj . ($hex !== '' ? '<' . $hex . '>' : '') . '] TJ';
            if ($rtl) {
                $ops[] = 'EMC';
            }
            return;
        }
        $unit = 1000 / $size; // pt to thousandths of the text size, for TJ
        $tj = ''; // the TJ array being written: hex strings and adjustments
        $hex = ''; // glyphs not yet closed into a hex string
        $rise = 0.0;
        $spans = self::clusters($glyphs, $rtl);
        $spanEnd = -1;
        foreach ($glyphs as $i => $glyph) {
            [$g, $a, $t] = $glyph;
            if (isset($spans[$i])) {
                if ($hex !== '') {
                    $tj .= '<' . $hex . '>';
                    $hex = '';
                }
                if ($tj !== '') {
                    $ops[] = '[' . $tj . '] TJ';
                    $tj = '';
                }
                $stext = $spans[$i][1];
                $ops[] = $this->actualTextHex[$stext] ??= '/Span<</ActualText<feff' . bin2hex(mb_convert_encoding($stext, 'UTF-16BE', 'UTF-8')) . '>>>BDC';
                $spanEnd = $spans[$i][0];
            }
            $ck = strlen($t) === 1 ? (($g << 8) | ord($t)) : ($g . "\0" . $t);
            if (isset($cids[$ck])) {
                $h = $cids[$ck];
            } else {
                $cid = ++$font['next'];
                $used[$cid] = [$g, $t];
                $h = sprintf('%04x', $cid);
                $cids[$ck] = $h;
            }
            // A placed mark: moved by its offset (a TJ adjustment across, text rise up), then the
            // pen goes back to where the run continues.
            $dx = $glyph[3] ?? 0.0;
            $dy = $glyph[4] ?? 0.0;
            if ($dy != $rise) {
                if ($hex !== '') {
                    $tj .= '<' . $hex . '>';
                    $hex = '';
                }
                if ($tj !== '') {
                    $ops[] = '[' . $tj . '] TJ';
                    $tj = '';
                }
                $ops[] = self::n($dy) . ' Ts';
                $rise = $dy;
            }
            if ($dx > 1e-6 || $dx < -1e-6) {
                if ($hex !== '') {
                    $tj .= '<' . $hex . '>';
                    $hex = '';
                }
                $tj .= ' ' . self::n(-$dx * $unit) . ' ';
            }
            $hex .= $h;
            // TJ adjustment: difference between the font's advance and the laid-out advance.
            $adj = ($adv[$g] / $upem - $a / $size) * 1000 + $dx * $unit;
            if ($adj > 0.05 || $adj < -0.05) {
                $tj .= '<' . $hex . '> ' . self::n($adj) . ' ';
                $hex = '';
            }
            if ($i === $spanEnd) {
                if ($hex !== '') {
                    $tj .= '<' . $hex . '>';
                    $hex = '';
                }
                if ($tj !== '') {
                    $ops[] = '[' . $tj . '] TJ';
                    $tj = '';
                }
                $ops[] = 'EMC';
            }
        }
        if ($hex !== '') {
            $tj .= '<' . $hex . '>';
        }
        if ($tj !== '') {
            $ops[] = '[' . $tj . '] TJ';
        }
        if ($rise != 0.0) {
            $ops[] = '0 Ts';
        }
        if ($rtl) {
            $ops[] = 'EMC';
        }
    }

    /**
     * The clusters of a run with marks: [first glyph => [last glyph, text]]. A letter and its
     * marks is written as one span whose text, in reading order, stands for it; extraction would
     * otherwise see the marks as characters of their own. In right-to-left runs (in display
     * order) the marks come before their letter.
     */
    private static function clusters(array $glyphs, bool $rtl): array
    {
        // (A glyph without text of its own, like a mark whose text went with another, joins
        // its cluster too.)
        $spans = [];
        $n = count($glyphs);
        if ($rtl) {
            for ($i = 0; $i < $n; $i++) {
                $j = $i;
                while ($j < $n) {
                    $t = $glyphs[$j][2];
                    if ($t !== '' && (ord($t[0]) < 0x80 || !(self::$startsWithMark[$t] ??= preg_match('/^\p{M}/u', $t) === 1))) {
                        break;
                    }
                    $j++;
                }
                if ($j > $i && $j < $n) {
                    $text = $glyphs[$j][2];
                    for ($m = $j - 1; $m >= $i; $m--) {
                        $text .= $glyphs[$m][2];
                    }
                    $spans[$i] = [$j, $text];
                }
                $i = $j;
            }
        } else {
            for ($i = 0; $i < $n; $i++) {
                $j = $i;
                while ($j + 1 < $n) {
                    $t = $glyphs[$j + 1][2];
                    if ($t !== '' && (ord($t[0]) < 0x80 || !(self::$startsWithMark[$t] ??= preg_match('/^\p{M}/u', $t) === 1))) {
                        break;
                    }
                    $j++;
                }
                if ($j > $i) {
                    $text = '';
                    for ($m = $i; $m <= $j; $m++) {
                        $text .= $glyphs[$m][2];
                    }
                    $spans[$i] = [$j, $text];
                }
                $i = $j;
            }
        }
        return $spans;
    }

    private function strokeOps(array $s): string
    {
        $c = $s['color'];
        $ops = self::n($s['w']) . ' w ' . self::n($c[0]) . ' ' . self::n($c[1]) . ' ' . self::n($c[2]) . ' RG';
        if ($s['dash']) {
            $ops .= ' [' . self::n($s['dash'][0]) . ' ' . self::n($s['dash'][1]) . '] 0 d';
        }
        return $ops;
    }

    private function drawing(array &$ops, float $x, float $y, array $item): void
    {
        if ($item[0] === 'line') {
            [, $dx, $dy, $stroke] = $item;
            $ops[] = 'q ' . $this->strokeOps($stroke) . ' ' . self::n($x) . ' ' . self::n($y) . ' m '
                . self::n($x + $dx) . ' ' . self::n($y - $dy) . ' l S Q';
            return;
        }
        if ($item[0] === 'rect') {
            [, $w, $h, $fill, $stroke, $r] = $item;
            $path = $r > 0 ? $this->roundRect($x, $y - $h, $w, $h, min($r, $w / 2, $h / 2))
                : self::n($x) . ' ' . self::n($y - $h) . ' ' . self::n($w) . ' ' . self::n($h) . ' re';
            $op = 'q ';
            if ($fill) {
                $op .= self::n($fill[0]) . ' ' . self::n($fill[1]) . ' ' . self::n($fill[2]) . ' rg ';
            }
            if ($stroke) {
                $op .= $this->strokeOps($stroke) . ' ';
            }
            $op .= $path . ' ' . ($fill && $stroke ? 'B' : ($fill ? 'f' : 'S')) . ' Q';
            $ops[] = $op;
        }
    }

    private function roundRect(float $x, float $y, float $w, float $h, float $r): string
    {
        $k = 0.5523 * $r;
        $n = fn ($v) => self::n($v);
        $x1 = $x + $w;
        $y1 = $y + $h;
        return implode(' ', [
            $n($x + $r), $n($y), 'm',
            $n($x1 - $r), $n($y), 'l',
            $n($x1 - $r + $k), $n($y), $n($x1), $n($y + $r - $k), $n($x1), $n($y + $r), 'c',
            $n($x1), $n($y1 - $r), 'l',
            $n($x1), $n($y1 - $r + $k), $n($x1 - $r + $k), $n($y1), $n($x1 - $r), $n($y1), 'c',
            $n($x + $r), $n($y1), 'l',
            $n($x + $r - $k), $n($y1), $n($x), $n($y1 - $r + $k), $n($x), $n($y1 - $r), 'c',
            $n($x), $n($y + $r), 'l',
            $n($x), $n($y + $r - $k), $n($x + $r - $k), $n($y), $n($x + $r), $n($y), 'c',
            'h',
        ]);
    }

    private function embedFonts(): void
    {
        foreach ($this->fonts as $f) {
            /** @var Face $face */
            $face = $f['face'];
            $m = $face->m;
            $used = $f['used'];
            ksort($used);
            $gids = array_unique(array_column($used, 0));
            sort($gids);
            $tag = '';
            $h = crc32($face->path . implode(',', $gids));
            for ($i = 0; $i < 6; $i++) {
                $tag .= chr(65 + $h % 26);
                $h = intdiv($h, 26);
            }
            $name = $tag . '+' . $m['postScriptName'];
            $scale = 1000 / $face->upem;

            $sub = \YetiPdf\Write\Guard::run('Font ' . basename($face->path), fn () => (new Subsetter((string) file_get_contents($face->path)))->subset(array_fill_keys($gids, true), $m['numGlyphs']));
            $file = $this->alloc();
            $this->objects[$file] = $this->stream($sub, ' /Length1 ' . strlen($sub));

            $desc = $this->alloc();
            $bb = array_map(fn ($v) => self::n($v * $scale), $m['bbox']);
            $flags = 4 | ($m['italic'] ? 64 : 0);
            $this->objects[$desc] = '<< /Type /FontDescriptor /FontName /' . $name . ' /Flags ' . $flags
                . ' /FontBBox [' . implode(' ', $bb) . '] /ItalicAngle ' . self::n($m['italicAngle'])
                . ' /Ascent ' . self::n($m['ascender'] * $scale) . ' /Descent ' . self::n($m['descender'] * $scale)
                . ' /CapHeight ' . self::n($m['capHeight'] * $scale) . ' /StemV ' . (int) (50 + $m['weight'] / 65 * 8)
                . ' /FontFile2 ' . $file . ' 0 R >>';

            $w = '';
            $prev = -2;
            $mapGids = [0];
            $advances = $face->advances;
            foreach ($used as $cid => [$g]) {
                $w .= ($cid === $prev + 1 ? ' ' : ($prev >= 0 ? '] ' : '') . $cid . ' [') . self::n($advances[$g] * $scale);
                $prev = $cid;
                $mapGids[] = $g;
            }
            $w .= $used ? ']' : '';
            $mapId = $this->alloc();
            $this->objects[$mapId] = $this->stream(pack('n*', ...$mapGids));

            $cid = $this->alloc();
            $this->objects[$cid] = '<< /Type /Font /Subtype /CIDFontType2 /BaseFont /' . $name
                . ' /CIDSystemInfo << /Registry (Adobe) /Ordering (Identity) /Supplement 0 >>'
                . ' /FontDescriptor ' . $desc . ' 0 R /CIDToGIDMap ' . $mapId . ' 0 R /W [' . $w . '] >>';

            $cmap = $this->alloc();
            $this->objects[$cmap] = $this->stream($this->toUnicode($used));

            $this->objects[$f['id']] = '<< /Type /Font /Subtype /Type0 /BaseFont /' . $name
                . ' /Encoding /Identity-H /DescendantFonts [' . $cid . ' 0 R] /ToUnicode ' . $cmap . ' 0 R >>';
        }
    }

    private function toUnicode(array $used): string
    {
        $entries = [];
        foreach ($used as $cid => [, $t]) {
            if (strlen($t) === 1 && ($b = ord($t)) < 0x80) {
                $entries[] = sprintf('<%04x> <00%02x>', $cid, $b);
            } else {
                $entries[] = sprintf('<%04x> <%s>', $cid, bin2hex(mb_convert_encoding($t, 'UTF-16BE', 'UTF-8')));
            }
        }
        $out = "/CIDInit /ProcSet findresource begin\n12 dict begin\nbegincmap\n"
            . "/CIDSystemInfo << /Registry (Adobe) /Ordering (UCS) /Supplement 0 >> def\n"
            . "/CMapName /Adobe-Identity-UCS def\n/CMapType 2 def\n"
            . "1 begincodespacerange\n<0000> <ffff>\nendcodespacerange\n";
        foreach (array_chunk($entries, 100) as $chunk) {
            $out .= count($chunk) . " beginbfchar\n" . implode("\n", $chunk) . "\nendbfchar\n";
        }
        return $out . "endcmap\nCMapName currentdict /CMap defineresource pop\nend\nend";
    }

    private static function textString(string $s): string
    {
        return '<feff' . bin2hex(mb_convert_encoding($s, 'UTF-16BE', 'UTF-8')) . '>';
    }

    public function output(): string
    {
        $this->embedFonts();
        $this->objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $this->objects[2] = '<< /Type /Pages /Kids [' . implode(' ', array_map(fn ($p) => "$p 0 R", $this->pages)) . '] /Count ' . count($this->pages) . ' >>';
        $info = $this->alloc();
        $dict = '/Producer ' . self::textString('YetiPDF Write') . ' /CreationDate (D:' . gmdate('YmdHis') . 'Z)';
        foreach (['title' => 'Title', 'author' => 'Author', 'subject' => 'Subject'] as $k => $key) {
            if (!empty($this->info[$k])) {
                $dict .= " /$key " . self::textString($this->info[$k]);
            }
        }
        $this->objects[$info] = '<< ' . $dict . ' >>';

        ksort($this->objects);
        $out = "%PDF-1.7\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        foreach ($this->objects as $id => $body) {
            $offsets[$id] = strlen($out);
            $out .= "$id 0 obj\n$body\nendobj\n";
        }
        $xref = strlen($out);
        $count = $this->next;
        $out .= "xref\n0 $count\n0000000000 65535 f \n";
        for ($i = 1; $i < $count; $i++) {
            $out .= isset($offsets[$i]) ? sprintf("%010d 00000 n \n", $offsets[$i]) : "0000000000 65535 f \n";
        }
        $id = md5($out);
        $out .= "trailer\n<< /Size $count /Root 1 0 R /Info $info 0 R /ID [<$id> <$id>] >>\nstartxref\n$xref\n%%EOF\n";
        return $out;
    }
}
