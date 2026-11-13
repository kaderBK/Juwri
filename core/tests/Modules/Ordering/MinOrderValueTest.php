<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Ordering;

use App\Models\MenuCategory;
use App\Models\Order;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Menu\Models\MenuExtra;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * B8-P1 follow-up (review #5): the server-side minimum-order-value gate must count
 * the SAME price the order total does — base + variant + paid extras — so an order
 * whose add-ons clear the minimum isn't wrongly rejected.
 */
class MinOrderValueTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        $this->withoutMiddleware();
    }

    private function storeFixture(float $min): array
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 'T', 'monthly_price' => 1, 'annual_price' => 1,
            'lifetime_price' => 1, 'status' => 1, 'settings' => json_encode(['restaurant_limit' => 999, 'menu_limit' => 999, 'allow_ordering' => '1']),
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $r = Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'd.png',
            'cover_image' => 'd.png', 'address' => '1', 'phone' => '1', 'created_at' => now(), 'updated_at' => now(),
        ]);
        StoreOption::updateStoreOption($r->id, 'min_order_value', (string) $min);
        StoreOption::updateStoreOption($r->id, 'store_submit_order_notification', 0);
        StoreOption::updateStoreOption($r->id, 'quickorder_enable', 0);
        $cat = MenuCategory::create(['store_id' => $r->id, 'name' => 'C', 'position' => 1]);
        $menu = Menu::create(['store_id' => $r->id, 'category_id' => $cat->id, 'name' => 'Base', 'price' => 15, 'active' => '1', 'position' => 1]);
        $extra = MenuExtra::create(['menu_id' => $menu->id, 'title' => 'Add-on', 'price' => 8]);

        return [$r, $menu, $extra];
    }

    private function payload(Menu $m, array $extra = []): array
    {
        return array_merge([
            'items' => json_encode([['id' => $m->id, 'quantity' => 1, 'variants' => 0, 'extras' => [], 'mods' => []]]),
            'tip_amount' => '0', 'name' => 'Jane', 'ordering-type' => 'takeaway', 'phone-number' => '555', 'pay_via' => 'cod',
        ], $extra);
    }

    public function test_base_plus_extras_over_minimum_is_accepted(): void
    {
        // min 20; base 15 (under) + 8 extra = 23 (over) → must NOT be rejected.
        [$r, $m, $extra] = $this->storeFixture(20);
        $items = json_encode([['id' => $m->id, 'quantity' => 1, 'variants' => 0, 'extras' => [['id' => $extra->id]], 'mods' => []]]);

        $this->post(route('store.submitOrder', $r), $this->payload($m, ['items' => $items]))
            ->assertOk()->assertJson(['success' => true]);

        $this->assertSame(1, Order::where('store_id', $r->id)->count());
    }

    public function test_genuinely_under_minimum_is_still_rejected(): void
    {
        [$r, $m] = $this->storeFixture(20); // base 15, no extras → 15 < 20
        $res = $this->post(route('store.submitOrder', $r), $this->payload($m))->assertOk();
        $res->assertJson(['success' => false]);
        $this->assertSame(0, Order::where('store_id', $r->id)->count());
    }
}
