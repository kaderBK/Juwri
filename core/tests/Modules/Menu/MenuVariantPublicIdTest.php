<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Menu;

use App\Models\MenuCategory;
use App\Services\StoreCloneService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Menu\Models\MenuVariant;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * P3-02: a priced variant is user-facing (a cart line names it), so it carries a UUIDv7 public id like every other
 * catalogue row: minted on create, backfilled by the migration, fresh on a store duplicate.
 */
class MenuVariantPublicIdTest extends TestCase
{
    use RefreshDatabase;

    private function item(): Menu
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(4), 'description' => 'T', 'monthly_price' => 10, 'annual_price' => 100,
            'lifetime_price' => 1000, 'settings' => ['restaurant_limit' => 5, 'menu_limit' => 999], 'status' => 1,
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => 'vpid-'.Str::lower(Str::random(6)), 'color' => '#111', 'title' => 'V',
            'sub_title' => 'T', 'description' => 'T', 'main_image' => 'default.png', 'cover_image' => 'default.png',
            'address' => '1 St', 'phone' => '1234',
        ]);
        $category = MenuCategory::create(['store_id' => $store->id, 'name' => 'Cat']);

        return Menu::create(['store_id' => $store->id, 'category_id' => $category->id, 'name' => 'Tee', 'price' => 10, 'active' => 1]);
    }

    public function test_a_new_variant_gets_a_uuid_v7_public_id_and_keeps_its_integer_key(): void
    {
        $item = $this->item();
        $variant = MenuVariant::create(['menu_id' => $item->id, 'store_id' => $item->store_id, 'price' => 12, 'options' => ['Size' => 'M']]);

        $this->assertTrue(Str::isUuid((string) $variant->public_id));
        $this->assertSame('7', $variant->public_id[14], 'UUID version 7');
        $this->assertIsInt($variant->id);
        $this->assertTrue($variant->getIncrementing());
    }

    public function test_the_migration_backfills_rows_without_a_public_id(): void
    {
        $item = $this->item();
        $a = MenuVariant::create(['menu_id' => $item->id, 'store_id' => $item->store_id, 'price' => 12]);
        $b = MenuVariant::create(['menu_id' => $item->id, 'store_id' => $item->store_id, 'price' => 13]);
        DB::table('menu_variants')->update(['public_id' => null]);

        $migration = require base_path('app/Modules/Menu/Database/Migrations/2026_10_05_120000_add_public_id_to_menu_variants.php');
        $migration->up();

        $ids = DB::table('menu_variants')->whereIn('id', [$a->id, $b->id])->pluck('public_id');
        $this->assertCount(2, $ids->filter(fn ($id) => Str::isUuid((string) $id)));
        $this->assertCount(2, $ids->unique());
    }

    public function test_a_store_duplicate_gives_its_variants_fresh_public_ids(): void
    {
        $item = $this->item();
        $variant = MenuVariant::create(['menu_id' => $item->id, 'store_id' => $item->store_id, 'price' => 12, 'options' => ['Size' => 'M']]);
        $source = Store::findOrFail($item->store_id);

        app(StoreCloneService::class)->clone($source);

        $copy = Store::where('user_id', $source->user_id)->where('id', '!=', $source->id)->firstOrFail();
        $copied = MenuVariant::query()->whereIn('menu_id', Menu::where('store_id', $copy->id)->pluck('id'))->get();
        $this->assertCount(1, $copied);
        $this->assertTrue(Str::isUuid((string) $copied->first()->public_id));
        $this->assertNotSame($variant->public_id, $copied->first()->public_id);
    }
}
