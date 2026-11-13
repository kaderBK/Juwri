<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Ordering;

use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Symbol fallback for store_money_format() / store_money_plain() in
 * app/Http/Helpers/CurrencyDisplay.php — a store that has set currency_code
 * MUST show that currency's symbol (via currency_symbol_for), even when no
 * explicit currency_sign is set. The bug it guards against: currency_code is
 * loaded but the per-store currency_sign option is left empty, so the helper
 * falls back to the global `settings.currency_sign` ('$' by default) and prints
 * a Kuwaiti price as "$12.345" instead of "د.ك 12.345".
 */
class StoreMoneySymbolFallbackTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Minimal Store + StoreOption seed: gives us a store id and the couple of
     * currency_* options the helpers read. Mirrors OrderHubCheckoutViewTest's
     * fixture pattern so the helpers under test (store_options + StoreSettings
     * underneath) see exactly what they would in a real request.
     *
     * @param  string|null  $code  ISO code (e.g. 'KWD'); null = leave unset.
     * @param  string|null  $sign  raw sign (e.g. '€'); null = leave unset.
     */
    private function makeStore(?string $code = null, ?string $sign = null): Store
    {
        $owner = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        $slug = Str::slug('r'.Str::random(6));
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => $slug, 'color' => '#111', 'title' => 'R',
            'sub_title' => 'x', 'description' => 'x', 'main_image' => 'default.png',
            'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '123',
        ]);
        if ($code !== null) {
            StoreOption::updateStoreOption($store->id, 'currency_code', $code);
        }
        if ($sign !== null) {
            StoreOption::updateStoreOption($store->id, 'currency_sign', $sign);
        }

        return $store;
    }

    /**
     * Data provider: ISO code → expected display symbol from currency_symbol_map().
     * All three are non-USD, non-€, all have non-ASCII glyphs, and one (AED) is
     * a U+20C3 (the spacing/isolate exception) — so a single data provider
     * covers the three behaviour branches the helper has for non-default signs.
     *
     * @return iterable<string, array{0:string, 1:string}>
     */
    public static function currencySymbolProvider(): iterable
    {
        return [
            'KWD' => ['KWD', 'د.ك'],
            'SAR' => ['SAR', '⃁'],
            'AED' => ['AED', '⃃'],
        ];
    }

    /**
     * (1) Code-only fallback: a store that has set currency_code but no
     * currency_sign MUST render with the code's symbol from the ISO map.
     * The bug we are guarding against: the helper reaches for the global
     * `settings.currency_sign` ('$' by default) instead of looking the code up.
     */
    #[DataProvider('currencySymbolProvider')]
    public function test_code_only_store_renders_code_symbol_not_dollar(string $code, string $expectedSymbol): void
    {
        $store = $this->makeStore(code: $code);
        $formatted = store_money_format(12.5, $store->id);
        $plain = store_money_plain(12.5, $store->id);

        $this->assertStringContainsString($expectedSymbol, $formatted, "store_money_format() must contain $code symbol");
        $this->assertStringNotContainsString('$', $formatted, "store_money_format() must not fall back to \$ for $code");
        // Plain (SMS/receipt/JSON) output deliberately uses the ISO code, never "$" (currency_sign_safe).
        $this->assertStringContainsString($code, $plain, "store_money_plain() must show $code");
        $this->assertStringNotContainsString('$', $plain, "store_money_plain() must not fall back to \$ for $code");
    }

    /**
     * (2) Explicit currency_sign wins over the code's symbol — a store that has
     * both 'currency_code' = 'EUR' and 'currency_sign' = '€' must render with '€'
     * (the explicit one), NOT with the symbol currency_symbol_for('EUR') would
     * have returned (also '€' here, so this doubles as a "explicit sign is
     * honoured" check; the failing shape is "code-only path silently returns €").
     */
    public function test_explicit_currency_sign_wins_over_code(): void
    {
        $store = $this->makeStore(code: 'EUR', sign: '€');
        $formatted = store_money_format(12.5, $store->id);
        $plain = store_money_plain(12.5, $store->id);

        $this->assertStringContainsString('€', $formatted);
        $this->assertStringNotContainsString('$', $formatted);
        $this->assertStringContainsString('€', $plain);
    }

    /**
     * (3) No code, no sign: the helper still has to render something, so it
     * falls back to the global default ('$' from settings.currency_sign).
     * Guards against a regression where the code-only branch leaks into the
     * default branch and the no-sign store starts printing "12.50" (no sign).
     */
    public function test_no_code_no_sign_falls_back_to_dollar(): void
    {
        $store = $this->makeStore();
        $formatted = store_money_format(12.5, $store->id);
        $plain = store_money_plain(12.5, $store->id);

        $this->assertStringContainsString('$', $formatted, 'default sign must be $');
        $this->assertStringContainsString('$', $plain, 'default sign must be $');
    }

    /**
     * (4) Locale 'ar' (RTL): the symbol and the digits are still BOTH present
     * in store_money_format(), and the web format's bidi-isolate pair
     * (\u{2068}…\u{2069}) is still wrapped around the symbol — so a downstream
     * `<bdi dir="ltr">` can flip the sign back to the left of the number
     * regardless of locale. Runs for both code-only (KWD) and code-only-with-
     * unicode-sign (SAR), where cur_isolate()'s branches differ.
     */
    #[DataProvider('currencySymbolProvider')]
    public function test_ar_locale_keeps_symbol_and_digits_and_isolate_chars(string $code, string $expectedSymbol): void
    {
        app()->setLocale('ar');
        try {
            $store = $this->makeStore(code: $code);
            $formatted = store_money_format(12.5, $store->id);

            $this->assertStringContainsString($expectedSymbol, $formatted, "$code symbol must be present under ar locale");
            $this->assertStringContainsString('12.50', $formatted, 'digits must be present under ar locale');
            $this->assertStringContainsString(
                "\u{2068}".$expectedSymbol."\u{2069}",
                $formatted,
                "web format must isolate $code symbol with U+2068 / U+2069"
            );
        } finally {
            app()->setLocale('en');
        }
    }

    /**
     * (5) KWD is a 3-decimal currency (1000 fils per dinar); the helper MUST
     * hand the number through `store_price_format()` which consults
     * `currency_decimals()`. A regression to 2 decimals would silently
     * truncatye 12.345 → 12.35 and mis-bill any merchant running on KWD.
     */
    public function test_kwd_three_decimal_amount(): void
    {
        $store = $this->makeStore(code: 'KWD');
        $formatted = store_money_format(12.345, $store->id);
        $plain = store_money_plain(12.345, $store->id);

        $this->assertStringContainsString('12.345', $formatted);
        $this->assertStringContainsString('12.345', $plain);
    }
}
