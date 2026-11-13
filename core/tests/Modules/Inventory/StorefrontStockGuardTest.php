<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Inventory;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\MenuCategory;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Menu\Models\MenuVariant;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * P4-04 (e2e journey p4-inventory found these on the DEFAULT storefront pipeline): the unified pipeline and the API refused a
 * customer's order for more stock than the shelf has, but /send-order's default path took the stock negative; a cart whose every
 * line was dropped left an EMPTY order on the kitchen screen; and (already refused) another store's item id is pinned too.
 */
class StorefrontStockGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        Mail::fake();
        Notification::fake();
        Queue::fake();
        $this->shareLayoutGlobals();
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
    }

    private function store(): Store
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 't', 'monthly_price' => 1, 'annual_price' => 1,
            'lifetime_price' => 1, 'status' => 1, 'settings' => ['restaurant_limit' => 999, 'allow_ordering' => '1'],
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);

        return Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(8)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 't', 'description' => 't', 'main_image' => 'default.png',
            'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '123',
        ]);
    }

    private function item(Store $store, string $name, ?int $stock, bool $soldOut = false): Menu
    {
        $cat = MenuCategory::firstOrCreate(['store_id' => $store->id, 'name' => 'Cat']);

        return Menu::create([
            'store_id' => $store->id, 'category_id' => $cat->id, 'name' => $name, 'price' => 10.00, 'active' => 1,
            'is_sold_out' => $soldOut, 'track_inventory' => $stock !== null, 'stock_qty' => $stock, 'low_stock_threshold' => 0,
        ]);
    }

    /** @param list<array<string, int>> $lines */
    private function order(Store $store, array $lines): TestResponse
    {
        return $this->post(route('store.submitOrder', $store), [
            'items' => json_encode(array_map(fn ($l) => $l + ['extras' => []], $lines)),
            'name' => 'Guest', 'ordering-type' => 'takeaway', 'phone-number' => '555-2000', 'pay_via' => 'cash', 'total' => '10.00',
        ]);
    }

    public function test_more_than_the_stock_is_refused_and_takes_nothing(): void
    {
        $store = $this->store();
        $latte = $this->item($store, 'Latte', 2);

        $res = $this->order($store, [['id' => $latte->id, 'quantity' => 5]])->assertOk();

        $this->assertFalse($res->json('success'));
        $this->assertStringContainsString('does not have enough stock', (string) $res->json('message'));
        $this->assertSame(2, (int) $latte->fresh()->stock_qty, 'nothing was taken');
        $this->assertSame(0, Order::where('store_id', $store->id)->count(), 'and no order was left behind');
    }

    public function test_the_stock_is_taken_and_the_last_unit_goes_sold_out(): void
    {
        $store = $this->store();
        $muffin = $this->item($store, 'Muffin', 1);

        $this->assertTrue($this->order($store, [['id' => $muffin->id, 'quantity' => 1]])->assertOk()->json('success'));
        $this->assertSame(0, (int) $muffin->fresh()->stock_qty);
        $this->assertTrue((bool) $muffin->fresh()->is_sold_out);

        $again = $this->order($store, [['id' => $muffin->id, 'quantity' => 1]])->assertOk();
        $this->assertFalse($again->json('success'));
        $this->assertSame(1, Order::where('store_id', $store->id)->count(), 'only the first order exists');
        $this->assertSame(0, (int) $muffin->fresh()->stock_qty, 'the stock never goes below zero');
    }

    public function test_one_short_line_refuses_the_whole_order_and_the_other_line_keeps_its_stock(): void
    {
        $store = $this->store();
        $ok = $this->item($store, 'Plenty', 10);
        $short = $this->item($store, 'Short', 1);

        $res = $this->order($store, [['id' => $ok->id, 'quantity' => 2], ['id' => $short->id, 'quantity' => 3]])->assertOk();

        $this->assertFalse($res->json('success'));
        $this->assertSame(10, (int) $ok->fresh()->stock_qty, 'the first line was rolled back with the order');
        $this->assertSame(1, (int) $short->fresh()->stock_qty);
        $this->assertSame(0, Order::where('store_id', $store->id)->count());
    }

    private function variant(Menu $item, ?int $stock): MenuVariant
    {
        return MenuVariant::create(['menu_id' => $item->id, 'store_id' => $item->store_id, 'price' => 12, 'options' => (object) [],
            'stock_qty' => $stock, 'active' => true, 'position' => 1]);
    }

    public function test_p6_103_the_last_variant_unit_goes_to_one_shopper_only(): void
    {
        $store = $this->store();
        $shirt = $this->item($store, 'Shirt', null); // the item itself is not counted; the size cell is
        $large = $this->variant($shirt, 1);

        $this->assertTrue($this->order($store, [['id' => $shirt->id, 'quantity' => 1, 'variants' => $large->id]])->assertOk()->json('success'));
        $this->assertSame(0, (int) $large->fresh()->stock_qty);

        $second = $this->order($store, [['id' => $shirt->id, 'quantity' => 1, 'variants' => $large->id]])->assertOk();
        $this->assertFalse($second->json('success'), 'refused before any payment is taken');
        $this->assertStringContainsString('does not have enough stock', (string) $second->json('message'));
        $this->assertSame(0, (int) $large->fresh()->stock_qty, 'never negative');
        $this->assertSame(1, Order::where('store_id', $store->id)->count());
    }

    public function test_p6_103_a_short_variant_rolls_back_the_item_stock_too(): void
    {
        $store = $this->store();
        $shirt = $this->item($store, 'Shirt', 5);
        $large = $this->variant($shirt, 1);

        $this->assertFalse($this->order($store, [['id' => $shirt->id, 'quantity' => 2, 'variants' => $large->id]])->assertOk()->json('success'));
        $this->assertSame(5, (int) $shirt->fresh()->stock_qty);
        $this->assertSame(1, (int) $large->fresh()->stock_qty);
    }

    public function test_p6_103_an_untracked_variant_is_unaffected(): void
    {
        $store = $this->store();
        $shirt = $this->item($store, 'Shirt', null);
        $any = $this->variant($shirt, null);

        $this->assertTrue($this->order($store, [['id' => $shirt->id, 'quantity' => 9, 'variants' => $any->id]])->assertOk()->json('success'));
        $this->assertNull($any->fresh()->stock_qty);
    }

    public function test_an_untracked_item_is_unaffected(): void
    {
        $store = $this->store();
        $plain = $this->item($store, 'Plain', null);

        $this->assertTrue($this->order($store, [['id' => $plain->id, 'quantity' => 50]])->assertOk()->json('success'));
        $this->assertSame(1, Order::where('store_id', $store->id)->count());
    }

    public function test_a_cart_of_only_sold_out_items_does_not_leave_an_empty_order(): void
    {
        $store = $this->store();
        $gone = $this->item($store, 'Gone', 0, true);

        $res = $this->order($store, [['id' => $gone->id, 'quantity' => 1]])->assertOk();

        $this->assertFalse($res->json('success'));
        $this->assertSame(0, Order::where('store_id', $store->id)->count(), 'no empty order for the kitchen');
    }

    public function test_another_stores_item_is_refused_and_its_stock_is_not_taken(): void
    {
        $mine = $this->store();
        $theirs = $this->store();
        $own = $this->item($mine, 'Own', 5);
        $foreign = $this->item($theirs, 'Foreign', 5);

        $mixed = $this->order($mine, [['id' => $own->id, 'quantity' => 1], ['id' => $foreign->id, 'quantity' => 2]])->assertOk();
        $this->assertFalse($mixed->json('success'), 'a cart naming another store\'s item is refused whole');
        $this->assertSame(5, (int) $foreign->fresh()->stock_qty, 'the other store\'s stock is untouched');
        $this->assertSame(5, (int) $own->fresh()->stock_qty);

        $onlyForeign = $this->order($mine, [['id' => $foreign->id, 'quantity' => 1]])->assertOk();
        $this->assertFalse($onlyForeign->json('success'));
        $this->assertSame(0, Order::where('store_id', $mine->id)->count());
        $this->assertSame(5, (int) $foreign->fresh()->stock_qty);
    }

    public function test_the_refusal_names_the_item_in_the_shoppers_language(): void
    {
        $store = $this->store();
        $latte = $this->item($store, 'Latte', 1);

        $en = $this->order($store, [['id' => $latte->id, 'quantity' => 4]])->assertOk();
        $this->assertSame('Latte does not have enough stock left.', $en->json('message'));

        // the same wording the unified pipeline and the goods flow use, translated: not a raw English string
        app()->setLocale('ar');
        $this->assertSame('لا يتوفر مخزون كافٍ من Latte.', ___(':item does not have enough stock left.', ['item' => 'Latte']));
        foreach (['ar', 'de', 'es', 'fr', 'hi', 'pt', 'ru', 'tr', 'ur', 'zh'] as $locale) {
            app()->setLocale($locale);
            $this->assertNotSame('Latte does not have enough stock left.', ___(':item does not have enough stock left.', ['item' => 'Latte']), $locale);
        }
        app()->setLocale('en');
    }
}
