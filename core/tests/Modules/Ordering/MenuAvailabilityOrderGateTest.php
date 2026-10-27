<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Ordering;

use App\Models\MenuCategory;
use App\Models\Order;
use App\Services\OrderApiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Menu\Models\MenuVariant;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * A customer must not be able to ORDER an item the storefront hides — resolveLines()
 * only checked is_sold_out + schedule, never `active` (publish state). Staff/owner/
 * aggregator channels keep the deliberate override; guest channels (incl. kiosk) do not.
 */
class MenuAvailabilityOrderGateTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: Store, 1: Menu} */
    private function storeWithItem(array $menuAttrs = []): array
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 'T', 'monthly_price' => 1, 'annual_price' => 1,
            'lifetime_price' => 1, 'status' => 1, 'settings' => json_encode(['restaurant_limit' => 999, 'allow_ordering' => '1']),
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'd.png',
            'cover_image' => 'd.png', 'address' => '1', 'phone' => '1', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Mains', 'active' => 1]);
        $menu = Menu::create(array_merge([
            'store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Dish', 'price' => 10, 'active' => '1',
        ], $menuAttrs));

        return [$store, $menu];
    }

    private function order(Store $store, array $data): Order
    {
        return OrderApiService::create((int) $store->user_id, (int) $store->id, array_merge(['type' => 'takeaway'], $data));
    }

    public function test_guest_cannot_order_an_unpublished_item(): void
    {
        [$store, $menu] = $this->storeWithItem(['active' => '0']);
        $this->expectException(ValidationException::class);
        $this->order($store, ['source' => 'qr', 'items' => [['item_id' => $menu->id, 'quantity' => 1]]]);
    }

    public function test_kiosk_mode_cannot_order_an_unpublished_item(): void
    {
        // Pre-fix 'kiosk' was outside GUEST_SOURCES → a remote client faking kiosk_mode skipped the gate.
        [$store, $menu] = $this->storeWithItem(['active' => '0']);
        $this->expectException(ValidationException::class);
        $this->order($store, ['source' => 'kiosk', 'items' => [['item_id' => $menu->id, 'quantity' => 1]]]);
    }

    public function test_guest_cannot_order_an_inactive_variation(): void
    {
        [$store, $menu] = $this->storeWithItem();
        $variant = MenuVariant::create(['menu_id' => $menu->id, 'price' => 20, 'active' => false]);
        $this->expectException(ValidationException::class);
        $this->order($store, ['source' => 'qr', 'items' => [['item_id' => $menu->id, 'quantity' => 1, 'variation' => $variant->id]]]);
    }

    public function test_staff_pos_can_still_ring_an_unpublished_item(): void
    {
        // The deliberate override: a cashier can sell an unpublished item (the register badges it).
        [$store, $menu] = $this->storeWithItem(['active' => '0']);
        $order = $this->order($store, ['source' => 'pos', 'items' => [['item_id' => $menu->id, 'quantity' => 1]]]);
        $this->assertNotNull($order->id);
    }
}
