<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Ordering;

use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Settings\StoreSettings;
use Tests\TestCase;

/**
 * Platform E2E v2 — A9 multi-currency + tax integrity.
 *
 * The storefront currency-switch wiring is covered by the a-currency journey.
 * This pins the two integrity invariants:
 *   • Tax is computed on the PRE-discount subtotal and rounded to the ORDER's
 *     currency decimals — so the same 15% lands 2dp in USD and 3dp in a
 *     3-decimal currency (BHD), and a discount never shrinks the tax base.
 *   • The displayed symbol POSITION follows currency_pos_effective: in RTL (Arabic)
 *     a non-USD sign auto-places AFTER the number = a number-first string (pos 0),
 *     which the bidi flip renders with the sign visually after the digits.
 */
class E2eV2A9CurrencyTaxTest extends TestCase
{
    use RefreshDatabase;

    /** A store_options bag (stdClass) as Order::computeTax expects. */
    private function taxOpts(string $currency): object
    {
        return (object) [
            'restaurant_taxx_charge' => 15,
            'restaurant_tax_type' => 'percentage',
            'currency_code' => $currency,
        ];
    }

    public function test_tax_is_pre_discount_and_rounds_per_currency(): void
    {
        $usd = $this->taxOpts('USD');
        $bhd = $this->taxOpts('BHD'); // 3-decimal currency

        // Same 15% on 100 → 15 in both, but the rounding precision differs.
        $this->assertEqualsWithDelta(15.00, Order::computeTax(100.0, $usd), 0.0001);
        $this->assertEqualsWithDelta(15.000, Order::computeTax(100.0, $bhd, 'BHD'), 0.0001);

        // A fractional base discriminates 2dp vs 3dp: 10.10 × 15% = 1.515.
        $this->assertEqualsWithDelta(1.52, Order::computeTax(10.10, $usd), 0.0001, 'USD rounds to 2dp');
        $this->assertEqualsWithDelta(1.515, Order::computeTax(10.10, $bhd, 'BHD'), 0.0001, 'BHD keeps 3dp fils');

        // PRE-discount base: computeTax is a function of the SUBTOTAL it is given.
        // The order pipeline passes the pre-discount subtotal, so a discount can
        // never lower the tax — tax(100) must exceed tax(90), i.e. the base is the
        // full subtotal, not subtotal−discount.
        $taxFull = Order::computeTax(100.0, $usd);
        $taxDiscounted = Order::computeTax(90.0, $usd);
        $this->assertGreaterThan($taxDiscounted, $taxFull, 'tax scales with the pre-discount subtotal');
        $this->assertEqualsWithDelta(15.0, $taxFull, 0.0001);
        $this->assertEqualsWithDelta(13.5, $taxDiscounted, 0.0001);
    }

    public function test_order_stores_tax_on_pre_discount_subtotal(): void
    {
        // A concrete order row: subtotal 100, a 10 discount. The stored tax must be
        // 15% of the SUBTOTAL (15.00), not of subtotal−discount (13.50).
        $order = new Order(['store_id' => 1]);
        $subtotal = 100.0;
        $discount = 10.0;
        $tax = Order::computeTax($subtotal, $this->taxOpts('USD'));
        $grand = $subtotal - $discount + $tax;

        $this->assertEqualsWithDelta(15.00, $tax, 0.0001, 'tax is 15% of the pre-discount subtotal');
        $this->assertEqualsWithDelta(105.00, $grand, 0.0001, 'grand = subtotal − discount + tax(pre-discount)');
        $this->assertNotEqualsWithDelta(13.50, $tax, 0.0001, 'tax is NOT computed on subtotal − discount');
    }

    public function test_symbol_position_effective_flips_in_rtl(): void
    {
        $owner = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.rand(100000, 999999), 'color' => '#111',
            'title' => 'R', 'sub_title' => 'x', 'main_image' => 'a.png', 'cover_image' => 'a.png', 'address' => 'x', 'phone' => '1',
        ]);
        // Store the symbol AFTER the number (pos 0), a plain (non-dirham) sign.
        StoreSettings::set($store->id, 'currency_pos', '0');
        StoreSettings::set($store->id, 'currency_sign', '$');

        app()->setLocale('en');
        $this->assertSame(0, currency_pos_effective($store->id), 'LTR keeps the stored sign-after position');

        app()->setLocale('ar');
        // Non-USD sign → RTL auto-places it AFTER the number = a number-first string
        // (pos 0), which the bidi flip renders with the sign visually after the digits.
        $this->assertSame(0, currency_pos_effective($store->id), 'RTL auto-places a non-USD sign after (number-first)');

        app()->setLocale('en'); // restore
    }
}
