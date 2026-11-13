<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Money;


/**
 * Bug batch 7 · 3: totals that are never summed across currencies. Revenue tiles keep ONE currency in their existing
 * figure (the platform's for admin tiles, the reseller's for panel tiles) and report every other currency separately
 * (code => amount) — no FX, so a KWD invoice never adds its number to a USD total.
 */
final class CurrencyTotals
{
    /**
     * Exact per-currency sums of minor amounts. A row without a currency counts in $fallback.
     *
     * @param  iterable<array{0: ?string, 1: int|string}>  $rows  [currency, minor]
     * @return array<string, int>
     */
    public static function minorByCurrency(iterable $rows, string $fallback): array
    {
        $out = [];
        foreach ($rows as [$code, $minor]) {
            $code = Currencies::normalize($code) ?: Currencies::normalize($fallback);
            $out[$code] = ($out[$code] ?? 0) + (int) $minor;
        }

        return $out;
    }

    /**
     * The $primary currency's total and every other currency's, in major units (non-zero others only, largest first).
     *
     * @param  array<string, int>  $minorByCurrency
     * @return array{0: float, 1: array<string, float>}
     */
    public static function split(array $minorByCurrency, string $primary): array
    {
        $primary = Currencies::normalize($primary);
        $others = [];
        foreach ($minorByCurrency as $code => $minor) {
            if ($code !== $primary && (int) $minor !== 0) {
                $others[$code] = Money::majorFromMinor((int) $minor, $code);
            }
        }
        arsort($others);

        return [Money::majorFromMinor((int) ($minorByCurrency[$primary] ?? 0), $primary), $others];
    }

    /** One amount as "KWD 13.833" (ISO code + the currency's own decimals). */
    public static function format(float|int $major, string $code): string
    {
        return $code.' '.number_format((float) $major, Currencies::exponent($code), '.', ',');
    }

    /**
     * "KWD 13.833 · EUR 5.00" for an other-currencies map.
     *
     * @param  array<string, float|int>  $majorByCurrency
     */
    public static function label(array $majorByCurrency): string
    {
        $parts = [];
        foreach ($majorByCurrency as $code => $major) {
            $parts[] = self::format($major, (string) $code);
        }

        return implode(' · ', $parts);
    }
}
