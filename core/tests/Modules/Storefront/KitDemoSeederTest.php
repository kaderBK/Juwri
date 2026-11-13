<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\MenuCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Services\Store\StoreStatus;
use Juwri\Core\Support\ModuleSeeders;
use Juwri\Modules\Menu\Models\Allergy;
use Juwri\Modules\Menu\Models\Brand;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Menu\Models\ModifierGroup;
use Juwri\Modules\Ordering\Services\Fulfillment\FulfillmentSettingsService;
use Juwri\Modules\Storefront\Database\Seeders\KitDemoSeeder;
use Tests\Support\PinsMiddayClock;
use Tests\TestCase;

/**
 * P3-00: one demo store per new store type from the kit demo JSON (cafe, grocery, services), seeders only.
 */
class KitDemoSeederTest extends TestCase
{
    use PinsMiddayClock;
    use RefreshDatabase;

    private const SLUGS = ['cafe' => 'bloom-coffee-demo', 'grocery' => 'fresh-mart-demo', 'services' => 'sidra-salon-demo'];

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
    }

    private function seedTwice(): void
    {
        $this->seed(KitDemoSeeder::class);
        $this->seed(KitDemoSeeder::class); // idempotent: the second run rebuilds, never duplicates
    }

    private function demo(string $type): Store
    {
        return Store::where('slug', self::SLUGS[$type])->firstOrFail();
    }

    public function test_it_is_a_demo_seeder_so_migrate_fresh_seed_builds_it(): void
    {
        $this->assertContains(KitDemoSeeder::class, ModuleSeeders::ordered('demo'));
        $this->assertSame(['cafe', 'grocery', 'services'], KitDemoSeeder::types());
    }

    public function test_one_store_per_new_type_with_the_kit_catalogue_and_no_duplicates_on_a_rerun(): void
    {
        $this->seedTwice();
        $expected = ['cafe' => [6, 39], 'grocery' => [9, 107], 'services' => [7, 23]];

        foreach ($expected as $type => [$categories, $items]) {
            $store = $this->demo($type);
            $this->assertSame($type, $store->store_type);
            $this->assertSame(1, Store::where('slug', self::SLUGS[$type])->count());
            $this->assertSame($categories, MenuCategory::where('store_id', $store->id)->count(), "$type categories");
            $this->assertSame($items, Menu::where('store_id', $store->id)->count(), "$type items");
            $this->assertSame(0, Menu::onlyTrashed()->where('store_id', $store->id)->count(), "$type left soft-deleted rows");
        }
        $this->assertSame(1, User::where('email', 'kit-demo-owner@demo.test')->count());
    }

    public function test_each_store_offers_exactly_the_modes_of_its_type_and_scheduling_for_slot_modes(): void
    {
        $this->seedTwice();
        $settings = app(FulfillmentSettingsService::class);

        $this->assertEqualsCanonicalizing(['takeaway', 'on-table', 'delivery'], array_values((array) $settings->enabledModes($this->demo('cafe'))));
        $this->assertEqualsCanonicalizing(['delivery', 'pickup_slot'], array_values((array) $settings->enabledModes($this->demo('grocery'))));
        $this->assertEqualsCanonicalizing(['appointment', 'at_home'], array_values((array) $settings->enabledModes($this->demo('services'))));
        $this->assertSame('1', (string) store_options($this->demo('grocery')->id, 'allow_order_scheduling'));
        $this->assertSame('1', (string) store_options($this->demo('services')->id, 'allow_order_scheduling'));
        $this->assertSame('0', (string) store_options($this->demo('cafe')->id, 'allow_order_scheduling'));
    }

    public function test_arabic_translations_reach_the_storefront_shape(): void
    {
        $this->seedTwice();
        $espresso = Menu::where('store_id', $this->demo('cafe')->id)->where('name', 'Espresso')->first();

        $this->assertNotNull($espresso);
        $this->assertSame('Espresso', $espresso->getRawOriginal('name'));
        $this->assertSame('إسبريسو', $espresso->translations->ar->title);
        $this->assertSame('جرعة قصيرة كثيفة من خلطتنا الخاصة.', $espresso->translations->ar->description);
        $this->assertSame('القهوة', MenuCategory::where('store_id', $this->demo('cafe')->id)->orderBy('position')->first()->translations->ar->title);
    }

    public function test_cafe_modifier_library_is_attached_and_allergens_resolve(): void
    {
        $this->seedTwice();
        $store = $this->demo('cafe');
        $groups = ModifierGroup::query()->with('modifiers')->where('store_id', $store->id)->where('is_global', true)->get();

        $this->assertSame(6, $groups->count());
        $size = $groups->firstWhere('title', 'Size');
        $this->assertSame('variant', $size->kind);
        $this->assertTrue((bool) $size->required);
        $this->assertSame(['Small', 'Regular', 'Large'], $size->modifiers->pluck('title')->all());
        $this->assertSame('صغير', $size->modifiers->first()->translations->ar->title);

        $latte = Menu::where('store_id', $store->id)->where('name', 'Latte')->first();
        $this->assertNotNull($latte);
        $this->assertTrue($latte->attachedVariantOptions()->where('modifier_groups.id', $size->id)->exists(), 'Latte lacks the Size group');

        $cake = Menu::where('store_id', $store->id)->where('name', 'Lotus cake slice')->firstOrFail();
        $ids = array_map('intval', explode(',', (string) $cake->allergies));
        $this->assertEqualsCanonicalizing(['Gluten', 'Dairy', 'Eggs'], Allergy::whereIn('id', $ids)->where('store_id', $store->id)->pluck('title')->all());
    }

    public function test_grocery_weighed_items_packs_brands_and_stock(): void
    {
        $this->seedTwice();
        $store = $this->demo('grocery');
        $items = Menu::where('store_id', $store->id)->get();

        $this->assertSame(12, $items->where('pricing_unit', 'kg')->count());
        $bananas = $items->firstWhere('name', 'Bananas');
        $attrs = (array) $bananas->getAttribute('attributes');
        $this->assertTrue($attrs['sold_by_weight']);
        $this->assertSame([250, 500, 1000, 2000], $attrs['weight_steps_g']);
        $this->assertSame('FM-00001', $bananas->external_sku);
        $this->assertTrue((bool) $bananas->is_popular);
        $this->assertTrue((bool) $bananas->track_inventory);
        $this->assertSame(24, (int) $bananas->stock_qty);
        $this->assertGreaterThan(0, Brand::where('store_id', $store->id)->count());
        $this->assertSame(7, $items->filter(fn (Menu $m) => ! empty(((array) $m->getAttribute('attributes'))['compare_at_price'] ?? null))->count(), '7 items on offer');
    }

    public function test_services_keep_duration_deposit_staff_and_the_at_home_flag(): void
    {
        $this->seedTwice();
        $service = Menu::where('store_id', $this->demo('services')->id)->where('name', 'Haircut & blow-dry')->firstOrFail();
        $attrs = (array) $service->getAttribute('attributes');

        $this->assertSame(45, $attrs['duration_min']);
        $this->assertSame(5, $attrs['buffer_min']);
        $this->assertFalse($attrs['at_home_available']);
        $this->assertSame('fixed', ((array) $attrs['deposit'])['type']);
        $this->assertSame(['maha', 'lina', 'sara'], array_column((array) $attrs['staff'], 'slug'));

        $duo = Menu::where('store_id', $this->demo('services')->id)->where('name', 'Mani-pedi duo')->firstOrFail();
        $this->assertTrue(((array) $duo->getAttribute('attributes'))['at_home_available']);
        $this->assertSame(['Classic manicure', 'Classic pedicure'], array_column(json_decode(json_encode(((array) $duo->getAttribute('attributes'))['includes']), true), 'en'));
    }

    public function test_the_demo_storefronts_render_on_their_inherited_templates(): void
    {
        $this->seedTwice();
        foreach (['cafe' => 'matcha', 'grocery' => 'zevoa', 'services' => 'bookla'] as $type => $template) {
            $store = $this->demo($type);
            $this->assertSame($template, store_options($store->id, 'store_template'));
            $this->get('/'.$store->slug)->assertOk();
        }
    }

    public function test_the_opening_hours_are_on_the_stores_one_branch(): void
    {
        $this->seedTwice();
        foreach (array_keys(self::SLUGS) as $type) {
            $store = $this->demo($type);
            $branches = Branch::where('store_id', $store->id)->get();
            $this->assertCount(1, $branches, "{$type}: one branch, not the automatic one plus a second (P3-02)");
            $this->assertIsArray($branches->first()->hours);
            $this->assertSame($branches->first()->id, StoreStatus::for($store)['branch']?->id);
        }
    }
}
