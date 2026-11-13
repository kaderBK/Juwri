<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Pos\Services\Printing;

/**
 * PARITY-RUN T1-2: contextual Arabic shaping + a poor-man's bidi reorder.
 *
 * Why this exists at all: an ESC/POS printer draws glyphs, it does not do text
 * layout. Hand it logical-order Arabic and you get isolated, left-to-right
 * letters — technically "Arabic support", practically unreadable. Both output
 * lanes need the same fix first:
 *
 *   • raster lane  — GD draws glyphs one after another with no shaping engine,
 *                    so it needs presentation forms in visual order;
 *   • text lane    — a printer with an Arabic code page maps U+FE70..U+FEFC
 *                    directly, so the same transform makes the cheap lane work
 *                    too (this is a strict improvement on the raw UTF-8
 *                    passthrough documented in kiosk/SUNMI.md).
 *
 * Deliberately NOT a full bidi implementation: it reverses runs rather than
 * resolving embedding levels, which is correct for receipt-shaped text (a line
 * of Arabic with Latin/numeric islands) and predictable everywhere else.
 * Tashkeel is stripped — thermal heads smear combining marks at 203 dpi.
 */
class ArabicShaper
{
    /**
     * base codepoint => [isolated, final, initial, medial].
     * Two-entry rows are right-joining letters (they never take an initial or
     * medial form, so the letter AFTER them always starts a new joining group).
     */
    private const FORMS = [
        0x0621 => [0xFE80],
        0x0622 => [0xFE81, 0xFE82],
        0x0623 => [0xFE83, 0xFE84],
        0x0624 => [0xFE85, 0xFE86],
        0x0625 => [0xFE87, 0xFE88],
        0x0626 => [0xFE89, 0xFE8A, 0xFE8B, 0xFE8C],
        0x0627 => [0xFE8D, 0xFE8E],
        0x0628 => [0xFE8F, 0xFE90, 0xFE91, 0xFE92],
        0x0629 => [0xFE93, 0xFE94],
        0x062A => [0xFE95, 0xFE96, 0xFE97, 0xFE98],
        0x062B => [0xFE99, 0xFE9A, 0xFE9B, 0xFE9C],
        0x062C => [0xFE9D, 0xFE9E, 0xFE9F, 0xFEA0],
        0x062D => [0xFEA1, 0xFEA2, 0xFEA3, 0xFEA4],
        0x062E => [0xFEA5, 0xFEA6, 0xFEA7, 0xFEA8],
        0x062F => [0xFEA9, 0xFEAA],
        0x0630 => [0xFEAB, 0xFEAC],
        0x0631 => [0xFEAD, 0xFEAE],
        0x0632 => [0xFEAF, 0xFEB0],
        0x0633 => [0xFEB1, 0xFEB2, 0xFEB3, 0xFEB4],
        0x0634 => [0xFEB5, 0xFEB6, 0xFEB7, 0xFEB8],
        0x0635 => [0xFEB9, 0xFEBA, 0xFEBB, 0xFEBC],
        0x0636 => [0xFEBD, 0xFEBE, 0xFEBF, 0xFEC0],
        0x0637 => [0xFEC1, 0xFEC2, 0xFEC3, 0xFEC4],
        0x0638 => [0xFEC5, 0xFEC6, 0xFEC7, 0xFEC8],
        0x0639 => [0xFEC9, 0xFECA, 0xFECB, 0xFECC],
        0x063A => [0xFECD, 0xFECE, 0xFECF, 0xFED0],
        0x0640 => [0x0640, 0x0640, 0x0640, 0x0640], // tatweel joins both sides
        0x0641 => [0xFED1, 0xFED2, 0xFED3, 0xFED4],
        0x0642 => [0xFED5, 0xFED6, 0xFED7, 0xFED8],
        0x0643 => [0xFED9, 0xFEDA, 0xFEDB, 0xFEDC],
        0x0644 => [0xFEDD, 0xFEDE, 0xFEDF, 0xFEE0],
        0x0645 => [0xFEE1, 0xFEE2, 0xFEE3, 0xFEE4],
        0x0646 => [0xFEE5, 0xFEE6, 0xFEE7, 0xFEE8],
        0x0647 => [0xFEE9, 0xFEEA, 0xFEEB, 0xFEEC],
        0x0648 => [0xFEED, 0xFEEE],
        0x0649 => [0xFEEF, 0xFEF0],
        0x064A => [0xFEF1, 0xFEF2, 0xFEF3, 0xFEF4],
        0x0671 => [0xFB50, 0xFB51],
        0x067E => [0xFB56, 0xFB57, 0xFB58, 0xFB59], // peh
        0x0686 => [0xFB7A, 0xFB7B, 0xFB7C, 0xFB7D], // tcheh
        0x0698 => [0xFB8A, 0xFB8B],                 // jeh
        0x06A9 => [0xFB8E, 0xFB8F, 0xFB90, 0xFB91], // keheh
        0x06AF => [0xFB92, 0xFB93, 0xFB94, 0xFB95], // gaf
        0x06CC => [0xFBFC, 0xFBFD, 0xFBFE, 0xFBFF], // farsi yeh
    ];

    /** LAM + alef variant => [isolated ligature, final ligature]. */
    private const LAM_ALEF = [
        0x0622 => [0xFEF5, 0xFEF6],
        0x0623 => [0xFEF7, 0xFEF8],
        0x0625 => [0xFEF9, 0xFEFA],
        0x0627 => [0xFEFB, 0xFEFC],
    ];

