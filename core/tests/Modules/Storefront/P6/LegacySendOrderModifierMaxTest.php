<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront\P6;

use App\Http\Middleware\EnsureStaffDashboardPermission;
use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\MenuCategory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\StoreOption;
use App\Services\FeatureAccess;
use App\Services\FeatureRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Menu\Models\MenuVariantOption;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * BL-001 — a legacy (non-unified) storefront order that picks more than a
 * modifier group's max_select must be refused, not silently trimmed. The
 * refusal is the existing JSON envelope and the whole order is rolled back.
 */
class LegacySendOrderModifierMaxTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';

        Mail::fake();
        Notification::fake();
        Queue::fake();

        config(['settings.admin_allow_combos' => true]);
        // BL-001 only concerns the legacy inline branch.
        config(['settings.storefront_order_pipeline' => 'legacy']);
        config(['settings.storefront_order_pipeline_stores' => '']);
        FeatureRegistry::clearCache();
        FeatureAccess::flush();

        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
            EnsureStaffDashboardPermission::class,
        ]);
    }

    private function store(): Store
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(4), 'description' => 'T', 'monthly_price' => 10,
            'annual_price' => 100, 'lifetime_price' => 1000, 'status' => 1,
            'settings' => ['restaurant_limit' => 5, 'allow_ordering' => '1'],
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $title = 'R'.Str::random(6);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'default.png', 'cover_image' => 'default.png', 'address' => '1 St',
            'phone' => '1234', 'created_at' => now(), 'updated_at' => now(),
        ]);
        StoreOption::updateStoreOption($store->id, 'restaurant_takeaway_order', '1');
        FeatureAccess::flush();

        return $store;
    }

    private function item(Store $store, string $name, float $price): Menu
    {
        $cat = MenuCategory::firstOrCreate(['store_id' => $store->id, 'name' => 'Cat']);

        return Menu::create([
            'store_id' => $store->id, 'category_id' => $cat->id,
            'name' => $name, 'price' => $price, 'active' => 1,
        ]);
    }

    /** A "Toppings" group (A/B/C, +1 each) with the given max_select attached to $menu. */
    private function toppingsGroup(Store $store, Menu $menu, ?int $max): MenuVariantOption
    {
        $group = MenuVariantOption::create([
            'store_id' => $store->id, 'is_global' => true, 'menu_id' => null,
            'title' => 'Toppings', 'options' => ['A', 'B', 'C'], 'option_prices' => [1, 1, 1],
            'position' => 0, 'active' => 1, 'required' => false, 'min_select' => 0, 'max_select' => $max,
        ]);
        $menu->attachedVariantOptions()->attach($group->id, ['active' => true]);

        return $group;
    }

    /**
     * @return array<string, string>
     */
    private function payload(Menu $menu, MenuVariantOption $group, int $picks): array
    {
        return [
            'items' => json_encode([[
                'id' => $menu->id,
                'quantity' => 1,
                'variants' => 0,
                'extras' => [],
                'mods' => array_map(
                    fn (int $index) => ['groupId' => $group->id, 'optIndex' => $index],
                    range(0, $picks - 1)
                ),
            ]]),
        ];
    }

    private function send(Store $store, array $extra = []): TestResponse
    {
        return $this->postJson(route('store.submitOrder', $store), array_merge([
            'name' => 'Test Guest',
            'ordering-type' => 'takeaway',
            'phone-number' => '12345678',
            'pay_via' => 'cash',
        ], $extra));
    }

    public function test_three_picks_over_a_max_of_two_are_refused_and_the_order_is_rolled_back(): void
    {
        $store = $this->store();
        $menu = $this->item($store, 'Pizza', 10.00);
        $group = $this->toppingsGroup($store, $menu, 2);

        $response = $this->send($store, $this->payload($menu, $group, 3));

        $response->assertJson(['success' => false]);
        $message = (string) $response->json('message');
        $this->assertStringContainsString('Toppings', $message);
        $this->assertStringContainsString('at most 2', $message);

        $this->assertSame(0, Order::count());
        $this->assertSame(0, OrderItem::count());
    }

    public function test_exactly_two_picks_are_accepted_and_snapshotted(): void
    {
        $store = $this->store();
        $menu = $this->item($store, 'Pizza', 10.00);
        $group = $this->toppingsGroup($store, $menu, 2);

        $this->send($store, $this->payload($menu, $group, 2))->assertJson(['success' => true]);

        $order = Order::where('store_id', $store->id)->firstOrFail();
        $line = $order->items()->firstOrFail();

        $this->assertSame('Toppings', $line->options[0]['title']);
        $selected = array_map(fn (array $pick) => $pick['option'], $line->options[0]['selected']);
        $this->assertSame(['A', 'B'], $selected);
    }

    public function test_max_zero_is_unlimited_and_keeps_all_three_picks(): void
    {
        $store = $this->store();
        $menu = $this->item($store, 'Pizza', 10.00);
        $group = $this->toppingsGroup($store, $menu, 0);

        $this->send($store, $this->payload($menu, $group, 3))->assertJson(['success' => true]);

        $order = Order::where('store_id', $store->id)->firstOrFail();
        $line = $order->items()->firstOrFail();

        $this->assertSame('Toppings', $line->options[0]['title']);
        $selected = array_map(fn (array $pick) => $pick['option'], $line->options[0]['selected']);
        $this->assertSame(['A', 'B', 'C'], $selected);
    }
}
