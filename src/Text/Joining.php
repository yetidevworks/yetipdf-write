<?php

declare(strict_types=1);

namespace YetiPdf\Write\Text;

/**
 * Which form each letter of an Arabic-style script takes (isolated, final, medial, initial, and
 * Syriac's extra finals), from how it and its neighbours join: HarfBuzz's state machine over the
 * joining types of ArabicShaping.txt.
 */
final class Joining
{
    // Joining types, as resources/unicode.php numbers them.
    private const U = 0;
    private const T = 7;

    // Forms.
    public const NONE = '';

    /**
     * HarfBuzz's table: per state, per joining type (U, L, R, D, Alaph, Dalath/Rish), [form for the
     * letter before, form for this one, next state].
     */
    private const STATES = [
        // 0: the letter before doesn't join on.
        [['', '', 0], ['', 'isol', 2], ['', 'isol', 1], ['', 'isol', 2], ['', 'isol', 1], ['', 'isol', 6]],
        // 1: the letter before is right-joining (or an isolated Alaph) and doesn't join on.
        [['', '', 0], ['', 'isol', 2], ['', 'isol', 1], ['', 'isol', 2], ['', 'fin2', 5], ['', 'isol', 6]],
        // 2: the letter before is dual- or left-joining, isolated so far, and joins on.
        [['', '', 0], ['', 'isol', 2], ['init', 'fina', 1], ['init', 'fina', 3], ['init', 'fina', 4], ['init', 'fina', 6]],
        // 3: the letter before is dual-joining, final so far, and joins on.
        [['', '', 0], ['', 'isol', 2], ['medi', 'fina', 1], ['medi', 'fina', 3], ['medi', 'fina', 4], ['medi', 'fina', 6]],
        // 4: the letter before is a final Alaph.
        [['', '', 0], ['', 'isol', 2], ['med2', 'isol', 1], ['med2', 'isol', 2], ['med2', 'fin2', 5], ['med2', 'isol', 6]],
        // 5: the letter before is an Alaph in its second or third final form.
        [['', '', 0], ['', 'isol', 2], ['isol', 'isol', 1], ['isol', 'isol', 2], ['isol', 'fin2', 5], ['isol', 'isol', 6]],
        // 6: the letter before is Dalath or Rish.
        [['', '', 0], ['', 'isol', 2], ['', 'isol', 1], ['', 'isol', 2], ['', 'fin3', 5], ['', 'isol', 6]],
    ];

    /** @var array<int, int> */
    private static array $fallbackTypes = [];

    /** A character's joining type: listed, else transparent for marks and format characters, else non-joining. */
    public static function type(int $cp): int
    {
        if ($cp < 0x80) {
            return self::U;
        }
        return Unicode::data()['joining'][$cp]
            ?? (self::$fallbackTypes[$cp] ??= preg_match('/^[\p{Mn}\p{Me}\p{Cf}]$/u', mb_chr($cp)) ? self::T : self::U);
    }

    /**
     * The form each character takes ('' for none: non-joining and transparent ones).
     *
     * @param list<int> $cps
     * @return list<string>
     */
    public static function forms(array $cps): array
    {
        $forms = array_fill(0, count($cps), self::NONE);
        $joining = Unicode::data()['joining'];
        $prev = -1;
        $state = 0;
        foreach ($cps as $i => $cp) {
            if ($cp < 0x80) {
                $type = self::U;
            } else {
                $type = $joining[$cp]
                    ?? (self::$fallbackTypes[$cp] ??= preg_match('/^[\p{Mn}\p{Me}\p{Cf}]$/u', mb_chr($cp)) ? self::T : self::U);
            }
            if ($type === self::T) {
                continue;
            }
            [$before, $own, $state] = self::STATES[$state][$type];
            if ($before !== '' && $prev >= 0) {
                $forms[$prev] = $before;
            }
            $forms[$i] = $own;
            $prev = $i;
        }
        return $forms;
    }
}
