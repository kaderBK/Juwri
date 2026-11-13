<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Verticals;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use Juwri\Modules\Menu\Models\Brand;
use Juwri\Core\Models\EntitlementOverride;
use Juwri\Modules\Menu\Models\Menu;
use App\Models\MenuCategory;
use Juwri\Modules\Menu\Models\MenuVariant;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use App\Services\FeatureAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * rev/multi-vertical Lane A7 — import/export catalog CSV (WORK-ITEMS A7).
 *
 * Reuses the ImportsMenuCsvCore engine (extended additively with optional
 * brand/barcode/weight/dimensions/attributes/variants columns) — export a
 * catalog, re-import the exact file it wrote, and the catalog must be
 * unchanged: same item count, same brand/attributes/variants, no duplicates.
 */
class RetailCatalogCsvRoundTripTest extends TestCase
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

    private function makeOwnerAndStore(): array
    {
        $plan = TestPlans::create(['name' => 'P'.Str::random(5), 'description' => 'T', 'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1, 'settings' => ['restaurant_limit' => 5, 'menu_limit' => 999, 'category_limit' => 999], 'status' => 1]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        EntitlementOverride::create(['user_id' => $owner->id, 'feature_key' => 'multi_vertical', 'value' => '1', 'source_type' => 'admin']);
        FeatureAccess::flush();
        $title = 'S'.Str::random(6);
        $store = Store::create(['user_id' => $owner->id, 'store_type' => 'retail', 'slug' => Str::slug($title).'-'.Str::random(5), 'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'default.png', 'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '1234', 'created_at' => now(), 'updated_at' => now()]);

        return [$owner, $store];
    }

    public function test_export_then_reimport_a_20_product_catalog_is_unchanged(): void
    {
        [$owner, $store] = $this->makeOwnerAndStore();
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Widgets']);
        $brand = Brand::create(['store_id' => $store->id, 'name' => 'Acme']);

        for ($i = 1; $i <= 20; $i++) {
            $item = Menu::create([
                'store_id' => $store->id,
                'category_id' => $cat->id,
                'name' => "Product $i",
                'price' => 10 + $i,
                'brand_id' => $brand->id,
                'weight' => 1.5,
                'dimensions' => ['length' => 10, 'width' => 5, 'height' => 2, 'unit' => 'cm'],
                'attributes' => ['color' => 'blue', 'sku_prefix' => "P$i"],
                'barcode' => "BC-$i",
            ]);
            if ($i <= 5) {
                MenuVariant::create(['menu_id' => $item->id, 'price' => 10 + $i, 'options' => (object) ['Size' => 'M'], 'sku' => "P$i-M", 'stock_qty' => 3, 'barcode' => "V-$i"]);
            }
        }

        $this->assertSame(20, Menu::where('store_id', $store->id)->count());

        $export = $this->actingAs($owner)->get(route('retail.products.exportCsv', $store));
        $export->assertOk();
        $csv = $export->streamedContent();
        $this->assertNotEmpty($csv);

        $file = UploadedFile::fake()->createWithContent('catalog.csv', $csv);
        $import = $this->actingAs($owner)->post(route('retail.products.importCsv', $store), ['csv_file' => $file]);
        $import->assertRedirect();

        // Unchanged: still 20 items (no duplicates), same brand/attributes/variants.
        $this->assertSame(20, Menu::where('store_id', $store->id)->count());

        $reimported = Menu::where('store_id', $store->id)->where('name', 'Product 3')->first();
        $this->assertNotNull($reimported);
        $this->assertSame($brand->id, $reimported->brand_id);
        $this->assertSame(['color' => 'blue', 'sku_prefix' => 'P3'], $reimported->attributes);
        $this->assertSame('BC-3', $reimported->barcode);
        $this->assertSame(1, $reimported->variants()->count());
        $this->assertSame('P3-M', $reimported->variants()->first()->sku);
    }

    public function test_export_neutralizes_formula_cells(): void
    {
        [$owner, $store] = $this->makeOwnerAndStore();
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Widgets']);
        foreach (['=HYPERLINK("http://x")', '+1', '-1', '@SUM(1)'] as $i => $evil) {
            Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => $evil, 'price' => 5, 'barcode' => 'B'.$i]);
        }

        $csv = $this->actingAs($owner)->get(route('retail.products.exportCsv', $store))->assertOk()->streamedContent();
        $rows = array_map(fn ($l) => str_getcsv($l, ',', '"', '\\'), array_filter(explode("\n", ltrim($csv, "\xEF\xBB\xBF"))));
        $names = array_column($rows, 2);
        foreach (['=HYPERLINK("http://x")', '+1', '-1', '@SUM(1)'] as $evil) {
            $this->assertContains("'".$evil, $names);
            $this->assertNotContains($evil, $names);
        }
    }

    public function test_formula_looking_names_round_trip_through_export_and_import(): void
    {
        [$owner, $store] = $this->makeOwnerAndStore();
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Deals']);
        $names = ['-20% Deal', '=Special', '+Plus', '@Home', "'Quoted"];
        foreach ($names as $i => $n) {
            Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => $n, 'price' => 5, 'barcode' => 'R'.$i]);
        }

        $csv = $this->actingAs($owner)->get(route('retail.products.exportCsv', $store))->assertOk()->streamedContent();
        $file = UploadedFile::fake()->createWithContent('catalog.csv', $csv);
        $this->actingAs($owner)->post(route('retail.products.importCsv', $store), ['csv_file' => $file])->assertRedirect();

        $this->assertEqualsCanonicalizing($names, Menu::where('store_id', $store->id)->get()->map->getRawOriginal('name')->all());
    }

    public function test_import_creates_a_brand_by_name_and_a_variant_matrix(): void
    {
        [$owner, $store] = $this->makeOwnerAndStore();
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Shirts']);

        $variants = json_encode([
            ['options' => ['Size' => 'S'], 'price' => 20, 'sku' => 'TS-S', 'stock_qty' => 4, 'barcode' => 'B-S'],
            ['options' => ['Size' => 'M'], 'price' => 20, 'sku' => 'TS-M', 'stock_qty' => 6, 'barcode' => 'B-M'],
        ]);

        $rows = "id,category_id,name,description,price,cost,active,brand,barcode,weight,dimensions,attributes,variants\n";
        $rows .= '"","'.$cat->id.'","T-Shirt","","25","","1","Threadworks","TS-BARCODE","0.3","{""length"":1}","{""fabric"":""cotton""}","'.str_replace('"', '""', $variants)."\"\n";

        $file = UploadedFile::fake()->createWithContent('catalog.csv', $rows);
        $this->actingAs($owner)->post(route('retail.products.importCsv', $store), ['csv_file' => $file])->assertRedirect();

        $item = Menu::where('store_id', $store->id)->where('name', 'T-Shirt')->first();
        $this->assertNotNull($item);
        $this->assertSame('Threadworks', $item->brand->name ?? null);
        $this->assertSame('TS-BARCODE', $item->barcode);
        $this->assertSame(['fabric' => 'cotton'], $item->attributes);
        $this->assertSame(2, $item->variants()->count());
    }
}
