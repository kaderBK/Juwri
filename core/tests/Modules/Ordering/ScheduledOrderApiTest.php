<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Ordering;

use App\Models\MenuCategory;
use App\Models\Order;
use App\Models\StoreOption;
use App\Services\Agents\AgentToolsService;
use App\Services\OrderApiService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Ordering\Jobs\SendStaffOrderPushJob;
use Tests\Support\TestPlans;
use Tests\TestCase;

class ScheduledOrderApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        Queue::fake();
    }

    private function make(): array
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 't', 'monthly_price' => 10, 'annual_price' => 100,
            'lifetime_price' => 1000, 'status' => 1,
            'settings' => ['restaurant_limit' => 999, 'menu_limit' => 999, 'category_limit' => 999, 'allow_ordering' => '1', 'ai_chatbot' => '1'],
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $r = Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 't', 'description' => 't', 'main_image' => 'default.png',
            'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '123', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $cat = MenuCategory::create(['store_id' => $r->id, 'name' => 'C', 'parent' => null, 'position' => 1]);
        $menu = Menu::create([
            'store_id' => $r->id, 'category_id' => $cat->id, 'name' => 'Item', 'description' => 'd',
            'price' => 10, 'image' => 'default.png', 'type' => 'veg', 'active' => '1', 'position' => 1,
        ]);
        foreach ([
            'allow_order_scheduling' => '1', 'scheduling_slot_interval' => 30,
            'scheduling_lead_minutes' => 30, 'scheduling_prep_minutes' => 20, 'timezone' => 'UTC',
        ] as $k => $v) {
            StoreOption::updateStoreOption($r->id, $k, $v);
        }

        return compact('owner', 'r', 'menu');
    }

    public function test_order_api_service_sets_scheduled_and_fire_at(): void
    {
        ['owner' => $owner, 'r' => $r, 'menu' => $menu] = $this->make();
        Carbon::setTestNow(Carbon::parse('2026-08-01 12:00', 'UTC'));

        $order = OrderApiService::create($owner->id, $r->id, [
            'type' => 'takeaway', 'source' => 'api', 'scheduled_at' => '2026-08-01 13:00',
            'items' => [['item_id' => $menu->id, 'quantity' => 1]],
        ]);
        Carbon::setTestNow();

        $this->assertSame('2026-08-01 13:00', $order->scheduled_at->format('Y-m-d H:i'));
        $this->assertSame('2026-08-01 12:40', $order->fire_at->format('Y-m-d H:i'));
        // Pending scheduled order must not push a staff alert at creation.
        Queue::assertNotPushed(SendStaffOrderPushJob::class);
    }

    public function test_order_api_service_rejects_bad_scheduled_time(): void
    {
        ['owner' => $owner, 'r' => $r, 'menu' => $menu] = $this->make();
        Carbon::setTestNow(Carbon::parse('2026-08-01 12:00', 'UTC'));

        $this->expectException(\InvalidArgumentException::class);
        try {
            OrderApiService::create($owner->id, $r->id, [
                'type' => 'takeaway', 'source' => 'api', 'scheduled_at' => '2026-08-01 12:10', // inside lead
                'items' => [['item_id' => $menu->id, 'quantity' => 1]],
            ]);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_agent_create_order_supports_scheduling(): void
    {
        ['r' => $r, 'menu' => $menu] = $this->make();
        Carbon::setTestNow(Carbon::parse('2026-08-01 12:00', 'UTC'));

        $ok = AgentToolsService::createOrder($r, [['item_id' => $menu->id, 'quantity' => 1]], [
            'type' => 'takeaway', 'scheduled_at' => '2026-08-01 13:00',
        ]);
        $bad = AgentToolsService::createOrder($r, [['item_id' => $menu->id, 'quantity' => 1]], [
            'type' => 'takeaway', 'scheduled_at' => '2026-08-01 12:05',
        ]);
        Carbon::setTestNow();

        $this->assertTrue($ok['ok']);
        $this->assertSame('2026-08-01 13:00', $ok['scheduled_at']);
        $this->assertFalse($bad['ok']);
    }

    public function test_platform_kill_switch_disables_scheduling(): void
    {
        ['owner' => $owner, 'r' => $r, 'menu' => $menu] = $this->make();
        config(['settings.admin_allow_scheduled_orders' => false]);
        Carbon::setTestNow(Carbon::parse('2026-08-01 12:00', 'UTC'));

        $this->expectException(\InvalidArgumentException::class);
        try {
            OrderApiService::create($owner->id, $r->id, [
                'type' => 'takeaway', 'source' => 'api', 'scheduled_at' => '2026-08-01 13:00',
                'items' => [['item_id' => $menu->id, 'quantity' => 1]],
            ]);
        } finally {
            Carbon::setTestNow();
        }
    }
}
