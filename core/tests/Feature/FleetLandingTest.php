<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Order;
use App\Models\StoreOption;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Juwri\Core\Http\Middleware\Locale;
use Juwri\Core\Models\Language;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * P2-4 — multi-store fleet landing: ?scope=all for a >1-store owner renders a
 * per-store table with a drill-through that sets context; single-store owners
 * are unaffected; per-store counts match the single-store view.
 */
class FleetLandingTest extends TestCase
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
        View::share([
            'activeTheme' => active_theme(), 'activeThemeAssets' => active_theme(true),
            'settings' => (object) [], 'languages' => collect(), 'admin_languages' => collect(),
        ]);
        $this->withoutMiddleware([
            Installed::class,
            Locale::class,
            PreventRequestForgery::class,
        ]);
    }

    private function owner(): User
    {
        $plan = TestPlans::create(['name' => 'P '.Str::random(6), 'description' => 'T', 'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1, 'settings' => json_encode(['restaurant_limit' => 999, 'menu_limit' => 999, 'scan_limit' => 999]), 'status' => 1]);

        return User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
    }

    private function store(User $o, string $title): Store
    {
        return Store::create(['user_id' => $o->id, 'title' => $title, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)), 'color' => '#111', 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'default.png', 'cover_image' => 'default.png', 'address' => '1', 'phone' => '1', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function seedTodayOrders(int $storeId, int $n): void
    {
        $rows = [];
        for ($i = 0; $i < $n; $i++) {
            $rows[] = ['store_id' => $storeId, 'status' => 'completed', 'type' => 'dine_in', 'customer_name' => 'C', 'phone_number' => '1', 'created_at' => Carbon::now()];
        }
        Order::insert($rows);
    }

    public function test_multi_store_owner_gets_fleet_table_on_scope_all(): void
    {
        $o = $this->owner();
        $a = $this->store($o, 'Alpha Kitchen');
        $b = $this->store($o, 'Beta Bistro');
        $this->seedTodayOrders($a->id, 3);
        $this->seedTodayOrders($b->id, 2);

        $res = $this->actingAs($o)->get(route('dashboard', ['scope' => 'all']));
        $res->assertStatus(200);
        $res->assertSeeHtml('Alpha Kitchen');
        $res->assertSeeHtml('Beta Bistro');
        // Drill-through: each row posts the store into the shared context route.
        $res->assertSeeHtml('name="store_id" value="'.$a->id.'"');
        $res->assertSeeHtml('fleet-row');
    }

    public function test_single_store_owner_never_sees_fleet(): void
    {
        $o = $this->owner();
        $this->store($o, 'Solo Cafe');

        $res = $this->actingAs($o)->get(route('dashboard', ['scope' => 'all']));
        $res->assertStatus(200);
        $res->assertDontSeeHtml('fleet-row');
    }

    public function test_fleet_orders_match_single_store_view(): void
    {
        $o = $this->owner();
        $a = $this->store($o, 'Alpha Kitchen');
        $this->store($o, 'Beta Bistro');
        $this->seedTodayOrders($a->id, 4);

        // Single-store view (context A) today order count.
        $single = $this->actingAs($o)->withSession(['dashboard_store_id' => $a->id])->get(route('dashboard'));
        $single->assertStatus(200);
        $single->assertDontSeeHtml('fleet-row'); // context set → normal home

        // Fleet view shows the same per-store order count (4) for Alpha.
        $fleet = $this->actingAs($o)->get(route('dashboard', ['scope' => 'all']));
        $fleet->assertStatus(200)->assertSeeHtml('fleet-row')->assertSeeHtml('Alpha Kitchen');
        // The row's order cell renders "4".
        $this->assertMatchesRegularExpression('/Alpha Kitchen.*?>\s*4\s*</s', $fleet->getContent());
    }

    public function test_fleet_sales_are_formatted_in_each_stores_own_currency(): void
    {
        $o = $this->owner();
        $a = $this->store($o, 'Alpha Kitchen');
        $b = $this->store($o, 'Beta Bistro');
        StoreOption::updateStoreOption($a->id, 'currency_code', 'OMR');
        StoreOption::updateStoreOption($a->id, 'currency_sign', 'OMRSIGN');
        StoreOption::updateStoreOption($b->id, 'currency_code', 'EUR');
        StoreOption::updateStoreOption($b->id, 'currency_sign', 'EURSIGN');
        config(['settings.currency_code' => 'USD', 'settings.currency_sign' => '$']);
        $this->seedTodayOrders($a->id, 1);
        $this->seedTodayOrders($b->id, 1);

        $html = $this->actingAs($o)->get(route('dashboard', ['scope' => 'all']))->assertStatus(200)->getContent();
        $this->assertMatchesRegularExpression('/Alpha Kitchen.*?OMRSIGN/s', $html);
        $this->assertMatchesRegularExpression('/Beta Bistro.*?EURSIGN/s', $html);
    }
}
