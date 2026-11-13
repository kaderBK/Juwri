<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Customers;

use App\Models\Customer;
use App\Models\LoyaltyAccount;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Customers\Services\CustomerMergeService;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Payments\Services\LedgerService;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * GOL C1 (rev/guest-order-linking): folding a passwordless shadow Customer
 * into the canonical account moves every reference and refuses unsafe merges.
 */
class CustomerMergeServiceTest extends TestCase
{
    use RefreshDatabase;

    private function makeRestaurant(): Store
    {
        $plan = TestPlans::create(['name' => 'P'.Str::random(4), 'description' => 'T', 'monthly_price' => 10, 'annual_price' => 100, 'lifetime_price' => 1000, 'settings' => ['restaurant_limit' => 5, 'allow_ordering' => '1'], 'status' => 1]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $title = 'R'.Str::random(6);

        return Store::create(['user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::random(5), 'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'default.png', 'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '1234', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function shadow(Store $r, array $attrs = []): Customer
    {
        return Customer::create(array_merge([
            'store_id' => $r->id, 'name' => 'Shadow', 'source' => 'checkout',
        ], $attrs));
    }

    private function account(Store $r, array $attrs = []): Customer
    {
        return Customer::create(array_merge([
            'store_id' => $r->id, 'name' => 'Account', 'email' => 'acct'.Str::random(5).'@t.com',
            'password' => bcrypt('secret-123'),
        ], $attrs));
    }

    public function test_refuses_passworded_shadow_cross_restaurant_and_self(): void
    {
        $r = $this->makeRestaurant();
        $r2 = $this->makeRestaurant();
        $canonical = $this->account($r);

        // A passworded row is a real account — never merged away (ATO guard).
        $real = $this->account($r, ['email' => 'real@t.com']);
        try {
            CustomerMergeService::merge($real, $canonical);
            $this->fail('Expected refusal of passworded shadow.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('password', $e->getMessage());
        }
        $this->assertDatabaseHas('customers', ['id' => $real->id]);

        // Cross-restaurant refused.
        $foreign = $this->shadow($r2);
        try {
            CustomerMergeService::merge($foreign, $canonical);
            $this->fail('Expected refusal of cross-restaurant merge.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('restaurant', $e->getMessage());
        }

        // Self-merge refused.
        try {
            CustomerMergeService::merge($canonical, $canonical);
            $this->fail('Expected refusal of self-merge.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('itself', $e->getMessage());
        }
    }

    public function test_moves_orders_addresses_and_aggregates_then_deletes_shadow(): void
    {
        $r = $this->makeRestaurant();
        $shadow = $this->shadow($r, ['phone' => '555-1111', 'orders_count' => 2, 'total_spent' => 30.50]);
        $canonical = $this->account($r, ['orders_count' => 1, 'total_spent' => 10.00]);

        $o1 = Order::create(['store_id' => $r->id, 'customer_id' => $shadow->id, 'customer_name' => 'S']);
        $o2 = Order::create(['store_id' => $r->id, 'customer_id' => $shadow->id, 'customer_name' => 'S']);
        DB::table('customer_addresses')->insert(['customer_id' => $shadow->id, 'label' => 'Home', 'line' => '1 St', 'created_at' => now(), 'updated_at' => now()]);

        $moved = CustomerMergeService::merge($shadow, $canonical);

        $this->assertSame(2, $moved['orders']);
        $this->assertSame($canonical->id, $o1->fresh()->customer_id);
        $this->assertSame($canonical->id, $o2->fresh()->customer_id);
        $this->assertDatabaseHas('customer_addresses', ['customer_id' => $canonical->id, 'label' => 'Home']);
        $this->assertDatabaseMissing('customers', ['id' => $shadow->id]);

        $canonical->refresh();
        $this->assertSame(3, $canonical->orders_count);
        $this->assertEquals(40.50, (float) $canonical->total_spent);
        // Shadow's phone filled onto the canonical's empty slot (unique index freed by delete).
        $this->assertSame('555-1111', $canonical->phone);
    }

    public function test_loyalty_accounts_fold_when_both_exist(): void
    {
        $r = $this->makeRestaurant();
        $shadow = $this->shadow($r);
        $canonical = $this->account($r);

        $sAcct = LoyaltyAccount::forCustomer($shadow->id, $r->id);
        $sAcct->earn(910001, 50, 'guest earn');
        $cAcct = LoyaltyAccount::forCustomer($canonical->id, $r->id);
        $cAcct->earn(910002, 20, 'member earn');

        CustomerMergeService::merge($shadow, $canonical);

        $this->assertDatabaseMissing('loyalty_accounts', ['id' => $sAcct->id]);
        $cAcct->refresh();
        $this->assertSame(70, $cAcct->balance);
        $this->assertSame(70, $cAcct->lifetime_earned);
        // Shadow's ledger history moved (not cascaded away) — double-earn dedupe intact.
        $this->assertDatabaseHas('loyalty_ledger_entries', ['loyalty_account_id' => $cAcct->id, 'order_id' => 910001]);
        $this->assertFalse($cAcct->earn(910001, 50, 'replay'), 'moved ledger row must still block a double-earn');
    }

    public function test_store_credit_repoints_or_transfers(): void
    {
        $r = $this->makeRestaurant();

        // Case 1: only the shadow has an account — repoint, balance preserved.
        $shadow = $this->shadow($r);
        $canonical = $this->account($r);
        $sAcct = LedgerService::accountFor('customer', $shadow->id, 'customer_store_credit', 'USD');
        $clearing = LedgerService::accountFor('platform', 0, 'platform', 'USD');
        LedgerService::post([
            ['account' => $clearing, 'amount' => -15.0, 'allow_negative' => true, 'ref' => 't1:clr', 'reason' => 't'],
            ['account' => $sAcct, 'amount' => 15.0, 'ref' => 't1:cust', 'reason' => 't'],
        ], null, 't');

        $moved = CustomerMergeService::merge($shadow, $canonical);
        $this->assertEquals(15.0, $moved['store_credit']);
        $this->assertDatabaseHas('ledger_accounts', ['id' => $sAcct->id, 'owner_id' => $canonical->id]);

        // Case 2: both have accounts — balanced transfer, shadow account closed.
        $shadow2 = $this->shadow($r, ['name' => 'Shadow2']);
        $canonical2 = $this->account($r, ['name' => 'Account2']);
        $sAcct2 = LedgerService::accountFor('customer', $shadow2->id, 'customer_store_credit', 'USD');
        $cAcct2 = LedgerService::accountFor('customer', $canonical2->id, 'customer_store_credit', 'USD');
        LedgerService::post([
            ['account' => $clearing, 'amount' => -12.5, 'allow_negative' => true, 'ref' => 't2:clr', 'reason' => 't'],
            ['account' => $sAcct2, 'amount' => 12.5, 'ref' => 't2:cust', 'reason' => 't'],
        ], null, 't');

        $moved2 = CustomerMergeService::merge($shadow2, $canonical2);
        $this->assertEquals(12.5, $moved2['store_credit']);
        $this->assertEquals(12.5, round(LedgerService::balanceFor($cAcct2->fresh()), 2));
        $this->assertEquals(0.0, round(LedgerService::balanceFor($sAcct2->fresh()), 2));
        $this->assertSame('closed', $sAcct2->fresh()->status);
    }

    public function test_coupon_redemption_seq_collision_renumbers(): void
    {
        $r = $this->makeRestaurant();
        $shadow = $this->shadow($r);
        $canonical = $this->account($r);

        DB::table('coupon_redemptions')->insert([
            ['coupon_id' => 7, 'customer_id' => $shadow->id, 'seq' => 1, 'created_at' => now()],
            ['coupon_id' => 7, 'customer_id' => $canonical->id, 'seq' => 1, 'created_at' => now()],
        ]);

        $moved = CustomerMergeService::merge($shadow, $canonical);

        $this->assertSame(1, $moved['coupon_redemptions']);
        $rows = DB::table('coupon_redemptions')->where('coupon_id', 7)
            ->where('customer_id', $canonical->id)->orderBy('seq')->pluck('seq')->all();
        $this->assertSame([1, 2], array_map('intval', $rows));
    }

    public function test_follow_duplicates_deduped_not_wiped(): void
    {
        $r = $this->makeRestaurant();
        $shadow = $this->shadow($r);
        $canonical = $this->account($r);

        // Shadow follows two restaurants; canonical already follows one of them.
        $r2 = $this->makeRestaurant();
        DB::table('store_follows')->insert([
            ['customer_id' => $shadow->id, 'store_id' => $r->id, 'account_id' => $r->account_id, 'created_at' => now(), 'updated_at' => now()],
            ['customer_id' => $shadow->id, 'store_id' => $r2->id, 'account_id' => $r2->account_id, 'created_at' => now(), 'updated_at' => now()],
            ['customer_id' => $canonical->id, 'store_id' => $r->id, 'account_id' => $r->account_id, 'created_at' => now(), 'updated_at' => now()],
        ]);

        CustomerMergeService::merge($shadow, $canonical);

        $follows = DB::table('store_follows')->where('customer_id', $canonical->id)->pluck('store_id')->all();
        sort($follows);
        $this->assertSame([$r->id, $r2->id], array_map('intval', $follows));
        $this->assertSame(0, DB::table('store_follows')->where('customer_id', $shadow->id)->count());
    }

    public function test_moves_customer_notifications(): void
    {
        $r = $this->makeRestaurant();
        $shadow = $this->shadow($r);
        $canonical = $this->account($r);

        DB::table('customer_notifications')->insert([
            'account_id' => $r->account_id, 'store_id' => $r->id, 'customer_id' => $shadow->id,
            'title' => 'Hi', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $moved = CustomerMergeService::merge($shadow, $canonical);

        $this->assertSame(1, $moved['customer_notifications']);
        $this->assertSame(1, DB::table('customer_notifications')->where('customer_id', $canonical->id)->count());
        $this->assertSame(0, DB::table('customer_notifications')->where('customer_id', $shadow->id)->count());
    }

    public function test_moves_return_requests(): void
    {
        $r = $this->makeRestaurant();
        $shadow = $this->shadow($r);
        $canonical = $this->account($r);
        $order = Order::create(['store_id' => $r->id, 'customer_id' => $shadow->id, 'customer_name' => 'S']);

        DB::table('return_requests')->insert([
            'account_id' => $r->account_id, 'store_id' => $r->id, 'order_id' => $order->id, 'customer_id' => $shadow->id,
            'resolution' => 'refund', 'lines' => '[]', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $moved = CustomerMergeService::merge($shadow, $canonical);

        $this->assertSame(1, $moved['return_requests']);
        $this->assertSame(1, DB::table('return_requests')->where('customer_id', $canonical->id)->count());
        $this->assertSame(0, DB::table('return_requests')->where('customer_id', $shadow->id)->count());
    }

    public function test_moves_hotel_bookings(): void
    {
        $r = $this->makeRestaurant();
        $shadow = $this->shadow($r);
        $canonical = $this->account($r);

        DB::table('hotel_bookings')->insert([
            'account_id' => $r->account_id, 'store_id' => $r->id, 'customer_id' => $shadow->id,
            'code' => 'ABCD1234', 'guest_name' => 'G', 'room_type_id' => 1,
            'check_in' => '2026-09-01', 'check_out' => '2026-09-02', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $moved = CustomerMergeService::merge($shadow, $canonical);

        $this->assertSame(1, $moved['hotel_bookings']);
        $this->assertSame(1, DB::table('hotel_bookings')->where('customer_id', $canonical->id)->count());
        $this->assertSame(0, DB::table('hotel_bookings')->where('customer_id', $shadow->id)->count());
    }

    public function test_moves_skin_profiles(): void
    {
        $r = $this->makeRestaurant();
        $shadow = $this->shadow($r);
        $canonical = $this->account($r);

        DB::table('skin_profiles')->insert([
            'account_id' => $r->account_id, 'store_id' => $r->id, 'customer_id' => $shadow->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $moved = CustomerMergeService::merge($shadow, $canonical);

        $this->assertSame(1, $moved['skin_profiles']);
        $this->assertSame(1, DB::table('skin_profiles')->where('customer_id', $canonical->id)->count());
        $this->assertSame(0, DB::table('skin_profiles')->where('customer_id', $shadow->id)->count());
    }

    public function test_moves_wishlist_items(): void
    {
        $r = $this->makeRestaurant();
        $shadow = $this->shadow($r);
        $canonical = $this->account($r);
        $menu = Menu::create(['store_id' => $r->id, 'name' => 'Shirt', 'active' => 1]);

        DB::table('wishlist_items')->insert([
            'account_id' => $r->account_id, 'store_id' => $r->id, 'customer_id' => $shadow->id,
            'menu_id' => $menu->id, 'created_at' => now(),
        ]);

        $moved = CustomerMergeService::merge($shadow, $canonical);

        $this->assertSame(1, $moved['wishlist_items']);
        $this->assertSame(1, DB::table('wishlist_items')->where('customer_id', $canonical->id)->count());
        $this->assertSame(0, DB::table('wishlist_items')->where('customer_id', $shadow->id)->count());
    }

    public function test_moves_stock_alerts(): void
    {
        $r = $this->makeRestaurant();
        $shadow = $this->shadow($r);
        $canonical = $this->account($r);
        $menu = Menu::create(['store_id' => $r->id, 'name' => 'Shirt', 'active' => 1]);

        DB::table('stock_alerts')->insert([
            'account_id' => $r->account_id, 'store_id' => $r->id, 'customer_id' => $shadow->id, 'menu_id' => $menu->id,
            'channel' => 'email', 'contact' => 'a@example.com', 'contact_hash' => str_repeat('a', 64),
            'target' => 'm'.$menu->id.':v0', 'status' => 'waiting', 'opted_in_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $moved = CustomerMergeService::merge($shadow, $canonical);

        $this->assertSame(1, $moved['stock_alerts']);
        $this->assertSame(1, DB::table('stock_alerts')->where('customer_id', $canonical->id)->count());
        $this->assertSame(0, DB::table('stock_alerts')->where('customer_id', $shadow->id)->count());
    }

    public function test_wishlist_duplicates_deduped_not_wiped(): void
    {
        $r = $this->makeRestaurant();
        $shadow = $this->shadow($r);
        $canonical = $this->account($r);
        $shared = Menu::create(['store_id' => $r->id, 'name' => 'Shared', 'active' => 1]);
        $onlyShadow = Menu::create(['store_id' => $r->id, 'name' => 'OnlyShadow', 'active' => 1]);

        DB::table('wishlist_items')->insert([
            ['account_id' => $r->account_id, 'store_id' => $r->id, 'customer_id' => $shadow->id, 'menu_id' => $shared->id, 'created_at' => now()],
            ['account_id' => $r->account_id, 'store_id' => $r->id, 'customer_id' => $shadow->id, 'menu_id' => $onlyShadow->id, 'created_at' => now()],
            ['account_id' => $r->account_id, 'store_id' => $r->id, 'customer_id' => $canonical->id, 'menu_id' => $shared->id, 'created_at' => now()],
        ]);

        $moved = CustomerMergeService::merge($shadow, $canonical);

        $this->assertSame(1, $moved['wishlist_items']);
        $menus = DB::table('wishlist_items')->where('customer_id', $canonical->id)->orderBy('menu_id')->pluck('menu_id')->map(fn ($id) => (int) $id)->all();
        $this->assertSame([$shared->id, $onlyShadow->id], $menus);
        $this->assertSame(0, DB::table('wishlist_items')->where('customer_id', $shadow->id)->count());
    }

    public function test_stock_alerts_repointed_next_to_existing_canonical_alerts(): void
    {
        $r = $this->makeRestaurant();
        $shadow = $this->shadow($r);
        $canonical = $this->account($r);
        $menu = Menu::create(['store_id' => $r->id, 'name' => 'Shirt', 'active' => 1]);
        $target = 'm'.$menu->id.':v0';
        $hash = str_repeat('a', 64);

        DB::table('stock_alerts')->insert([
            ['account_id' => $r->account_id, 'store_id' => $r->id, 'customer_id' => $shadow->id, 'menu_id' => $menu->id, 'channel' => 'email', 'contact' => 'a@example.com', 'contact_hash' => $hash, 'target' => $target, 'status' => 'waiting', 'opted_in_at' => now(), 'created_at' => now()],
            ['account_id' => $r->account_id, 'store_id' => $r->id, 'customer_id' => $shadow->id, 'menu_id' => $menu->id, 'channel' => 'email', 'contact' => 'a@example.com', 'contact_hash' => str_repeat('b', 64), 'target' => 'm'.$menu->id.':v1', 'status' => 'waiting', 'opted_in_at' => now(), 'created_at' => now()],
            ['account_id' => $r->account_id, 'store_id' => $r->id, 'customer_id' => $canonical->id, 'menu_id' => $menu->id, 'channel' => 'email', 'contact' => 'a@example.com', 'contact_hash' => str_repeat('c', 64), 'target' => 'm'.$menu->id.':v2', 'status' => 'waiting', 'opted_in_at' => now(), 'created_at' => now()],
        ]);

        $moved = CustomerMergeService::merge($shadow, $canonical);

        $this->assertSame(2, $moved['stock_alerts']);
        $targets = DB::table('stock_alerts')->where('customer_id', $canonical->id)->orderBy('target')->pluck('target')->all();
        $this->assertSame([$target, 'm'.$menu->id.':v1', 'm'.$menu->id.':v2'], $targets);
        $this->assertSame(0, DB::table('stock_alerts')->where('customer_id', $shadow->id)->count());
    }

    public function test_other_store_customer_rows_untouched(): void
    {
        $r = $this->makeRestaurant();
        $r2 = $this->makeRestaurant();
        $shadow = $this->shadow($r);
        $canonical = $this->account($r);
        $foreign = $this->shadow($r2, ['name' => 'Foreign']);

        DB::table('customer_notifications')->insert([
            'account_id' => $r->account_id, 'store_id' => $r->id, 'customer_id' => $shadow->id, 'title' => 'Mine', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('customer_notifications')->insert([
            'account_id' => $r2->account_id, 'store_id' => $r2->id, 'customer_id' => $foreign->id, 'title' => 'Foreign', 'created_at' => now(), 'updated_at' => now(),
        ]);

        CustomerMergeService::merge($shadow, $canonical);

        $this->assertSame(1, DB::table('customer_notifications')->where('customer_id', $canonical->id)->count());
        $this->assertSame(1, DB::table('customer_notifications')->where('customer_id', $foreign->id)->count());
        $this->assertSame(0, DB::table('customer_notifications')->where('customer_id', $shadow->id)->count());
    }
}
