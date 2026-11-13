<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Pos;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\MenuCategory;
use App\Models\StaffMember;
use App\Models\StoreOption;
use App\Services\PosService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * MC-22 — POS money rounds/renders to the STORE's currency decimals: cash change
 * on a 3-decimal currency keeps its fils (was hardcoded 2dp), and the customer
 * display's JS money() reads the store's decimals injected by the blade.
 */
class PosCurrencyDecimalsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
        ]);
    }

    private function omrStore(): Store
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 'T', 'monthly_price' => 1, 'annual_price' => 1,
            'lifetime_price' => 1, 'status' => 1,
            'settings' => ['restaurant_limit' => 999, 'menu_limit' => 999, 'allow_ordering' => '1', 'pos_enabled' => '1'],
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'd.png',
            'cover_image' => 'd.png', 'address' => '1 St', 'phone' => '1',
        ]);
        StoreOption::updateStoreOption($store->id, 'currency_code', 'OMR');

        return $store;
    }

    public function test_cash_change_keeps_fils_on_a_three_decimal_currency(): void
    {
        $r = $this->omrStore();
        $staff = StaffMember::create([
            'owner_id' => $r->user_id, 'store_id' => $r->id, 'name' => 'S', 'email' => Str::random(6).'@t.test',
            'password' => bcrypt('x'), 'role' => 'cashier', 'active' => 1,
        ]);
        $cat = MenuCategory::create(['store_id' => $r->id, 'name' => 'Mains', 'active' => 1]);
        $item = Menu::create(['store_id' => $r->id, 'category_id' => $cat->id, 'name' => 'Dish', 'price' => 1.234, 'active' => 1]);

        PosService::openShift($r, $staff, null, 0);
        $order = PosService::createOrder($r, $staff, [
            'type' => 'takeaway', 'items' => [['item_id' => $item->id, 'quantity' => 1]],
        ]);

        // Tender 2.000 on a 1.234 order → change 0.766 (fils), not the old 0.77.
        PosService::settle($order, 'cash', 2.0);

        $this->assertEqualsWithDelta(0.766, (float) $order->fresh()->change_due, 0.0005);
    }

    public function test_shift_over_short_uses_store_decimals(): void
    {
        $r = $this->omrStore();
        $staff = StaffMember::create([
            'owner_id' => $r->user_id, 'store_id' => $r->id, 'name' => 'S', 'email' => Str::random(6).'@t.test',
            'password' => bcrypt('x'), 'role' => 'cashier', 'active' => 1,
        ]);

        $shift = PosService::openShift($r, $staff, null, 0);
        // No sales → expected cash 0; count 0.001 (one baisa) over → over_short 0.001, not 0.00.
        PosService::closeShift($shift, 0.001);

        $this->assertEqualsWithDelta(0.001, (float) $shift->fresh()->over_short, 0.0005);
    }

    public function test_customer_display_injects_store_decimals_into_money(): void
    {
        $r = $this->omrStore();

        $this->actingAs($r->user, 'web')
            ->get(route('pos.display', ['store' => $r->id]))->assertOk()->assertSeeHtml('toFixed(3)'); // OMR → 3-decimal money()
    }
}
