<?php

// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Money;

use Brick\Money\Currency as BrickCurrency;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Juwri\Core\Support\RequestEpoch;
use Throwable;

/**
 * P1-05 (J25-4): the minor-unit exponent of a currency — `currencies.exponent` (ISO 4217: JOD/KWD/BHD/OMR/IQD/LYD/
 * TND 3, JPY/KRW/VND/… 0, CLF/UYW 4, else 2). Loaded once per process; while the table does not exist yet
 * (migrations) brick/money's ISO data answers, and an unknown code is 2.
 *
 * Storage exponent only. Display decimals stay currency_decimals() (IQD shows whole dinars) and gateway minor
 * units stay GatewayMinorUnits (each gateway's own convention, e.g. Stripe's ×10 rule for 3-decimal currencies).
 *
 * Bug batch 9 · 2 — the decimals rule. Money that is STORED or CHARGED (prices, taxes, discounts, totals, wallet and
 * ledger amounts, invoices, estimates of an invoice) rounds to this exponent — money_decimals() — so the number a
 * surface computes is the number the `*_minor` column holds. currency_decimals() is for DISPLAY only (number_format,
 * labels, storefront formatting). They differ for IQD (3 / 2), MGA (2 / 0), ISK and UYI (0 / 2), CLF and UYW (4 / 2):
 * rounding money at the display decimals there either drops minor units the column keeps (IQD 13.833 → 13.83) or
 * invents ones it cannot hold (ISK 1300.50).
 */
final class Currencies
{
    public const DEFAULT_EXPONENT = 2;

    /** P6-58: cross-request copy of the table (reference data; call flush() after changing `currencies`). */
    public const CACHE_KEY = 'currencies.exponents';

    /** @var array<string, int>|null */
    private static ?array $exponents = null;

    /** The application instance $exponents was loaded for (a test's RefreshDatabase may change `currencies`). */
    private static ?int $memoApp = null;

    public static function exponent(?string $code): int
    {
        $code = self::normalize($code);
        if ($code === '') {
            return self::DEFAULT_EXPONENT;
        }

        self::scopeMemo();
        $map = self::$exponents ??= self::load();
        if (isset($map[$code])) {
            return $map[$code];
        }

        try {
            return BrickCurrency::of($code)->getDefaultFractionDigits();
        } catch (Throwable) {
            return self::DEFAULT_EXPONENT;
        }
    }

    /** A brick currency carrying OUR exponent (custom codes and non-ISO codes included). */
    public static function brick(?string $code): BrickCurrency
    {
        $code = self::normalize($code) ?: 'XXX';

        return new BrickCurrency($code, null, $code, self::exponent($code));
    }

    /**
     * Portable SQL for 10^exponent as a decimal divisor (minor → major in queries): `price_minor / <this>`.
     * A NULL exponent (currency not in the table) divides by 100, like exponent().
     */
    public static function sqlDivisor(string $exponentColumn): string
    {
        return "(CASE {$exponentColumn} WHEN 0 THEN 1.0 WHEN 1 THEN 10.0 WHEN 3 THEN 1000.0 WHEN 4 THEN 10000.0 ELSE 100.0 END)";
    }

    /**
     * Portable SQL for a minor-unit column as a MAJOR-unit decimal, row by row in that row's currency
     * (`SUM(<this>)` over rows of mixed currencies adds each at its own exponent). Both arguments are raw SQL —
     * qualify them yourself (with the table prefix) in joins. No currency column → exponent 2.
     */
    public static function sqlMajor(string $minorExpr, ?string $currencyExpr): string
    {
        if ($currencyExpr === null) {
            return "({$minorExpr} / 100.0)";
        }
        $table = DB::connection()->getTablePrefix().'currencies';
        $exponent = "(SELECT jx_cur.exponent FROM {$table} jx_cur WHERE jx_cur.code = {$currencyExpr})";

        return "({$minorExpr} / ".self::sqlDivisor($exponent).')';
    }

    public static function normalize(?string $code): string
    {
        return strtoupper(trim((string) $code));
    }

    /** Forget the loaded table (tests that change `currencies`). */
    public static function flush(): void
    {
        self::$exponents = null;
        try {
            Cache::forget(self::CACHE_KEY);
        } catch (Throwable) {
        }
    }

    private static function scopeMemo(): void
    {
        $id = RequestEpoch::id();
        if (self::$memoApp !== $id) {
            self::$memoApp = $id;
            self::$exponents = null;
        }
    }

    /** @return array<string, int> */
    private static function load(): array
    {
        try {
            $map = Cache::get(self::CACHE_KEY);
            if (is_array($map) && $map !== []) {
                return $map;
            }
            $map = DB::table('currencies')->pluck('exponent', 'code')
                ->mapWithKeys(fn ($e, $c) => [strtoupper((string) $c) => (int) $e])->all();
            if ($map !== []) {
                Cache::put(self::CACHE_KEY, $map, 3600);
            }

            return $map;
        } catch (Throwable) {
            return [];
        }
    }
}
