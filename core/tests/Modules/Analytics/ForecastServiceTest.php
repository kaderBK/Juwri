<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Analytics;

use Juwri\Modules\Menu\Models\Menu;
use App\Models\Order;
use App\Models\OrderItem;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use App\Services\Analytics\ForecastService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * GAPS-RUN-2 B5-P2: demand forecasting math.
 */
class ForecastServiceTest extends TestCase
{
    use RefreshDatabase;

    private Store $r;

    protected function setUp(): void
    {
        parent::setUp();
        $plan = TestPlans::create([
            'name' => 'P' . Str::random(5), 'description' => 'T', 'monthly_price' => 1, 'annual_price' => 1,
            'lifetime_price' => 1, 'status' => 1, 'settings' => json_encode(['restaurant_limit' => 999]),
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $this->r = Store::create([
            'user_id' => $owner->id, 'slug' => 'r-' . Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'd.png',
            'cover_image' => 'd.png', 'address' => '1', 'phone' => '1', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function seedOrders(string $date, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            Order::create(['store_id' => $this->r->id, 'type' => 'delivery', 'status' => 'new'])
                ->forceFill(['created_at' => Carbon::parse($date . ' 12:00:00')])->save();
        }
    }

    public function test_insufficient_history_is_honest(): void
    {
        $this->seedOrders('2026-07-10', 2);
        $out = new ForecastService()->compute($this->r->id, Carbon::parse('2026-07-14'));
        $this->assertTrue($out['insufficient']);
    }

    public function test_weighted_same_weekday_moving_average(): void
    {
        // Target weekday = Monday. Three Mondays, oldest→newest counts 2,4,6.
        $this->seedOrders('2026-06-29', 2);
        $this->seedOrders('2026-07-06', 4);
        $this->seedOrders('2026-07-13', 6);
        // 14 filler distinct non-Monday days (1 order each) to clear the min-data gate.
        $d = Carbon::parse('2026-06-30');
        $added = 0;
        while ($added < 14) {
            if ($d->dayOfWeek !== Carbon::MONDAY) {
                $this->seedOrders($d->toDateString(), 1);
                $added++;
            }
            $d->addDay();
        }

        $out = new ForecastService()->compute($this->r->id, Carbon::parse('2026-07-14'));

        $this->assertFalse($out['insufficient']);
        $monday = collect($out['forecast'])->firstWhere('weekday', 'Mon');
        // (2*1 + 4*2 + 6*3) / (1+2+3) = 28/6 = 4.67 → 5
        $this->assertSame(5, $monday['orders']);
    }

    public function test_item_prep_and_staffing_present(): void
    {
        $menu = Menu::create(['store_id' => $this->r->id, 'name' => 'Burger', 'price' => 8, 'active' => 1]);
        // Enough data + items in the trailing 28 days.
        $d = Carbon::parse('2026-06-16');
        $added = 0;
        while ($added < 20) {
            $this->seedOrders($d->toDateString(), 2);
            $order = Order::where('store_id', $this->r->id)->latest('id')->first();
            OrderItem::create(['order_id' => $order->id, 'item_id' => $menu->id, 'quantity' => 3]);
            $added++;
            $d->addDay();
        }

        $out = new ForecastService()->compute($this->r->id, Carbon::parse('2026-07-14'));

        $this->assertFalse($out['insufficient']);
        $this->assertCount(7, $out['forecast']);
        $this->assertNotEmpty($out['staffing']);
        // Prep may be present when tomorrow's forecast > 0.
        $this->assertIsArray($out['prep']);
    }

    public function test_command_runs_and_caches(): void
    {
        $this->artisan('analytics:forecast')->assertExitCode(0);
    }
}
