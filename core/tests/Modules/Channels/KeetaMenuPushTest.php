<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Channels;

use App\Models\MenuCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Channels\Services\Aggregators\Keeta\KeetaAllergenMap;
use Juwri\Modules\Channels\Services\Aggregators\Keeta\KeetaMenuPusher;
use Juwri\Modules\Menu\Models\Allergy;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Menu\Models\MenuExtra;
use Juwri\Modules\Menu\Models\MenuVariantOption;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * N2-P2 — Keeta full-replace menu payload: hierarchy, OpenItemCode, SFDA allergen
 * mapping + unmapped report, sold-out exclusion, localized names.
 */
class KeetaMenuPushTest extends TestCase
{
    use RefreshDatabase;

    private function storeFixture(): Store
    {
        $plan = TestPlans::create([
            'name' => 'P '.Str::random(5), 'description' => 'T', 'monthly_price' => 1,
            'annual_price' => 1, 'lifetime_price' => 1,
            'settings' => json_encode(['restaurant_limit' => 999]), 'status' => 1,
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1]);

        return Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)),
            'color' => '#111', 'title' => 'R', 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1 St', 'phone' => '1',
        ]);
    }

    public function test_allergen_map_and_unmapped(): void
    {
        [$codes, $unmapped] = KeetaAllergenMap::map(['Gluten', 'Milk', 'Unicorn Dust']);
        sort($codes);
        $this->assertSame(['GLUTEN', 'MILK'], $codes);
        $this->assertSame(['Unicorn Dust'], $unmapped);
    }

    public function test_full_replace_payload_shape(): void
    {
        $r = $this->storeFixture();
        $gluten = Allergy::create(['store_id' => $r->id, 'title' => 'Gluten', 'active' => '1', 'position' => 1]);
        $weird = Allergy::create(['store_id' => $r->id, 'title' => 'Stardust', 'active' => '1', 'position' => 2]);

        $cat = MenuCategory::create(['store_id' => $r->id, 'name' => 'Mains', 'active' => '1', 'position' => 1]);
        $pizza = Menu::create([
            'store_id' => $r->id, 'category_id' => $cat->id, 'name' => 'Pizza',
            'description' => 'Cheesy', 'price' => 12, 'active' => '1', 'position' => 1,
            'allergies' => $gluten->id.','.$weird->id,
            'translations' => (object) ['ar' => (object) ['title' => 'بيتزا']],
        ]);
        MenuExtra::create(['menu_id' => $pizza->id, 'title' => 'Extra Cheese', 'price' => 2, 'position' => 1]);
        MenuVariantOption::create([
            'menu_id' => $pizza->id, 'title' => 'Crust', 'options' => ['Thin', 'Thick'],
            'required' => 1, 'min_select' => 1, 'max_select' => 1, 'position' => 1, 'option_prices' => [0, 3],
        ]);
        // Sold-out item must be excluded from the full-replace push.
        Menu::create(['store_id' => $r->id, 'category_id' => $cat->id, 'name' => 'Gone', 'price' => 5, 'active' => '1', 'position' => 2, 'is_sold_out' => 1]);

        $payload = new KeetaMenuPusher()->build($r->id, null, 'STORE1');

        $this->assertSame('STORE1', $payload['shop']['code']);
        $this->assertCount(1, $payload['categories']);
        $this->assertSame('cat_'.$cat->id, $payload['categories'][0]['code']);

        // Only the non-sold-out item.
        $this->assertCount(1, $payload['spus']);
        $spu = $payload['spus'][0];
        $this->assertSame('spu_'.$pizza->id, $spu['code']);
        $this->assertSame('Pizza', $spu['name']['en']);
        $this->assertSame('بيتزا', $spu['name']['ar']);

        $sku = $spu['skus'][0];
        $this->assertSame('sku_'.$pizza->id, $sku['code']);
        $this->assertSame(1200, $sku['price']); // cents
        $this->assertSame(['GLUTEN'], $sku['sfdaAllergens']);

        // Choice groups: Crust + Extras.
        $codes = array_column($sku['choiceGroups'], 'code');
        $this->assertContains('cg_grp_'.MenuVariantOption::first()->id, $codes);
        $crust = collect($sku['choiceGroups'])->firstWhere('name.en', 'Crust');
        $this->assertTrue($crust['required']);
        $this->assertSame(300, $crust['choiceGroupSkus'][1]['price']); // Thick +3.00

        // Unmapped allergen surfaced, never dropped.
        $this->assertSame(['Stardust'], $payload['unmapped_allergens']);
    }
}
