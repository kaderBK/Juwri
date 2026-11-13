<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Menu;

use App\Models\MenuCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Allergy;
use Juwri\Modules\Menu\Models\BranchMenu;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Menu\Models\MenuExtra;
use Juwri\Modules\Menu\Models\MenuVariantOption;
use Juwri\Modules\Menu\Services\Catalog\CatalogExport;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * I0 — canonical CatalogExport: one normalized tree every menu-pushing driver consumes.
 */
class CatalogExportTest extends TestCase
{
    use RefreshDatabase;

    private function storeFixture(): Store
    {
        $plan = TestPlans::create([
            'name' => 'P '.Str::random(5), 'description' => 'T',
            'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1,
            'settings' => json_encode(['restaurant_limit' => 999, 'menu_limit' => 999, 'allow_ordering' => '1']),
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

    public function test_snapshot_shape_with_variants_extras_groups_branch_and_allergens(): void
    {
        $r = $this->storeFixture();
        $branchId = 7;

        $allergy = Allergy::create(['store_id' => $r->id, 'title' => 'Gluten', 'active' => '1', 'position' => 1]);

        $cat = MenuCategory::create(['store_id' => $r->id, 'name' => 'Mains', 'active' => '1', 'position' => 1]);
        $item = Menu::create([
            'store_id' => $r->id, 'category_id' => $cat->id, 'name' => 'Pizza',
            'description' => 'Cheesy', 'price' => 12, 'active' => '1', 'position' => 1,
            'gallery_images' => ['g1.png', 'g2.png'], 'calories' => 800,
            'allergies' => (string) $allergy->id,
            'translations' => (object) ['ar' => (object) ['title' => 'بيتزا', 'description' => 'جبنة']],
        ]);

        // Branch override: cheaper at branch 7.
        BranchMenu::create(['menu_id' => $item->id, 'branch_id' => $branchId, 'price' => 9, 'available' => 1]);

        MenuExtra::create(['menu_id' => $item->id, 'title' => 'Extra Cheese', 'price' => 2, 'position' => 1]);
        MenuVariantOption::create([
            'menu_id' => $item->id, 'title' => 'Crust', 'options' => ['Thin', 'Thick'],
            'required' => 1, 'min_select' => 1, 'max_select' => 1, 'position' => 1,
            'option_prices' => [0, 3],
        ]);

        // A sold-out item stays in the tree but flagged unavailable.
        Menu::create([
            'store_id' => $r->id, 'category_id' => $cat->id, 'name' => 'SoldOut',
            'price' => 5, 'active' => '1', 'position' => 2, 'is_sold_out' => 1,
        ]);

        $tree = new CatalogExport()->forRestaurant($r->id, $branchId, true);

        $this->assertSame($r->id, $tree['store_id']);
        $this->assertSame($branchId, $tree['branch_id']);
        $this->assertCount(1, $tree['categories']);

        $c = $tree['categories'][0];
        $this->assertSame('Mains', $c['name']['en']);
        $this->assertCount(2, $c['items']);

        $pizza = $c['items'][0];
        $this->assertSame('Pizza', $pizza['name']['en']);
        $this->assertSame('بيتزا', $pizza['name']['ar']);
        $this->assertSame('جبنة', $pizza['description']['ar']);
        $this->assertSame(12.0, $pizza['price']);
        $this->assertSame(9.0, $pizza['branch_price']); // branch override applied
        $this->assertSame(800, $pizza['calories']);
        $this->assertSame(['Gluten'], $pizza['allergens']);
        $this->assertCount(2, $pizza['gallery']);
        $this->assertTrue($pizza['available']);
        $this->assertFalse($pizza['sold_out']);

        // modifier groups: Crust choice group + Extras group.
        $groups = collect($pizza['modifier_groups']);
        $crust = $groups->firstWhere('name.en', 'Crust');
        $this->assertNotNull($crust);
        $this->assertTrue($crust['required']);
        $this->assertSame(1, $crust['min']);
        $this->assertSame(1, $crust['max']);
        $this->assertSame('Thick', $crust['options'][1]['name']['en']);
        $this->assertSame(3.0, $crust['options'][1]['price']);

        $extras = $groups->firstWhere('id', 'extras_'.$item->id);
        $this->assertNotNull($extras);
        $this->assertSame(0, $extras['min']);
        $this->assertSame('Extra Cheese', $extras['options'][0]['name']['en']);
        $this->assertSame(2.0, $extras['options'][0]['price']);

        // sold-out item present but unavailable.
        $soldOut = $c['items'][1];
        $this->assertSame('SoldOut', $soldOut['name']['en']);
        $this->assertTrue($soldOut['sold_out']);
        $this->assertFalse($soldOut['available']);
    }

    public function test_only_active_categories_and_items_and_stable_across_calls(): void
    {
        $r = $this->storeFixture();
        $cat = MenuCategory::create(['store_id' => $r->id, 'name' => 'Live', 'active' => '1', 'position' => 1]);
        MenuCategory::create(['store_id' => $r->id, 'name' => 'Hidden', 'active' => '0', 'position' => 2]);
        Menu::create(['store_id' => $r->id, 'category_id' => $cat->id, 'name' => 'On', 'price' => 5, 'active' => '1', 'position' => 1]);
        Menu::create(['store_id' => $r->id, 'category_id' => $cat->id, 'name' => 'Off', 'price' => 5, 'active' => '0', 'position' => 2]);

        $svc = new CatalogExport;
        $a = $svc->forRestaurant($r->id, null, true);
        $b = $svc->forRestaurant($r->id, null, false); // cached

        $this->assertCount(1, $a['categories']);
        $this->assertCount(1, $a['categories'][0]['items']);
        $this->assertSame('On', $a['categories'][0]['items'][0]['name']['en']);
        $this->assertSame($a['categories'], $b['categories']);
    }
}
