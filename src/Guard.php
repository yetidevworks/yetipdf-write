<?php

declare(strict_types=1);

namespace YetiPdf\Write;

/**
 * Runs a decoder (font, image, SVG) so that malformed input ends as a PdfException with a
 * useful message: PHP warnings, ValueErrors and arithmetic errors inside it are converted,
 * rather than leaking out as notices, fatal errors or a quietly broken PDF.
 */
final class Guard
{
    /**
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    public static function run(string $what, callable $fn): mixed
    {
        set_error_handler(static function (int $no, string $msg) use ($what): never {
            throw new PdfException("$what is malformed ($msg)");
        });
        try {
            return $fn();
        } catch (PdfException $e) {
            throw $e;
        } catch (\ValueError | \ArithmeticError | \TypeError $e) {
            throw new PdfException("$what is malformed ({$e->getMessage()})", 0, $e);
        } finally {
            restore_error_handler();
        }
    }
}
