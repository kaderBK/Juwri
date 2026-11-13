<?php

use Juwri\Core\Models\Branch;
use Juwri\Core\Money\Currencies;
use Juwri\Core\Money\Money;

// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/*
 * Store-scoped money accessors (extracted from Helper.php, WI-M15-style split to
 * keep Helper.php under its size ceiling). These read a store's own currency,
 * timezone and price formatting from store_options() for contexts without the
 * storefront config override. Pure global helpers: no class dependencies, so no
 * use-imports are needed. Autoloaded via composer "files".
 *
 * The whole group is function_exists-guarded so this file is SAFE to load while
 * an older Helper.php that still defines these functions is also autoloaded — the
 * guard skips the redeclaration instead of fatalling. That lets prod converge to
 * the split in two phases (ship this file first, then the slimmed Helper.php)
 * without any window where store_currency() is either duplicated or undefined.
 *
 * MC-20: store-scoped price formatting for contexts that DON'T have the storefront
 * config override (StoreController@publicView sets config('settings.currency_*')
 * per request, so the config-based trio is store-correct only there).
 * Push/SMS/WhatsApp/POS/order-lookup render off that path and were leaking the
 * platform currency. These read the store's own currency from store_options
 * directly. No new static memo is introduced: store_options() is already memoized
 * per request and rebinds on \Juwri\Core\Support\RequestEpoch::id() (StoreOption::scopeMemo), so
 * these inherit a rebind-safe cache without a leak-prone static of their own.
 */

if (! function_exists('store_currency')) {
    /** The store's own ISO currency code (falls back to the platform currency). */
    function store_currency(int|string $storeId): string
    {
        // No store currency: the PLATFORM's (never another store's dashboard / storefront display swap).
        return (string) (store_options($storeId, 'currency_code') ?: platform_currency());
    }

    /**
     * The store's own IANA timezone, with a three-level fallback. This is the
     * canonical resolver every store-scoped day boundary (reports, sold-out
     * reset, "is open now") should use instead of a bare now()/today(), which
     * resolves to the single platform timezone and is wrong for stores elsewhere.
     *
     * P6-289: resolution order —
     *   1. The store's own `timezone` option, when set AND a valid IANA zone
     *      (timezone_identifiers_list()). The owner screen writes this; an
     *      empty/invalid value clears the option so it never reaches here.
     *   2. The timezone of the store's primary active branch — first active
     *      branch by position, then id, that has a non-empty valid timezone.
     *      A Riyadh store owner who set their branch tz on branch creation but
     *      never set the store-level option gets Asia/Riyadh, not UTC.
     *   3. The platform default (`config('app.timezone')`, 'UTC').
     *
     * Never throws — any oddity
     * (bad stored value, no branches, DB error) returns the app timezone.
     */
    function store_tz(int|string $storeId): string
    {
        $key = (string) $storeId;

        $appTz = (string) (config('app.timezone') ?: 'UTC');

        // Level 1: store's own option (store_options() already memoizes single-key reads).
        try {
            $opt = store_options($storeId, 'timezone');
        } catch (Throwable $e) {
            $opt = null;
        }
        if (is_string($opt) && $opt !== '' && in_array($opt, timezone_identifiers_list(), true)) {

            return $opt;
        }

        // Level 2: first active branch's timezone (by position, id), if it has a valid zone.
        if ($key !== '' && ctype_digit($key)) {
            try {
                $branchTz = Branch::primaryTimezone((int) $storeId); // memoised per request (hot path)
            } catch (Throwable $e) {
                $branchTz = null;
            }
            if (is_string($branchTz) && $branchTz !== '' && in_array($branchTz, timezone_identifiers_list(), true)) {
                return $branchTz;
            }
        }

        return $appTz;
    }

    /** The store's currency-position flag (1 = symbol before price), config fallback. */
    function store_currency_pos(int|string $storeId): int
    {
        // store_options() returns false (not null) for an unset key; a legitimately
        // stored 0 (symbol after price) must survive, so only false/null/'' fall back.
        $pos = store_options($storeId, 'currency_pos');
        if ($pos === false || $pos === null || $pos === '') {
            $pos = config('settings.currency_pos', 1);
        }

        return (int) $pos;
    }

    /** Decimal-formatted price in the store's own currency decimals (no symbol). */
    function store_price_format(int|float|string $price, int|string $storeId): string
    {
        return number_format((float) $price, currency_decimals(store_currency($storeId)), '.', '');
    }

    /** Store price with the store's currency SIGN, honoring its position. */
    function store_price_symbol(int|float|string $price, int|string $storeId): string
    {
        // NOT isolate-wrapped: store_price_symbol feeds text/data channels (push labels,
        // API price_formatted), not only web display — the invisible isolate chars would
        // leak there. Web surfaces isolate via $curSign / price_symbol_format instead.
        $sign = (string) (store_options($storeId, 'currency_sign') ?: config('settings.currency_sign'));

        return currency_pos_effective($storeId) === 1
            ? $sign.store_price_format($price, $storeId)
            : store_price_format($price, $storeId).$sign;
    }

    /** Store price with the store's currency CODE, honoring its position. */
    function store_price_code(int|float|string $price, int|string $storeId): string
    {
        // Not isolate-wrapped — same reason as store_price_symbol (text/data channels).
        $code = store_currency($storeId);

        return currency_pos_effective($storeId) === 1
            ? $code.' '.store_price_format($price, $storeId)
            : store_price_format($price, $storeId).' '.$code;
    }
}

