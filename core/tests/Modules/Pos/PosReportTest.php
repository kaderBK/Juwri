<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Pos;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\MenuCategory;
use App\Models\Order;
use App\Models\StaffMember;
use App\Models\StoreOption;
use App\Services\PosService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Tests\Support\TestPlans;
use Tests\TestCase;

/** POS P6: sales report totals (by method/staff), manager-gated. */
class PosReportTest extends TestCase
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

    private function make(): array
    {
        $plan = TestPlans::create([
            'name' => 'P '.Str::random(5), 'description' => 'T',
            'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1,
            'settings' => ['restaurant_limit' => 999, 'menu_limit' => 999, 'allow_ordering' => '1', 'pos_enabled' => '1'],
            'status' => 1,
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $title = 'R '.Str::random(5);
        $r = Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1 St', 'phone' => '1',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $cat = MenuCategory::create(['store_id' => $r->id, 'name' => 'Mains', 'active' => 1]);
        $item = Menu::create(['store_id' => $r->id, 'category_id' => $cat->id, 'name' => 'Dish', 'price' => 10, 'active' => 1]);

        return [$r, $item];
    }

    private function staff(Store $r, string $role): StaffMember
    {
        return StaffMember::create([
            'owner_id' => $r->user_id, 'store_id' => $r->id, 'name' => 'S '.Str::random(4),
            'email' => Str::random(6).'@t.test', 'password' => bcrypt('x'), 'role' => $role, 'active' => 1,
        ]);
    }

    public function test_sales_report_totals_by_method(): void
    {
        [$r, $item] = $this->make();
        $manager = $this->staff($r, 'manager');

        $o1 = PosService::createOrder($r, $manager, ['type' => 'takeaway', 'items' => [['item_id' => $item->id, 'quantity' => 1]]]);
        PosService::settle($o1, 'cash', 10.0); // 10
        $o2 = PosService::createOrder($r, $manager, ['type' => 'takeaway', 'items' => [['item_id' => $item->id, 'quantity' => 2]]]);
        PosService::settle($o2, 'card'); // 20

        $res = $this->actingAs($manager, 'staff')->getJson(route('pos.sales'))->assertOk();

        $this->assertSame(2, $res->json('orders'));
        $this->assertEquals(30, $res->json('total'));
        $this->assertEquals(10, $res->json('sales_by_method.cash'));
        $this->assertEquals(20, $res->json('sales_by_method.card'));
    }

    /** The POS "today" report runs on the store's clock, not the platform's. */
    public function test_sales_report_today_uses_store_timezone(): void
    {
        $appTz = config('app.timezone') ?: 'UTC';
        // NY is on Aug 8 22:00; the platform (Asia/Qatar) is already Aug 9 05:00.
        Carbon::setTestNow(Carbon::parse('2026-08-09 02:00:00', 'UTC'));

        [$r, $item] = $this->make();
        StoreOption::updateStoreOption($r->id, 'timezone', 'America/New_York');
        $manager = $this->staff($r, 'manager');

        $o = PosService::createOrder($r, $manager, ['type' => 'takeaway', 'items' => [['item_id' => $item->id, 'quantity' => 1]]]);
        PosService::settle($o, 'cash', 10.0);
        // Sold at 16:00 New-York-time on Aug 8 — the store's "today", but the platform
        // calendar is already Aug 9, so a platform-tz window would miss it.
        Order::where('id', $o->id)->update([
            'created_at' => Carbon::parse('2026-08-08 20:00:00', 'UTC')->setTimezone($appTz),
        ]);

        $res = $this->actingAs($manager, 'staff')->getJson(route('pos.sales'))->assertOk();

        $this->assertSame(1, $res->json('orders'), 'the NY-today order is in the store-tz window');
        $this->assertSame('2026-08-08', $res->json('to'), 'window label is the store-local day');

        Carbon::setTestNow();
    }

    /** Garbage ?from/?to date params fall back to today's window, never a 500. */
    public function test_sales_report_tolerates_garbage_date_params(): void
    {
        [$r] = $this->make();
        $manager = $this->staff($r, 'manager');

        $this->actingAs($manager, 'staff')
            ->getJson(route('pos.sales', ['from' => 'garbage', 'to' => '!!!']))
            ->assertOk();
    }

    public function test_sales_report_forbidden_for_cashier(): void
    {
        [$r] = $this->make();
        $cashier = $this->staff($r, 'cashier'); // no analytics / shift_close
        $this->actingAs($cashier, 'staff')->getJson(route('pos.sales'))->assertStatus(403);
    }
}
