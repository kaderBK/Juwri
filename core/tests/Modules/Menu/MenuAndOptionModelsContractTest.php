<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Menu;

use App\Models\MenuBanner;
use App\Models\MenuCategory;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Models\UserOption;
use Juwri\Core\Settings\StoreSettings;
use Juwri\Core\Settings\UserSettings;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Menu\Models\MenuExtra;
use Juwri\Modules\Menu\Models\MenuVariantOption;
use Tests\TestCase;

/**
 * WI-09 models characterisation: the key/value option models (user and
 * store options) and the storefront-language accessors of the menu models
 * (`Quick_user_lang_code` cookie picks an entry of the `translations` column),
 * plus the category → items cascade on delete.
 */
class MenuAndOptionModelsContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        unset($_COOKIE['Quick_user_lang_code']);
    }

    protected function tearDown(): void
    {
        unset($_COOKIE['Quick_user_lang_code']);
        parent::tearDown();
    }

    public function test_user_options_read_and_write(): void
    {
        $user = User::factory()->create();

        $this->assertFalse(UserOption::getUserOption($user->id, 'missing'));
        $this->assertSame('fallback', UserOption::getUserOption($user->id, 'missing', 'fallback'));
        $this->assertFalse(UserOption::getUserOption($user->id, 'missing', ''), 'an empty default reads as false');

        $this->assertTrue(UserOption::updateUserOption($user->id, 'color', 'red'));
        $this->assertSame('red', UserOption::getUserOption($user->id, 'color', 'fallback'));
        $this->assertTrue(UserOption::updateUserOption($user->id, 'color', 'blue'));
        $this->assertSame('blue', UserOption::getUserOption($user->id, 'color'));

        UserOption::updateUserOption($user->id, 'blank', '');
        $this->assertSame('', UserOption::getUserOption($user->id, 'blank', 'fallback'), 'an existing row wins over the default');
        $this->assertSame([$user->id], UserSettings::userIdsWhere('color', fn ($v) => $v === 'blue'));
    }

    public function test_store_options_read_and_write(): void
    {
        $store = Store::factory()->create();

        $this->assertFalse(StoreOption::getStoreOption($store->id, 'missing'));
        $this->assertSame('d', StoreOption::getStoreOption($store->id, 'missing', 'd'));
        $this->assertTrue(StoreOption::updateStoreOption($store->id, 'k', 'v1'));
        $this->assertSame('v1', StoreOption::getStoreOption($store->id, 'k'));
        $this->assertTrue(StoreOption::updateStoreOption($store->id, 'k', json_encode(['a' => 1])));
        $this->assertEquals((object) ['a' => 1], StoreOption::getStoreOption($store->id, 'k'));
        $this->assertSame(json_encode(['a' => 1]), StoreSettings::legacy((int) $store->id, 'k'));
    }

    /** The `translations` column is cast to object: pass the decoded shape. */
    private function translations(array $byLang): array
    {
        return $byLang;
    }

    public function test_menu_models_follow_the_storefront_language_cookie(): void
    {
        $store = Store::factory()->create();
        $category = MenuCategory::create([
            'store_id' => $store->id, 'name' => 'Mains',
            'translations' => $this->translations(['ar' => ['title' => 'رئيسي'], 'fr' => ['title' => '']]),
        ]);
        $menu = Menu::create([
            'store_id' => $store->id, 'category_id' => $category->id, 'name' => 'Burger', 'description' => 'Beef', 'price' => 5, 'active' => '1',
            'translations' => $this->translations(['ar' => ['title' => 'برغر', 'description' => 'لحم']]),
        ]);
        $extra = MenuExtra::create([
            'menu_id' => $menu->id, 'title' => 'Cheese', 'price' => 1, 'active' => 1,
            'translations' => $this->translations(['ar' => ['title' => 'جبنة']]),
        ]);
        $group = MenuVariantOption::create([
            'menu_id' => $menu->id, 'title' => 'Size', 'options' => ['S', 'L'], 'active' => 1,
            'translations' => $this->translations(['ar' => ['title' => 'الحجم', 'options' => ['ص', 'ك']]]),
        ]);
        $banner = MenuBanner::create([
            'store_id' => $store->id, 'title' => 'Sale', 'subtitle' => 'Today', 'active' => 1,
            'translations' => $this->translations(['ar' => ['title' => 'تخفيض', 'subtitle' => 'اليوم']]),
        ]);

        $read = fn () => [
            MenuCategory::find($category->id)->name,
            Menu::find($menu->id)->name,
            Menu::find($menu->id)->description,
            MenuExtra::find($extra->id)->title,
            MenuVariantOption::find($group->id)->title,
            MenuVariantOption::find($group->id)->options,
            MenuBanner::find($banner->id)->title,
            MenuBanner::find($banner->id)->subtitle,
        ];

        $this->assertEquals(['Mains', 'Burger', 'Beef', 'Cheese', 'Size', ['S', 'L'], 'Sale', 'Today'], $read());

        $_COOKIE['Quick_user_lang_code'] = 'ar';
        $this->assertEquals(['رئيسي', 'برغر', 'لحم', 'جبنة', 'الحجم', ['ص', 'ك'], 'تخفيض', 'اليوم'], $read());

        // An empty translation or an unknown language falls back to the base value.
        $_COOKIE['Quick_user_lang_code'] = 'fr';
        $this->assertEquals(['Mains', 'Burger', 'Beef', 'Cheese', 'Size', ['S', 'L'], 'Sale', 'Today'], $read());
    }

    public function test_deleting_a_category_deletes_its_items_and_subcategories_are_ordered(): void
    {
        $store = Store::factory()->create();
        $category = MenuCategory::create(['store_id' => $store->id, 'name' => 'Parent']);
        $second = MenuCategory::create(['store_id' => $store->id, 'name' => 'B', 'parent' => $category->id, 'position' => 2]);
        $first = MenuCategory::create(['store_id' => $store->id, 'name' => 'A', 'parent' => $category->id, 'position' => 1]);
        $this->assertSame([$first->id, $second->id], $category->subcategories->pluck('id')->all());

        $item = Menu::create(['store_id' => $store->id, 'category_id' => $category->id, 'name' => 'X', 'price' => 1, 'active' => '1']);

        $category->delete();

        $this->assertNull(Menu::find($item->id));
    }
}
