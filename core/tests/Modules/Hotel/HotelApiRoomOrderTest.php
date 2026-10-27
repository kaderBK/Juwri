<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Hotel;

use App\Models\MenuCategory;
use App\Models\StoreTable;
use App\Services\OrderApiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * C8 — OrderApiService admits type 'room_service' but modelled no room: create() nulled
 * table_number and never set service_location, so every non-storefront caller (agent,
 * customer app, POS, REST) produced a location-less ticket the kitchen could not deliver.
 * create() now resolves the room like the storefront and rejects an unresolvable one.
 * Charge-to-room stays out of the API v1.
 */
class HotelApiRoomOrderTest extends TestCase
{
    use RefreshDatabase;

    private function storeFixture(): Store
    {
        $plan = TestPlans::create([
            'name' => 'P '.Str::random(5), 'description' => 'T',
            'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1,
            'settings' => json_encode(['restaurant_limit' => 999, 'menu_limit' => 999, 'allow_ordering' => '1']),
            'status' => 1,
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $title = 'R '.Str::random(5);

        return Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1 St', 'phone' => '1',
        ]);
    }

    private function item(Store $store): Menu
    {
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Mains', 'active' => '1']);

        return Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Dish', 'price' => 12, 'active' => 1]);
    }

    private function room(Store $store, ?int $branchId = null): StoreTable
    {
        return StoreTable::create([
            'store_id' => $store->id, 'table_no' => 305, 'key' => Str::random(10),
            'seats' => 2, 'area_type' => 'room', 'label' => 'Suite 305', 'branch_id' => $branchId,
        ]);
    }

    public function test_room_service_order_resolves_the_room_by_key(): void
    {
        $store = $this->storeFixture();
        $item = $this->item($store);
        $room = $this->room($store);

        $order = OrderApiService::create((int) $store->user_id, $store->id, [
            'type' => 'room_service',
            'room' => $room->key,
            'items' => [['item_id' => $item->id, 'quantity' => 1]],
        ]);

        $this->assertSame('room_service', $order->type);
        $this->assertSame('Suite 305', $order->service_location, 'service_location must carry the room label');
        $this->assertSame('305', (string) $order->table_number, 'table_number must carry the room number for KDS/printing');
    }

    public function test_room_service_order_resolves_the_room_by_numeric_id(): void
    {
        $store = $this->storeFixture();
        $item = $this->item($store);
        $room = $this->room($store);

        $order = OrderApiService::create((int) $store->user_id, $store->id, [
            'type' => 'room_service',
            'room' => (string) $room->id,
            'items' => [['item_id' => $item->id, 'quantity' => 1]],
        ]);

        $this->assertSame('Suite 305', $order->service_location);
    }

    public function test_a_null_branch_room_resolves_even_with_a_branch_scoped_order(): void
    {
        $store = $this->storeFixture();
        $item = $this->item($store);
        $room = $this->room($store, branchId: null); // room created under "All branches"

        $order = OrderApiService::create((int) $store->user_id, $store->id, [
            'type' => 'room_service',
            'room' => $room->key,
            'branch_id' => 999, // a branch scope must not hide a NULL-branch room
            'items' => [['item_id' => $item->id, 'quantity' => 1]],
        ]);

        $this->assertSame('Suite 305', $order->service_location);
    }

    public function test_missing_room_is_rejected(): void
    {
        $store = $this->storeFixture();
        $item = $this->item($store);

        $this->expectException(ValidationException::class);

        OrderApiService::create((int) $store->user_id, $store->id, [
            'type' => 'room_service',
            'items' => [['item_id' => $item->id, 'quantity' => 1]],
        ]);
    }

    public function test_an_unresolvable_room_is_rejected(): void
    {
        $store = $this->storeFixture();
        $item = $this->item($store);
        $this->room($store);

        $this->expectException(ValidationException::class);

        OrderApiService::create((int) $store->user_id, $store->id, [
            'type' => 'room_service',
            'room' => 'no-such-room-key',
            'items' => [['item_id' => $item->id, 'quantity' => 1]],
        ]);
    }

    public function test_a_dine_in_table_is_not_a_valid_room(): void
    {
        $store = $this->storeFixture();
        $item = $this->item($store);
        $table = StoreTable::create([
            'store_id' => $store->id, 'table_no' => 7, 'key' => Str::random(10),
            'seats' => 4, 'area_type' => 'table',
        ]);

        $this->expectException(ValidationException::class);

        OrderApiService::create((int) $store->user_id, $store->id, [
            'type' => 'room_service',
            'room' => $table->key, // an ordinary table, not area_type='room'
            'items' => [['item_id' => $item->id, 'quantity' => 1]],
        ]);
    }
}
