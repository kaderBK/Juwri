<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Integrations;

use App\Models\MenuCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Integrations\Services\Import\GloriaFoodImporter;
use Juwri\Modules\Menu\Models\Menu;
use Tests\Support\TestPlans;
use Tests\TestCase;

class GloriaFoodImportTest extends TestCase
{
    use RefreshDatabase;

    private function storeFixture(): Store
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 'T', 'monthly_price' => 1, 'annual_price' => 1,
            'lifetime_price' => 1, 'status' => 1, 'settings' => json_encode(['restaurant_limit' => 999, 'menu_limit' => 999, 'category_limit' => 999]),
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);

        return Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'd.png',
            'cover_image' => 'd.png', 'address' => '1', 'phone' => '1', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function csv(): string
    {
        return "Category,Item Name,Description,Price\n"
            ."Burgers,Classic Burger,Juicy beef,\$9.50\n"
            ."Burgers,Cheese Burger,With cheese,10.00\n"
            ."Drinks,Cola,,2.5\n";
    }

    public function test_parse_maps_flexible_headers_and_price(): void
    {
        $parsed = new GloriaFoodImporter()->parse($this->csv());
        $this->assertCount(3, $parsed['rows']);
        $this->assertSame('Classic Burger', $parsed['rows'][0]['name']);
        $this->assertEqualsWithDelta(9.5, $parsed['rows'][0]['price'], 0.001); // currency stripped
        $this->assertSame('Burgers', $parsed['rows'][0]['category']);
    }

    public function test_preview_counts_without_writing(): void
    {
        $r = $this->storeFixture();
        $preview = new GloriaFoodImporter()->preview($r->id, $this->csv());

        $this->assertSame(2, $preview['categories_created']); // Burgers, Drinks
        $this->assertSame(3, $preview['items_created']);
        $this->assertSame(0, Menu::where('store_id', $r->id)->count()); // nothing written
    }

    public function test_import_creates_menu_and_is_idempotent(): void
    {
        $r = $this->storeFixture();
        $importer = new GloriaFoodImporter;

        $batch = $importer->import($r->id, $this->csv());
        $this->assertSame(2, $batch->categories_created);
        $this->assertSame(3, $batch->items_created);
        $this->assertSame(3, Menu::where('store_id', $r->id)->count());
        $this->assertSame(2, MenuCategory::where('store_id', $r->id)->count());

        // Re-upload the same file → idempotent (same batch, no duplicates).
        $again = $importer->import($r->id, $this->csv());
        $this->assertSame($batch->id, $again->id);
        $this->assertSame(3, Menu::where('store_id', $r->id)->count());
    }

    public function test_existing_items_are_skipped_not_duplicated(): void
    {
        $r = $this->storeFixture();
        $cat = MenuCategory::create(['store_id' => $r->id, 'name' => 'Burgers', 'position' => 1]);
        Menu::create(['store_id' => $r->id, 'category_id' => $cat->id, 'name' => 'Classic Burger', 'price' => 8, 'active' => 1]);

        $batch = new GloriaFoodImporter()->import($r->id, $this->csv());
        $this->assertSame(1, $batch->skipped); // Classic Burger already existed
        $this->assertSame(1, Menu::where('store_id', $r->id)->where('name', 'Classic Burger')->count());
    }

    public function test_bad_file_reports_error(): void
    {
        $r = $this->storeFixture();
        $preview = new GloriaFoodImporter()->preview($r->id, 'just one line, no headers we know');
        $this->assertNotEmpty($preview['errors']);
    }
}
