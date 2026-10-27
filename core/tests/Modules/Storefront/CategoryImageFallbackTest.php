<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront;

use App\Http\Middleware\EnsureStaffDashboardPermission;
use App\Http\Middleware\Installed;
use App\Models\MenuCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Juwri\Core\Http\Middleware\Locale;
use Juwri\Core\Models\Language;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Auth\Http\Middleware\Authenticate;
use Juwri\Modules\Auth\Http\Middleware\CheckUserIsBanned;
use Juwri\Modules\Menu\Contracts\StorefrontCatalog;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Storefront\Services\ViewModel\CatalogPresenter;
use Juwri\Modules\Storefront\Services\ViewModel\MoneyFormat;
use Tests\Support\TestPlans;
use Tests\TestCase;

/** BL-007: a category image whose local file is missing renders no image, not a broken one. */
class CategoryImageFallbackTest extends TestCase
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

    private function owner(): User
    {
        $plan = TestPlans::create([
            'name' => 'P '.Str::random(5), 'description' => 'T',
            'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1,
            'settings' => ['restaurant_limit' => 999, 'category_limit' => 999, 'menu_limit' => 999],
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

    private function category(Store $store, string $image): MenuCategory
    {
        $category = MenuCategory::create([
            'store_id' => $store->id, 'name' => 'Mains', 'image' => $image, 'active' => '1', 'position' => 0,
        ]);
        Menu::create([
            'store_id' => $store->id, 'category_id' => $category->id,
            'name' => 'Club', 'price' => 10, 'image' => '', 'active' => '1',
        ]);

        return $category;
    }

    /** @return array<string, mixed> */
    private function catalogRow(Store $store, MenuCategory $category): array
    {
        $catalog = app(StorefrontCatalog::class);
        $presenter = new CatalogPresenter($catalog, $store, new MoneyFormat($store), $store->isFoodVertical(), false);
        $data = $presenter->catalog($catalog->items($store));

        foreach ($data['categories'] as $row) {
            if ($row['id'] === (string) $category->public_id) {
                return $row;
            }
        }

        $this->fail('The category was not in the storefront catalog.');
    }

    public function test_existing_category_image_is_in_the_catalog_row(): void
    {
        $owner = $this->owner();
        $store = $this->store($owner);
        $dir = public_path('storage/menu');
        File::ensureDirectoryExists($dir);
        $file = 'bl007-'.Str::random(8).'.png';
        imagepng(imagecreatetruecolor(10, 10), $dir.'/'.$file);

        try {
            $category = $this->category($store, $file);

            $row = $this->catalogRow($store, $category);

            $this->assertArrayHasKey('image', $row);
            $this->assertIsArray($row['image']);
            $this->assertStringContainsString($file, (string) $row['image']['src']);
            $this->assertNotSame('', $category->fresh()->image_url);
        } finally {
            File::delete($dir.'/'.$file);
        }
    }

    public function test_missing_category_image_is_skipped_and_the_url_is_empty(): void
    {
        $owner = $this->owner();
        $store = $this->store($owner);
        $dir = public_path('storage/menu');
        $file = 'bl007-missing-'.Str::random(8).'.png';
        File::delete($dir.'/'.$file);
        $category = $this->category($store, $file);

        $row = $this->catalogRow($store, $category);

        $this->assertArrayNotHasKey('image', $row);
        $this->assertSame('', $category->fresh()->image_url);
    }

    public function test_default_png_stays_hidden(): void
    {
        $owner = $this->owner();
        $store = $this->store($owner);
        $category = $this->category($store, 'default.png');

        $row = $this->catalogRow($store, $category);

        $this->assertArrayNotHasKey('image', $row);
    }
}
