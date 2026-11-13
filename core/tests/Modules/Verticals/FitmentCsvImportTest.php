<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Verticals;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use Juwri\Core\Models\EntitlementOverride;
use Juwri\Modules\Menu\Models\Menu;
use App\Models\MenuCategory;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use App\Models\VehicleFitment;
use App\Services\FeatureAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * rev/multi-vertical Lane E4 — fitment CSV import (O-ACES default: a flat
 * fitment sheet for ONE part; full ACES/PIES ingest deferred). Mirrors
 * ImportsMenuCsvCore's parsing shape (BOM strip, header-driven columns,
 * per-row validation) but fitment rows are their OWN file/table — a
 * dedicated parser in FitmentController, not a fork of that trait.
 */
class FitmentCsvImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
    }

    private function makeUser(): User
    {
        $plan = TestPlans::create(['name' => 'P'.Str::random(5), 'description' => 'T', 'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1, 'settings' => ['restaurant_limit' => 5, 'menu_limit' => 999], 'status' => 1]);

        $user = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        EntitlementOverride::create(['user_id' => $user->id, 'feature_key' => 'multi_vertical', 'value' => '1', 'source_type' => 'admin']);
        FeatureAccess::flush();

        return $user;
    }

    private function makeStore(User $owner): Store
    {
        $title = 'S'.Str::random(6);

        return Store::create(['user_id' => $owner->id, 'store_type' => 'carparts', 'slug' => Str::slug($title).'-'.Str::random(5), 'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'default.png', 'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '1234', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function makePart(Store $store): Menu
    {
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Brakes']);

        return Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Brake Pad Set', 'price' => 39.99, 'active' => 1]);
    }

    public function test_import_a_hundred_row_fitment_csv(): void
    {
        $owner = $this->makeUser();
        $store = $this->makeStore($owner);
        $part = $this->makePart($store);

        $rows = ['year,make,model,submodel,engine,notes'];
        for ($year = 2000; $year < 2100; $year++) {
            $rows[] = "{$year},Ford,F-150,XLT,5.0L,imported";
        }
        // 100 data rows (2000..2099).
        $file = UploadedFile::fake()->createWithContent('fitments.csv', implode("\n", $rows));

        $this->actingAs($owner)->post(route('retail.fitments.importCsv', [$store, $part]), [
            'csv_file' => $file,
        ])->assertRedirect();

        $this->assertSame(100, VehicleFitment::where('menu_id', $part->id)->count());
    }

    public function test_import_skips_rows_missing_or_invalid_required_columns(): void
    {
        $owner = $this->makeUser();
        $store = $this->makeStore($owner);
        $part = $this->makePart($store);

        $csv = "year,make,model\n2020,Ford,F-150\n,,\n1800,Ford,F-150\n";
        $file = UploadedFile::fake()->createWithContent('fitments.csv', $csv);

        $this->actingAs($owner)->post(route('retail.fitments.importCsv', [$store, $part]), [
            'csv_file' => $file,
        ])->assertRedirect();

        // Only the valid 2020 row survives; the blank row and the out-of-range
        // 1800 year are both skipped.
        $this->assertSame(1, VehicleFitment::where('menu_id', $part->id)->count());
    }

    public function test_import_rejects_a_csv_missing_required_headers(): void
    {
        $owner = $this->makeUser();
        $store = $this->makeStore($owner);
        $part = $this->makePart($store);

        $file = UploadedFile::fake()->createWithContent('fitments.csv', "make,model\nFord,F-150\n");

        $this->actingAs($owner)->post(route('retail.fitments.importCsv', [$store, $part]), [
            'csv_file' => $file,
        ])->assertRedirect();

        $this->assertSame(0, VehicleFitment::where('menu_id', $part->id)->count());
    }

    public function test_a_non_carparts_store_cannot_import_fitments(): void
    {
        $owner = $this->makeUser();
        $store = Store::create(['user_id' => $owner->id, 'store_type' => 'retail', 'slug' => Str::slug('R'.Str::random(6)).'-'.Str::random(5), 'color' => '#111', 'title' => 'R', 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'default.png', 'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '1234', 'created_at' => now(), 'updated_at' => now()]);
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Cat']);
        $part = Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Item', 'price' => 10, 'active' => 1]);

        $file = UploadedFile::fake()->createWithContent('fitments.csv', "year,make,model\n2020,Ford,F-150\n");

        $this->actingAs($owner)->post(route('retail.fitments.importCsv', [$store, $part]), [
            'csv_file' => $file,
        ])->assertNotFound();
    }
}
