<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Ordering;

use App\Models\MenuCategory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Tests\TestCase;

/**
 * D3 (parity gate) — the snapshotted grand_total column must equal the live
 * Order::getTotalAttribute() accessor to the cent, so the dashboard can sum the
 * column instead of hydrating every order+item graph. This is the explicit gate
 * that must be green before the read-path swap.
 */
class OrderTotalParityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
    }

    private function store(User $owner): array
    {
        $r = Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 't', 'description' => 't', 'main_image' => 'default.png',
            'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '123',
        ]);
        $cat = MenuCategory::create(['store_id' => $r->id, 'name' => 'C', 'parent' => null, 'position' => 1]);
        $menu = Menu::create([
            'store_id' => $r->id, 'category_id' => $cat->id, 'name' => 'Item', 'description' => 'd',
            'price' => 12.5, 'image' => 'default.png', 'type' => 'veg', 'active' => '1', 'position' => 1,
        ]);

        return [$r, $menu];
    }

    private function order(Store $r, Menu $menu, array $attrs = []): Order
    {
        $o = Order::create(array_merge([
            'store_id' => $r->id, 'type' => 'takeaway', 'customer_name' => 'X',
            'phone_number' => '5', 'status' => 'pending', 'seen' => 0, 'created_at' => now(),
        ], $attrs));
        OrderItem::create(['order_id' => $o->id, 'item_id' => $menu->id, 'quantity' => (int) ($attrs['_qty'] ?? 2)]);

        return $o->fresh();
    }

    public function test_grand_total_column_equals_the_accessor_across_mixed_orders(): void
    {
        $owner = User::factory()->create(['status' => 1, 'group_id' => 1]);
        [$r, $menu] = $this->store($owner);
        StoreOption::updateStoreOption($r->id, 'restaurant_tax', 10); // 10% tax

        $orders = [
            $this->order($r, $menu, ['_qty' => 2]),
            $this->order($r, $menu, ['_qty' => 1, 'discount_amount' => 3.5]),
            $this->order($r, $menu, ['_qty' => 3, 'tip_amount' => 4.0, 'donation_amount' => 1.0]),
            $this->order($r, $menu, ['_qty' => 2, 'service_charge_amount' => 2.25]),
        ];

        // Snapshot the columns (as the R1c write-hooks / backfill do).
        foreach ($orders as $o) {
            $o->snapshotTotals();
        }

        $columnSum = (float) Order::where('store_id', $r->id)->sum(DB::raw(Order::minorMoneySql('grand_total')));
        $accessorSum = Order::where('store_id', $r->id)->with(['items.menu', 'items.itemExtras.extra', 'items.variant'])
            ->get()->sum(fn ($o) => (float) $o->total);

        $this->assertEqualsWithDelta($accessorSum, $columnSum, 0.01, 'column sum must equal accessor sum');
        $this->assertGreaterThan(0, $columnSum);
    }

    public function test_parity_holds_on_a_three_decimal_currency_store(): void
    {
        $owner = User::factory()->create(['status' => 1, 'group_id' => 1]);
        [$r, $menu] = $this->store($owner);
        StoreOption::updateStoreOption($r->id, 'currency_code', 'BHD'); // 3-decimal
        StoreOption::updateStoreOption($r->id, 'restaurant_tax', 5);

        $o = $this->order($r, $menu, ['_qty' => 3, 'discount_amount' => 1.125]);
        $o->snapshotTotals();

        $this->assertEqualsWithDelta((float) $o->fresh()->total, (float) $o->fresh()->grand_total, 0.001);
    }
}
