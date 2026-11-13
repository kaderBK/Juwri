<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Ordering;

use App\Models\Order;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Tests\TestCase;

/**
 * MC-10 — an order's currency is stamped at creation and frozen: its totals keep
 * rounding to that currency even after the store changes currency. Legacy rows
 * (no stamp) fall back to the store's live currency, so they re-total
 * bit-identically.
 */
class OrderCurrencyStampTest extends TestCase
{
    use RefreshDatabase;

    private function store(string $currency): Store
    {
        $user = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        $store = Store::create([
            'user_id' => $user->id, 'slug' => Str::slug('r'.Str::random(6)),
            'color' => '#111', 'title' => 'R', 'sub_title' => 'x', 'description' => 'x',
            'main_image' => 'default.png', 'cover_image' => 'default.png',
            'address' => '1 St', 'phone' => '123',
        ]);
        StoreOption::updateStoreOption($store->id, 'currency_code', $currency);

        return $store;
    }

    private function order(Store $store, array $attrs = []): Order
    {
        return Order::create($attrs + [
            'store_id' => $store->id, 'status' => 'pending', 'type' => 'takeaway',
            'is_paid' => 0, 'track_token' => Str::random(12),
        ]);
    }

    public function test_creating_stamps_the_stores_currency(): void
    {
        $store = $this->store('OMR');
        $order = $this->order($store);

        $this->assertSame('OMR', $order->currency_code);
        $this->assertSame('OMR', $order->currencyCode());
    }

    public function test_explicit_currency_is_not_overwritten(): void
    {
        $store = $this->store('USD');
        $order = $this->order($store, ['currency_code' => 'EUR']);

        $this->assertSame('EUR', $order->currency_code);
    }

    public function test_legacy_null_stamp_falls_back_to_live_store_currency(): void
    {
        $store = $this->store('USD');
        $order = $this->order($store);
        // Simulate a pre-migration row (raw: a model save re-stamps a blank currency since P1-05).
        DB::table('orders')->where('id', $order->id)->update(['currency_code' => null]);

        $this->assertNull($order->fresh()->currency_code);
        $this->assertSame('USD', $order->fresh()->currencyCode());
    }

    public function test_stamp_survives_a_store_currency_change(): void
    {
        $store = $this->store('OMR');
        $order = $this->order($store);
        $this->assertSame('OMR', $order->currencyCode());

        // Store switches to a 2-decimal currency later.
        StoreOption::updateStoreOption($store->id, 'currency_code', 'USD');

        // The stamped order still resolves to OMR; a brand-new order gets USD.
        $this->assertSame('OMR', $order->fresh()->currencyCode());
        $this->assertSame('USD', $this->order($store)->currencyCode());
    }

    public function test_compute_tax_rounds_to_the_passed_currency_not_the_bag(): void
    {
        // 3-decimal tax kept when the order's stamped currency is OMR, even though
        // the live bag says USD; null (the default) falls back to the bag → 2dp.
        $bag = (object) ['restaurant_taxx_charge' => '10', 'restaurant_tax_type' => 'percentage', 'currency_code' => 'USD'];

        $this->assertSame(1.234, Order::computeTax(12.34, $bag, 'OMR'));   // 3-decimal (12.34 × 10%)
        $this->assertSame(1.23, Order::computeTax(12.34, $bag));           // bag default (USD, 2dp)
    }
}
