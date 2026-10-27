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
use Juwri\Core\Models\Language;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Ai\Models\TranslationMemory;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * WI-09v characterisation of the owner menu editor, category group
 * (User/Concerns/ManagesMenu: menu, addCategory, updateCategory, deleteCategory,
 * reorderCategory, addSubCategory, updateSubCategory): owner-only, a failed
 * validator flashes its message (form posts) or answers {success:false}
 * (AJAX), the category limit, the editing-language cookie deciding whether a
 * name goes to the base column or only into translations, and the storefront
 * cache being busted on every write.
 */
class OwnerMenuCategoriesContractTest extends TestCase
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

    private function owner(array $settings = []): User
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 't', 'monthly_price' => 1, 'annual_price' => 1,
            'lifetime_price' => 1, 'status' => 1,
            'settings' => $settings + ['restaurant_limit' => 9, 'menu_limit' => 999, 'category_limit' => 999],
        ]);

        return User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
    }

    private function store(User $owner): Store
    {
        return Store::create([
            'user_id' => $owner->id, 'slug' => 'cat-'.Str::lower(Str::random(6)), 'color' => '#111', 'title' => 'R',
            'sub_title' => 't', 'description' => 't', 'main_image' => 'default.png', 'cover_image' => 'default.png',
            'address' => '1', 'phone' => '1',
        ]);
    }

    private function flash(): array
    {
        return [session('app_alert_type'), session('app_alert_message')];
    }

    public function test_menu_page_is_owner_only_and_picks_the_editor_by_template(): void
    {
        $owner = $this->owner();
        $store = $this->store($owner);

        $this->actingAs($this->owner())->get(route('stores.menu', $store->id))->assertNotFound();

        $res = $this->actingAs($owner)->get(route('stores.menu', $store->id))->assertOk()->assertViewIs('owner.menu.index');
        $this->assertSame([], $res->viewData('menu_languages'));
        $this->assertSame([], $res->viewData('default_menu_language'));
        $this->assertTrue($res->viewData('store')->is($store));

        StoreOption::updateStoreOption($store->id, 'store_template', 'flipbook');
        $this->actingAs($owner)->get(route('stores.menu', $store->id))->assertOk()->assertViewIs('owner.menu.image');
    }

    public function test_menu_page_language_defaults_to_the_cookie_then_the_store_default(): void
    {
        $owner = $this->owner();
        $store = $this->store($owner);
        foreach (['en' => 'English', 'fr' => 'French'] as $code => $name) {
            Language::query()->firstOrCreate(['code' => $code], ['name' => $name, 'direction' => 'ltr', 'active' => 1, 'position' => 1]);
        }
        StoreOption::updateStoreOption($store->id, 'menu_languages', json_encode(['en', 'fr']));
        StoreOption::updateStoreOption($store->id, 'default_language', 'en');

        $res = $this->actingAs($owner)->get(route('stores.menu', $store->id))->assertOk();
        $this->assertEqualsCanonicalizing(['en', 'fr'], $res->viewData('menu_languages')->pluck('code')->all());
        $this->assertSame('en', $res->viewData('default_menu_language')->code);

        $_COOKIE['Quick_user_lang_code'] = 'fr';
        $res = $this->actingAs($owner)->get(route('stores.menu', $store->id))->assertOk();
        $this->assertSame('fr', $res->viewData('default_menu_language')->code);

        $_COOKIE['Quick_user_lang_code'] = 'de'; // not a menu language → store default
        $res = $this->actingAs($owner)->get(route('stores.menu', $store->id))->assertOk();
        $this->assertSame('en', $res->viewData('default_menu_language')->code);
    }

    public function test_add_category_contract(): void
    {
        $owner = $this->owner();
        $store = $this->store($owner);
        $before = StorefrontMenuCache::version($store->id);

        $this->actingAs($this->owner())->post(route('stores.addCategory', $store->id), ['name' => 'X'])->assertNotFound(); // BL-043: the owner tenant scope hides the other account's row (404)
        $this->assertSame(0, MenuCategory::count());

        $this->actingAs($owner)->from('/back')->post(route('stores.addCategory', $store->id), ['name' => ''])->assertRedirect('/back');
        $this->assertSame('error', session('app_alert_type'));
        $this->assertStringContainsString('name field is required', session('app_alert_message'));

        $this->actingAs($owner)->from('/back')->post(route('stores.addCategory', $store->id), ['name' => 'Starters', 'image' => ''])
            ->assertRedirect('/back');
        $this->assertSame(['success', ___('Saved Successfully')], $this->flash());
        $cat = MenuCategory::sole();
        $this->assertSame(['Starters', null, $store->id, null], [$cat->getRawOriginal('name'), $cat->image, (int) $cat->store_id, $cat->parent]);
        $this->assertNotSame($before, StorefrontMenuCache::peek($store->id));

        $this->actingAs($owner)->post(route('stores.addCategory', $store->id), ['name' => 'Mains', 'image' => 'pic.png']);
        $this->assertSame('pic.png', MenuCategory::where('name', 'Mains')->value('image'));
    }

    public function test_category_limit_counts_every_category_of_the_store(): void
    {
        $this->requiresEditionPackage('behaviour of an edition package (plans, billing, reseller or marketplace)');
        $owner = $this->owner(['category_limit' => 1]);
        $store = $this->store($owner);
        MenuCategory::create(['store_id' => $store->id, 'name' => 'One']);

        foreach (['stores.addCategory' => ['name' => 'Two'], 'stores.addSubCategory' => ['name' => 'Two', 'parent' => 1]] as $route => $body) {
            $this->actingAs($owner)->post(route($route, $store->id), $body)->assertRedirect();
            $this->assertSame(['error', ___('Limit exceeded, please upgrade your membership.')], $this->flash());
        }
        $this->assertSame(1, MenuCategory::count());
    }

    public function test_update_category_without_a_language_cookie_writes_the_base_name(): void
    {
        $owner = $this->owner();
        $store = $this->store($owner);
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Old', 'image' => 'a.png', 'active' => '0']);
        $before = StorefrontMenuCache::version($store->id);

        $this->actingAs($owner)->post(route('stores.updateCategory', $store->id), [
            'id' => $cat->id, 'name' => 'New',
            'available_from' => '11:00', 'available_to' => '', 'available_days' => ['MON', 'xx', 'fri', 'mon'],
            'available_order_types' => 'Delivery,bogus,dine-in',
        ])->assertRedirect();
        $this->assertSame(['success', ___('Saved Successfully')], $this->flash());
        $cat->refresh();
        $this->assertSame('New', $cat->getRawOriginal('name'));
        $this->assertSame('a.png', $cat->image, 'image absent from the payload keeps the old one');
        $this->assertSame('0', (string) $cat->getRawOriginal('active'), 'active absent keeps the stored value');
        $this->assertSame('11:00', substr((string) $cat->available_from, 0, 5));
        $this->assertNull($cat->available_to);
        $this->assertSame('mon,fri', $cat->available_days);
        $this->assertSame('delivery', $cat->available_order_types);
        $this->assertEquals(new \stdClass, $cat->translations);
        $this->assertNotSame($before, StorefrontMenuCache::peek($store->id));

        $this->actingAs($owner)->post(route('stores.updateCategory', $store->id), ['id' => $cat->id, 'name' => 'New', 'image' => '', 'active' => '1']);
        $cat->refresh();
        $this->assertNull($cat->image);
        $this->assertSame('1', (string) $cat->getRawOriginal('active'));
        $this->assertNull($cat->available_days);
        $this->assertNull($cat->available_from);
    }

    public function test_update_category_with_a_language_cookie(): void
    {
        $owner = $this->owner();
        $store = $this->store($owner);
        StoreOption::updateStoreOption($store->id, 'default_language', 'en');
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Drinks']);

        $_COOKIE['Quick_user_lang_code'] = 'fr';
        $this->actingAs($owner)->post(route('stores.updateCategory', $store->id), ['id' => $cat->id, 'name' => 'Boissons']);
        $cat->refresh();
        $this->assertSame('Drinks', $cat->getRawOriginal('name'));
        $this->assertEquals((object) ['fr' => (object) ['title' => 'Boissons']], $cat->translations);
        $this->assertSame('Boissons', TranslationMemory::where('store_id', $store->id)->where('lang', 'fr')->value('target_text'));
        $this->assertSame('Drinks', TranslationMemory::where('store_id', $store->id)->value('source_text'));

        $_COOKIE['Quick_user_lang_code'] = 'en';
        $this->actingAs($owner)->post(route('stores.updateCategory', $store->id), ['id' => $cat->id, 'name' => 'Beverages']);
        $cat->refresh();
        $this->assertSame('Beverages', $cat->getRawOriginal('name'));
        $this->assertEquals((object) ['fr' => (object) ['title' => 'Boissons'], 'en' => (object) ['title' => 'Beverages']], $cat->translations);
        $this->assertSame(1, TranslationMemory::count());
    }

    public function test_update_category_guards(): void
    {
        $owner = $this->owner();
        $store = $this->store($owner);
        $foreign = MenuCategory::create(['store_id' => $this->store($this->owner())->id, 'name' => 'F']);
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Mine']);

        $this->actingAs($owner)->post(route('stores.updateCategory', $store->id), ['id' => $foreign->id, 'name' => 'Hack'])->assertNotFound();
        $this->actingAs($owner)->post(route('stores.updateCategory', $store->id), ['id' => $cat->id, 'name' => 'x', 'active' => '5'])->assertRedirect();
        $this->assertSame('error', session('app_alert_type'));
        $this->assertStringContainsString('active', session('app_alert_message'));
        $this->actingAs($this->owner())->post(route('stores.updateCategory', $store->id), ['id' => $cat->id, 'name' => 'Hack'])->assertNotFound(); // BL-043: the owner tenant scope hides the other account's row (404)
        $this->assertSame('Mine', $cat->fresh()->getRawOriginal('name'));
    }

    public function test_delete_category_contract(): void
    {
        $owner = $this->owner();
        $store = $this->store($owner);
        $parent = MenuCategory::create(['store_id' => $store->id, 'name' => 'P']);
        $child = MenuCategory::create(['store_id' => $store->id, 'name' => 'C', 'parent' => $parent->id]);

        $this->actingAs($this->owner())->postJson(route('stores.deleteCategory', $store->id), ['id' => $parent->id])
            ->assertNotFound(); // BL-043: the owner tenant scope hides the other account's row (404)
        $this->actingAs($owner)->postJson(route('stores.deleteCategory', $store->id), [])
            ->assertOk()->assertExactJson(['success' => false, 'message' => 'The id field is required.']);
        $this->actingAs($owner)->postJson(route('stores.deleteCategory', $store->id), ['id' => 999999])->assertNotFound();

        $this->actingAs($owner)->postJson(route('stores.deleteCategory', $store->id), ['id' => $parent->id, 'mode' => 'bogus'])
            ->assertOk()->assertJson(['success' => false, 'needs_choice' => true, 'message' => ___('This category has sub-categories.')])
            ->assertJsonPath('subcategories', 1);
        $this->assertNotNull($parent->fresh());

        $before = StorefrontMenuCache::version($store->id);
        $this->actingAs($owner)->postJson(route('stores.deleteCategory', $store->id), ['id' => $parent->id, 'mode' => 'cascade'])
            ->assertOk()->assertExactJson(['success' => true, 'message' => ___('Deleted Successfully')]);
        $this->assertSame(0, MenuCategory::where('store_id', $store->id)->count());
        $this->assertNotSame($before, StorefrontMenuCache::peek($store->id));
    }

    public function test_reorder_category_touches_only_this_stores_rows_and_always_answers_success(): void
    {
        $owner = $this->owner();
        $store = $this->store($owner);
        $a = MenuCategory::create(['store_id' => $store->id, 'name' => 'A', 'position' => 5]);
        $b = MenuCategory::create(['store_id' => $store->id, 'name' => 'B', 'position' => 5]);
        $foreign = MenuCategory::create(['store_id' => $this->store($this->owner())->id, 'name' => 'F', 'position' => 5]);

        $this->actingAs($owner)->postJson(route('stores.reorderCategory', $store->id), ['position' => [$b->id, $foreign->id, $a->id]])
            ->assertOk()->assertExactJson(['success' => true, 'message' => ___('Updated Successfully')]);
        $this->assertSame([2, 0, 5], [(int) $a->fresh()->position, (int) $b->fresh()->position, (int) $foreign->fresh()->position]);

        $this->actingAs($this->owner())->postJson(route('stores.reorderCategory', $store->id), ['position' => [$a->id]])
            ->assertNotFound(); // BL-043: the owner tenant scope hides the other account's row (404)
        $this->assertSame(2, (int) $a->fresh()->position);
        $this->actingAs($owner)->postJson(route('stores.reorderCategory', $store->id), ['position' => 'x'])
            ->assertOk()->assertJson(['success' => true]);
    }

    public function test_sub_category_add_and_update(): void
    {
        $owner = $this->owner();
        $store = $this->store($owner);
        StoreOption::updateStoreOption($store->id, 'default_language', 'en');
        $parent = MenuCategory::create(['store_id' => $store->id, 'name' => 'P']);
        $other = MenuCategory::create(['store_id' => $store->id, 'name' => 'Q']);

        $this->actingAs($owner)->post(route('stores.addSubCategory', $store->id), ['name' => 'Sub'])->assertRedirect();
        $this->assertStringContainsString('parent field is required', session('app_alert_message'));
        $this->actingAs($this->owner())->post(route('stores.addSubCategory', $store->id), ['name' => 'Sub', 'parent' => $parent->id])->assertNotFound(); // BL-043: the owner tenant scope hides the other account's row (404)
        $this->assertSame(0, MenuCategory::where('name', 'Sub')->count());

        $before = StorefrontMenuCache::version($store->id);
        $this->actingAs($owner)->post(route('stores.addSubCategory', $store->id), ['name' => 'Sub', 'parent' => $parent->id]);
        $this->assertSame(['success', ___('Saved Successfully')], $this->flash());
        $sub = MenuCategory::where('name', 'Sub')->sole();
        $this->assertSame([$parent->id, $store->id], [(int) $sub->parent, (int) $sub->store_id]);
        $this->assertNotSame($before, StorefrontMenuCache::peek($store->id));

        $_COOKIE['Quick_user_lang_code'] = 'fr';
        $this->actingAs($owner)->post(route('stores.updateSubCategory', $store->id), ['id' => $sub->id, 'name' => 'Sous', 'parent' => $other->id]);
        $this->assertSame(['success', ___('Saved Successfully')], $this->flash());
        $sub->refresh();
        $this->assertSame(['Sub', $other->id], [$sub->getRawOriginal('name'), (int) $sub->parent]);
        $this->assertEquals((object) ['fr' => (object) ['title' => 'Sous']], $sub->translations);
        $this->assertSame(0, TranslationMemory::count(), 'sub-category edits do not feed the translation memory');

        unset($_COOKIE['Quick_user_lang_code']);
        $this->actingAs($owner)->post(route('stores.updateSubCategory', $store->id), ['id' => $sub->id, 'name' => 'Base', 'parent' => $parent->id]);
        $this->assertSame('Base', $sub->fresh()->getRawOriginal('name'));

        $this->actingAs($owner)->post(route('stores.updateSubCategory', $store->id), ['id' => $sub->id, 'name' => 'x']);
        $this->assertStringContainsString('parent field is required', session('app_alert_message'));
        $this->actingAs($this->owner())->post(route('stores.updateSubCategory', $store->id), ['id' => $sub->id, 'name' => 'x', 'parent' => 1])->assertNotFound(); // BL-043: the owner tenant scope hides the other account's row (404)
        $this->assertSame('Base', $sub->fresh()->getRawOriginal('name'));
    }
}
