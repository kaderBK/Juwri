<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Kitchen;

use App\Http\Middleware\Installed;
use App\Models\MenuCategory;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Pos\Models\SignageScreen;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * SYSTEMS-RUN K-P1 + O-P1: KDS all-day summary + signage announce/auto-clear config.
 */
class KdsAllDaySignageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([Installed::class]);
    }

    private function storeFixture(): array
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 'T', 'monthly_price' => 1, 'annual_price' => 1,
            'lifetime_price' => 1, 'status' => 1, 'settings' => json_encode(['restaurant_limit' => 999]),
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $r = Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#0af',
            'title' => 'R', 'sub_title' => 'x', 'description' => 'x', 'main_image' => 'logo.png', 'cover_image' => 'd.png', 'address' => '1', 'phone' => '1',
        ]);

        return [$owner, $r];
    }

    public function test_kds_all_day_aggregates_open_items(): void
    {
        [$owner, $r] = $this->storeFixture();
        $cat = MenuCategory::create(['store_id' => $r->id, 'name' => 'C']);
        $burger = Menu::create(['store_id' => $r->id, 'category_id' => $cat->id, 'name' => 'Burger', 'price' => 10, 'active' => 1]);
        $order = Order::create(['store_id' => $r->id, 'type' => 'takeaway', 'status' => 'preparing']);
        OrderItem::create(['order_id' => $order->id, 'item_id' => $burger->id, 'quantity' => 4, 'variation' => 0, 'status' => 'preparing']);
        OrderItem::create(['order_id' => $order->id, 'item_id' => $burger->id, 'quantity' => 2, 'variation' => 0, 'status' => 'queued']);

        $res = $this->actingAs($owner)->getJson(route('kds.allDay').'?store_id='.$r->id)->assertOk();
        $summary = collect($res->json('all_day'));
        $this->assertSame(6, $summary->firstWhere('name', 'Burger')['qty']);
    }

    public function test_signage_config_and_auto_clear(): void
    {
        [$owner, $r] = $this->storeFixture();
        config(['settings.admin_allow_digital_signage' => true]);
        $screen = SignageScreen::create([
            'store_id' => $r->id, 'kind' => 'pickup', 'token' => Str::random(20), 'name' => 'Board', 'active' => true,
            'config' => ['announce' => true, 'announce_lang' => 'ar', 'auto_clear_minutes' => 5, 'promo_panel' => true],
        ]);
        // A stale ready order (older than 5 min) should be auto-cleared.
        Order::create(['store_id' => $r->id, 'type' => 'takeaway', 'status' => 'ready', 'customer_name' => 'Old', 'ready_at' => now()->subMinutes(10)]);
        Order::create(['store_id' => $r->id, 'type' => 'takeaway', 'status' => 'ready', 'customer_name' => 'Fresh', 'ready_at' => now()->subMinute()]);

        $res = $this->getJson(route('signage.state', $screen->token))->assertOk();
        $res->assertJsonPath('config.announce', true)->assertJsonPath('config.announce_lang', 'ar')->assertJsonPath('config.brand_color', '#0af');
        $ready = collect($res->json('ready'));
        $this->assertSame(1, $ready->count()); // only the fresh one
        $this->assertSame('Fresh', $ready->first()['name']);
    }
}
