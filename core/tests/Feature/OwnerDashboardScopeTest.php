<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Order;
use App\Models\StoreView;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Juwri\Core\Http\Middleware\Locale;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Language;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Models\UserOption;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * R2 — the owner dashboard follows the store/branch switcher.
 * Activity metrics scope to the selected store (?scope=all restores owner-wide);
 * branch scoping applies to orders (scans carry no branch).
 */
class OwnerDashboardScopeTest extends TestCase
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
        $this->withoutMiddleware([
            Installed::class,
            Locale::class,
            PreventRequestForgery::class,
        ]);
        View::share([
            'activeTheme' => active_theme(),
            'activeThemeAssets' => active_theme(true),
            'settings' => (object) [],
            'languages' => collect(),
            'admin_languages' => collect(),
        ]);
    }

    private function makeOwner(): User
    {
        $plan = TestPlans::create([
            'name' => 'P '.Str::random(6), 'description' => 'T',
            'monthly_price' => 10, 'annual_price' => 100, 'lifetime_price' => 1000,
            'settings' => json_encode(['restaurant_limit' => 999, 'menu_limit' => 999, 'scan_limit' => 999]),
            'status' => 1,
        ]);

        return User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
    }

    private function makeStore(User $owner): Store
    {
        $title = 'R '.Str::random(6);

        return Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'default.png', 'cover_image' => 'default.png',
            'address' => '1 St', 'phone' => '1234567890', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function seedScans(int $storeId, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            StoreView::create([
                'store_id' => $storeId, 'ip' => '10.0.0.'.random_int(1, 254),
                'date' => Carbon::now(), 'device' => 'Mobile', 'lang' => 'en',
            ]);
        }
    }

    private function seedOrders(int $storeId, ?int $branchId, int $count): void
    {
        // orders table has no updated_at column — insert() avoids the timestamp writes.
        $rows = [];
        for ($i = 0; $i < $count; $i++) {
            $rows[] = [
                'store_id' => $storeId, 'branch_id' => $branchId,
                'status' => 'completed', 'type' => 'dine_in',
                'customer_name' => 'C', 'phone_number' => '1',
                'created_at' => Carbon::now(),
            ];
        }
        Order::insert($rows);
    }

    public function test_scope_all_aggregates_every_store(): void
    {
        $owner = $this->makeOwner();
        $a = $this->makeStore($owner);
        $b = $this->makeStore($owner);
        $this->seedScans($a->id, 3);
        $this->seedScans($b->id, 5);

        $response = $this->actingAs($owner)->get(route('dashboard', ['scope' => 'all']));

        $response->assertStatus(200);
        $this->assertTrue($response->viewData('scopeAll'));
        $this->assertSame(8, array_sum($response->viewData('scans')), 'all stores: 3 + 5');
    }

    public function test_selected_store_scopes_activity(): void
    {
        $owner = $this->makeOwner();
        $a = $this->makeStore($owner);
        $b = $this->makeStore($owner);
        $this->seedScans($a->id, 3);
        $this->seedScans($b->id, 5);

        $response = $this->actingAs($owner)
            ->withSession(['dashboard_store_id' => $b->id])
            ->get(route('dashboard'));

        $response->assertStatus(200);
        $this->assertSame($b->id, $response->viewData('activeStore')?->id);
        $this->assertSame(5, array_sum($response->viewData('scans')), 'store B only');
    }

    public function test_saved_default_store_scopes_activity(): void
    {
        $owner = $this->makeOwner();
        $a = $this->makeStore($owner);
        $b = $this->makeStore($owner);
        $this->seedScans($a->id, 3);
        $this->seedScans($b->id, 5);
        UserOption::updateUserOption($owner->id, 'default_store_id', $a->id);

        $response = $this->actingAs($owner)->get(route('dashboard'));

        $response->assertStatus(200);
        $this->assertSame($a->id, $response->viewData('activeStore')?->id);
        $this->assertSame(3, array_sum($response->viewData('scans')), 'store A only');
    }

    public function test_branch_scopes_orders_only(): void
    {
        $owner = $this->makeOwner();
        $store = $this->makeStore($owner);
        $branchX = Branch::create(['store_id' => $store->id, 'name' => 'X', 'active' => 1, 'accepting_orders' => 1, 'position' => 1]);
        $branchY = Branch::create(['store_id' => $store->id, 'name' => 'Y', 'active' => 1, 'accepting_orders' => 1, 'position' => 2]);
        $this->seedOrders($store->id, $branchX->id, 2);
        $this->seedOrders($store->id, $branchY->id, 3);

        $response = $this->actingAs($owner)
            ->withSession(['dashboard_store_id' => $store->id, 'dashboard_branch_id' => $branchX->id])
            ->get(route('dashboard'));

        $response->assertStatus(200);
        $this->assertSame($branchX->id, $response->viewData('activeBranch')?->id);
        $this->assertSame(2, $response->viewData('thisMonthOrders'), 'only branch X orders');
    }
}
