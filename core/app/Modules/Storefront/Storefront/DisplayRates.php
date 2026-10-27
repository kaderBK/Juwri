<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Storefront;

use Juwri\Core\Services\ExchangeRateService;

/**
 * MC-31 — the ONE display-currency rate resolver. ThemeContext and CommerceRuntime
 * each had their own copy of the "CODE = rate" parser; this replaces both.
 *
 * Modes (store option `currency_rates_mode`, default `manual`):
 *   • manual — the merchant's `currency_rates_manual` lines are the rates.
 *   • auto   — rates for the store's `display_currencies` come from the daily FX
 *              feed (ExchangeRateService); any manual line is a PIN that overrides
 *              the auto rate. A stale feed hides the auto codes but the pins survive.
 *
 * These are DISPLAY rates only — never a charge, a stored total, or JSON-LD.
 */
class DisplayRates
{
    /**
     * @param  array<string, float>  $rates  {CODE: rate} relative to base (base itself is 1.0)
     */
    public function __construct(
        public readonly string $base,
        public readonly array $rates,
        public readonly ?string $asOf = null,
        public readonly bool $indicative = false,
    ) {}

    /**
     * Switcher codes: base first, then the non-base display codes. Empty when no
     * rates are set (the theme then hides the switcher).
     *
     * @return list<string>
     */
    public function codes(): array
    {
        $others = array_values(array_filter(array_keys($this->rates), fn ($c) => $c !== $this->base));

        return $others === [] ? [] : array_merge([$this->base], $others);
    }

    /** Convenience: load the store's options bag + base currency, then resolve. */
    public static function forStore(int|string $storeId): self
    {
        return self::fromOptions(store_options($storeId), strtoupper((string) store_currency($storeId)));
    }

    /**
     * Resolve from an already-loaded options bag — the storefront callers pass the
     * SAME `$postOptions` object they build the rest of the CFG from (which may be
     * toggle-masked), so the manual-rate output stays byte-identical to the two
     * legacy parsers rather than re-querying.
     */
    public static function fromOptions(?object $o, ?string $base): self
    {
        $base = strtoupper((string) ($base ?: 'USD'));
        $manual = self::parseManual((string) ($o->currency_rates_manual ?? ''));
        $mode = strtolower((string) ($o->currency_rates_mode ?? 'manual'));

        if ($mode !== 'auto') {
            return new self($base, $manual, null, false);
        }

        // Auto mode: manual lines are pins that always survive; auto rates fill the
        // rest of display_currencies from the feed, and are hidden when it is stale.
        $rates = $manual;
        $asOf = null;

        if (! ExchangeRateService::isStale()) {
            foreach (self::parseCodeList((string) ($o->display_currencies ?? '')) as $code) {
                if ($code === $base || isset($rates[$code])) {
                    continue; // base is 1.0; a manual pin overrides the auto rate
                }
                $rate = ExchangeRateService::rate($base, $code);
                if ($rate !== null && $rate > 0) {
                    $rates[$code] = $rate;
                }
            }
            $asOf = ExchangeRateService::lastFetchedAt()?->toDateString();
        }

        return new self($base, $rates, $asOf, true);
    }

    /**
     * Parse "CODE = rate" lines → {CODE: rate}. Matches the two legacy parsers
     * byte-for-byte (no base exclusion here; the rate map may include base).
     *
     * @return array<string, float>
     */
    public static function parseManual(string $raw): array
    {
        $rates = [];
        foreach (preg_split('/\r\n|\r|\n/', $raw) as $line) {
            $line = trim($line);
            if ($line === '' || ! str_contains($line, '=')) {
                continue;
            }
            [$code, $rate] = array_map('trim', explode('=', $line, 2));
            $code = strtoupper($code);
            if (preg_match('/^[A-Z]{3}$/', $code) === 1 && is_numeric($rate) && (float) $rate > 0) {
                $rates[$code] = (float) $rate;
            }
        }

        return $rates;
    }

    /**
     * Parse a currency-code list (comma/space/newline separated) → uppercased codes.
     *
     * @return list<string>
     */
    private static function parseCodeList(string $raw): array
    {
        $codes = [];
        foreach (preg_split('/[\s,]+/', strtoupper($raw)) as $code) {
            $code = trim($code);
            if (preg_match('/^[A-Z]{3}$/', $code)) {
                $codes[$code] = true;
            }
        }

        return array_keys($codes);
    }
}
