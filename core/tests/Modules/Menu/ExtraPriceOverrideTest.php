<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Menu;

use App\Models\Order;
use App\Models\OrderItem;
use App\Services\OrderApiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Menu\Models\MenuExtra;
use Juwri\Modules\Ordering\Models\OrderItemExtra;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * A per-item modifier price_override is what the diner is quoted, but the shared order
 * pipeline stored only extra_id and charged the raw library MenuExtra price. Snapshot the
 * effective price at order time and charge it. resolveLines also resolves extras against the
 * item's OWN offered set, so a modifier the item doesn't offer (incl. another store's global
 * modifier) can no longer be attached.
 */
class ExtraPriceOverrideTest extends TestCase
{
    use RefreshDatabase;

    private function store(): Store
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 'x',
            'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1,
            'settings' => ['restaurant_limit' => 999], 'status' => 1,
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);

        return Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 'x', 'description' => 'x', 'main_image' => 'd.png',
            'cover_image' => 'd.png', 'address' => '1', 'phone' => '1', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function item(Store $store, float $price = 100): Menu
    {
        return Menu::create([
            'store_id' => $store->id, 'name' => 'Burger', 'price' => $price,
            'active' => 1, 'is_sold_out' => 0,
        ]);
    }

    private function globalExtra(Store $store, float $base = 5): MenuExtra
    {
        // Global/library modifier: menu_id null, restaurant-scoped.
        return MenuExtra::create([
            'store_id' => $store->id, 'menu_id' => null, 'title' => 'Cheese',
            'price' => $base, 'active' => 1,
        ]);
    }

    /** Attach an extra to an item via the library pivot with a per-item override. */
    private function attach(Menu $menu, MenuExtra $extra, float $override): void
    {
        // P1-05: through the typed pivot (price_override → price_override_minor in the store currency).
        $menu->attachedExtras()->attach($extra->id, ['price_override' => $override, 'active' => 1, 'position' => 1]);
    }

    public function test_compute_money_charges_the_snapshot_price_with_legacy_fallback(): void
    {
        $store = $this->store();
        $menu = $this->item($store, 100);
        $extra = $this->globalExtra($store, 5);

        $order = Order::create([
            'store_id' => $store->id, 'type' => 'takeaway', 'status' => 'pending',
            'track_token' => Str::random(16), 'created_at' => now(),
        ]);
        $oi = OrderItem::create(['order_id' => $order->id, 'item_id' => $menu->id, 'quantity' => 1, 'status' => 'queued']);
        $oie = OrderItemExtra::create(['order_item_id' => $oi->id, 'extra_id' => $extra->id, 'price' => 8]);

        // Snapshot (8) is charged, not the library base (5): 100 + 8 = 108.
        $this->assertSame(108.0, $order->fresh()->computeMoney()['subtotal']);

        // A legacy row (no snapshot) falls back to the live base price: 100 + 5 = 105.
        DB::table('order_item_extras')->where('id', $oie->id)->update(['price_minor' => null]); // P1-05
        $this->assertSame(105.0, $order->fresh()->computeMoney()['subtotal']);
    }

    public function test_resolve_lines_applies_override_and_snapshots_it(): void
    {
        $store = $this->store();
        $menu = $this->item($store, 100);
        $extra = $this->globalExtra($store, 5);
        $this->attach($menu, $extra, 8); // diner is quoted 8, not the base 5

        $resolved = $this->resolveLines($store->id, [
            ['item_id' => $menu->id, 'quantity' => 1, 'extras' => [$extra->id]],
        ]);

        $this->assertSame($extra->id, $resolved[0]['extras'][0]['id']);
        $this->assertSame(8.0, $resolved[0]['extras'][0]['price'], 'the override, not the base, is snapshotted');
    }

    public function test_resolve_lines_rejects_an_extra_the_item_does_not_offer(): void
    {
        $store = $this->store();
        $menu = $this->item($store, 100);
        // A global modifier that exists for the store but is NOT attached to this item.
        $unoffered = $this->globalExtra($store, 5);

        $this->expectException(ValidationException::class);
        $this->resolveLines($store->id, [
            ['item_id' => $menu->id, 'quantity' => 1, 'extras' => [$unoffered->id]],
        ]);
    }

    public function test_resolve_lines_rejects_another_stores_global_extra(): void
    {
        $storeA = $this->store();
        $storeB = $this->store();
        $menuA = $this->item($storeA, 100);
        $foreign = $this->globalExtra($storeB, 5); // belongs to store B

        $this->expectException(ValidationException::class);
        $this->resolveLines($storeA->id, [
            ['item_id' => $menuA->id, 'quantity' => 1, 'extras' => [$foreign->id]],
        ]);
    }

    /** Invoke the protected static resolver as a guest channel (availability enforced). */
    private function resolveLines(int $storeId, array $lines): array
    {
        $m = new \ReflectionMethod(OrderApiService::class, 'resolveLines');
        $m->setAccessible(true);

        return $m->invoke(null, $storeId, $lines, 'qr', 'takeaway');
    }
}
