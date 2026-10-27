<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Analytics;

use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Tests\TestCase;

/**
 * MC-40 / MC-44 — revenueSumByCurrency groups revenue by the order's stamped
 * currency, and a single-currency owner's output stays byte-identical to
 * revenueSum() (the pinned fast path).
 */
class RevenueByCurrencyTest extends TestCase
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

    private function paidOrder(Store $store, ?string $currency, float $grand): void
    {
        Order::forceCreate([
            'store_id' => $store->id, 'status' => 'completed', 'type' => 'takeaway',
            'is_paid' => 1, 'track_token' => Str::random(12), 'currency_code' => $currency,
            'grand_total' => $grand, 'created_at' => now(),
        ]);
    }

    public function test_single_currency_is_byte_identical_to_revenue_sum(): void
    {
        config(['settings.currency_code' => 'USD']);
        $store = $this->store();
        $this->paidOrder($store, 'USD', 10.00);
        $this->paidOrder($store, 'USD', 5.50);

        $scope = fn () => Order::where('store_id', $store->id);
        $byCurrency = Order::revenueSumByCurrency($scope());

        $this->assertSame(['USD'], array_keys($byCurrency));
        $this->assertEqualsWithDelta(Order::revenueSum($scope()), $byCurrency['USD'], 1e-9, 'fast path == revenueSum');
        $this->assertEqualsWithDelta(15.50, $byCurrency['USD'], 1e-9);
    }

    public function test_multi_currency_is_grouped_not_blind_summed(): void
    {
        config(['settings.currency_code' => 'USD']);
        $store = $this->store();
        $this->paidOrder($store, 'USD', 10.00);
        $this->paidOrder($store, 'OMR', 1.234);
        $this->paidOrder($store, 'OMR', 2.766);

        $byCurrency = Order::revenueSumByCurrency(Order::where('store_id', $store->id));

        $this->assertEqualsWithDelta(10.00, $byCurrency['USD'], 1e-9);
        $this->assertEqualsWithDelta(4.00, $byCurrency['OMR'], 1e-9);
    }

    public function test_null_stamp_rolls_into_platform_base(): void
    {
        config(['settings.currency_code' => 'GBP']);
        $store = $this->store();
        $this->paidOrder($store, null, 7.00);      // legacy unstamped
        $this->paidOrder($store, 'GBP', 3.00);

        $byCurrency = Order::revenueSumByCurrency(Order::where('store_id', $store->id));

        $this->assertSame(['GBP'], array_keys($byCurrency), 'null rolls into base, not a separate bucket');
        $this->assertEqualsWithDelta(10.00, $byCurrency['GBP'], 1e-9);
    }

    public function test_only_revenue_counted_orders_count(): void
    {
        config(['settings.currency_code' => 'USD']);
        $store = $this->store();
        $this->paidOrder($store, 'USD', 10.00);
        // A pending, unpaid order must NOT count.
        Order::forceCreate([
            'store_id' => $store->id, 'status' => 'pending', 'type' => 'takeaway',
            'is_paid' => 0, 'track_token' => Str::random(12), 'currency_code' => 'USD',
            'grand_total' => 99.00, 'created_at' => now(),
        ]);

        $this->assertEqualsWithDelta(10.00, Order::revenueSumByCurrency(Order::where('store_id', $store->id))['USD'], 1e-9);
    }
}
