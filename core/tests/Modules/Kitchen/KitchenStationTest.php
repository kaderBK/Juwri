<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Kitchen;

use App\Models\KitchenStation;
use Juwri\Core\Models\Language;
use Juwri\Modules\Menu\Models\Menu;
use App\Models\MenuCategory;
use App\Models\Order;
use App\Models\OrderItem;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\TestPlans;
use Tests\TestCase;

class KitchenStationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        Language::firstOrCreate(['code' => 'en'], ['name' => 'English', 'direction' => 'ltr', 'active' => 1]);
        $this->withoutMiddleware();
    }

    private function makeUser(): User
    {
        $plan = TestPlans::create([
            'name' => 'P ' . Str::random(6), 'description' => 'T',
            'monthly_price' => 10, 'annual_price' => 100, 'lifetime_price' => 1000,
            'settings' => json_encode(['restaurant_limit' => 999, 'menu_limit' => 999]), 'status' => 1,
        ]);
        return User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
    }

    private function makeRestaurant(User $owner): Store
    {
        $title = 'R ' . Str::random(6);
        return Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug($title) . '-' . Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'default.png', 'cover_image' => 'default.png',
            'address' => '1 St', 'phone' => '123', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function makeMenuItem(Store $r, array $a = []): Menu
    {
        $cat = MenuCategory::create(['store_id' => $r->id, 'name' => 'Cat ' . Str::random(4)]);
        return Menu::create(array_merge([
            'store_id' => $r->id, 'category_id' => $cat->id, 'name' => 'Item ' . Str::random(4),
            'price' => 5, 'active' => '1',
        ], $a));
    }

    public function test_station_crud_and_isolation(): void
    {
        $owner = $this->makeUser();
        $r = $this->makeRestaurant($owner);

        $this->actingAs($owner)->post(route('stations.store'), [
            'store_id' => $r->id, 'name' => 'Grill', 'display_color' => '#ff0000',
        ])->assertRedirect();
        $station = KitchenStation::where('store_id', $r->id)->first();
        $this->assertEquals('Grill', $station->name);

        $intruder = $this->makeUser();
        $this->actingAs($intruder)->put(route('stations.update', $station->id), ['name' => 'x'])->assertNotFound();
    }

    public function test_assign_item_to_station(): void
    {
        $owner = $this->makeUser();
        $r = $this->makeRestaurant($owner);
        $station = KitchenStation::create(['store_id' => $r->id, 'name' => 'Bar', 'active' => true]);
        $item = $this->makeMenuItem($r);

        $this->actingAs($owner)->post(route('stations.assign'), [
            'menu_id' => $item->id, 'station_id' => $station->id,
        ])->assertRedirect();
        $this->assertEquals($station->id, $item->fresh()->station_id);

        // Unassign
        $this->actingAs($owner)->post(route('stations.assign'), [
            'menu_id' => $item->id, 'station_id' => '',
        ])->assertRedirect();
        $this->assertNull($item->fresh()->station_id);
    }

    public function test_cannot_assign_foreign_station(): void
    {
        $owner = $this->makeUser();
        $r1 = $this->makeRestaurant($owner);
        $r2 = $this->makeRestaurant($owner);
        $item = $this->makeMenuItem($r1);
        $foreignStation = KitchenStation::create(['store_id' => $r2->id, 'name' => 'X', 'active' => true]);

        $this->actingAs($owner)->post(route('stations.assign'), [
            'menu_id' => $item->id, 'station_id' => $foreignStation->id,
        ])->assertNotFound();
        $this->assertNull($item->fresh()->station_id);
    }

    public function test_deleting_station_unassigns_items(): void
    {
        $owner = $this->makeUser();
        $r = $this->makeRestaurant($owner);
        $station = KitchenStation::create(['store_id' => $r->id, 'name' => 'Cold', 'active' => true]);
        $item = $this->makeMenuItem($r, ['station_id' => $station->id]);

        $this->actingAs($owner)->delete(route('stations.destroy', $station->id))->assertRedirect();
        $this->assertNull($item->fresh()->station_id);
        $this->assertDatabaseMissing('kitchen_stations', ['id' => $station->id]);
    }

    /** KDS poll station filter returns only that station's items */
    public function test_kds_poll_filters_by_station(): void
    {
        $owner = $this->makeUser();
        $r = $this->makeRestaurant($owner);
        $grill = KitchenStation::create(['store_id' => $r->id, 'name' => 'Grill', 'active' => true]);
        $bar   = KitchenStation::create(['store_id' => $r->id, 'name' => 'Bar', 'active' => true]);

        $burger = $this->makeMenuItem($r, ['station_id' => $grill->id]);
        $cola   = $this->makeMenuItem($r, ['station_id' => $bar->id]);

        $order = Order::create(['store_id' => $r->id, 'type' => 'pickup', 'customer_name' => 'A', 'status' => 'new', 'created_at' => now()]);
        OrderItem::create(['order_id' => $order->id, 'item_id' => $burger->id, 'station_id' => $grill->id, 'quantity' => 1, 'variation' => 0]);
        OrderItem::create(['order_id' => $order->id, 'item_id' => $cola->id, 'station_id' => $bar->id, 'quantity' => 2, 'variation' => 0]);

        $resp = $this->actingAs($owner)->getJson(route('kds.poll', ['store_id' => $r->id, 'station_id' => $grill->id]));
        $resp->assertOk();
        $data = $resp->json();
        $this->assertCount(1, $data);                       // one order
        $this->assertCount(1, $data[0]['items']);           // only the grill item
        $this->assertEquals('Grill', $data[0]['items'][0]['station']);
    }
}
