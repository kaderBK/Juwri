<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront;

use App\Models\MenuCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Contracts\StorefrontCatalog;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Storefront\Services\ViewModel\CatalogPresenter;
use Juwri\Modules\Storefront\Services\ViewModel\MoneyFormat;
use Tests\Support\TestPlans;
use Tests\TestCase;

/** P6-308: category and item card images use width variants when they exist, otherwise the original upload. */
class CatalogPresenterCardImageTest extends TestCase
{
    use RefreshDatabase;

    private string $prefix = '';

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        $this->prefix = 'p6308-'.Str::lower(Str::random(6));
    }

    private function store(): Store
    {
        $plan = TestPlans::create([
            'name' => 'P '.Str::random(5), 'description' => 'T',
            'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1,
            'settings' => ['restaurant_limit' => 999, 'category_limit' => 999, 'menu_limit' => 999],
            'status' => 1,
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);

        return Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)),
            'color' => '#111', 'title' => 'R', 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'default.png', 'cover_image' => 'default.png', 'address' => '1', 'phone' => '1',
        ]);
    }

    private function image(string $name, int $width, int $height): string
    {
        $dir = public_path('storage/menu');
        File::ensureDirectoryExists($dir);
        imagejpeg(imagecreatetruecolor($width, $height), $dir.'/'.$name);

        return $name;
    }

    private function cleanup(): void
    {
        $dir = public_path('storage/menu');
        File::delete(File::glob($dir.'/'.$this->prefix.'*'));
        File::delete(File::glob($dir.'/thumbs/'.$this->prefix.'*'));
    }

    private function presenter(Store $store): CatalogPresenter
    {
        return new CatalogPresenter(app(StorefrontCatalog::class), $store, new MoneyFormat($store), false, false);
    }

    /** @return array<string, mixed> */
    private function categoryRow(CatalogPresenter $presenter, MenuCategory $category, Menu $item): array
    {
        $rows = collect($presenter->catalog(collect([$item]))['categories'])->keyBy('id');

        return $rows->get((string) $category->public_id);
    }

    public function test_wide_image_gets_srcset_width_variants(): void
    {
        $store = $this->store();
        $image = $this->image($this->prefix.'-wide.jpg', 1000, 700);
        $category = MenuCategory::create([
            'store_id' => $store->id, 'name' => 'Drinks', 'image' => $image, 'active' => '1',
        ]);
        $item = Menu::create([
            'store_id' => $store->id, 'category_id' => $category->id, 'name' => 'Tea', 'price' => 1, 'active' => 1,
        ]);

        try {
            $row = $this->categoryRow($this->presenter($store), $category, $item);

            $this->assertArrayHasKey('image', $row);
            $this->assertSame(['src', 'srcset', 'alt'], array_keys($row['image']));
            $this->assertStringContainsString('-w320.webp', $row['image']['srcset']);
            $this->assertStringContainsString(' 640w', $row['image']['srcset']);
            $this->assertStringContainsString('-w640.webp', $row['image']['src']);
            $this->assertSame('Drinks', $row['image']['alt']);
        } finally {
            $this->cleanup();
        }
    }

    public function test_small_image_falls_back_to_the_original_without_srcset(): void
    {
        $store = $this->store();
        $image = $this->image($this->prefix.'-small.jpg', 200, 140);
        $category = MenuCategory::create([
            'store_id' => $store->id, 'name' => 'Drinks', 'image' => $image, 'active' => '1',
        ]);
        $item = Menu::create([
            'store_id' => $store->id, 'category_id' => $category->id, 'name' => 'Tea', 'price' => 1, 'active' => 1,
        ]);

        try {
            $row = $this->categoryRow($this->presenter($store), $category, $item);

            $this->assertArrayHasKey('image', $row);
            $this->assertSame(['src', 'alt'], array_keys($row['image']));
            $this->assertArrayNotHasKey('srcset', $row['image']);
            $this->assertStringContainsString($image, $row['image']['src']);
            $this->assertSame('Drinks', $row['image']['alt']);
        } finally {
            $this->cleanup();
        }
    }

    public function test_default_or_empty_category_image_is_omitted(): void
    {
        $store = $this->store();
        $default = MenuCategory::create([
            'store_id' => $store->id, 'name' => 'Default', 'image' => 'default.png', 'active' => '1',
        ]);
        $empty = MenuCategory::create([
            'store_id' => $store->id, 'name' => 'Empty', 'image' => '', 'active' => '1',
        ]);
        $items = collect([
            Menu::create([
                'store_id' => $store->id, 'category_id' => $default->id, 'name' => 'A', 'price' => 1, 'active' => 1,
            ]),
            Menu::create([
                'store_id' => $store->id, 'category_id' => $empty->id, 'name' => 'B', 'price' => 1, 'active' => 1,
            ]),
        ]);

        $rows = collect($this->presenter($store)->catalog($items)['categories'])->keyBy('id');

        $this->assertArrayNotHasKey('image', $rows->get((string) $default->public_id));
        $this->assertArrayNotHasKey('image', $rows->get((string) $empty->public_id));
    }
}
