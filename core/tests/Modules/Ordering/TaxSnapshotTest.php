<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Ordering;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\MenuCategory;
use App\Models\Order;
use App\Models\StaffMember;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Ordering\Services\Tax\TaxSnapshot;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * D7 — tax snapshot: the frozen `[{label, rate, amount}]` shape, render preferring
 * the snapshot, the legacy null fallback recomputing live, and the drift guard —
 * a rate change after the sale must never rewrite the receipt's tax.
 */
class TaxSnapshotTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
    }

    private function storeFixture(string $taxCharge = '15', string $taxType = 'percentage'): Store
    {
        $plan = TestPlans::create([
            'name' => 'P '.Str::random(5), 'description' => 'T',
            'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1,
            'settings' => ['restaurant_limit' => 999, 'menu_limit' => 999, 'allow_ordering' => '1', 'pos_enabled' => '1'],
            'status' => 1,
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $title = 'R '.Str::random(5);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1 St', 'phone' => '1',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->setTax($store, $taxCharge, $taxType);

        return $store;
    }

    private function setTax(Store $store, string $charge, string $type = 'percentage'): void
    {
        StoreOption::updateStoreOption($store->id, 'restaurant_taxx_charge', $charge);
        StoreOption::updateStoreOption($store->id, 'restaurant_tax_type', $type);
    }

    private function staff(Store $r): StaffMember
    {
        return StaffMember::create([
            'owner_id' => $r->user_id, 'store_id' => $r->id,
            'name' => 'M '.Str::random(4), 'email' => Str::random(6).'@t.test',
            'password' => bcrypt('x'), 'role' => 'manager', 'active' => 1,
        ]);
    }

    private function item(Store $r, float $price = 10): Menu
    {
        $cat = MenuCategory::create(['store_id' => $r->id, 'name' => 'Mains', 'active' => 1]);

        return Menu::create([
            'store_id' => $r->id, 'category_id' => $cat->id, 'name' => 'Dish '.Str::random(3),
            'price' => $price, 'active' => 1, 'stock_qty' => 50, 'track_inventory' => 1,
        ]);
    }

    private function posOrder(Store $r, StaffMember $s, int $qty = 2): Order
    {
        $res = $this->actingAs($s, 'staff')
            ->postJson(route('pos.orders.store', ['store' => $r->id]), [
                'type' => 'takeaway', 'items' => [['item_id' => $this->item($r)->id, 'quantity' => $qty]],
            ])->assertStatus(201);

        return Order::findOrFail($res->json('id'));
    }

    public function test_pos_order_is_stamped_with_the_tax_breakdown_shape(): void
    {
        $r = $this->storeFixture('15');
        $order = $this->posOrder($r, $this->staff($r)); // 2 × $10 = $20, 15% = $3

        $lines = TaxSnapshot::lines($order->fresh());
        $this->assertCount(1, $lines);
        $this->assertSame('Tax', $lines[0]['label']);
        $this->assertEqualsWithDelta(15.0, (float) $lines[0]['rate'], 0.001);
        $this->assertEqualsWithDelta(3.0, (float) $lines[0]['amount'], 0.001);
        // Stored as JSON on the column, not left null.
        $this->assertNotNull($order->fresh()->getAttribute('tax_breakdown'));
    }

    public function test_render_prefers_the_snapshot_after_a_rate_change(): void
    {
        $r = $this->storeFixture('15');
        $order = $this->posOrder($r, $this->staff($r)); // stamped at 15% → $3

        // The owner raises the tax to 20% AFTER the sale.
        $this->setTax($r, '20');

        // The frozen snapshot still reads $3, not the new $4.
        $this->assertEqualsWithDelta(3.0, TaxSnapshot::total($order->fresh()), 0.001);
    }

    public function test_legacy_null_order_recomputes_from_live_rates(): void
    {
        $r = $this->storeFixture('10');
        // A pre-migration order carries a null breakdown.
        $order = Order::create([
            'store_id' => $r->id, 'status' => 'completed', 'type' => 'takeaway',
            'customer_name' => 'C', 'created_at' => now(),
        ]);
        $cat = MenuCategory::create(['store_id' => $r->id, 'name' => 'M', 'active' => 1]);
        $menu = Menu::create(['store_id' => $r->id, 'category_id' => $cat->id, 'name' => 'D', 'price' => 10, 'active' => 1]);
        $order->items()->create(['item_id' => $menu->id, 'quantity' => 2]); // $20 subtotal

        $this->assertNull($order->fresh()->getAttribute('tax_breakdown'));
        // Live recompute: 10% of $20 = $2.
        $this->assertEqualsWithDelta(2.0, TaxSnapshot::total($order->fresh()), 0.001);

        // And it tracks the live rate (no snapshot to freeze it).
        $this->setTax($r, '25');
        $this->assertEqualsWithDelta(5.0, TaxSnapshot::total($order->fresh()), 0.001);
    }

    public function test_stamp_is_idempotent(): void
    {
        $r = $this->storeFixture('15');
        $order = $this->posOrder($r, $this->staff($r));
        $first = $order->fresh()->getAttribute('tax_breakdown');

        // Re-stamping (e.g. a retried create) must not overwrite the first snapshot,
        // even after the live rate moved.
        $this->setTax($r, '99');
        TaxSnapshot::stamp($order->fresh());

        $this->assertSame($first, $order->fresh()->getAttribute('tax_breakdown'));
        $this->assertEqualsWithDelta(3.0, TaxSnapshot::total($order->fresh()), 0.001);
    }

    public function test_no_tax_configured_stamps_empty_not_null(): void
    {
        $r = $this->storeFixture('0');
        $order = $this->posOrder($r, $this->staff($r));

        // Stamped (not null) so render prefers it; no tax lines.
        $this->assertNotNull($order->fresh()->getAttribute('tax_breakdown'));
        $this->assertSame([], TaxSnapshot::lines($order->fresh()));
    }
}