    /** Does the string contain any right-to-left script? */
    public static function isRtl(string $text): bool
    {
        return (bool) preg_match('/[\x{0590}-\x{05FF}\x{0600}-\x{06FF}\x{0750}-\x{077F}\x{FB50}-\x{FDFF}\x{FE70}-\x{FEFF}]/u', $text);
    }

    /**
     * Shape + visually reorder a single line. Non-Arabic input is returned
     * unchanged, so this is safe to call on every line unconditionally.
     */
    public static function shape(string $text): string
    {
        if (! self::isRtl($text)) {
            return $text;
        }

        return self::reorder(self::contextualize(self::stripTashkeel($text)));
    }

    /** Drop combining marks; a thermal head cannot render them legibly. */
    public static function stripTashkeel(string $text): string
    {
        return preg_replace('/[\x{064B}-\x{0655}\x{0670}]/u', '', $text) ?? $text;
    }

    /** Logical-order presentation forms (no reordering yet). */
    public static function contextualize(string $text): string
    {
        $chars = self::codepoints($text);
        $out = [];
        $count = count($chars);

        for ($i = 0; $i < $count; $i++) {
            $cp = $chars[$i];

            if (! isset(self::FORMS[$cp])) {
                $out[] = $cp;
                continue;
            }

            // LAM followed by an alef variant collapses into one ligature glyph.
            if ($cp === 0x0644 && isset($chars[$i + 1]) && isset(self::LAM_ALEF[$chars[$i + 1]])) {
                $lig = self::LAM_ALEF[$chars[$i + 1]];
                $out[] = self::joinsForward($chars[$i - 1] ?? null) ? $lig[1] : $lig[0];
                $i++; // the alef is consumed by the ligature
                continue;
            }

            $prevJoins = self::joinsForward($chars[$i - 1] ?? null);
            $nextJoinable = isset($chars[$i + 1]) && isset(self::FORMS[$chars[$i + 1]]);
            $canJoinForward = count(self::FORMS[$cp]) === 4;

            $forms = self::FORMS[$cp];
            if ($prevJoins && $canJoinForward && $nextJoinable) {
                $out[] = $forms[3];
            } elseif ($prevJoins) {
                $out[] = $forms[1];
            } elseif ($canJoinForward && $nextJoinable) {
                $out[] = $forms[2];
            } else {
                $out[] = $forms[0];
            }
        }

        return self::fromCodepoints($out);
    }

    /** Can this character connect to the one that follows it? */
    private static function joinsForward(?int $cp): bool
    {
        return $cp !== null && isset(self::FORMS[$cp]) && count(self::FORMS[$cp]) === 4;
    }

    /**
     * Visual order: RTL runs are reversed, LTR runs (Latin words, prices, order
     * numbers) keep their reading order, and the runs themselves are emitted
     * right-to-left. Neutrals (spaces, punctuation) stay with the run they
     * follow, which is what makes "الطلب #12" print as "12# الطلب".
     */
    public static function reorder(string $text): string
    {
        $chars = self::codepoints($text);
        $runs = [];      // [isRtl, [codepoints]]
        $current = null;

        foreach ($chars as $cp) {
            $rtl = self::isRtlCodepoint($cp);
            $neutral = self::isNeutral($cp);

            if ($current === null) {
                $current = [$neutral ? false : $rtl, []];
            } elseif (! $neutral && $current[0] !== $rtl) {
                $runs[] = $current;
                $current = [$rtl, []];
            }

            $current[1][] = $cp;
        }
        if ($current !== null) {
            $runs[] = $current;
        }

        $out = [];
        foreach (array_reverse($runs) as [$rtl, $cps]) {
            // A trailing neutral run (e.g. a space) belongs on the other side once
            // the runs flip, so reversing the run's own characters is enough.
            $out = array_merge($out, $rtl ? array_reverse($cps) : $cps);
        }

        return self::fromCodepoints($out);
    }

    private static function isRtlCodepoint(int $cp): bool
    {
        return ($cp >= 0x0590 && $cp <= 0x08FF)
            || ($cp >= 0xFB1D && $cp <= 0xFDFF)
            || ($cp >= 0xFE70 && $cp <= 0xFEFF);
    }

    /** Whitespace + ASCII punctuation take the direction of their surroundings. */
    private static function isNeutral(int $cp): bool
    {
        return $cp === 0x20 || $cp === 0x09
            || ($cp >= 0x21 && $cp <= 0x2F)
            || ($cp >= 0x3A && $cp <= 0x40)
            || ($cp >= 0x5B && $cp <= 0x60)
            || ($cp >= 0x7B && $cp <= 0x7E);
    }

    /** @return array<int,int> */
    private static function codepoints(string $text): array
    {
        $cps = [];
        $len = mb_strlen($text, 'UTF-8');
        for ($i = 0; $i < $len; $i++) {
            $ch = mb_substr($text, $i, 1, 'UTF-8');
            $cps[] = (int) hexdec(bin2hex(mb_convert_encoding($ch, 'UCS-4BE', 'UTF-8')));
        }

        return $cps;
    }

    /** @param array<int,int> $cps */
    private static function fromCodepoints(array $cps): string
    {
        $out = '';
        foreach ($cps as $cp) {
            $out .= mb_convert_encoding(pack('N', $cp), 'UTF-8', 'UCS-4BE');
        }

        return $out;
    }
}
