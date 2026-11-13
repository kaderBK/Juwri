<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature;

use App\Http\Middleware\EnsureStaffDashboardPermission;
use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\MenuCategory;
use App\Models\Order;
use App\Models\StoreOption;
use App\Models\StoreTable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * P6-351 — the QR-table checkout on the storefront web POST endpoint
 * (User\StoreController::submitOrder, legacy pipeline).
 *
 * Before: any string was accepted as `table` and a non-numeric label (e.g. "A5")
 * was silently dropped (order->table_number = null, service_location left blank).
 *
 * Fix: after TableMergeService::normalizeTableNumber, the on-table branch resolves
 * the StoreTable row the same way StorefrontOrderPlacement::table() does
 * (same store, area_type != 'room', branch = posted branch or NULL branch, match
 * by `key`, or by `table_no` only when the post is all digits — PostgreSQL would
 * reject a text-vs-int comparison). Unknown tables -> the existing
 * "This table was not found at this store." message; labelled tables land on
 * order->service_location (mirroring room_service) and on the contact line.
 */
class WebCheckoutTableTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        $this->shareLayoutGlobals();
        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
            EnsureStaffDashboardPermission::class,
        ]);
        // Order placement dispatches confirmation mail/notifications + jobs; fake
        // them so the empty test-store email template never hits the CSS inliner.
        Mail::fake();
        Notification::fake();
        Queue::fake();
    }

    /** @return array{0: User, 1: Store, 2: Menu} */
    private function fixture(): array
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(4), 'description' => 'T',
            'monthly_price' => 10, 'annual_price' => 100, 'lifetime_price' => 1000,
            'settings' => ['restaurant_limit' => 5, 'allow_ordering' => '1'], 'status' => 1,
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $title = 'R'.Str::random(6);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => 's-'.Str::lower(Str::random(6)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'default.png', 'cover_image' => 'default.png',
            'address' => '1 St', 'phone' => '1234', 'created_at' => now(), 'updated_at' => now(),
        ]);
        StoreOption::updateStoreOption($store->id, 'restaurant_on_table_order', '1');
        StoreOption::updateStoreOption($store->id, 'store_submit_order_notification', '0');

        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Cat']);
        $menu = Menu::create([
            'store_id' => $store->id, 'category_id' => $cat->id,
            'name' => 'Burger', 'price' => 10.00, 'active' => 1,
        ]);

        return [$owner, $store, $menu];
    }

    private function items(Menu $menu): string
    {
        return json_encode([['id' => $menu->id, 'quantity' => 1, 'variants' => 0, 'extras' => [], 'mods' => []]]);
    }

    private function postOrder(Store $store, array $extra): TestResponse
    {
        return $this->postJson(route('store.submitOrder', $store), array_merge([
            'items' => '[]',
            'name' => 'Guest',
            'ordering-type' => 'on-table',
            'pay_via' => 'cash',
            'total' => '10.00',
        ], $extra));
    }

    /**
     * The headline fix: a labelled "A5" table — table_no is text, label carries
     * the human name — lands on order->service_location, never on the integer
     * table_number column.
     */
    public function test_a_labelled_table_lands_the_label_on_service_location(): void
    {
        [, $store, $menu] = $this->fixture();
        // The guest's `table` post is looked up by `key` (StorefrontOrderPlacement::table()
        // shape); keys collide on `table_no` only when the post is all digits, so the
        // labelled-table path always carries the row's human key as well, the way the
        // scan flow does. Mirror it here so the post value resolves.
        StoreTable::create([
            'store_id' => $store->id, 'table_no' => 5, 'label' => 'A5',
            'key' => 'A5', 'seats' => 4, 'status' => 'free', 'branch_id' => null,
        ]);

        $res = $this->postOrder($store, [
            'items' => $this->items($menu),
            'table' => 'A5',
        ]);
        $res->assertOk()->assertJson(['success' => true]);

        $order = Order::where('store_id', $store->id)->sole();
        $this->assertSame('on-table', $order->type);
        $this->assertSame(5, (int) $order->table_number);
        $this->assertSame('A5', $order->service_location, 'A non-numeric table label goes to service_location (mirror of room_service)');
    }

    /** Unknown table -> refused with the existing fixed message; no order row. */
    public function test_an_unknown_table_is_refused_with_the_fixed_message(): void
    {
        [, $store, $menu] = $this->fixture();

        $res = $this->postOrder($store, [
            'items' => $this->items($menu),
            'table' => '9999',
        ]);
        $res->assertOk()->assertJson([
            'success' => false,
            'message' => ___('This table was not found at this store.'),
        ]);
        $this->assertSame(0, Order::where('store_id', $store->id)->count(), 'a refused table must not leave an Order row');
    }

    /**
     * A table of another branch must not resolve when the guest posts that branch.
     *
     * Same store, two branches A and B; the dine-in row lives on branch B (so a
     * guest scanning it from the B terminal can be served). A request that
     * declares branch_id=A is on the A terminal — it must not see branch B's
     * tables, the same way StoreController's branch gate already refuses a B-row
     * against an A-side request for the menu. (UNSCOPED rows, branch_id IS NULL,
     * remain resolvable — that's the A10 carve-out used for rooms.)
     */
    public function test_a_table_of_another_branch_is_refused(): void
    {
        [, $store, $menu] = $this->fixture();
        $branchA = Branch::create([
            'store_id' => $store->id, 'name' => 'Branch A',
            'active' => 1, 'accepting_orders' => 1,
        ]);
        $branchB = Branch::create([
            'store_id' => $store->id, 'name' => 'Branch B',
            'active' => 1, 'accepting_orders' => 1,
        ]);
        StoreTable::create([
            'store_id' => $store->id, 'table_no' => 7, 'label' => 'T7',
            'key' => Str::random(10), 'seats' => 4, 'status' => 'free',
            'branch_id' => $branchB->id,
        ]);

        // Guest posts branch_id=B: row belongs to B → resolves.
        $res = $this->postOrder($store, [
            'items' => $this->items($menu),
            'table' => '7',
            'branch_id' => (string) $branchB->id,
        ]);
        $res->assertOk()->assertJson(['success' => true]);
        $this->assertSame(7, (int) Order::where('store_id', $store->id)->latest('id')->value('table_number'));

        // Guest posts branch_id=A: row belongs to B (not null) → refused.
        $res2 = $this->postOrder($store, [
            'items' => $this->items($menu),
            'table' => '7',
            'branch_id' => (string) $branchA->id,
        ]);
        $res2->assertOk()->assertJson([
            'success' => false,
            'message' => ___('This table was not found at this store.'),
        ]);
    }

    /** A numeric table that the store owns — table_number 7 still goes to the int column. */
    public function test_a_numeric_table_still_sets_table_number(): void
    {
        [, $store, $menu] = $this->fixture();
        StoreTable::create([
            'store_id' => $store->id, 'table_no' => 7, 'label' => 'T7',
            'key' => Str::random(10), 'seats' => 4, 'status' => 'free', 'branch_id' => null,
        ]);

        $res = $this->postOrder($store, [
            'items' => $this->items($menu),
            'table' => '7',
        ]);
        $res->assertOk()->assertJson(['success' => true]);

        $order = Order::where('store_id', $store->id)->sole();
        $this->assertSame(7, (int) $order->table_number);
        $this->assertSame('T7', $order->service_location);
    }
}
