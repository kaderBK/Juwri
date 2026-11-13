<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Menu;

use App\Http\Middleware\EnsureStaffDashboardPermission;
use App\Http\Middleware\Installed;
use App\Models\Ingredient;
use App\Models\MenuCategory;
use App\Models\RecipeItem;
use App\Services\Entitlements;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Juwri\Core\Http\Middleware\Locale;
use Juwri\Core\Models\Language;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Auth\Http\Middleware\Authenticate;
use Juwri\Modules\Auth\Http\Middleware\CheckUserIsBanned;
use Juwri\Modules\Media\Services\StorageService;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Menu\Models\MenuVariant;
use Juwri\Modules\Menu\Models\MenuVariantOption;
use Tests\Support\TestPlans;
use Tests\TestCase;

/** P6-308 (category image basename, card image variants) and P6-309 (bulk duplicate limit/links, % on variants). */
class P6BulkDuplicateAndCardImageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        Language::firstOrCreate(['code' => 'en'], ['name' => 'English', 'direction' => 'ltr', 'active' => 1]);
        $this->withoutMiddleware([
            Authenticate::class, EnsureStaffDashboardPermission::class,
            Installed::class, CheckUserIsBanned::class, Locale::class,
        ]);
    }

    private function owner(int $menuLimit = 999): User
    {
        $plan = TestPlans::create([
            'name' => 'P '.Str::random(5), 'description' => 'T',
            'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1,
            'settings' => ['restaurant_limit' => 999, 'category_limit' => 999, 'menu_limit' => $menuLimit],
            'status' => 1,
        ]);

        return User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
    }

    private function store(User $owner): Store
    {
        $t = 'R'.Str::random(6);

        return Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug($t).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $t, 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'default.png', 'cover_image' => 'default.png', 'address' => '1', 'phone' => '1',
        ]);
    }

    public function test_duplicate_past_the_plan_limit_is_refused_and_copies_nothing(): void
    {
        $owner = $this->owner(5);
        $store = $this->store($owner);
        $items = [];
        for ($i = 0; $i < 4; $i++) {
            $items[] = Menu::create(['store_id' => $store->id, 'name' => "I$i", 'price' => 1, 'active' => 1]);
        }

        // The menu count is a plan key: an edition that grants every plan key has nothing to withhold.
        if ((string) (Entitlements::planTrace($owner, 'menu_limit')['value'] ?? '') !== '5') {
            $this->markTestSkipped('This edition grants every plan key (Community): nothing to withhold.');
        }

        $this->actingAs($owner)->post(route('stores.menuBulk', $store->id), [
            'action' => 'duplicate', 'ids' => [$items[0]->id, $items[1]->id],
        ])->assertStatus(422)->assertJson(['success' => false]);
        $this->assertSame(4, Menu::where('store_id', $store->id)->count());

        $this->actingAs($owner)->post(route('stores.menuBulk', $store->id), [
            'action' => 'duplicate', 'ids' => [$items[0]->id],
        ])->assertOk();
        $this->assertSame(5, Menu::where('store_id', $store->id)->count());
    }

    public function test_copy_keeps_group_links_and_recipe_lines(): void
    {
        $owner = $this->owner();
        $store = $this->store($owner);
        $item = Menu::create(['store_id' => $store->id, 'name' => 'Pizza', 'price' => 10, 'active' => 1]);
        $group = MenuVariantOption::create([
            'store_id' => $store->id, 'title' => 'Toppings', 'is_global' => true, 'menu_id' => null,
            'options' => ['A', 'B'], 'option_prices' => [1, 1], 'required' => false, 'min_select' => 0, 'max_select' => 2, 'position' => 0, 'active' => true,
        ]);
        $item->attachedVariantOptions()->attach($group->id, ['position' => 0, 'required' => false, 'min_select' => 0, 'max_select' => 2, 'active' => true]);
        $ing = Ingredient::create(['store_id' => $store->id, 'name' => 'Cheese', 'unit' => 'g', 'current_stock' => 5, 'par_level' => 0, 'cost_per_unit' => 1, 'position' => 1]);
        RecipeItem::create(['menu_id' => $item->id, 'ingredient_id' => $ing->id, 'quantity' => 30]);

        $this->actingAs($owner)->post(route('stores.menuBulk', $store->id), [
            'action' => 'duplicate', 'ids' => [$item->id],
        ])->assertOk();

        $copy = Menu::where('store_id', $store->id)->where('id', '!=', $item->id)->firstOrFail();
        $this->assertSame([$group->id], $copy->attachedVariantOptions()->pluck('modifier_groups.id')->all());
        $this->assertSame(2, (int) $copy->attachedVariantOptions()->first()->pivot->max_select);
        $this->assertSame(1, RecipeItem::where('menu_id', $copy->id)->count());
    }

    public function test_percent_change_moves_variant_prices(): void
    {
        $owner = $this->owner();
        $store = $this->store($owner);
        $item = Menu::create(['store_id' => $store->id, 'name' => 'Shirt', 'price' => 20, 'active' => 1]);
        $variant = MenuVariant::create(['menu_id' => $item->id, 'price' => 30, 'options' => ['Fit' => 'A'], 'active' => 1, 'position' => 0]);

        $this->actingAs($owner)->post(route('stores.menuBulk', $store->id), [
            'action' => 'price_adjust_percent', 'percent' => 10, 'ids' => [$item->id],
        ])->assertOk();

        $this->assertEqualsWithDelta(22.0, (float) $item->fresh()->price, 0.001);
        $this->assertEqualsWithDelta(33.0, (float) $variant->fresh()->price, 0.001);
    }

    public function test_category_image_path_is_stored_as_a_file_name(): void
    {
        $owner = $this->owner();
        $store = $this->store($owner);

        $this->actingAs($owner)->post(route('stores.addCategory', $store->id), ['name' => 'Drinks', 'image' => '../x.png']);
        $cat = MenuCategory::where('store_id', $store->id)->firstOrFail();
        $this->assertSame('x.png', $cat->image);

        $this->actingAs($owner)->post(route('stores.updateCategory', $store->id), ['id' => $cat->id, 'name' => 'Drinks', 'image' => '../../y.png']);
        $this->assertSame('y.png', $cat->fresh()->image);
    }

    public function test_wide_upload_gets_320_and_640_variants_and_a_small_one_keeps_the_original(): void
    {
        $dir = public_path('storage/menu');
        File::ensureDirectoryExists($dir);
        $big = 'p6308-'.Str::random(6).'.jpg';
        $small = 'p6308-'.Str::random(6).'.jpg';
        imagejpeg(imagecreatetruecolor(1000, 700), $dir.'/'.$big);
        imagejpeg(imagecreatetruecolor(200, 140), $dir.'/'.$small);

        try {
            $v = StorageService::widthVariants('storage/menu/', $big);
            $this->assertNotNull($v);
            $this->assertStringContainsString('-w640.webp', $v['src']);
            $this->assertStringContainsString('-w320.webp', $v['srcset']);
            $this->assertStringContainsString(' 640w', $v['srcset']);
            $this->assertFileExists($dir.'/thumbs/'.pathinfo($big, PATHINFO_FILENAME).'-w320.webp');

            $this->assertNull(StorageService::widthVariants('storage/menu/', $small));
            $this->assertNull(StorageService::widthVariants('storage/menu/', 'missing.jpg'));
        } finally {
            File::delete([$dir.'/'.$big, $dir.'/'.$small]);
            File::delete(File::glob($dir.'/thumbs/p6308-*'));
        }
    }
}
