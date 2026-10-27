<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Ordering;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\MenuCategory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\StaffMember;
use App\Services\OrderApiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Menu\Models\MenuVariantOption;
use Tests\Support\TestPlans;
use Tests\TestCase;

/** T4-1: orders-hub recent (history) endpoint — tenant-scoped, POS-only, unheld. */
class PosOrdersHubTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
        ]);
    }

    private function storeFixture(): array
    {
        $plan = TestPlans::create([
            'name' => 'P '.Str::random(5), 'description' => 'T',
            'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1,
            'settings' => ['restaurant_limit' => 999, 'menu_limit' => 999, 'allow_ordering' => '1', 'pos_enabled' => '1'],
            'status' => 1,
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $r = Store::create([
            'user_id' => $owner->id, 'slug' => 'x-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => 'R', 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1', 'phone' => '1',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $staff = StaffMember::create([
            'owner_id' => $r->user_id, 'store_id' => $r->id,
            'name' => 'S', 'email' => Str::random(6).'@t.test', 'password' => bcrypt('x'),
            'role' => 'cashier', 'active' => 1,
        ]);

        return [$r, $staff];
    }

    private function order(Store $r, array $attr): Order
    {
        $o = new Order;
        $o->forceFill(array_merge(['store_id' => $r->id, 'type' => 'takeaway', 'status' => 'new', 'is_paid' => 0, 'source' => 'pos', 'created_at' => now()], $attr))->save();

        return $o;
    }

    public function test_edit_order_loads_cart_and_replaces_lines(): void
    {
        [$r, $staff] = $this->storeFixture();
        $cat = MenuCategory::create(['store_id' => $r->id, 'name' => 'M', 'active' => 1]);
        $a = Menu::create(['store_id' => $r->id, 'category_id' => $cat->id, 'name' => 'Alpha', 'price' => 10, 'active' => 1]);
        $b = Menu::create(['store_id' => $r->id, 'category_id' => $cat->id, 'name' => 'Beta', 'price' => 7, 'active' => 1]);

        $order = OrderApiService::create($r->user_id, $r->id, [
            'type' => 'on-table', 'table_number' => '4',
            'items' => [['item_id' => $a->id, 'quantity' => 2]],
        ]);

        // Cart-shaped load returns editable lines (ids + unit price).
        $this->actingAs($staff, 'staff')
            ->getJson(route('pos.orders.cart', ['id' => $order->id, 'store' => $r->id]))
            ->assertOk()
            ->assertJsonPath('lines.0.id', $a->id)
            ->assertJsonPath('lines.0.qty', 2)
            ->assertJsonPath('lines.0.unitPrice', 10);

        // Replace with a different set.
        $this->actingAs($staff, 'staff')
            ->postJson(route('pos.orders.replace', ['id' => $order->id, 'store' => $r->id]), [
                'items' => [['item_id' => $b->id, 'quantity' => 1], ['item_id' => $a->id, 'quantity' => 1]],
            ])->assertOk();

        $fresh = $order->fresh();
        $this->assertSame(2, $fresh->items()->count());
        $this->assertSame([$a->id, $b->id], $fresh->items()->pluck('item_id')->sort()->values()->all());
        $this->assertSame(2, (int) $fresh->items()->sum('quantity'));

        // Paid order → 422.
        $fresh->forceFill(['is_paid' => 1])->save();
        $this->actingAs($staff, 'staff')
            ->postJson(route('pos.orders.replace', ['id' => $order->id, 'store' => $r->id]), [
                'items' => [['item_id' => $a->id, 'quantity' => 1]],
            ])->assertStatus(422);
    }

    public function test_store_returns_print_instructions_for_the_new_order(): void
    {
        [$r, $staff] = $this->storeFixture();
        $cat = MenuCategory::create(['store_id' => $r->id, 'name' => 'M', 'active' => 1]);
        $item = Menu::create(['store_id' => $r->id, 'category_id' => $cat->id, 'name' => 'Dish', 'price' => 10, 'active' => 1]);

        $this->actingAs($staff, 'staff')
            ->postJson(route('pos.orders.store', ['store' => $r->id]), [
                'type' => 'takeaway', 'items' => [['item_id' => $item->id, 'quantity' => 1]],
            ])
            ->assertStatus(201)
            ->assertJsonStructure(['id', 'print' => ['auto', 'copies']]); // client prints the new order
    }

    public function test_covers_persisted_for_dine_in_and_ignored_otherwise(): void
    {
        [$r, $staff] = $this->storeFixture();
        $cat = MenuCategory::create(['store_id' => $r->id, 'name' => 'M', 'active' => 1]);
        $item = Menu::create(['store_id' => $r->id, 'category_id' => $cat->id, 'name' => 'Dish', 'price' => 10, 'active' => 1]);

        // Dine-in: covers stored.
        $dine = $this->actingAs($staff, 'staff')
            ->postJson(route('pos.orders.store', ['store' => $r->id]), [
                'type' => 'on-table', 'table_number' => '5', 'covers' => 4,
                'items' => [['item_id' => $item->id, 'quantity' => 1]],
            ])->assertStatus(201);
        $this->assertSame(4, (int) Order::find($dine->json('id'))->covers);

        // Takeaway: covers ignored (dine-in only).
        $take = $this->actingAs($staff, 'staff')
            ->postJson(route('pos.orders.store', ['store' => $r->id]), [
                'type' => 'takeaway', 'covers' => 4,
                'items' => [['item_id' => $item->id, 'quantity' => 1]],
            ])->assertStatus(201);
        $this->assertNull(Order::find($take->json('id'))->covers);
    }

    public function test_hold_persists_note_and_resume_unholds(): void
    {
        [$r, $staff] = $this->storeFixture();
        $cat = MenuCategory::create(['store_id' => $r->id, 'name' => 'M', 'active' => 1]);
        $item = Menu::create(['store_id' => $r->id, 'category_id' => $cat->id, 'name' => 'Dish', 'price' => 10, 'active' => 1]);

        $res = $this->actingAs($staff, 'staff')
            ->postJson(route('pos.orders.store', ['store' => $r->id]), [
                'type' => 'takeaway', 'held' => true, 'note' => 'table by window',
                'items' => [['item_id' => $item->id, 'quantity' => 2]],
            ])
            ->assertStatus(201);

        $order = Order::find($res->json('id'));
        $this->assertSame('table by window', $order->message);   // T4-2: note → orders.message
        $this->assertNotNull($order->held_at);

        // Resume un-holds (items preserved — options-preservation covered in PosOptionsTest).
        $this->actingAs($staff, 'staff')
            ->postJson(route('pos.orders.resume', ['id' => $order->id, 'store' => $r->id]))
            ->assertOk();
        $this->assertNull($order->fresh()->held_at);
        $this->assertSame(2, (int) $order->items()->sum('quantity'));
    }

    public function test_order_detail_returns_lines_with_options(): void
    {
        [$r, $staff] = $this->storeFixture();
        $cat = MenuCategory::create(['store_id' => $r->id, 'name' => 'M', 'active' => 1]);
        $item = Menu::create(['store_id' => $r->id, 'category_id' => $cat->id, 'name' => 'Dish', 'price' => 10, 'active' => 1]);
        $g = MenuVariantOption::create([
            'store_id' => $r->id, 'is_global' => false, 'menu_id' => $item->id,
            'title' => 'Size', 'options' => ['Small', 'Large'], 'option_prices' => [0, 3],
            'required' => true, 'min_select' => 1, 'max_select' => 1, 'active' => 1,
        ]);
        $item->attachedVariantOptions()->attach($g->id, ['active' => true, 'position' => 0]);

        $order = OrderApiService::create($r->user_id, $r->id, [
            'type' => 'takeaway', 'held' => true,
            'items' => [['item_id' => $item->id, 'quantity' => 1, 'options' => [['group_id' => $g->id, 'selected' => ['Large']]]]],
        ]);

        $this->actingAs($staff, 'staff')
            ->getJson(route('pos.orders.detail', ['id' => $order->id, 'store' => $r->id]))
            ->assertOk()
            ->assertJsonPath('lines.0.name', 'Dish')
            ->assertJsonPath('lines.0.options.0.title', 'Size')
            ->assertJsonPath('lines.0.options.0.selected', 'Large');
    }

    public function test_recent_lists_pos_orders_excludes_held_and_other_tenants(): void
    {
        [$r, $staff] = $this->storeFixture();
        $keep = $this->order($r, []);
        $this->order($r, ['held_at' => now()]);          // held → excluded
        $this->order($r, ['source' => 'qr']);            // non-POS → excluded
        [$rB] = $this->storeFixture();
        $this->order($rB, []);                           // other tenant → excluded

        $res = $this->actingAs($staff, 'staff')
            ->getJson(route('pos.orders.recent', ['store' => $r->id]))
            ->assertOk();

        $ids = collect($res->json())->pluck('id')->all();
        $this->assertSame([$keep->id], $ids);
    }

    public function test_incoming_lists_non_pos_orders_excludes_pos_held_and_other_tenants(): void
    {
        [$r, $staff] = $this->storeFixture();
        $qr = $this->order($r, ['source' => 'qr']);       // online/QR → included
        $wa = $this->order($r, ['source' => 'whatsapp']); // WhatsApp → included
        $this->order($r, []);                             // source=pos → excluded
        $this->order($r, ['source' => 'qr', 'held_at' => now()]); // held → excluded
        [$rB] = $this->storeFixture();
        $this->order($rB, ['source' => 'qr']);            // other tenant → excluded

        $res = $this->actingAs($staff, 'staff')
            ->getJson(route('pos.orders.incoming', ['store' => $r->id]))
            ->assertOk();

        $ids = collect($res->json())->pluck('id')->sort()->values()->all();
        $this->assertSame(collect([$qr->id, $wa->id])->sort()->values()->all(), $ids);
        $this->assertSame('qr', collect($res->json())->firstWhere('id', $qr->id)['source']);
    }

    /** D7 (integrations pack): the incoming payload carries a human channel badge. */
    public function test_incoming_carries_channel_source_label(): void
    {
        [$r, $staff] = $this->storeFixture();
        $agg = $this->order($r, ['source' => 'deliverect']);   // aggregator channel
        $qr = $this->order($r, ['source' => 'qr']);            // native online

        $rows = collect($this->actingAs($staff, 'staff')
            ->getJson(route('pos.orders.incoming', ['store' => $r->id]))
            ->assertOk()
            ->json());

        $aggRow = $rows->firstWhere('id', $agg->id);
        $this->assertSame('Deliverect', $aggRow['source_label']);
        $this->assertTrue($aggRow['is_channel']);

        $qrRow = $rows->firstWhere('id', $qr->id);
        $this->assertSame('Qr', $qrRow['source_label']); // ucfirst native fallback
        $this->assertFalse($qrRow['is_channel']);
    }

    public function test_add_items_appends_to_an_open_order_and_rejects_paid(): void
    {
        [$r, $staff] = $this->storeFixture();
        $cat = MenuCategory::create(['store_id' => $r->id, 'name' => 'M', 'active' => 1]);
        $item = Menu::create(['store_id' => $r->id, 'category_id' => $cat->id, 'name' => 'Dish', 'price' => 10, 'active' => 1]);

        // Open dine-in order with 1 line.
        $order = OrderApiService::create($r->user_id, $r->id, [
            'type' => 'on-table', 'table_number' => '3',
            'items' => [['item_id' => $item->id, 'quantity' => 1]],
        ]);
        $this->assertSame(1, (int) $order->items()->sum('quantity'));

        // Add 2 more of the same item.
        $this->actingAs($staff, 'staff')
            ->postJson(route('pos.orders.addItems', ['id' => $order->id, 'store' => $r->id]), [
                'items' => [['item_id' => $item->id, 'quantity' => 2]],
            ])->assertOk();
        $this->assertSame(3, (int) $order->fresh()->items()->sum('quantity'));

        // Once paid, no more additions.
        $order->forceFill(['is_paid' => 1])->save();
        $this->actingAs($staff, 'staff')
            ->postJson(route('pos.orders.addItems', ['id' => $order->id, 'store' => $r->id]), [
                'items' => [['item_id' => $item->id, 'quantity' => 1]],
            ])->assertStatus(422);
    }

    public function test_settling_a_held_order_unholds_it(): void
    {
        [$r, $staff] = $this->storeFixture();
        $order = $this->order($r, ['held_at' => now()]);

        $this->actingAs($staff, 'staff')
            ->postJson(route('pos.orders.settle', ['id' => $order->id, 'store' => $r->id]), ['method' => 'card'])
            ->assertOk();

        $fresh = $order->fresh();
        $this->assertSame(1, (int) $fresh->is_paid);
        $this->assertNull($fresh->held_at); // no paid-but-parked zombie in the On-hold tab
    }

    public function test_external_order_can_be_settled_from_the_register(): void
    {
        [$r, $staff] = $this->storeFixture();
        $cat = MenuCategory::create(['store_id' => $r->id, 'name' => 'M', 'active' => 1]);
        $item = Menu::create(['store_id' => $r->id, 'category_id' => $cat->id, 'name' => 'Dish', 'price' => 40, 'active' => 1]);
        $order = $this->order($r, ['source' => 'qr', 'is_paid' => 0]); // external, unpaid
        OrderItem::create(['order_id' => $order->id, 'item_id' => $item->id, 'quantity' => 1]);
        $this->assertEqualsWithDelta(40.0, (float) $order->fresh()->total, 0.01); // no tax configured

        $res = $this->actingAs($staff, 'staff')
            ->postJson(route('pos.orders.settle', ['id' => $order->id, 'store' => $r->id]), [
                'method' => 'cash', 'tendered' => 50,
            ])
            ->assertOk()
            ->assertJsonPath('is_paid', true);

        $fresh = $order->fresh();
        $this->assertSame(1, (int) $fresh->is_paid);
        $this->assertSame('cash', $fresh->payment_method);
        $this->assertEqualsWithDelta(10.0, (float) $fresh->change_due, 0.01); // 50 − 40
    }
}
