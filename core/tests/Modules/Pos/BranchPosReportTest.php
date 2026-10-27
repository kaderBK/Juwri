<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Pos;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Ingredient;
use App\Models\MenuCategory;
use App\Models\Order;
use App\Models\StaffMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Illuminate\Support\ViewErrorBag;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Inventory\Models\BranchStock;
use Juwri\Modules\Menu\Models\Menu;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * D9 — branch dimension. The POS sales report filters by branch (defaulting to the
 * register's own branch, a manager may switch or see all), and the inventory report
 * shows a chosen branch's on-hand (BranchStock) with a whole-store fallback.
 */
class BranchPosReportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        $this->shareLayoutGlobals();
        View::share('errors', new ViewErrorBag);
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
    }

    private function storeFixture(): Store
    {
        $plan = TestPlans::create([
            'name' => 'P '.Str::random(5), 'description' => 'T',
            'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1,
            'settings' => ['restaurant_limit' => 999, 'menu_limit' => 999, 'allow_ordering' => '1', 'pos_enabled' => '1'],
            'status' => 1,
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $title = 'R '.Str::random(5);

        return Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1 St', 'phone' => '1',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function staff(Store $r, string $role = 'manager', ?int $branchId = null): StaffMember
    {
        return StaffMember::create([
            'owner_id' => $r->user_id, 'store_id' => $r->id, 'branch_id' => $branchId,
            'name' => 'M '.Str::random(4), 'email' => Str::random(6).'@t.test',
            'password' => bcrypt('x'), 'role' => $role, 'active' => 1,
        ]);
    }

    private function menu(Store $r, float $price = 10): Menu
    {
        $cat = MenuCategory::create(['store_id' => $r->id, 'name' => 'Mains', 'active' => 1]);

        return Menu::create(['store_id' => $r->id, 'category_id' => $cat->id, 'name' => 'D', 'price' => $price, 'active' => 1]);
    }

    private function paidPosOrder(Store $r, ?int $branchId, Menu $menu, int $qty): Order
    {
        $o = Order::create([
            'store_id' => $r->id, 'branch_id' => $branchId, 'source' => 'pos', 'is_paid' => 1,
            'status' => 'completed', 'type' => 'takeaway', 'payment_method' => 'cash', 'created_at' => now(),
        ]);
        $o->items()->create(['item_id' => $menu->id, 'quantity' => $qty]);

        return $o->fresh();
    }

    public function test_sales_report_filters_by_branch(): void
    {
        $r = $this->storeFixture();
        $a = Branch::create(['store_id' => $r->id, 'name' => 'A', 'active' => 1, 'position' => 1]);
        $b = Branch::create(['store_id' => $r->id, 'name' => 'B', 'active' => 1, 'position' => 2]);
        $menu = $this->menu($r, 10);
        $this->paidPosOrder($r, $a->id, $menu, 2); // $20 at A
        $this->paidPosOrder($r, $b->id, $menu, 5); // $50 at B
        $owner = User::find($r->user_id);

        // Branch A only.
        $this->actingAs($owner)
            ->getJson(route('pos.sales', ['store' => $r->id, 'branch_id' => $a->id]))
            ->assertOk()->assertJsonPath('total', 20)->assertJsonPath('branch_id', $a->id);

        // Whole store (branch_id=0).
        $this->actingAs($owner)
            ->getJson(route('pos.sales', ['store' => $r->id, 'branch_id' => 0]))
            ->assertOk()->assertJsonPath('total', 70)->assertJsonPath('branch_id', null);
    }

    public function test_sales_report_defaults_to_the_staffs_own_branch(): void
    {
        $r = $this->storeFixture();
        $a = Branch::create(['store_id' => $r->id, 'name' => 'A', 'active' => 1, 'position' => 1]);
        $b = Branch::create(['store_id' => $r->id, 'name' => 'B', 'active' => 1, 'position' => 2]);
        $menu = $this->menu($r, 10);
        $this->paidPosOrder($r, $a->id, $menu, 3); // $30 at A
        $this->paidPosOrder($r, $b->id, $menu, 9); // $90 at B

        // A manager pinned to branch A, no explicit branch_id → sees only A.
        $mgr = $this->staff($r, 'manager', $a->id);
        $this->actingAs($mgr, 'staff')
            ->getJson(route('pos.sales', ['store' => $r->id]))
            ->assertOk()->assertJsonPath('total', 30)->assertJsonPath('branch_id', $a->id);
    }

    public function test_sales_report_is_manager_gated(): void
    {
        $r = $this->storeFixture();
        $cashier = $this->staff($r, 'cashier'); // no analytics / pos_shift_close

        $this->actingAs($cashier, 'staff')
            ->getJson(route('pos.sales', ['store' => $r->id]))
            ->assertStatus(403);
    }

    public function test_inventory_report_shows_branch_on_hand(): void
    {
        $r = $this->storeFixture();
        $a = Branch::create(['store_id' => $r->id, 'name' => 'A', 'active' => 1, 'position' => 1]);
        $ing = Ingredient::create([
            'store_id' => $r->id, 'name' => 'Flour', 'unit' => 'kg',
            'current_stock' => 100, 'par_level' => 10, 'cost_per_unit' => 2, 'position' => 1,
        ]);
        // Branch A holds only 3kg.
        BranchStock::create(['branch_id' => $a->id, 'ingredient_id' => $ing->id, 'current_stock' => 3, 'par_level' => 10]);
        $owner = User::find($r->user_id);

        // Whole store → shared 100kg × $2 = $200.
        $this->actingAs($owner)
            ->get(route('inventory.reports.index', ['store_id' => $r->id]))
            ->assertOk()
            ->assertViewHas('report', fn ($rep) => abs(($rep['stockValue'] ?? 0) - 200.0) < 0.01);

        // Branch A → 3kg × $2 = $6, and it is low (3 ≤ par 10).
        $this->actingAs($owner)
            ->get(route('inventory.reports.index', ['store_id' => $r->id, 'branch_id' => $a->id]))
            ->assertOk()
            ->assertViewHas('report', fn ($rep) => abs(($rep['stockValue'] ?? 0) - 6.0) < 0.01 && $rep['lowStock']->count() === 1);
    }
}
