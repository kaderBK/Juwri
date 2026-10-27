<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Ordering;

use App\Http\Controllers\Api\V1\Concerns\FormatsOrders;
use App\Models\Order;
use App\Models\StoreOption;
use App\Services\OrderApiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * MC-13 — the API order payloads (FormatsOrders row + detail) carry the order's
 * stamped currency. Additive: existing fields are untouched.
 */
class OrderCurrencyApiFieldTest extends TestCase
{
    use RefreshDatabase;

    private function formatter(): object
    {
        return new class
        {
            use FormatsOrders;

            public function row(Order $o): array
            {
                return $this->orderRow($o);
            }

            public function detail(Order $o): array
            {
                return $this->orderDetail($o);
            }
        };
    }

    private function storeWithItem(string $currency): array
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 't', 'monthly_price' => 10, 'annual_price' => 100,
            'lifetime_price' => 1000, 'status' => 1,
            'settings' => ['restaurant_limit' => 999, 'menu_limit' => 999, 'allow_ordering' => '1'],
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'Store', 'sub_title' => 't', 'description' => 't', 'main_image' => 'default.png',
            'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '123',
        ]);
        StoreOption::updateStoreOption($store->id, 'currency_code', $currency);
        $menu = Menu::create(['store_id' => $store->id, 'name' => 'Dish', 'price' => 10, 'active' => 1]);

        return [$store, $menu];
    }

    public function test_api_order_row_and_detail_include_stamped_currency(): void
    {
        [$store, $menu] = $this->storeWithItem('OMR');

        $order = OrderApiService::create((int) $store->user_id, $store->id, [
            'type' => 'takeaway',
            'items' => [['item_id' => $menu->id, 'quantity' => 1]],
        ]);

        $this->assertSame('OMR', $order->currencyCode());

        $row = $this->formatter()->row($order->fresh());
        $this->assertArrayHasKey('currency', $row);
        $this->assertSame('OMR', $row['currency']);

        $detail = $this->formatter()->detail($order->fresh());
        $this->assertSame('OMR', $detail['currency']);
    }
}
