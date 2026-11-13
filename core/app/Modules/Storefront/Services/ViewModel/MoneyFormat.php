<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Services\ViewModel;

use Juwri\Core\Models\Store;
use Juwri\Core\Money\Currencies;
use Juwri\Core\Money\Money;
use Juwri\Core\Settings\StoreSettings;

/**
 * P3-02: Money for the view model — `{minor, currency, formatted}` (THEME-CONTRACT §4). `formatted` is made here,
 * once, for the store and the render language: the currency's display decimals, the store's sign (the Saudi Riyal
 * / UAE Dirham glyphs normalised, a word sign such as "SAR" spaced), the effective side (the RTL rule of
 * currency_pos_effective), the sign isolated (U+2068 … U+2069) and USD kept left-to-right on an RTL page. A
 * negative amount (a discount) reads "−SAR 12.20" (U+2212). Themes print `formatted` and never format money.
 *
 * Built for the app locale that is current when the view model is built (ViewModelBuilder sets it).
 */
final class MoneyFormat
{
    public readonly string $currency;

    public readonly string $sign;

    public readonly int $exponent;

    public readonly int $decimals;

    public readonly bool $signFirst;

    public function __construct(Store $store)
    {
        $this->currency = strtoupper(store_currency((int) $store->id));
        $stored = trim(StoreSettings::string((int) $store->id, 'currency_sign'));
        $this->sign = normalize_currency_sign($stored !== '' ? $stored : currency_symbol_for($this->currency), $this->currency);
        $this->exponent = Currencies::exponent($this->currency);
        $this->decimals = currency_decimals($this->currency);
        $this->signFirst = currency_pos_effective((int) $store->id) === 1;
    }

    /** @return array{minor: int, currency: string, formatted: string} */
    public function money(int $minor): array
    {
        return ['minor' => $minor, 'currency' => $this->currency, 'formatted' => $this->format($minor)];
    }

    /** @return array{minor: int, currency: string, formatted: string} */
    public function major(float|int|string $major): array
    {
        return $this->money(Money::minorFromMajor($major, $this->currency));
    }

    public function format(int $minor): string
    {
        $amount = number_format(abs(Money::majorFromMinor($minor, $this->currency)), $this->decimals, '.', ',');
        $gap = currency_symbol_gap($this->sign) ?: (preg_match('/\p{L}{2,}/u', $this->sign) === 1 ? ' ' : '');
        $sign = $this->sign === '' ? '' : "\u{2068}".$this->sign."\u{2069}";
        $text = $this->signFirst ? $sign.$gap.$amount : $amount.$gap.$sign;
        if ($this->currency === 'USD' && is_rtl()) {
            $text = "\u{2066}".$text."\u{2069}";
        }

        return ($minor < 0 ? "\u{2212}" : '').$text;
    }

    /**
     * The `money` block of the view model.
     *
     * @return array{currency: array{code: string, symbol: string, exponent: int, symbol_position_effective: 'before'|'after'}}
     */
    public function block(): array
    {
        return ['currency' => [
            'code' => $this->currency,
            'symbol' => $this->sign,
            'exponent' => $this->exponent,
            'symbol_position_effective' => $this->signFirst ? 'before' : 'after',
        ]];
    }
}
