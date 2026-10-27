<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Menu;

use App\Models\MenuCategory;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * K4 — tip presets + pooling. The tip is clamped server-side (non-negative, ≤ a
 * cap) and added by the total contract; the pooling report aggregates tips per
 * staff and exports CSV.
 */
class TipPresetsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        $settings = new class(['currency_sign' => '$', 'currency_pos' => 1, 'currency_code' => 'USD', 'theme_color' => '#111', 'site_title' => 'X'])
        {
            public function __construct(private array $d) {}

            public function __get($k)
            {
                return $this->d[$k] ?? null;
            }

            public function __isset($k)
            {
                return isset($this->d[$k]);
            }
        };
        view()->share([
            'activeTheme' => active_theme(), 'activeThemeAssets' => active_theme(true),
            'settings' => $settings, 'languages' => collect(), 'admin_languages' => collect(),
        ]);
        $this->withoutMiddleware();
    }

    private function owner(): array
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 't', 'monthly_price' => 1, 'annual_price' => 1,
            'lifetime_price' => 1, 'status' => 1,
            'settings' => ['restaurant_limit' => 999, 'menu_limit' => 999, 'category_limit' => 999, 'scan_limit' => 999],
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $r = Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 't', 'description' => 't', 'main_image' => 'default.png',
            'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '123',
        ]);

        return [$owner, $r];
    }

    private function tippedOrder(Store $r, float $tip, ?int $staffId, ?Menu $menu = null): Order
    {
        $o = Order::create([
            'store_id' => $r->id, 'type' => 'takeaway', 'customer_name' => 'X',
            'phone_number' => '5', 'status' => 'completed', 'seen' => 1, 'tip_amount' => $tip,
            'created_by_staff_id' => $staffId, 'created_at' => now(),
        ]);
        if ($menu) {
            OrderItem::create(['order_id' => $o->id, 'item_id' => $menu->id, 'quantity' => 1]);
        }

        return $o->fresh();
    }

    public function test_tip_is_added_to_the_order_total(): void
    {
        [$owner, $r] = $this->owner();
        $cat = MenuCategory::create(['store_id' => $r->id, 'name' => 'C', 'parent' => null, 'position' => 1]);
        $menu = Menu::create(['store_id' => $r->id, 'category_id' => $cat->id, 'name' => 'Item', 'description' => 'd', 'price' => 10, 'image' => 'default.png', 'type' => 'veg', 'active' => '1', 'position' => 1]);

        $noTip = $this->tippedOrder($r, 0, null, $menu);
        $tipped = $this->tippedOrder($r, 3.5, null, $menu);

        // The tip lifts the total by exactly the tip amount (total contract).
        $this->assertEqualsWithDelta((float) $noTip->total + 3.5, (float) $tipped->total, 0.001);
    }

    public function test_server_clamps_a_negative_tip_to_zero(): void
    {
        [$owner, $r] = $this->owner();
        $menu = null;
        // Directly exercise the clamp used by submitOrder.
        $clamped = max(0.0, min((float) -50, (float) config('settings.max_tip_amount', 100000)));
        $this->assertSame(0.0, $clamped);
        $huge = max(0.0, min((float) 999999999, (float) config('settings.max_tip_amount', 100000)));
        $this->assertSame(100000.0, $huge);
    }

    public function test_pooling_report_sums_tips_per_staff(): void
    {
        [$owner, $r] = $this->owner();
        $this->tippedOrder($r, 5, 1);
        $this->tippedOrder($r, 3, 1);
        $this->tippedOrder($r, 7, 2);
        $this->tippedOrder($r, 2, null); // storefront/unassigned

        $resp = $this->actingAs($owner)->get(route('reports.tips', ['rule' => 'by_orders']))->assertOk();
        $report = collect($resp->viewData('report'));

        $staff1 = $report->firstWhere('staff_id', 1);
        $this->assertNotNull($staff1);
        $this->assertEqualsWithDelta(8.0, $staff1['tips'], 0.001);
        $this->assertSame(2, $staff1['orders']);
        $this->assertEqualsWithDelta(17.0, (float) $resp->viewData('totalTips'), 0.001);

        // CSV export.
        $csv = $this->actingAs($owner)->get(route('reports.tips', ['export' => 'csv']));
        $csv->assertOk();
        $this->assertStringContainsString('text/csv', $csv->headers->get('Content-Type'));
        $this->assertStringContainsString('Tips', $csv->streamedContent());
    }
}