if (! function_exists('money_round')) {
    /**
     * P1-00a: round a money amount the same way on every PHP version (the PHP 8.3
     * rule the storefront money core mirrors). See Juwri\Core\Money\LegacyRound.
     */
    function money_round(float|int|string $value, int $decimals = 2): float
    {
        return Money::round(is_string($value) ? (float) $value : $value, $decimals);
    }
}

if (! function_exists('money_decimals')) {
    /**
     * Bug batch 9 · 2: the decimals MONEY is rounded to before it is stored or charged — the currency's minor-unit
     * exponent ({@see Currencies::exponent()}), the precision `*_minor` columns hold. Display keeps
     * currency_decimals(). The two differ for IQD (3 / 2), MGA (2 / 0), ISK and UYI (0 / 2), CLF and UYW (4 / 2).
     * Without a code: the current currency (the store's on a storefront render, else the platform's), as
     * currency_decimals().
     */
    function money_decimals(?string $code = null): int
    {
        return Currencies::exponent($code ?: (string) config('settings.currency_code', 'USD'));
    }
}

if (! function_exists('remember_platform_currency')) {
    /**
     * Bug batch 10 · 1: call BEFORE a request swaps `settings.currency_code` for a store / transaction currency (the
     * storefront render, order mails, payment pages, Settlement::useStoreCurrency). The swap is for DISPLAY; the
     * platform currency (merchant wallet, platform ledger) is kept in `settings.platform_currency_code` so money
     * written later in the same request is not stamped with the store's currency.
     */
    function remember_platform_currency(): void
    {
        if (config('settings.platform_currency_code') === null) {
            config(['settings.platform_currency_code' => strtoupper((string) (config('settings.currency_code') ?: 'USD'))]);
        }
        // The platform's own sign and position too (a new store starts with them), once per request.
        foreach (['sign', 'pos'] as $part) {
            if (! config()->has('settings.platform_currency_'.$part)) {
                config(['settings.platform_currency_'.$part => config('settings.currency_'.$part)]);
            }
        }
    }
}

if (! function_exists('platform_currency_option')) {
    /**
     * The platform's own currency `code`, `sign` or `pos`, unaffected by a dashboard / storefront / payment-page
     * display swap of `settings.currency_*` (see remember_platform_currency()).
     */
    function platform_currency_option(string $part): mixed
    {
        if ($part === 'code') {
            return platform_currency();
        }

        return config()->has('settings.platform_currency_'.$part)
            ? config('settings.platform_currency_'.$part)
            : config('settings.currency_'.$part);
    }
}

if (! function_exists('platform_currency')) {
    /** Bug batch 10 · 1: the platform currency, unaffected by a storefront / payment-page display swap. */
    function platform_currency(): string
    {
        return strtoupper((string) (config('settings.platform_currency_code') ?: config('settings.currency_code') ?: 'USD'));
    }
}

if (! function_exists('platform_price_symbol_format')) {
    /**
     * price_symbol_format() for PLATFORM money (plan billing, platform transactions) on a screen whose
     * `settings.currency_*` may be swapped to a store's currency (the owner dashboard, a storefront): the amount in
     * $code — a transaction's own currency_code — or, without one, the platform currency, with the platform's own
     * sign and position. Same rendering rules (decimals, RTL position, dirham, LTR isolate) as price_symbol_format().
     */
    function platform_price_symbol_format(float|int|string|null $price, ?string $code = null): string
    {
        $platform = platform_currency();
        $code = strtoupper(trim((string) $code)) ?: $platform;
        $isPlatform = $code === $platform;
        $keys = ['settings.currency_code', 'settings.currency_sign', 'settings.currency_pos'];
        $saved = [];
        foreach ($keys as $key) {
            $saved[$key] = config($key);
        }

        config([
            'settings.currency_code' => $code,
            'settings.currency_sign' => $isPlatform
                ? (platform_currency_option('sign') ?: currency_symbol_for($code))
                : currency_symbol_for($code),
            'settings.currency_pos' => $isPlatform ? (int) (platform_currency_option('pos') ?? 1) : 1,
        ]);

        try {
            return price_symbol_format((float) $price);
        } finally {
            config($saved);
        }
    }
}
