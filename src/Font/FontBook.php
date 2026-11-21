<?php

declare(strict_types=1);

namespace YetiPdf\Write\Font;

use YetiPdf\Write\PdfException;

/** The fonts a document may use, grouped by family, with weight/style matching. */
final class FontBook
{
    /** @var array<string, list<Face>> lowercase family => faces */
    private array $families = [];
    /** @var array<string, list<Face>> */
    private array $listCache = [];

    public function __construct(private readonly ?string $cacheDir = null)
    {
    }

    public function addFile(string $path): Face
    {
        $face = Face::load($path, $this->cacheDir);
        $this->families[strtolower($face->family)][] = $face;
        $this->listCache = [];
        return $face;
    }

    public function addDirectory(string $dir): void
    {
        foreach (glob(rtrim($dir, '/') . '/*.{ttf,TTF}', GLOB_BRACE) ?: [] as $file) {
            $this->addFile($file);
        }
    }

    public function has(string $family): bool
    {
        return isset($this->families[strtolower($family)]);
    }

    /**
     * The faces for a font list ("Figtree, DejaVu Sans"), in order. Unknown families are
     * skipped; it's an error only when none of them is known.
     *
     * @return list<Face>
     */
    public function faces(string $list, int $weight, bool $italic): array
    {
        $key = "$list|$weight|" . (int) $italic;
        if (isset($this->listCache[$key])) {
            return $this->listCache[$key];
        }
        $out = [];
        foreach (explode(',', $list) as $family) {
            $family = trim($family, " \t\"'");
            if ($family !== '' && $this->has($family)) {
                $out[] = $this->select($family, $weight, $italic);
            }
        }
        if (!$out) {
            $this->select(trim(explode(',', $list)[0], " \t\"'"), $weight, $italic); // throws with the known families
        }
        return $this->listCache[$key] = $out;
    }

    /** Any registered face (closest weight and style) that has a glyph for the code point. */
    public function fallback(int $cp, int $weight, bool $italic): ?Face
    {
        foreach (array_keys($this->families) as $family) {
            $face = $this->select($family, $weight, $italic);
            if ($face->glyph($cp) !== 0) {
                return $face;
            }
        }
        return null;
    }

    /** Closest face: same style first, then nearest weight (heavier wins ties at 400+). */
    public function select(string $family, int $weight, bool $italic): Face
    {
        $faces = $this->families[strtolower($family)] ?? null;
        if (!$faces) {
            if (!$this->families) {
                throw new PdfException('No fonts registered');
            }
            throw new PdfException("Unknown font family '$family' (known: " . implode(', ', array_keys($this->families)) . ')');
        }
        $best = null;
        $bestScore = PHP_INT_MAX;
        foreach ($faces as $f) {
            $d = abs($f->weight - $weight);
            if ($f->weight !== $weight && ($f->weight > $weight) !== ($weight >= 400)) {
                $d += 1;
            }
            $score = ($f->italic === $italic ? 0 : 10000) + $d * 2;
            if ($score < $bestScore) {
                $best = $f;
                $bestScore = $score;
            }
        }
        return $best;
    }
}
