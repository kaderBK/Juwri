<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature;

use App\Models\MenuCategory;
use App\Models\Order;
use App\Models\PriceSchedule;
use App\Models\StoreOption;
use App\Services\FeatureAccess;
use App\Services\FeatureRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Menu\Models\MenuExtra;
use Juwri\Modules\Menu\Services\Availability\MenuScheduleService;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * P6-349: the server-side minimum-order gate must compare the basket's SUBTOTAL the
 * same way the order path bills it — through MenuScheduleService::snapshotFor — so a
 * happy-hour basket is judged on what it is actually charged, not its list price.
 */
class WebCheckoutMinimumOrderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        $this->withoutMiddleware();

        // menu_schedules ships with its kill-switch ON, but pin it explicitly so the
        // schedule below is honoured regardless of platform defaults.
        config(['settings.admin_allow_menu_schedules' => true]);
        FeatureRegistry::clearCache();
        FeatureAccess::flush();
        MenuScheduleService::flush();
    }

    protected function tearDown(): void
    {
        MenuScheduleService::flush();
        parent::tearDown();
    }

    /** A store with a $min order minimum and a USD ($) currency. */
    private function storeFixture(float $min): Store
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
        StoreOption::updateStoreOption($r->id, 'currency_code', 'USD');
        StoreOption::updateStoreOption($r->id, 'currency_sign', '$');
        StoreOption::updateStoreOption($r->id, 'currency_pos', '1');

        return $r;
    }

    private function item(Store $store, float $price): Menu
    {
        $cat = MenuCategory::firstOrCreate(['store_id' => $store->id, 'name' => 'C']);

        return Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Base', 'price' => $price, 'active' => '1', 'position' => 1]);
    }

    /** A store-wide, always-live price schedule of the given type/value. */
    private function schedule(Store $store, string $type, float $value): PriceSchedule
    {
        $s = PriceSchedule::create([
            'store_id' => $store->id, 'name' => 'Sale', 'label' => 'Sale', 'scope' => 'store',
            'adjustment_type' => $type, 'adjustment_value' => $value, 'active' => true,
        ]);
        MenuScheduleService::flush();

        return $s;
    }

    private function payload(Menu $m, array $extras = []): array
    {
        return [
            'items' => json_encode([['id' => $m->id, 'quantity' => 1, 'variants' => 0, 'extras' => $extras, 'mods' => []]]),
            'tip_amount' => '0', 'name' => 'Jane', 'ordering-type' => 'takeaway', 'phone-number' => '555', 'pay_via' => 'cod',
        ];
    }

    public function test_a_scheduled_discount_that_drops_the_basket_below_the_minimum_is_refused_with_formatted_currency(): void
    {
        // min 20; list 30, but a 50% happy hour bills it 15 → under the minimum.
        $r = $this->storeFixture(20);
        $m = $this->item($r, 30);
        $this->schedule($r, 'percent', 50);

        $res = $this->post(route('store.submitOrder', $r), $this->payload($m))->assertOk();

        $res->assertJson([
            'success' => false,
            'message' => ___('Minimum order value is :min.', ['min' => store_money_format(20.0, $r->id)]),
        ]);
        $this->assertSame(0, Order::where('store_id', $r->id)->count());
    }

    public function test_a_price_up_schedule_is_ignored_and_the_billed_basket_clears_the_minimum(): void
    {
        // min 20; list 18 + 3 extra bills 21. A fixed_price 21 schedule is a price-UP
        // rule the engine never honours (it only discounts), so it must not reprice
        // the gate's subtotal — the basket clears the minimum on its billed amount.
        $r = $this->storeFixture(20);
        $m = $this->item($r, 18);
        $extra = MenuExtra::create(['menu_id' => $m->id, 'title' => 'Add-on', 'price' => 3]);
        $this->schedule($r, 'fixed_price', 21);

        $this->post(route('store.submitOrder', $r), $this->payload($m, [['id' => $extra->id]]))
            ->assertOk()->assertJson(['success' => true]);

        $this->assertSame(1, Order::where('store_id', $r->id)->count());
    }
}
