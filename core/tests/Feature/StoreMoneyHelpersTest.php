<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature;

use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Tests\TestCase;

/**
 * Contract for the store-scoped money helpers (app/Http/Helpers/CurrencyDisplay.php):
 * store_money_format() is the WEB twin of price_symbol_format but keyed off the
 * STORE's own currency options (not global config), and store_money_plain() is
 * its isolate-free variant for JSON/API/text channels.
 */
class StoreMoneyHelpersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Pin LTR: these assertions pin the sign/decimal FORMAT, not RTL position,
        // and currency_pos_effective() is locale-sensitive (RTL flips to number-first).
        // Without this a leaked 'ar' locale from an earlier test flips the order.
        app()->setLocale('en');
    }

    private function store(string $sign, string $code, string $pos): Store
    {
        $owner = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => 'shop-'.Str::lower(Str::random(6)),
            'color' => '#111', 'title' => 'Shop', 'sub_title' => 'Sub', 'description' => 'D',
            'main_image' => 'default.png', 'cover_image' => 'default.png',
            'address' => '1 St', 'phone' => '123',
        ]);
        StoreOption::updateStoreOption($store->id, 'currency_code', $code);
        StoreOption::updateStoreOption($store->id, 'currency_sign', $sign);
        StoreOption::updateStoreOption($store->id, 'currency_pos', $pos);

        return $store;
    }

    public function test_plain_is_sign_space_number_and_carries_no_bidi_isolates(): void
    {
        $store = $this->store('£', 'GBP', '1'); // sign before

        $out = store_money_plain(0, $store->id);

        $this->assertSame('£ 0.00', $out);
        // No LRI/FSI/PDI — they would leak as junk in SMS/JSON/text channels.
        foreach (["\u{2066}", "\u{2068}", "\u{2069}"] as $iso) {
            $this->assertStringNotContainsString($iso, $out, 'plain output must be isolate-free');
        }
    }

    public function test_web_format_wraps_usd_in_an_ltr_isolate(): void
    {
        $store = $this->store('$', 'USD', '1');

        $out = store_money_format(0, $store->id);

        // The whole unit is LTR-isolated so "$" never bidi-flips in an RTL page.
        $this->assertStringStartsWith("\u{2066}", $out);
        $this->assertStringEndsWith("\u{2069}", $out);
        $this->assertStringContainsString('0.00', $out);
    }

    public function test_web_format_does_not_isolate_a_plain_currency(): void
    {
        $store = $this->store('£', 'GBP', '1');

        $out = store_money_format(0, $store->id);

        // £ is neither USD nor the riyal glyph → no outer LTR isolate.
        $this->assertStringNotContainsString("\u{2066}", $out);
        $this->assertStringContainsString('£', $out);
        $this->assertStringContainsString('0.00', $out);
    }

    public function test_format_honours_store_decimals_not_the_platform_default(): void
    {
        $store = $this->store('R', 'OMR', '1'); // OMR = 3 decimals (fils)

        $this->assertSame('R 0.000', store_money_plain(0, $store->id));
    }

    public function test_web_format_does_not_outer_isolate_the_saudi_riyal(): void
    {
        // The riyal glyph must NOT get the outer LTR isolate: currency_pos_effective()
        // already yields sign-first for RTL so bidi renders it visually AFTER the
        // number; an outer LTR wrap would force it back BEFORE the digits (the exact
        // storefront regression this guards). USD is the only sign that gets wrapped.
        $store = $this->store("\u{20C1}", 'SAR', '2'); // Saudi Riyal, stored sign-after

        $out = store_money_format(12.5, $store->id);

        $this->assertStringNotContainsString("\u{2066}", $out, 'riyal must not be outer-LTR-isolated');
        $this->assertStringContainsString("\u{20C1}", $out);
        $this->assertStringContainsString(' ', $out, 'riyal keeps its number-facing gap');
    }
}
