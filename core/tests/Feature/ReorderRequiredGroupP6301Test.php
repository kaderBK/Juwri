<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Customer;
use App\Models\MenuCategory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Menu\Models\MenuVariantOption;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * P6-301 — reordering a line that carries a REQUIRED modifier group.
 *
 * The customer reorder endpoint builds each line from the old order's
 * `order_items.options` snapshot plus `order_note` (BuildsReorderPayload). A theme
 * that dropped either rebuilt the line without the group and the checkout then
 * refused it ("Choose at least 1 for …", OrderApiService::resolveOptions). This
 * feature test drives the real HTTP path: fetch the reorder payload, feed the
 * returned options / note straight back into the storefront checkout, and assert
 * the placed order carries the same choice-group snapshot and note.
 */
class ReorderRequiredGroupP6301Test extends TestCase
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

        // The unified checkout (OrderApiService) is where the required-group
        // validation and the options snapshot live.
        config(['settings.storefront_order_pipeline' => 'unified']);
        config(['settings.storefront_order_pipeline_stores' => '']);

        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
        ]);
    }

    private function createRestaurant(): Store
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(4), 'description' => 'T',
            'monthly_price' => 0, 'annual_price' => 0, 'lifetime_price' => 0,
            'settings' => ['restaurant_limit' => 999, 'allow_ordering' => '1'],
            'status' => 1,
        ]);

        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);

        $store = Store::create([
            'user_id' => $owner->id,
            'slug' => Str::slug('Pizza Palace').'-'.Str::lower(Str::random(5)),
            'color' => '#ff5500',
            'title' => 'Pizza Palace',
            'sub_title' => 'Test restaurant',
            'description' => 'Test description',
            'main_image' => 'default.png',
            'cover_image' => 'default.png',
            'address' => '1 Test Street',
            'phone' => '1234567890',
        ]);

        StoreOption::updateStoreOption($store->id, 'store_template', 'aurora');
        StoreOption::updateStoreOption($store->id, 'restaurant_takeaway_order', '1');

        return $store;
    }

    private function createCustomer(Store $store): Customer
    {
        return Customer::create([
            'store_id' => $store->id,
            'name' => 'Alice Diner',
            'email' => 'alice@example.test',
            'phone' => '555100200',
            'password' => Hash::make('secret123'),
        ]);
    }

    /**
     * A burger with one REQUIRED single-choice group ("Sauce": Plain +0 · Truffle +2.50).
     *
     * @return array{menu: Menu, group: MenuVariantOption}
     */
    private function createMenuFixture(Store $store): array
    {
        $category = MenuCategory::create([
            'store_id' => $store->id,
            'name' => 'Burgers',
            'parent' => null,
            'position' => 1,
        ]);

        $menu = Menu::create([
            'store_id' => $store->id,
            'category_id' => $category->id,
            'name' => 'Classic Burger',
            'description' => 'Juicy burger',
            'price' => 10,
            'image' => 'default.png',
            'type' => 'veg',
            'active' => '1',
            'position' => 1,
            'is_sold_out' => 0,
        ]);

        $group = MenuVariantOption::create([
            'store_id' => $store->id,
            'menu_id' => $menu->id,
            'title' => 'Sauce',
            'options' => ['Plain', 'Truffle'],
            'option_prices' => [0, 2.50],
            'required' => 1,
            'min_select' => 1,
            'max_select' => 1,
            'position' => 1,
            'active' => 1,
        ]);

        return compact('menu', 'group');
    }

    private function createCustomerOrder(Store $store, Customer $customer, array $fixture): Order
    {
        $order = Order::create([
            'store_id' => $store->id,
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'phone_number' => $customer->phone,
            'status' => 'completed',
            'type' => 'takeaway',
            'created_at' => now(),
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'item_id' => $fixture['menu']->id,
            'variation' => 0,
            'quantity' => 2,
            'order_note' => 'No onions',
            'options' => [[
                'group_id' => (int) $fixture['group']->id,
                'title' => 'Sauce',
                'selected' => [['option' => 'Truffle', 'price' => 2.50]],
            ]],
        ]);

        return $order->fresh(['items.menu', 'items.variant', 'items.itemExtras.extra']);
    }

    public function test_reorder_payload_options_place_an_order_with_the_same_required_group(): void
    {
        $store = $this->createRestaurant();
        $customer = $this->createCustomer($store);
        $fixture = $this->createMenuFixture($store);
        $order = $this->createCustomerOrder($store, $customer, $fixture);

        $this->actingAs($customer, 'customer');

        // 1. The customer reorder endpoint hands back the options snapshot + note.
        $payload = $this->post(route('customer.orders.reorder', [$store->id, $order->id]))
            ->assertOk()
            ->assertJson(['success' => true, 'missing_items' => []])
            ->json('items.0');

        $this->assertSame((int) $fixture['group']->id, (int) $payload['options'][0]['group_id']);
        $this->assertSame('Truffle', $payload['options'][0]['selected'][0]['option']);
        $this->assertSame('No onions', $payload['order_note']);

        // The theme's pickup helper turns the snapshot into the checkout's mods.
        $mods = [];
        foreach ($payload['options'] as $group) {
            $labels = array_values((array) $fixture['group']->getOriginal('options'));
            foreach ($group['selected'] as $selected) {
                $mods[] = [
                    'groupId' => (int) $group['group_id'],
                    'optIndex' => array_search($selected['option'], $labels, true),
                ];
            }
        }

        $line = [
            'id' => $payload['id'],
            'quantity' => $payload['qty'],
            'variants' => $payload['variantId'],
            'extras' => [],
            'mods' => $mods,
            'order_note' => $payload['order_note'],
        ];

        // Negative control: the group really is required, so dropping the
        // reorder options fails exactly the way the bug report described.
        $this->postJson(route('store.submitOrder', $store), [
            'items' => json_encode([array_merge($line, ['mods' => []])]),
            'name' => 'Alice Diner',
            'ordering-type' => 'takeaway',
            'phone-number' => '555100200',
            'pay_via' => 'cash',
        ])->assertOk()->assertJson(['success' => false]);

        $this->assertSame(0, Order::where('store_id', $store->id)->whereKeyNot($order->id)->count());

        // 2. Replaying those options places the order with the same snapshot + note.
        $this->postJson(route('store.submitOrder', $store), [
            'items' => json_encode([$line]),
            'name' => 'Alice Diner',
            'ordering-type' => 'takeaway',
            'phone-number' => '555100200',
            'pay_via' => 'cash',
        ])->assertOk()->assertJson(['success' => true]);

        $placed = Order::where('store_id', $store->id)->whereKeyNot($order->id)->sole();
        $placedLine = $placed->items()->sole();

        $this->assertSame((int) $fixture['menu']->id, (int) $placedLine->item_id);
        $this->assertSame(2, (int) $placedLine->quantity);
        // the server appends the option summary ("Sauce: Truffle (+2.50)") after the diner's own note
        $this->assertStringStartsWith('No onions', (string) $placedLine->order_note);
        $this->assertSame((int) $fixture['group']->id, (int) $placedLine->options[0]['group_id']);
        $this->assertSame('Truffle', $placedLine->options[0]['selected'][0]['option']);
        $this->assertEqualsWithDelta(2.50, (float) $placedLine->options[0]['selected'][0]['price'], 0.001);
    }
}
