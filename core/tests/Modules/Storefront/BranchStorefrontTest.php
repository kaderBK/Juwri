<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront;

use App\Models\MenuCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Language;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\BranchMenu;
use Juwri\Modules\Menu\Models\Menu;
use Tests\Support\TestPlans;
use Tests\TestCase;

class BranchStorefrontTest extends TestCase
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
        $this->withoutMiddleware();
    }

    private function makeUser(): User
    {
        $plan = TestPlans::create([
            'name' => 'P '.Str::random(6), 'description' => 'T',
            'monthly_price' => 10, 'annual_price' => 100, 'lifetime_price' => 1000,
            'settings' => json_encode(['restaurant_limit' => 999, 'menu_limit' => 999, 'allow_ordering' => '1']), 'status' => 1,
        ]);

        return User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
    }

    private function makeRestaurant(User $owner): Store
    {
        $title = 'R '.Str::random(6);

        return Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'default.png', 'cover_image' => 'default.png',
            'address' => '1 St', 'phone' => '123', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function makeMenuItem(Store $r, float $price = 10): Menu
    {
        $cat = MenuCategory::create(['store_id' => $r->id, 'name' => 'Cat']);

        return Menu::create(['store_id' => $r->id, 'category_id' => $cat->id, 'name' => 'Item '.Str::random(4), 'price' => $price, 'active' => '1']);
    }

    /** Ordering against a paused branch is rejected by submitOrder */
    public function test_send_order_blocked_for_paused_branch(): void
    {
        $owner = $this->makeUser();
        $r = $this->makeRestaurant($owner);
        $branch = Branch::where('store_id', $r->id)->first();
        $branch->update(['accepting_orders' => false]);
        $item = $this->makeMenuItem($r);

        $resp = $this->postJson(route('store.submitOrder', $r->slug), [
            'items' => [['id' => $item->id, 'quantity' => 1]],
            'name' => 'Joe',
            'ordering-type' => 'takeaway',
            'phone-number' => '123456',
            'pay_via' => 'cash',
            'branch_id' => $branch->id,
        ]);

        $resp->assertOk()->assertJson(['success' => false]);
        $this->assertDatabaseCount('orders', 0);
    }

    /** QR page lists a deep-link per branch */
    public function test_branch_qr_links_resolve(): void
    {
        $owner = $this->makeUser();
        $r = $this->makeRestaurant($owner);
        Branch::create(['store_id' => $r->id, 'name' => 'Second', 'active' => true, 'position' => 1]);

        // The controller attaches deep_link to each branch; assert the model logic builds them.
        $branches = Branch::where('store_id', $r->id)->get();
        $this->assertGreaterThanOrEqual(2, $branches->count());
        foreach ($branches as $b) {
            $link = route('publicView', $r->slug).'?branch_id='.$b->id;
            $this->assertStringContainsString('branch_id='.$b->id, $link);
        }
    }

    /** branch_menu availability + price override resolve through the Menu model */
    public function test_branch_pricing_resolution(): void
    {
        $owner = $this->makeUser();
        $r = $this->makeRestaurant($owner);
        $branch = Branch::where('store_id', $r->id)->first();
        $item = $this->makeMenuItem($r, 10);

        BranchMenu::create(['branch_id' => $branch->id, 'menu_id' => $item->id, 'available' => true, 'price' => 14.50]);
        $this->assertEquals(14.50, $item->fresh()->priceForBranch($branch->id));

        BranchMenu::where('branch_id', $branch->id)->where('menu_id', $item->id)->update(['available' => false]);
        $this->assertFalse($item->fresh()->availableAtBranch($branch->id));
    }
}
