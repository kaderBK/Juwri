<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Menu;

use App\Http\Middleware\EnsureStaffDashboardPermission;
use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * P6-275: menu CSV import from another platform. Excel in ar/fr/de writes ';'
 * and non-English header aliases, prices come locale-formatted ("12,50" /
 * "1,500") and files are often Windows-1256. The importer must sniff the
 * delimiter, normalise common header aliases, parse locale prices, and convert
 * the text to UTF-8 — skipping (not inventing) rows whose price is not a number.
 */
class MenuCsvImportLocaleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';

        $settings = new class(['theme_color' => '#111', 'site_title' => 'T', 'meta_description' => 'T', 'social_share_image' => 'default.png', 'userlangsel' => 0, 'include_language_code' => 0, 'site_favicon' => 'favicon.png'])
        {
            public function __construct(private array $data) {}

            public function __get(string $key): mixed
            {
                return $this->data[$key] ?? null;
            }

            public function __set(string $key, mixed $value): void
            {
                $this->data[$key] = $value;
            }

            public function __isset(string $key): bool
            {
                return array_key_exists($key, $this->data);
            }
        };
        view()->share(['activeTheme' => active_theme(), 'activeThemeAssets' => active_theme(true), 'settings' => $settings, 'languages' => collect(), 'pages' => collect()]);

        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
            EnsureStaffDashboardPermission::class,
        ]);
    }

    private function makeUser(): User
    {
        $plan = TestPlans::create(['name' => 'P'.Str::random(4), 'description' => 'T', 'monthly_price' => 10, 'annual_price' => 100, 'lifetime_price' => 1000, 'settings' => ['restaurant_limit' => 5, 'menu_limit' => 999], 'status' => 1]);

        return User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
    }

    private function makeRestaurant(User $owner): Store
    {
        $title = 'R'.Str::random(6);

        return Store::create(['user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::random(5), 'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'default.png', 'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '1234', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function import(User $owner, Store $store, string $content)
    {
        Storage::fake('local');
        $file = UploadedFile::fake()->createWithContent('menu.csv', $content);

        return $this->actingAs($owner)->post(route('stores.menuImportCsv', $store), ['csv_file' => $file]);
    }

    public function test_semicolon_delimited_file_imports(): void
    {
        $owner = $this->makeUser();
        $store = $this->makeRestaurant($owner);

        $this->import($owner, $store, "Item Name;Price\nKabsa;12.50")
            ->assertOk()
            ->assertJson(['success' => true, 'imported' => 1]);

        $this->assertDatabaseHas('items', [
            'store_id' => $store->id,
            'name' => 'Kabsa',
            'price_minor' => 1250,
        ]);
    }

    public function test_common_header_aliases_are_recognised(): void
    {
        $owner = $this->makeUser();
        $store = $this->makeRestaurant($owner);

        // "Item Name" / "Price" instead of the internal name / price.
        $this->import($owner, $store, "Item Name,Price\nLatte,4.00")
            ->assertOk()
            ->assertJson(['success' => true, 'imported' => 1]);

        $this->assertDatabaseHas('items', [
            'store_id' => $store->id,
            'name' => 'Latte',
            'price_minor' => 400,
        ]);
    }

    public function test_decimal_comma_price_is_parsed(): void
    {
        $owner = $this->makeUser();
        $store = $this->makeRestaurant($owner);

        $this->import($owner, $store, "Item Name;Price\nMezze;12,50")
            ->assertOk()
            ->assertJson(['success' => true, 'imported' => 1]);

        $this->assertDatabaseHas('items', [
            'store_id' => $store->id,
            'name' => 'Mezze',
            'price_minor' => 1250,
        ]);
    }

    public function test_thousands_separator_price_is_parsed(): void
    {
        $owner = $this->makeUser();
        $store = $this->makeRestaurant($owner);

        $this->import($owner, $store, "Item Name;Price\nBulk;1,500")
            ->assertOk()
            ->assertJson(['success' => true, 'imported' => 1]);

        $this->assertDatabaseHas('items', [
            'store_id' => $store->id,
            'name' => 'Bulk',
            'price_minor' => 150000,
        ]);
    }

    public function test_unparsable_price_row_is_skipped_with_an_error(): void
    {
        $owner = $this->makeUser();
        $store = $this->makeRestaurant($owner);

        $response = $this->import($owner, $store, "Item Name,Price\nBad,SAR x\nGood,5.00")
            ->assertOk()
            ->assertJson(['success' => true, 'skipped' => 1]);

        $this->assertStringContainsString('Row 2', (string) $response->json('message'), 'The skipped row must be reported.');
        $this->assertDatabaseMissing('items', ['store_id' => $store->id, 'name' => 'Bad']);
        $this->assertDatabaseHas('items', ['store_id' => $store->id, 'name' => 'Good']);
    }

    public function test_windows_1256_arabic_name_is_stored_as_utf8(): void
    {
        $owner = $this->makeUser();
        $store = $this->makeRestaurant($owner);

        $name = 'كبسة';
        $csv = iconv('UTF-8', 'CP1256', "Item Name,Price\n{$name},10.00");

        $this->import($owner, $store, $csv)
            ->assertOk()
            ->assertJson(['success' => true, 'imported' => 1]);

        $this->assertSame(
            $name,
            Menu::where('store_id', $store->id)->where('name', $name)->value('name'),
            'The Windows-1256 name must be readable UTF-8 after import.'
        );
    }
}
