<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Ordering;

use App\Models\MenuCategory;
use App\Models\Order;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Messaging\Jobs\SendPushNotificationJob;
use Tests\Support\TestPlans;
use Tests\TestCase;

class CurbsideOrderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        $this->withoutMiddleware();
    }

    private function storeFixture(bool $curbside = true): array
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 'T', 'monthly_price' => 1, 'annual_price' => 1,
            'lifetime_price' => 1, 'status' => 1, 'settings' => json_encode(['restaurant_limit' => 999, 'menu_limit' => 999, 'allow_ordering' => '1']),
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $r = Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'd.png',
            'cover_image' => 'd.png', 'address' => '1', 'phone' => '1', 'created_at' => now(), 'updated_at' => now(),
        ]);
        StoreOption::updateStoreOption($r->id, 'curbside_enabled', $curbside ? '1' : '0');
        StoreOption::updateStoreOption($r->id, 'store_submit_order_notification', 0);
        StoreOption::updateStoreOption($r->id, 'quickorder_enable', 0);
        $cat = MenuCategory::create(['store_id' => $r->id, 'name' => 'C', 'parent' => null, 'position' => 1]);
        $menu = Menu::create(['store_id' => $r->id, 'category_id' => $cat->id, 'name' => 'Item', 'description' => 'd', 'price' => 10, 'image' => 'd.png', 'type' => 'veg', 'active' => '1', 'position' => 1]);

        return [$r, $menu];
    }

    private function payload(Menu $m, array $extra = []): array
    {
        return array_merge([
            'items' => json_encode([['id' => $m->id, 'quantity' => 1, 'variants' => 0, 'extras' => [], 'mods' => []]]),
            'tip_amount' => '0', 'name' => 'Jane', 'ordering-type' => 'takeaway',
            'phone-number' => '555-1', 'pay_via' => 'cod',
        ], $extra);
    }

    public function test_curbside_order_stores_vehicle_details(): void
    {
        [$r, $m] = $this->storeFixture(true);
        $this->post(route('store.submitOrder', $r), $this->payload($m, [
            'curbside' => '1', 'vehicle_color' => 'Red', 'vehicle_make' => 'Toyota', 'vehicle_plate' => 'ABC123',
        ]))->assertOk()->assertJson(['success' => true]);

        $order = Order::where('store_id', $r->id)->latest('id')->first();
        $this->assertTrue((bool) $order->is_curbside);
        $this->assertStringContainsString('Toyota', $order->vehicle_details);
        $this->assertStringContainsString('ABC123', $order->vehicle_details);
    }

    public function test_curbside_ignored_when_disabled(): void
    {
        [$r, $m] = $this->storeFixture(false);
        $this->post(route('store.submitOrder', $r), $this->payload($m, ['curbside' => '1', 'vehicle_make' => 'Toyota']))
            ->assertOk()->assertJson(['success' => true]);
        $order = Order::where('store_id', $r->id)->latest('id')->first();
        $this->assertFalse((bool) $order->is_curbside);
    }

    public function test_arrived_pings_staff_and_is_idempotent(): void
    {
        Queue::fake();
        [$r, $m] = $this->storeFixture(true);
        $this->post(route('store.submitOrder', $r), $this->payload($m, ['curbside' => '1', 'vehicle_make' => 'Toyota']))->assertOk();
        $order = Order::where('store_id', $r->id)->latest('id')->first();

        $this->postJson(route('store.curbsideArrived', $order->track_token))->assertOk()->assertJson(['success' => true]);
        $this->assertNotNull($order->fresh()->curbside_arrived_at);
        $arrived = fn ($job) => str_contains((string) ($job->title ?? ''), 'Customer arrived');
        Queue::assertPushed(SendPushNotificationJob::class, $arrived);

        // Second tap → no duplicate arrival push (still exactly one).
        $this->postJson(route('store.curbsideArrived', $order->track_token))->assertOk();
        $count = collect(Queue::pushed(SendPushNotificationJob::class))->filter($arrived)->count();
        $this->assertSame(1, $count);
    }

    public function test_arrived_rejects_non_curbside_order(): void
    {
        [$r, $m] = $this->storeFixture(true);
        $this->post(route('store.submitOrder', $r), $this->payload($m))->assertOk(); // no curbside flag
        $order = Order::where('store_id', $r->id)->latest('id')->first();

        $this->postJson(route('store.curbsideArrived', $order->track_token))
            ->assertOk()->assertJson(['success' => false]);
    }
}
