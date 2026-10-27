<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\LegacyCompat\Import;

/**
 * The ONE converter every imported money column goes through (plan §4): a source float/decimal amount to integer
 * minor units of a currency, rounded half up (away from zero) ONCE, on the first dropped digit.
 *
 *  - Decimal text (a DECIMAL column, `'12.50'`) is rounded as text, never through a float.
 *  - A float is first written with 15 significant digits — the precision a double holds exactly — which drops binary
 *    noise (`0.1 + 0.2` → `0.3`, `1.005` → `1.005`) without inventing or rounding real digits (`1.00499` stays
 *    `1.00499` → 100, not 101). Formatting to a fixed few decimals first would round twice.
 *  - An amount whose minor value does not fit a signed 64-bit integer is refused (null), never clamped.
 *
 * Hook for the money lane (M5): price snapshots, per-order rounding diffs and the "> 1 minor unit" review list build on
 * this function; loaders never multiply amounts themselves.
 */
final class LegacyMoney
{
    /** Minor units, or null for a null/empty/non-numeric/overflowing source value (reported by the caller, never 0). */
    public static function toMinor(mixed $amount, int $exponent): ?int
    {
        $exponent = max(0, min(6, $exponent));
        $text = self::decimalText($amount);
        if ($text === null) {
            return null;
        }

        $negative = str_starts_with($text, '-');
        $text = ltrim($text, '+-');
        [$whole, $fraction] = explode('.', $text) + [1 => ''];
        $fraction = str_pad($fraction, $exponent + 1, '0');
        $digits = ltrim($whole.substr($fraction, 0, $exponent), '0');
        $roundUp = (int) $fraction[$exponent] >= 5;

        if ($digits === '') {
            $digits = '0';
        }
        // Overflow check on text: PHP_INT_MAX has 19 digits.
        $max = (string) PHP_INT_MAX;
        if (strlen($digits) > strlen($max) || (strlen($digits) === strlen($max) && strcmp($digits, $max) > 0)) {
            return null;
        }
        $minor = (int) $digits;
        if ($roundUp) {
            if ($minor === PHP_INT_MAX) {
                return null;
            }
            $minor++;
        }

        return $negative && $minor !== 0 ? -$minor : $minor;
    }

    /**
     * sprintf's %G follows LC_NUMERIC: under a decimal-comma locale 1.5 prints as "1,5". %G never prints a thousands
     * separator, so the only comma it can emit is the decimal point: normalise it (and the locale's own decimal point)
     * to '.' so the digit split below can never misread an amount.
     */
    public static function localeFree(string $text): string
    {
        $point = localeconv()['decimal_point'];
        if ($point !== '' && $point !== '.') {
            $text = str_replace($point, '.', $text);
        }

        return str_replace(',', '.', $text);
    }

    /** Plain decimal text (`-123.4500`, no exponent, no separators) of a source amount, or null. */
    private static function decimalText(mixed $amount): ?string
    {
        if (is_int($amount)) {
            return (string) $amount;
        }
        if (is_string($amount)) {
            $amount = trim($amount);
            if (preg_match('/^[+-]?(\d+(\.\d*)?|\.\d+)$/D', $amount) === 1) {
                return str_starts_with($amount, '.') || str_starts_with(ltrim($amount, '+-'), '.')
                    ? preg_replace('/^([+-]?)\./', '${1}0.', $amount)
                    : $amount;
            }
            if (! is_numeric($amount)) {
                return null;
            }
            $amount = (float) $amount; // exponent notation ("1e3"): through the float path below
        }
        if (! is_float($amount) || ! is_finite($amount)) {
            return null;
        }
        $text = self::localeFree(sprintf('%.15G', $amount));
        if (str_contains($text, 'E')) {
            // Very large or very small: expand the 15 significant digits without exponent notation.
            [$mantissa, $power] = explode('E', $text);
            $power = (int) $power;
            $negative = str_starts_with($mantissa, '-');
            $mantissa = ltrim($mantissa, '+-');
            [$int, $frac] = explode('.', $mantissa) + [1 => ''];
            $digits = $int.$frac;
            $point = strlen($int) + $power;
            if ($point <= 0) {
                $text = '0.'.str_repeat('0', -$point).$digits;
            } elseif ($point >= strlen($digits)) {
                $text = $digits.str_repeat('0', $point - strlen($digits));
            } else {
                $text = substr($digits, 0, $point).'.'.substr($digits, $point);
            }
            $text = ($negative ? '-' : '').$text;
        }

        return $text;
    }
}
