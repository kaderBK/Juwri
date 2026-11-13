<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Kitchen;

use App\Models\BranchMenuStation;
use App\Models\KitchenStation;
use App\Models\MenuCategory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\StoreOption;
use App\Services\KdsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Language;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Tests\Support\TestPlans;
use Tests\TestCase;

class KdsAdvancedTest extends TestCase
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
            'name' => 'P '.Str::random(6), 'description' => 'T',
            'monthly_price' => 10, 'annual_price' => 100, 'lifetime_price' => 1000,
            'settings' => json_encode(['restaurant_limit' => 999, 'menu_limit' => 999]), 'status' => 1,
        ]);

        return User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
    }

    private function makeRestaurant(User $owner): Store
    {
        $title = 'R '.Str::random(6);

        return Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'default.png', 'cover_image' => 'default.png',
            'address' => '1 St', 'phone' => '123', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function makeItem(Store $r, array $a = []): Menu
    {
        $cat = MenuCategory::create(['store_id' => $r->id, 'name' => 'C']);

        return Menu::create(array_merge(['store_id' => $r->id, 'category_id' => $cat->id, 'name' => 'Item '.Str::random(4), 'price' => 5, 'active' => '1'], $a));
    }

    private function makeOrder(Store $r): Order
    {
        return Order::create(['store_id' => $r->id, 'type' => 'pickup', 'customer_name' => 'A', 'status' => 'new', 'created_at' => now()]);
    }

    /** setMode persists + helper reads it */
    public function test_set_mode(): void
    {
        $owner = $this->makeUser();
        $r = $this->makeRestaurant($owner);
        $this->assertEquals('basic', kds_mode($r->id));

        $this->actingAs($owner)->post(route('kds.setMode'), ['store_id' => $r->id, 'mode' => 'advanced'])->assertRedirect();
        $this->assertEquals('advanced', kds_mode($r->id));
    }

    /** Item-level bump advances the item and re-syncs order status from all items */
    public function test_item_bump_syncs_order_status(): void
    {
        $owner = $this->makeUser();
        $r = $this->makeRestaurant($owner);
        $m1 = $this->makeItem($r);
        $m2 = $this->makeItem($r);
        $order = $this->makeOrder($r);
        $i1 = OrderItem::create(['order_id' => $order->id, 'item_id' => $m1->id, 'quantity' => 1, 'variation' => 0, 'status' => 'queued']);
        $i2 = OrderItem::create(['order_id' => $order->id, 'item_id' => $m2->id, 'quantity' => 1, 'variation' => 0, 'status' => 'queued']);

        // Bump i1 to preparing → order becomes preparing
        $this->actingAs($owner)->post(route('kds.item.bump', $i1->id))->assertOk();
        $this->assertEquals('preparing', $i1->fresh()->status);
        $this->assertEquals('preparing', $order->fresh()->status);

        // Bump i1 → ready; order still preparing (i2 queued)
        $this->actingAs($owner)->post(route('kds.item.bump', $i1->id))->assertOk();
        $this->assertEquals('ready', $i1->fresh()->status);
        $this->assertEquals('preparing', $order->fresh()->status);

        // Bring i2 to ready → ALL ready → order ready (expo)
        KdsService::bumpItem($i2->fresh()); // queued→preparing
        KdsService::bumpItem($i2->fresh()); // preparing→ready
        $this->assertEquals('ready', $order->fresh()->status);

        // Serve both → delivered
        KdsService::bumpItem($i1->fresh()); // ready→served
        KdsService::bumpItem($i2->fresh()); // ready→served
        $this->assertEquals('delivered', $order->fresh()->status);
    }

    /** Recall pulls an item (and the order) back a step */
    public function test_item_recall(): void
    {
        $owner = $this->makeUser();
        $r = $this->makeRestaurant($owner);
        $m = $this->makeItem($r);
        $order = $this->makeOrder($r);
        $i = OrderItem::create(['order_id' => $order->id, 'item_id' => $m->id, 'quantity' => 1, 'variation' => 0, 'status' => 'queued']);

        KdsService::bumpItem($i->fresh()); // → preparing
        KdsService::bumpItem($i->fresh()); // → ready ; single item all-ready → order ready
        $this->assertEquals('ready', $order->fresh()->status);

        $this->actingAs($owner)->post(route('kds.item.recall', $i->id))->assertOk();
        $this->assertEquals('preparing', $i->fresh()->status);
        $this->assertNull($i->fresh()->item_ready_at);
        $this->assertEquals('preparing', $order->fresh()->status);
    }

    /** Station routing: per-branch pivot overrides the menu default */
    public function test_station_routing_resolution(): void
    {
        $owner = $this->makeUser();
        $r = $this->makeRestaurant($owner);
        $branch = Branch::where('store_id', $r->id)->first();
        $grill = KitchenStation::create(['store_id' => $r->id, 'name' => 'Grill', 'active' => true]);
        $bar = KitchenStation::create(['store_id' => $r->id, 'name' => 'Bar', 'active' => true]);
        $m = $this->makeItem($r, ['station_id' => $grill->id]);

        // No pivot → menu default (grill)
        $this->assertEquals($grill->id, KdsService::resolveStationForOrderItem($branch->id, $m));

        // Pivot override → bar
        BranchMenuStation::create(['branch_id' => $branch->id, 'menu_id' => $m->id, 'station_id' => $bar->id]);
        $this->assertEquals($bar->id, KdsService::resolveStationForOrderItem($branch->id, $m->fresh()));

        // No branch → menu default
        $this->assertEquals($grill->id, KdsService::resolveStationForOrderItem(null, $m->fresh()));
    }

    /** poll returns item status + station fields, tenant-scoped */
    public function test_poll_payload_and_isolation(): void
    {
        $owner = $this->makeUser();
        $r = $this->makeRestaurant($owner);
        StoreOption::updateStoreOption($r->id, 'kds_mode', 'advanced');
        $station = KitchenStation::create(['store_id' => $r->id, 'name' => 'Grill', 'active' => true, 'target_minutes' => 8]);
        $m = $this->makeItem($r, ['station_id' => $station->id]);
        $order = $this->makeOrder($r);
        OrderItem::create(['order_id' => $order->id, 'item_id' => $m->id, 'quantity' => 2, 'variation' => 0, 'status' => 'queued', 'station_id' => $station->id]);

        $resp = $this->actingAs($owner)->getJson(route('kds.poll', ['store_id' => $r->id]));
        $resp->assertOk();
        $data = $resp->json();
        $this->assertCount(1, $data);
        $this->assertEquals('queued', $data[0]['items'][0]['status']);
        $this->assertEquals('Grill', $data[0]['items'][0]['station']);
        $this->assertEquals(8, $data[0]['items'][0]['target']);

        // Intruder cannot bump the item
        $intruder = $this->makeUser();
        $itemId = $order->items->first()->id;
        $this->actingAs($intruder)->post(route('kds.item.bump', $itemId))->assertNotFound();
    }
}
