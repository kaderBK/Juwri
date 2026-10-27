<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Menu;

use App\Http\Middleware\EnsureStaffDashboardPermission;
use App\Http\Middleware\Installed;
use App\Models\ImageMenu;
use App\Models\MenuCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Http\Middleware\Locale;
use Juwri\Core\Models\Language;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Auth\Http\Middleware\Authenticate;
use Juwri\Modules\Auth\Http\Middleware\CheckUserIsBanned;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Menu\Models\MenuExtra;
use Juwri\Modules\Menu\Models\MenuVariantOption;
use Tests\Support\TestPlans;
use Tests\TestCase;

class MenuImagesTest extends TestCase
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
        // Disable auth + install/locale gates; keep route-model binding (SubstituteBindings) intact.
        $this->withoutMiddleware([
            Authenticate::class, EnsureStaffDashboardPermission::class,
            Installed::class, CheckUserIsBanned::class, Locale::class,
        ]);
    }

    private function makeUser(): User
    {
        $plan = TestPlans::create([
            'name' => 'P '.Str::random(6), 'description' => 'T',
            'monthly_price' => 10, 'annual_price' => 100, 'lifetime_price' => 1000,
            'settings' => json_encode(['restaurant_limit' => 999, 'menu_limit' => 999, 'category_limit' => 999]), 'status' => 1,
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

    public function test_category_image_persists_on_create_and_update(): void
    {
        $owner = $this->makeUser();
        $r = $this->makeRestaurant($owner);

        $this->actingAs($owner)->post(route('stores.addCategory', $r->id), [
            'name' => 'Drinks', 'image' => 'cat1.webp',
        ]);
        $cat = MenuCategory::where('store_id', $r->id)->first();
        $this->assertSame('cat1.webp', $cat->image);

        $this->actingAs($owner)->post(route('stores.updateCategory', $r->id), [
            'id' => $cat->id, 'name' => 'Drinks', 'image' => 'cat2.webp',
        ]);
        $this->assertSame('cat2.webp', $cat->fresh()->image);
    }

    public function test_extra_image_persists(): void
    {
        $owner = $this->makeUser();
        $r = $this->makeRestaurant($owner);
        $cat = MenuCategory::create(['store_id' => $r->id, 'name' => 'C']);
        $menu = Menu::create(['store_id' => $r->id, 'category_id' => $cat->id, 'name' => 'Item', 'price' => 5, 'active' => '1']);

        $this->actingAs($owner)->post(route('stores.menuAddExtra', ['store' => $r->id, 'menu' => $menu->id]), [
            'title' => 'Cheese', 'price' => 1.5, 'image' => 'extra1.webp',
        ]);
        $extra = MenuExtra::where('menu_id', $menu->id)->first();
        $this->assertSame('extra1.webp', $extra->image);

        $this->actingAs($owner)->post(route('stores.menuUpdateExtra', ['store' => $r->id, 'menu' => $menu->id]), [
            'id' => $extra->id, 'title' => 'Cheese', 'price' => 1.5, 'image' => 'extra2.webp',
        ])->assertJson(['success' => true]);
        $this->assertSame('extra2.webp', $extra->fresh()->image);
    }

    public function test_variant_option_images_persist(): void
    {
        $owner = $this->makeUser();
        $r = $this->makeRestaurant($owner);
        $cat = MenuCategory::create(['store_id' => $r->id, 'name' => 'C']);
        $menu = Menu::create(['store_id' => $r->id, 'category_id' => $cat->id, 'name' => 'Item', 'price' => 5, 'active' => '1']);
        $opt = MenuVariantOption::create(['menu_id' => $menu->id, 'title' => 'Size', 'options' => ['Small', 'Large']]);

        $this->actingAs($owner)->post(route('stores.menuUpdateVariantOption', ['store' => $r->id, 'menu' => $menu->id]), [
            'id' => $opt->id, 'title' => 'Size', 'options' => 'Small, Large',
            'option_images' => ['small.webp', 'large.webp'],
        ])->assertJson(['success' => true]);

        $this->assertSame(['small.webp', 'large.webp'], $opt->fresh()->option_images);
    }

    public function test_gallery_filenames_persist_on_item(): void
    {
        $owner = $this->makeUser();
        $r = $this->makeRestaurant($owner);
        $cat = MenuCategory::create(['store_id' => $r->id, 'name' => 'C']);

        $this->actingAs($owner)->post(route('stores.addMenuItem', $r->id), [
            'category_id' => $cat->id, 'name' => 'Pizza', 'price' => 9, 'type' => 'veg',
            'gallery' => ['g1.webp', 'g2.webp'],
        ])->assertJson(['success' => true]);

        $menu = Menu::where('store_id', $r->id)->where('name', 'Pizza')->first();
        $this->assertSame(['g1.webp', 'g2.webp'], $menu->gallery_images);
    }

    public function test_gallery_caps_at_five(): void
    {
        $owner = $this->makeUser();
        $r = $this->makeRestaurant($owner);
        $cat = MenuCategory::create(['store_id' => $r->id, 'name' => 'C']);

        $this->actingAs($owner)->post(route('stores.addMenuItem', $r->id), [
            'category_id' => $cat->id, 'name' => 'Big', 'price' => 9, 'type' => 'veg',
            'gallery' => ['a.webp', 'b.webp', 'c.webp', 'd.webp', 'e.webp', 'f.webp', 'g.webp'],
        ])->assertJson(['success' => true]);

        $menu = Menu::where('store_id', $r->id)->where('name', 'Big')->first();
        $this->assertCount(5, $menu->gallery_images);
    }

    public function test_main_image_from_library_pick_is_saved(): void
    {
        $owner = $this->makeUser();
        $r = $this->makeRestaurant($owner);
        $cat = MenuCategory::create(['store_id' => $r->id, 'name' => 'C']);

        $this->actingAs($owner)->post(route('stores.addMenuItem', $r->id), [
            'category_id' => $cat->id, 'name' => 'Latte', 'price' => 5, 'type' => 'veg',
            'image' => 'lib-pick.webp', // media-library filename, not an upload
        ])->assertJson(['success' => true]);

        $menu = Menu::where('store_id', $r->id)->where('name', 'Latte')->first();
        $this->assertSame('lib-pick.webp', $menu->image);
    }

    public function test_main_image_updated_and_cleared_from_library(): void
    {
        $owner = $this->makeUser();
        $r = $this->makeRestaurant($owner);
        $cat = MenuCategory::create(['store_id' => $r->id, 'name' => 'C']);
        $menu = Menu::create(['store_id' => $r->id, 'category_id' => $cat->id, 'name' => 'Tea', 'price' => 3, 'type' => 'veg', 'image' => 'old.webp']);

        // swap to another library image
        $this->actingAs($owner)->post(route('stores.updateMenuItem', $r->id), [
            'id' => $menu->id, 'category_id' => $cat->id, 'name' => 'Tea', 'price' => 3, 'type' => 'veg',
            'image' => 'new.webp',
        ])->assertJson(['success' => true]);
        $this->assertSame('new.webp', $menu->fresh()->image);

        // Remove clicked → empty image field resets to default
        $this->actingAs($owner)->post(route('stores.updateMenuItem', $r->id), [
            'id' => $menu->id, 'category_id' => $cat->id, 'name' => 'Tea', 'price' => 3, 'type' => 'veg',
            'image' => '',
        ])->assertJson(['success' => true]);
        $this->assertSame('default.png', $menu->fresh()->image);
    }

    public function test_quick_image_updates_only_the_image(): void
    {
        $owner = $this->makeUser();
        $r = $this->makeRestaurant($owner);
        $cat = MenuCategory::create(['store_id' => $r->id, 'name' => 'C']);
        $menu = Menu::create([
            'store_id' => $r->id, 'category_id' => $cat->id, 'name' => 'Mocha', 'price' => 7, 'type' => 'veg',
            'image' => 'old.webp', 'description' => 'rich', 'active' => '1', 'is_popular' => 1,
            'available_days' => 'mon,fri', 'gallery_images' => ['g1.webp'],
        ]);

        $this->actingAs($owner)->post(route('stores.quickImageMenuItem', $r->id), [
            'id' => $menu->id, 'image' => 'fresh.webp',
        ])->assertJson(['success' => true, 'image' => 'fresh.webp']);

        $fresh = $menu->fresh();
        $this->assertSame('fresh.webp', $fresh->image);
        // every other field untouched (a full updateMenuItem would have wiped these)
        $this->assertSame('rich', $fresh->description);
        $this->assertSame('1', (string) $fresh->active);
        $this->assertSame(1, (int) $fresh->is_popular);
        $this->assertSame('mon,fri', $fresh->available_days);
        $this->assertSame(['g1.webp'], $fresh->gallery_images);
    }

    public function test_quick_edit_updates_only_name_and_price(): void
    {
        $owner = $this->makeUser();
        $r = $this->makeRestaurant($owner);
        $cat = MenuCategory::create(['store_id' => $r->id, 'name' => 'C']);
        $menu = Menu::create([
            'store_id' => $r->id, 'category_id' => $cat->id, 'name' => 'Soda', 'price' => 2, 'type' => 'veg',
            'image' => 'keep.webp', 'description' => 'fizzy', 'active' => '1', 'is_popular' => 1,
        ]);

        $this->actingAs($owner)->post(route('stores.quickEditMenuItem', $r->id), [
            'id' => $menu->id, 'name' => 'Cola',
        ])->assertJson(['success' => true, 'name' => 'Cola']);

        $this->actingAs($owner)->post(route('stores.quickEditMenuItem', $r->id), [
            'id' => $menu->id, 'price' => 3.5,
        ])->assertJson(['success' => true, 'price' => 3.5]);

        $fresh = $menu->fresh();
        $this->assertSame('Cola', $fresh->name);
        $this->assertEquals(3.5, (float) $fresh->price);
        // untouched
        $this->assertSame('keep.webp', $fresh->image);
        $this->assertSame('fizzy', $fresh->description);
        $this->assertSame('1', (string) $fresh->active);
        $this->assertSame(1, (int) $fresh->is_popular);
    }

    public function test_quick_edit_rejects_negative_price(): void
    {
        $owner = $this->makeUser();
        $r = $this->makeRestaurant($owner);
        $cat = MenuCategory::create(['store_id' => $r->id, 'name' => 'C']);
        $menu = Menu::create(['store_id' => $r->id, 'category_id' => $cat->id, 'name' => 'X', 'price' => 5, 'type' => 'veg']);

        $this->actingAs($owner)->post(route('stores.quickEditMenuItem', $r->id), [
            'id' => $menu->id, 'price' => -1,
        ])->assertJson(['success' => false]);
        $this->assertEquals(5.0, (float) $menu->fresh()->price);
    }

    public function test_quick_edit_rejects_foreign_restaurant_item(): void
    {
        $owner = $this->makeUser();
        $r = $this->makeRestaurant($owner);
        $other = $this->makeRestaurant($this->makeUser());
        $cat = MenuCategory::create(['store_id' => $other->id, 'name' => 'C']);
        $foreign = Menu::create(['store_id' => $other->id, 'category_id' => $cat->id, 'name' => 'Keep', 'price' => 1, 'type' => 'veg']);

        $this->actingAs($owner)->post(route('stores.quickEditMenuItem', $r->id), [
            'id' => $foreign->id, 'name' => 'Hacked', 'price' => 999,
        ]);
        $this->assertSame('Keep', $foreign->fresh()->name);
    }

    public function test_quick_image_rejects_foreign_restaurant_item(): void
    {
        $owner = $this->makeUser();
        $r = $this->makeRestaurant($owner);
        $other = $this->makeRestaurant($this->makeUser());
        $cat = MenuCategory::create(['store_id' => $other->id, 'name' => 'C']);
        $foreign = Menu::create(['store_id' => $other->id, 'category_id' => $cat->id, 'name' => 'X', 'price' => 1, 'type' => 'veg', 'image' => 'keep.webp']);

        // owner of $r may not touch $other's item
        $this->actingAs($owner)->post(route('stores.quickImageMenuItem', $r->id), [
            'id' => $foreign->id, 'image' => 'hack.webp',
        ]);
        $this->assertSame('keep.webp', $foreign->fresh()->image);
    }

    // The flipbook/pageview image-menu editor submits these as ordinary HTML
    // forms; a form post must redirect (+ flash) rather than dump the JSON body
    // into the browser. AJAX callers keep the JSON contract.

    public function test_delete_image_menu_redirects_for_html_form_post(): void
    {
        $owner = $this->makeUser();
        $r = $this->makeRestaurant($owner);
        $im = ImageMenu::create(['store_id' => $r->id, 'name' => 'Page 1', 'image' => 'p1.jpg', 'active' => '1']);

        $this->actingAs($owner)
            ->post(route('stores.deleteImageMenuItem', $r->id), ['id' => $im->id], ['Accept' => 'text/html'])
            ->assertRedirect() // 302, not a raw JSON page
            ->assertSessionHas('app_alert_type', 'success'); // owner sees a toast

        $this->assertDatabaseMissing('image_menus', ['id' => $im->id]);
    }

    public function test_delete_image_menu_returns_json_for_ajax(): void
    {
        $owner = $this->makeUser();
        $r = $this->makeRestaurant($owner);
        $im = ImageMenu::create(['store_id' => $r->id, 'name' => 'Page 1', 'image' => 'p1.jpg', 'active' => '1']);

        $this->actingAs($owner)
            ->postJson(route('stores.deleteImageMenuItem', $r->id), ['id' => $im->id])
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertDatabaseMissing('image_menus', ['id' => $im->id]);
    }

    public function test_update_image_menu_redirects_for_html_form_post(): void
    {
        $owner = $this->makeUser();
        $r = $this->makeRestaurant($owner);
        $im = ImageMenu::create(['store_id' => $r->id, 'name' => 'Old', 'image' => 'p1.jpg', 'active' => '1']);

        // No file upload — just rename + toggle; exercises the redirect branch.
        $this->actingAs($owner)
            ->post(route('stores.updateImageMenuItem', $r->id), [
                'id' => $im->id, 'name' => 'New', 'active' => '1',
            ], ['Accept' => 'text/html'])
            ->assertRedirect()
            ->assertSessionHas('app_alert_type', 'success');

        $this->assertSame('New', $im->fresh()->name);
    }
}
