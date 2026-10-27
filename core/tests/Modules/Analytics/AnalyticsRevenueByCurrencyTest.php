<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Analytics;

use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Analytics\Http\Controllers\User\AnalyticsController;
use ReflectionMethod;
use Tests\TestCase;

/**
 * MC-41 / MC-44 — AnalyticsController::revenueByCurrency groups revenue-counted
 * orders by their stamped currency (legacy null → platform base). A
 * single-currency owner's grouped revenue is byte-identical to the blind
 * sum('total') the analytics page + CSV summary already print (the pin), while a
 * multi-currency owner is split per code instead of summed across currencies.
 */
class AnalyticsRevenueByCurrencyTest extends TestCase
{
    use RefreshDatabase;

    private function store(): Store
    {
        $user = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);

        return Store::create([
            'user_id' => $user->id, 'slug' => Str::slug('r'.Str::random(6)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 'x', 'description' => 'x', 'main_image' => 'd.png',
            'cover_image' => 'd.png', 'address' => '1', 'phone' => '1',
        ]);
    }

    /** An item-less order whose total accessor == $tip (no items → subtotal 0, no tax). */
    private function tipOrder(Store $store, ?string $currency, float $tip): Order
    {
        return Order::forceCreate([
            'store_id' => $store->id, 'status' => 'completed', 'type' => 'takeaway',
            'is_paid' => 1, 'track_token' => Str::random(12), 'currency_code' => $currency,
            'tip_amount' => $tip, 'created_at' => now(),
        ]);
    }

    /** @return array<string, array{code:string,symbol:string,decimals:int,revenue:float,orders:int}> */
    private function group($orders, int $fallbackStoreId): array
    {
        $m = new ReflectionMethod(AnalyticsController::class, 'revenueByCurrency');
        $m->setAccessible(true);

        return $m->invoke(app(AnalyticsController::class), $orders, $fallbackStoreId);
    }

    public function test_single_currency_is_byte_identical_to_sum_total(): void
    {
        config(['settings.currency_code' => 'USD']);
        $store = $this->store();
        $this->tipOrder($store, 'USD', 10.00);
        $this->tipOrder($store, 'USD', 5.50);

        $orders = Order::where('store_id', $store->id)->get();
        $out = $this->group($orders, $store->id);

        $this->assertSame(['USD'], array_keys($out), 'one currency → one bucket');
        // The pin: grouped revenue == the blind sum('total') the summary prints.
        $this->assertEqualsWithDelta($orders->sum('total'), $out['USD']['revenue'], 1e-9);
        $this->assertEqualsWithDelta(15.50, $out['USD']['revenue'], 1e-9);
        $this->assertSame(2, $out['USD']['orders']);
        $this->assertSame(2, $out['USD']['decimals']);
    }

    public function test_multi_currency_is_grouped_highest_first_not_blind_summed(): void
    {
        config(['settings.currency_code' => 'USD']);
        $store = $this->store();
        $this->tipOrder($store, 'USD', 10.00);
        $this->tipOrder($store, 'OMR', 1.234);
        $this->tipOrder($store, 'OMR', 2.766);

        $out = $this->group(Order::where('store_id', $store->id)->get(), $store->id);

        $this->assertSame(['USD', 'OMR'], array_keys($out), 'sorted by revenue desc');
        $this->assertEqualsWithDelta(10.00, $out['USD']['revenue'], 1e-9);
        $this->assertEqualsWithDelta(4.00, $out['OMR']['revenue'], 1e-9);
        // OMR is a 3-decimal currency — decimals follow the ISO code, not the store.
        $this->assertSame(3, $out['OMR']['decimals']);
    }

    public function test_null_stamp_rolls_into_platform_base(): void
    {
        config(['settings.currency_code' => 'GBP']);
        $store = $this->store();
        $this->tipOrder($store, null, 7.00);      // legacy unstamped
        $this->tipOrder($store, 'GBP', 3.00);

        $out = $this->group(Order::where('store_id', $store->id)->get(), $store->id);

        $this->assertSame(['GBP'], array_keys($out), 'null rolls into base, not a separate bucket');
        $this->assertEqualsWithDelta(10.00, $out['GBP']['revenue'], 1e-9);
    }
}
