<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Hr;

use Juwri\Modules\Hr\Http\Controllers\User\PayrollController;
use Juwri\Modules\Menu\Models\Menu;
use App\Models\MenuCategory;
use App\Models\Order;
use App\Models\OrderItem;
use Juwri\Modules\Hr\Models\PayrollRun;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * Payroll labor % — labor cost is prorated to the report window. Summing a whole
 * pay period's net against only the window's sales produced impossible ratios
 * (>100%) and false over-threshold warnings.
 */
class PayrollLaborTest extends TestCase
{
    use RefreshDatabase;

    public function test_labor_pct_prorates_run_net_to_the_window(): void
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 'T', 'monthly_price' => 1, 'annual_price' => 1,
            'lifetime_price' => 1, 'status' => 1, 'settings' => json_encode(['restaurant_limit' => 999]),
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $title = 'R'.Str::random(6);
        $r = Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)), 'color' => '#111',
            'title' => $title, 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'default.png',
            'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '1',
        ]);

        // A 10-day run with $300 net.
        PayrollRun::create([
            'store_id' => $r->id, 'period_start' => '2026-01-01', 'period_end' => '2026-01-10',
            'status' => 'draft', 'total_net' => 300,
        ]);

        // $500 of paid sales inside a 4-day window strictly INSIDE the run's period
        // (avoids the exact-boundary date comparison, which sqlite string-compares).
        $cat = MenuCategory::create(['store_id' => $r->id, 'name' => 'C']);
        $item = Menu::create(['store_id' => $r->id, 'category_id' => $cat->id, 'name' => 'Dish', 'price' => 500, 'active' => 1]);
        $o = Order::create([
            'store_id' => $r->id, 'type' => 'dine_in', 'status' => 'completed', 'is_paid' => 1,
            'customer_name' => 'C', 'phone_number' => '1', 'created_at' => '2026-01-06 12:00:00',
        ]);
        OrderItem::create(['order_id' => $o->id, 'item_id' => $item->id, 'quantity' => 1, 'variation' => 0]);

        $controller = app(PayrollController::class);
        $this->actingAs($owner);
        $request = Request::create('/payroll-runs/labor/dashboard', 'GET', [
            'store_id' => $r->id, 'from' => '2026-01-05', 'to' => '2026-01-08',
        ]);
        $data = $controller->labor($request)->getData();

        // 4 of the run's 10 days overlap → labor = 300 × 4/10 = 120 (not the full 300).
        $this->assertEqualsWithDelta(120.0, (float) $data['labor'], 0.01);
        $this->assertEqualsWithDelta(500.0, (float) $data['sales'], 0.01);
        // 120 / 500 = 24%, below the 30% threshold — no false warning (the un-prorated
        // figure would have been 300/500 = 60% and tripped the warning).
        $this->assertEqualsWithDelta(24.0, (float) $data['laborPct'], 0.01);
        $this->assertFalse($data['warn']);
    }
}
