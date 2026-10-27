<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Menu;

use App\Http\Middleware\EnsureStaffDashboardPermission;
use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\MenuCategory;
use App\Models\StoreOption;
use App\Services\Storefront\StorefrontMenuCache;
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
 * WI-09v characterisation of the owner menu editor, modifier group
 * (User/Concerns/ManagesMenu: menuItemExtras and the add/update/delete/reorder
 * endpoints for variant options, variants and extras): owner-only, the item
 * must belong to the store, every id from the body is fenced to the store's
 * items, the editing-language cookie, the variant title answer, and which
 * writes bust the storefront cache.
 */
class OwnerMenuModifiersContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        $this->shareLayoutGlobals();
        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
            EnsureStaffDashboardPermission::class,
        ]);
    }

    protected function tearDown(): void
    {
        unset($_COOKIE['Quick_user_lang_code']);
        parent::tearDown();
    }

    private function owner(): User
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 't', 'monthly_price' => 1, 'annual_price' => 1,
            'lifetime_price' => 1, 'status' => 1, 'settings' => ['restaurant_limit' => 9, 'menu_limit' => 999],
        ]);

        return User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
    }

    /** @return array{0: Store, 1: Menu} */
    private function store(User $owner): array
    {
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => 'mod-'.Str::lower(Str::random(6)), 'color' => '#111', 'title' => 'R',
            'sub_title' => 't', 'description' => 't', 'main_image' => 'default.png', 'cover_image' => 'default.png',
            'address' => '1', 'phone' => '1',
        ]);
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'C']);

        return [$store, Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Pizza', 'price' => 10, 'active' => '1'])];
    }

    private function flash(): array
    {
        return [session('app_alert_type'), session('app_alert_message')];
    }

    public function test_extras_page_view_data(): void
    {
        $owner = $this->owner();
        [$store, $item] = $this->store($owner);
        [$otherPost, $otherItem] = $this->store($this->owner());
        $size = MenuVariantOption::create(['menu_id' => $item->id, 'store_id' => $store->id, 'title' => 'Size', 'options' => ['S', 'L']]);
        $crust = MenuVariantOption::create(['menu_id' => $item->id, 'store_id' => $store->id, 'title' => 'Crust', 'options' => ['Thin']]);
        MenuVariant::create(['menu_id' => $item->id, 'price' => 12, 'options' => [$size->id => 1, $crust->id => 0]]);
        MenuVariant::create(['menu_id' => $item->id, 'price' => 9, 'options' => [$size->id => 7]]);
        $lib = MenuExtra::create(['store_id' => $store->id, 'title' => 'Zz lib', 'price' => 1, 'is_global' => true]);
        MenuExtra::create(['store_id' => $store->id, 'title' => 'Aa lib', 'price' => 1, 'is_global' => true]);
        MenuVariantOption::create(['store_id' => $store->id, 'title' => 'Lib group', 'options' => ['x'], 'is_global' => true]);
        $item->attachedExtras()->attach($lib->id, ['position' => 0]);

        $this->actingAs($this->owner())->get(route('stores.menuItemExtras', [$store->id, $item->id]))->assertNotFound();
        $this->actingAs($owner)->get(route('stores.menuItemExtras', [$store->id, $otherItem->id]))->assertNotFound();

        $res = $this->actingAs($owner)->get(route('stores.menuItemExtras', [$store->id, $item->id]))->assertOk()->assertViewIs('owner.menu.extras');
        $this->assertSame(['L, Thin', ''], array_map(fn ($v) => $v->title, $res->viewData('variants')));
        $this->assertSame(['Aa lib', 'Zz lib'], $res->viewData('globalExtras')->pluck('title')->all());
        $this->assertSame(['Lib group'], $res->viewData('globalGroups')->pluck('title')->all());
        $this->assertSame([$lib->id], array_map('intval', $res->viewData('attachedExtraIds')));
        $this->assertSame([], $res->viewData('attachedGroupIds'));
        $this->assertSame([[], []], [$res->viewData('menu_languages'), $res->viewData('default_menu_language')]);
    }

    public function test_variant_option_crud(): void
    {
        $owner = $this->owner();
        [$store, $item] = $this->store($owner);
        [$otherPost, $otherItem] = $this->store($this->owner());
        StoreOption::updateStoreOption($store->id, 'default_language', 'en');

        $this->actingAs($owner)->post(route('stores.menuAddVariantOption', [$store->id, $item->id]), ['title' => 'Size'])->assertRedirect();
        $this->assertSame('error', session('app_alert_type'));
        $this->assertStringContainsString('options field is required', session('app_alert_message'));
        $this->actingAs($this->owner())->post(route('stores.menuAddVariantOption', [$store->id, $item->id]), ['title' => 'Size', 'options' => 'S'])->assertNotFound(); // BL-043: the owner tenant scope hides the other account's row (404)
        $this->actingAs($owner)->post(route('stores.menuAddVariantOption', [$store->id, $otherItem->id]), ['title' => 'Size', 'options' => 'S'])->assertNotFound();

        $before = StorefrontMenuCache::version($store->id);
        $this->actingAs($owner)->post(route('stores.menuAddVariantOption', [$store->id, $item->id]), ['title' => 'Size', 'options' => ' S , M,L ']);
        $this->assertSame(['success', ___('Saved Successfully')], $this->flash());
        $opt = MenuVariantOption::sole();
        $this->assertSame(['Size', ['S', 'M', 'L'], $item->id, $store->id], [$opt->getRawOriginal('title'), $opt->baseList('options'), (int) $opt->menu_id, (int) $opt->store_id]);
        $this->assertNotSame($before, StorefrontMenuCache::peek($store->id));

        // update: fenced, translation cookie, per-value images, active flag
        $foreignOpt = MenuVariantOption::create(['menu_id' => $otherItem->id, 'store_id' => $otherPost->id, 'title' => 'F', 'options' => ['a']]);
        $url = route('stores.menuUpdateVariantOption', [$store->id, $item->id]);
        $this->actingAs($owner)->postJson($url, ['id' => $foreignOpt->id, 'title' => 'X', 'options' => 'a'])->assertNotFound();
        $this->actingAs($owner)->postJson($url, ['id' => $opt->id])
            ->assertOk()->assertExactJson(['success' => false, 'message' => 'The title field is required.<br>The options field is required.']);
        $this->actingAs($this->owner())->postJson($url, ['id' => $opt->id, 'title' => 'X', 'options' => 'a'])
            ->assertNotFound(); // BL-043: the owner tenant scope hides the other account's row (404)

        $_COOKIE['Quick_user_lang_code'] = 'fr';
        $this->actingAs($owner)->postJson($url, ['id' => $opt->id, 'title' => 'Taille', 'options' => 'P, M ,G', 'option_images' => ['', 'm.png', null], 'active' => '1'])
            ->assertOk()->assertExactJson(['success' => true, 'message' => ___('Saved Successfully')]);
        $opt->refresh();
        $this->assertSame(['Size', ['S', 'M', 'L'], [null, 'm.png', null], true],
            [$opt->getRawOriginal('title'), $opt->baseList('options'), $opt->option_images, (bool) $opt->active]);
        $this->assertEquals((object) ['fr' => (object) ['title' => 'Taille', 'options' => ['P', 'M', 'G']]], $opt->translations);

        $_COOKIE['Quick_user_lang_code'] = 'en';
        $this->actingAs($owner)->postJson($url, ['id' => $opt->id, 'title' => 'Sizes', 'options' => 'Small,Large', 'option_images' => ['', '']]);
        $opt->refresh();
        $this->assertSame(['Sizes', ['Small', 'Large'], null, false],
            [$opt->getRawOriginal('title'), $opt->baseList('options'), $opt->option_images, (bool) $opt->active]);

        unset($_COOKIE['Quick_user_lang_code']);
        $opt->update(['option_images' => ['keep.png']]);
        $this->actingAs($owner)->postJson($url, ['id' => $opt->id, 'title' => 'Base', 'options' => 'One']);
        $opt->refresh();
        $this->assertSame(['Base', ['One'], ['keep.png']], [$opt->getRawOriginal('title'), $opt->baseList('options'), $opt->option_images]);

        $this->assertModifierReorderAndDelete(MenuVariantOption::class, $owner, $store, $item, $opt, $foreignOpt,
            'stores.menuReorderVariantOption', 'stores.menuDeleteVariantOption');
    }

    public function test_variant_crud_and_title_answer(): void
    {
        $owner = $this->owner();
        [$store, $item] = $this->store($owner);
        [, $otherItem] = $this->store($this->owner());
        $size = MenuVariantOption::create(['menu_id' => $item->id, 'store_id' => $store->id, 'title' => 'Size', 'options' => ['S', 'L']]);
        $crust = MenuVariantOption::create(['menu_id' => $item->id, 'store_id' => $store->id, 'title' => 'Crust', 'options' => ['Thin', 'Deep']]);

        $this->actingAs($owner)->post(route('stores.menuAddVariant', [$store->id, $item->id]), ['price' => '-1'])->assertRedirect();
        $this->assertStringContainsString('price field must be at least 0', session('app_alert_message'));
        $this->actingAs($this->owner())->post(route('stores.menuAddVariant', [$store->id, $item->id]), ['price' => '1'])->assertNotFound(); // BL-043: the owner tenant scope hides the other account's row (404)
        $this->actingAs($owner)->post(route('stores.menuAddVariant', [$store->id, $otherItem->id]), ['price' => '1'])->assertNotFound();

        $before = StorefrontMenuCache::version($store->id);
        $this->actingAs($owner)->post(route('stores.menuAddVariant', [$store->id, $item->id]), ['price' => '15', 'options' => [$size->id => '1', $crust->id => '0']]);
        $this->assertSame(['success', ___('Saved Successfully')], $this->flash());
        $variant = MenuVariant::sole();
        $this->assertSame([15.0, $item->id], [(float) $variant->price, (int) $variant->menu_id]);
        $this->assertEquals((object) [$size->id => '1', $crust->id => '0'], $variant->options);
        $this->assertNotSame($before, StorefrontMenuCache::peek($store->id));

        $url = route('stores.menuUpdateVariant', [$store->id, $item->id]);
        $foreign = MenuVariant::create(['menu_id' => $otherItem->id, 'price' => 1, 'options' => []]);
        $this->actingAs($owner)->postJson($url, ['id' => $foreign->id, 'price' => '2', 'options' => []])->assertNotFound();
        $this->actingAs($owner)->postJson($url, ['price' => '2'])->assertExactJson(['success' => false, 'message' => 'The id field is required.']);
        $this->actingAs($this->owner())->postJson($url, ['id' => $variant->id, 'price' => '2', 'options' => []])
            ->assertNotFound(); // BL-043: the owner tenant scope hides the other account's row (404)
        $this->actingAs($owner)->postJson($url, ['id' => $variant->id, 'price' => '18', 'options' => [$size->id => '0', $crust->id => '1', 999 => '0', $size->id + 100 => '5'], 'active' => 'on'])
            ->assertOk()->assertExactJson(['success' => true, 'message' => ___('Saved Successfully'), 'title' => 'S, Deep']);
        $variant->refresh();
        $this->assertSame([18.0, true], [(float) $variant->price, (bool) $variant->active]);
        $this->actingAs($owner)->postJson($url, ['id' => $variant->id, 'price' => '18', 'options' => [$size->id => '9']])
            ->assertExactJson(['success' => true, 'message' => ___('Saved Successfully'), 'title' => '']);
        $this->assertFalse((bool) $variant->fresh()->active);

        $this->assertModifierReorderAndDelete(MenuVariant::class, $owner, $store, $item, $variant, $foreign,
            'stores.menuReorderVariant', 'stores.menuDeleteVariant');
    }

    public function test_extra_crud(): void
    {
        $owner = $this->owner();
        [$store, $item] = $this->store($owner);
        [$otherPost, $otherItem] = $this->store($this->owner());
        StoreOption::updateStoreOption($store->id, 'default_language', 'en');

        $this->actingAs($owner)->post(route('stores.menuAddExtra', [$store->id, $item->id]), ['title' => 'Cheese'])->assertRedirect();
        $this->assertStringContainsString('price field is required', session('app_alert_message'));
        $this->actingAs($this->owner())->post(route('stores.menuAddExtra', [$store->id, $item->id]), ['title' => 'Cheese', 'price' => '1'])->assertNotFound(); // BL-043: the owner tenant scope hides the other account's row (404)
        $this->actingAs($owner)->post(route('stores.menuAddExtra', [$store->id, $otherItem->id]), ['title' => 'Cheese', 'price' => '1'])->assertNotFound();

        $before = StorefrontMenuCache::version($store->id);
        $this->actingAs($owner)->post(route('stores.menuAddExtra', [$store->id, $item->id]), ['title' => 'Cheese', 'price' => '1.5', 'image' => '']);
        $this->assertSame(['success', ___('Saved Successfully')], $this->flash());
        $extra = MenuExtra::sole();
        $this->assertSame(['Cheese', 1.5, null, $item->id, $store->id], [$extra->getRawOriginal('title'), (float) $extra->price, $extra->image, (int) $extra->menu_id, (int) $extra->store_id]);
        $this->assertNotSame($before, StorefrontMenuCache::peek($store->id));
        $this->actingAs($owner)->post(route('stores.menuAddExtra', [$store->id, $item->id]), ['title' => 'Olives', 'price' => '1', 'image' => 'o.png']);
        $this->assertSame('o.png', MenuExtra::where('title', 'Olives')->value('image'));

        $url = route('stores.menuUpdateExtra', [$store->id, $item->id]);
        $foreign = MenuExtra::create(['menu_id' => $otherItem->id, 'store_id' => $otherPost->id, 'title' => 'F', 'price' => 1]);
        $this->actingAs($owner)->postJson($url, ['id' => $foreign->id, 'title' => 'X', 'price' => '1'])->assertNotFound();
        $this->actingAs($owner)->postJson($url, ['id' => $extra->id, 'title' => 'X'])->assertExactJson(['success' => false, 'message' => 'The price field is required.']);
        $this->actingAs($this->owner())->postJson($url, ['id' => $extra->id, 'title' => 'X', 'price' => '1'])
            ->assertNotFound(); // BL-043: the owner tenant scope hides the other account's row (404)

        $extra->update(['image' => 'e.png']);
        $_COOKIE['Quick_user_lang_code'] = 'fr';
        $this->actingAs($owner)->postJson($url, ['id' => $extra->id, 'title' => 'Fromage', 'price' => '2', 'active' => '1'])
            ->assertOk()->assertExactJson(['success' => true, 'message' => ___('Saved Successfully')]);
        $extra->refresh();
        $this->assertSame(['Cheese', 2.0, 'e.png', true], [$extra->getRawOriginal('title'), (float) $extra->price, $extra->image, (bool) $extra->active]);
        $this->assertEquals((object) ['fr' => (object) ['title' => 'Fromage']], $extra->translations);

        unset($_COOKIE['Quick_user_lang_code']);
        $this->actingAs($owner)->postJson($url, ['id' => $extra->id, 'title' => 'Feta', 'price' => '2', 'image' => '']);
        $extra->refresh();
        $this->assertSame(['Feta', null, false], [$extra->getRawOriginal('title'), $extra->image, (bool) $extra->active]);

        $this->assertModifierReorderAndDelete(MenuExtra::class, $owner, $store, $item, $extra, $foreign,
            'stores.menuReorderExtra', 'stores.menuDeleteExtra');
    }

    /** Reorder + delete share one contract for the three modifier kinds. */
    private function assertModifierReorderAndDelete(string $class, User $owner, Store $store, Menu $item, $mine, $foreign, string $reorder, string $delete): void
    {
        $mine->update(['position' => 7]);
        $foreign->update(['position' => 7]);
        $this->actingAs($owner)->postJson(route($reorder, [$store->id, $item->id]), ['position' => [$foreign->id, $mine->id]])
            ->assertOk()->assertExactJson(['success' => true, 'message' => ___('Updated Successfully')]);
        $this->assertSame([1, 7], [(int) $mine->fresh()->position, (int) $foreign->fresh()->position]);
        $this->actingAs($this->owner())->postJson(route($reorder, [$store->id, $item->id]), ['position' => [$mine->id]])
            ->assertNotFound(); // BL-043: the owner tenant scope hides the other account's row (404)
        $this->assertSame(1, (int) $mine->fresh()->position);

        $this->actingAs($owner)->postJson(route($delete, [$store->id, $item->id]), [])
            ->assertExactJson(['success' => false, 'message' => 'The id field is required.']);
        $this->actingAs($this->owner())->postJson(route($delete, [$store->id, $item->id]), ['id' => $mine->id])
            ->assertNotFound(); // BL-043: the owner tenant scope hides the other account's row (404)
        $this->assertNotNull($class::find($mine->id));
        $this->actingAs($owner)->postJson(route($delete, [$store->id, $item->id]), ['id' => $foreign->id])
            ->assertOk()->assertExactJson(['success' => true, 'message' => ___('Deleted Successfully')]);
        $this->assertNotNull($class::find($foreign->id), 'a foreign row is silently left alone');
        $this->actingAs($owner)->postJson(route($delete, [$store->id, $item->id]), ['id' => $mine->id])
            ->assertExactJson(['success' => true, 'message' => ___('Deleted Successfully')]);
        $this->assertNull($class::find($mine->id));
    }
}
