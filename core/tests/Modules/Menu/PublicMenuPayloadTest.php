<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Menu;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\MenuCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Menu\Models\MenuExtra;
use Juwri\Modules\Menu\Models\MenuVariant;
use Juwri\Modules\Menu\Models\MenuVariantOption;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * GAP-1 (platform): the public menu payload must expose variants, extras,
 * variant groups and gallery so a headless/agent client can build a valid cart.
 */
class PublicMenuPayloadTest extends TestCase
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

    public function test_menu_payload_includes_variants_extras_groups_and_gallery(): void
    {
        $r = $this->storeFixture();
        $cat = MenuCategory::create(['store_id' => $r->id, 'name' => 'Mains', 'active' => 1]);
        $item = Menu::create([
            'store_id' => $r->id, 'category_id' => $cat->id, 'name' => 'Pizza',
            'description' => 'Cheesy', 'price' => 12, 'active' => 1,
            'gallery_images' => ['g1.png', 'g2.png'],
        ]);

        $variant = MenuVariant::create([
            'menu_id' => $item->id, 'price' => 15, 'options' => json_encode(['size' => 'Large']),
            'active' => 1, 'position' => 1,
        ]);
        MenuExtra::create(['menu_id' => $item->id, 'title' => 'Extra Cheese', 'price' => 2, 'position' => 1]);
        MenuVariantOption::create([
            'menu_id' => $item->id, 'title' => 'Crust', 'options' => json_encode(['Thin', 'Thick']),
            'required' => 1, 'min_select' => 1, 'max_select' => 1, 'position' => 1,
        ]);

        $res = $this->getJson(route('api.v1.public.menu', $r->id))->assertOk();

        $res->assertJsonPath('categories.0.items.0.name', 'Pizza')
            ->assertJsonPath('categories.0.items.0.variants.0.id', $variant->id)
            ->assertJsonPath('categories.0.items.0.extras.0.title', 'Extra Cheese')
            ->assertJsonPath('categories.0.items.0.variant_groups.0.title', 'Crust')
            ->assertJsonPath('categories.0.items.0.variant_groups.0.required', true)
            ->assertJsonPath('categories.0.items.0.variant_groups.0.max_select', 1)
            ->assertJsonCount(2, 'categories.0.items.0.gallery');

        // Prices are numeric (JSON may encode a .0 float as an int) — compare loosely.
        $this->assertEquals(15, $res->json('categories.0.items.0.variants.0.price'));
        $this->assertEquals(2, $res->json('categories.0.items.0.extras.0.price'));

        // The variant id in the payload is the value OrderApiService accepts as `variation`.
        $this->assertSame(
            $variant->id,
            $res->json('categories.0.items.0.variants.0.id')
        );
    }

    public function test_sold_out_item_excluded_from_payload(): void
    {
        $r = $this->storeFixture();
        $cat = MenuCategory::create(['store_id' => $r->id, 'name' => 'Mains', 'active' => 1]);
        Menu::create(['store_id' => $r->id, 'category_id' => $cat->id, 'name' => 'Available', 'price' => 5, 'active' => 1]);
        Menu::create(['store_id' => $r->id, 'category_id' => $cat->id, 'name' => 'GoneItem', 'price' => 5, 'active' => 1, 'is_sold_out' => 1]);

        $res = $this->getJson(route('api.v1.public.menu', $r->id))->assertOk();

        $names = collect($res->json('categories.0.items'))->pluck('name');
        $this->assertTrue($names->contains('Available'));
        $this->assertFalse($names->contains('GoneItem'));
    }
}
