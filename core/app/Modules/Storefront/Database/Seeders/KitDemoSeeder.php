<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Database\Seeders;

use App\Models\MenuCategory;
use App\Models\StoreOption;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\EntitlementOverride;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Support\ModuleSeeders;
use Juwri\Modules\Menu\Models\Allergy;
use Juwri\Modules\Menu\Models\Brand;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Menu\Models\MenuExtra;
use Juwri\Modules\Menu\Models\MenuVariantOption;
use Juwri\Modules\Menu\Models\Modifier;
use Juwri\Modules\Menu\Models\ModifierGroup;

/**
 * Phase 3 (P3-00) — one demo store per NEW store type, from the kit's demo content
 * (database/seeders/data/kit_demo/{cafe,grocery,services}.json, validated by tools/themes/tools/demo_check.py):
 * Bloom Coffee (cafe), Fresh Mart (grocery), Sidra Salon (services). Seeders only (J19); images are generated
 * placeholders until the image step.
 *
 * Idempotent: a store is found by its slug and its catalogue (categories, items, modifier library, allergens, brands)
 * is wiped and rebuilt on every run, like VerticalShowcaseSeeder. Runs with `migrate:fresh --seed` outside production.
 *
 * The storefront theme stays an inherited one of the type's theme set until the kit theme has its views
 * (brew P3-07, basket P3-09, book P3-11): CAFE/GROCERY/SERVICES below carry both. P3-05+ switches
 * `store_template` to the kit slug and preset (store.theme / store.preset in the JSON).
 *
 * Fields the data model has no column for yet are kept in `items.attributes` (README field mapping, "new field"):
 * services duration / buffer / deposit / pay options / at-home flag / staff, grocery pack size / unit / weighed
 * steps / origin / storage / nutrition. Services `option_library` durations (`duration_add_min`) and the staff
 * roster wait for the booking engine (P3-10).
 *
 *   php artisan db:seed --class=KitDemoSeeder
 */
class KitDemoSeeder extends Seeder
{
    private const OWNER_EMAIL = 'kit-demo-owner@demo.test';

    private const OWNER_PASSWORD = 'kit-demo-pass';

    /** type => [data file, inherited template used until the kit theme lands, brand colour] */
    private const STORES = [
        'cafe' => ['file' => 'cafe', 'template' => 'matcha', 'color' => '#3f7d4e'],
        'grocery' => ['file' => 'grocery', 'template' => 'zevoa', 'color' => '#2f9e44'],
        'services' => ['file' => 'services', 'template' => 'bookla', 'color' => '#a05a7a'],
    ];

    private const DAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

    /** Store types the seeder builds, in order. */
    public static function types(): array
    {
        return array_keys(self::STORES);
    }

    public function run(): void
    {
        if ($planSeeder = ModuleSeeders::find('DemoProPlanSeeder')) {
            $this->call($planSeeder, true);
        }

        $owner = User::updateOrCreate(
            ['email' => self::OWNER_EMAIL],
            [
                'username' => 'kitdemoowner', 'name' => 'Kit Demo Owner',
                'password' => Hash::make(self::OWNER_PASSWORD), 'user_type' => 'user',
                'group_id' => 2, 'status' => 1, 'country_code' => 'SA', 'country' => 'Saudi Arabia',
                'email_verified_at' => now(),
            ]
        );

        // Non-restaurant stores and slot-based fulfilment (pickup slots, appointments, at home) are entitlement-gated.
        foreach (['multi_vertical', 'scheduled_orders'] as $feature) {
            EntitlementOverride::updateOrCreate(
                ['user_id' => $owner->id, 'feature_key' => $feature],
                ['value' => '1', 'source_type' => 'admin']
            );
        }

        foreach (self::STORES as $type => $meta) {
            $data = json_decode((string) file_get_contents(database_path('seeders/data/kit_demo/'.$meta['file'].'.json')), true, 512, JSON_THROW_ON_ERROR);
            $this->buildStore($owner, $type, $meta, $data);
        }
    }

    /**
     * @param  array<string, mixed>  $meta
     * @param  array<string, mixed>  $data
     */
    private function buildStore(User $owner, string $type, array $meta, array $data): void
    {
        $s = $data['store'];
        $image = $this->placeholder('kit-demo-'.$type.'.png', $meta['color'], (string) $s['name']['en']);

        $store = Store::updateOrCreate(
            ['slug' => $s['slug']],
            [
                'user_id' => $owner->id, 'store_type' => $type,
                'title' => $s['name']['en'], 'sub_title' => $s['tagline']['en'], 'description' => $s['description']['en'],
                'address' => $s['address']['en'], 'phone' => preg_replace('/\D+/', '', (string) $s['phone']),
                'timing' => $this->timingText($s['hours']['weekly']),
                'color' => $meta['color'], 'catalog_visible' => 1, 'is_verified' => true,
                'main_image' => $image['logo'], 'cover_image' => $image['cover'],
            ]
        );
        $rid = (int) $store->id;

        $modes = array_values(array_intersect((array) $s['order_types'], (array) config("verticals.types.$type.fulfillment", [])));
        $slotModes = array_filter($modes, fn (string $m) => (bool) config("verticals.fulfillment.$m.needs_slot"));

        $options = [
            'store_template' => $meta['template'],
            'demo_showcase' => '1',
            'customer_auth_mode' => 'demo',
            'menu_languages' => json_encode(['en', 'ar']),
            'default_language' => 'en',
            'fulfillment_enabled_modes' => json_encode($modes),
            'allow_order_scheduling' => $slotModes !== [] ? '1' : '0',
            'timezone' => (string) $s['hours']['timezone'],
            'currency_code' => (string) $s['currency'],
            'restaurant_tax_type' => 'percentage',
            'restaurant_taxx_charge' => (string) $s['tax']['rate'],
            'restaurant_tax_label' => 'VAT',
        ];
        if (config("verticals.types.$type.group") === 'food') {
            $options += [
                'restaurant_on_table_order' => in_array('on-table', $modes, true) ? '1' : '0',
                'restaurant_takeaway_order' => in_array('takeaway', $modes, true) ? '1' : '0',
                'restaurant_delivery_order' => in_array('delivery', $modes, true) ? '1' : '0',
            ];
        }
        if (isset($s['delivery']['fee'])) {
            $options['restaurant_delivery_charge'] = (string) (int) round((float) $s['delivery']['fee']);
        }
        if (isset($s['delivery']['free_over'])) {
            $options['restaurant_free_delivery_over'] = (string) $s['delivery']['free_over']; // "free delivery over X" (the demo's own number)
        }
        if (isset($s['booking']['slot_step_min'])) {
            $options['scheduling_slot_interval'] = (string) (int) $s['booking']['slot_step_min'];
        }
        foreach ($options as $key => $value) {
            StoreOption::updateStoreOption($rid, $key, $value);
        }

        // P3-02: the hours go on the store's own first branch (Store::created makes "<name> — Main"); a second
        // "Main Branch" row left the primary branch without hours, so the storefront never showed opening times.
        $branch = Branch::where('store_id', $rid)->orderBy('position')->orderBy('id')->first() ?? new Branch(['store_id' => $rid]);
        $branch->fill(['address' => $s['address']['en'], 'phone' => preg_replace('/\D+/', '', (string) $s['phone']),
            'latitude' => 24.7136, 'longitude' => 46.6753, 'timezone' => (string) $s['hours']['timezone'],
            'hours' => $this->branchHours($s['hours']['weekly']),
            'active' => true, 'accepting_orders' => true, 'position' => 0]);
        $branch->name = $branch->name ?: 'Main Branch';
        $branch->save();

        $this->wipeCatalogue($rid);

        $allergenIds = [];
        foreach ($data['allergen_catalog'] ?? [] as $i => $a) {
            $allergen = Allergy::create([
                'store_id' => $rid, 'title' => $a['title']['en'], 'position' => $i, 'active' => '1',
                'translations' => ['ar' => ['title' => $a['title']['ar']]],
            ]);
            $allergenIds[$a['key']] = $allergen->id;
        }

        $groups = [];
        foreach (($data['modifier_library'] ?? $data['option_library'] ?? []) as $i => $g) {
            $groups[$g['key']] = $this->makeGroup($rid, $g, $i);
        }

        $categoryIds = [];
        foreach ($data['categories'] as $c) {
            $categoryIds[$c['slug']] = MenuCategory::create([
                'store_id' => $rid, 'name' => $c['name']['en'], 'position' => $c['position'], 'active' => '1',
                'translations' => ['ar' => ['title' => $c['name']['ar']]],
            ])->id;
        }

        $brandIds = [];
        $staff = [];
        foreach ($data['staff'] ?? [] as $member) {
            $staff[$member['slug']] = ['slug' => $member['slug'], 'name' => $member['name'], 'role' => $member['role'], 'service_categories' => $member['service_categories'], 'days_off' => $member['days_off']];
        }
        $names = [];
        foreach ($data['items'] as $item) {
            $names[$item['slug']] = $item['name'];
        }

        foreach ($data['items'] as $item) {
            $brandId = null;
            if (! empty($item['brand'])) {
                $brandId = $brandIds[$item['brand']] ??= Brand::create([
                    'store_id' => $rid, 'name' => $item['brand'], 'slug' => Str::slug($item['brand']),
                    'active' => true, 'position' => count($brandIds),
                ])->id;
            }
            $menu = $this->makeItem($rid, $type, $item, $categoryIds[$item['category']], $brandId, $allergenIds, $staff, $names, $image['item']);

            foreach (array_merge($item['modifier_groups'] ?? [], $item['option_groups'] ?? []) as $position => $key) {
                $this->attach($menu, $groups[$key], $position);
            }
        }
    }

    /** Wipe-and-rebuild: children first, then the catalogue rows of this store only. */
    private function wipeCatalogue(int $rid): void
    {
        $itemIds = Menu::withTrashed()->where('store_id', $rid)->pluck('id');
        DB::table('item_modifier_group')->whereIn('menu_id', $itemIds)->delete();
        Modifier::query()->where('store_id', $rid)->delete();
        ModifierGroup::query()->where('store_id', $rid)->delete();
        Menu::withTrashed()->where('store_id', $rid)->forceDelete();
        MenuCategory::where('store_id', $rid)->delete();
        Brand::where('store_id', $rid)->delete();
        Allergy::where('store_id', $rid)->delete();
    }

    /**
     * One library group of the store (`menu_id` null, global). Variant groups go through the legacy positional model
     * (options / option_prices), add-on groups are a group row plus one add-on row per option.
     *
     * @param  array<string, mixed>  $g
     */
    private function makeGroup(int $rid, array $g, int $position): ModifierGroup
    {
        $opts = $g['modifiers'] ?? $g['options'];
        $translations = ['title' => $g['title']['ar']];

        if ($g['kind'] === 'variant') {
            $translations['options'] = array_map(fn (array $o) => $o['title']['ar'], $opts);

            return MenuVariantOption::create([
                'store_id' => $rid, 'title' => $g['title']['en'], 'is_global' => true, 'menu_id' => null,
                'options' => array_map(fn (array $o) => $o['title']['en'], $opts),
                'option_prices' => array_map(fn (array $o) => (float) $o['price'], $opts),
                'required' => $g['required'], 'min_select' => $g['min_select'], 'max_select' => $g['max_select'],
                'position' => $position, 'active' => true,
                'translations' => ['ar' => $translations],
            ]);
        }

        $group = ModifierGroup::create([
            'store_id' => $rid, 'kind' => ModifierGroup::KIND_ADDON, 'is_global' => true, 'menu_id' => null,
            'title' => $g['title']['en'], 'required' => $g['required'], 'min_select' => $g['min_select'], 'max_select' => $g['max_select'],
            'position' => $position, 'active' => true, 'translations' => ['ar' => ['title' => $g['title']['ar']]],
        ]);
        foreach ($opts as $i => $o) {
            MenuExtra::create([
                'store_id' => $rid, 'modifier_group_id' => $group->id, 'is_global' => true,
                'title' => $o['title']['en'], 'price' => $o['price'], 'position' => $i, 'active' => true,
                'translations' => ['ar' => ['title' => $o['title']['ar']]],
            ]);
        }

        return $group;
    }

    /** Attach a library group to an item the way the owner editor does (variant group whole, add-ons one by one). */
    private function attach(Menu $menu, ModifierGroup $group, int $position): void
    {
        if ($group->kind === ModifierGroup::KIND_VARIANT) {
            $menu->attachedVariantOptions()->syncWithoutDetaching([
                $group->id => ['position' => $position, 'required' => $group->required, 'min_select' => $group->min_select, 'max_select' => $group->max_select, 'active' => true],
            ]);

            return;
        }
        $rows = [];
        foreach (Modifier::query()->where('modifier_group_id', $group->id)->orderBy('position')->get() as $i => $modifier) {
            $rows[$modifier->id] = ['position' => $position * 100 + $i, 'active' => true];
        }
        $menu->attachedExtras()->syncWithoutDetaching($rows);
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  array<string, int>  $allergenIds
     * @param  array<string, array<string, mixed>>  $staff
     * @param  array<string, array<string, string>>  $names
     */
    private function makeItem(int $rid, string $type, array $item, int $categoryId, ?int $brandId, array $allergenIds, array $staff, array $names, string $image): Menu
    {
        $badges = (array) ($item['badges'] ?? []);
        $attributes = array_filter([
            'slug' => $item['slug'],
            'badge' => in_array('new', $badges, true) ? 'new' : null,
            'compare_at_price' => $item['compare_at_price'] ?? null,
            'origin' => $item['origin'] ?? null,
            'storage' => $item['storage'] ?? null,
            'pack_size' => $item['pack_size'] ?? null,
            'pack_unit' => $item['pack_unit'] ?? null,
            'sold_by_weight' => $item['sold_by_weight'] ?? null,
            'weight_steps_g' => $item['weight_steps_g'] ?? null,
            'nutrition_per_100g' => $item['nutrition_per_100g'] ?? null,
            'image_prompt' => $item['image_prompt'] ?? null,
        ], fn ($v) => $v !== null);

        if ($type === 'services') {
            $attributes += [
                'duration_min' => $item['duration_min'], 'buffer_min' => $item['buffer_min'], 'deposit' => $item['deposit'],
                'pay_options' => $item['pay_options'], 'at_home_available' => (bool) $item['at_home_available'],
                'staff' => array_values(array_map(fn (string $slug) => $staff[$slug], $item['staff'])),
                'includes' => array_values(array_map(fn (string $slug) => $names[$slug], $item['includes'] ?? [])),
            ];
        }

        $nutrition = $item['nutrition_per_100g'] ?? [];
        $stock = $item['stock_qty'] ?? null;

        return Menu::create([
            'store_id' => $rid, 'category_id' => $categoryId, 'brand_id' => $brandId,
            'name' => $item['name']['en'], 'description' => $item['description']['en'],
            'translations' => ['ar' => ['title' => $item['name']['ar'], 'description' => $item['description']['ar']]],
            'price' => $item['price'], 'image' => $image,
            'type' => $item['type'] ?? 'veg',
            'is_vegan' => (bool) ($item['is_vegan'] ?? false), 'is_halal' => (bool) ($item['is_halal'] ?? false),
            'is_gluten_free' => (bool) ($item['is_gluten_free'] ?? false), 'spice_level' => (int) ($item['spice_level'] ?? 0),
            'calories' => $item['calories'] ?? ($nutrition['calories'] ?? null),
            'protein' => $nutrition['protein'] ?? null, 'carbs' => $nutrition['carbs'] ?? null, 'fat' => $nutrition['fat'] ?? null,
            'prep_minutes' => $item['prep_minutes'] ?? null,
            'allergies' => $allergenIds === [] || empty($item['allergens'])
                ? null
                : implode(',', array_map(fn (string $k) => $allergenIds[$k], $item['allergens'])),
            'pricing_unit' => $item['pricing_unit'] ?? null,
            'external_sku' => $item['sku'] ?? null, 'barcode' => $item['barcode'] ?? null,
            'track_inventory' => (bool) ($item['track_inventory'] ?? false),
            'stock_qty' => $stock, 'low_stock_threshold' => $item['low_stock_threshold'] ?? 5,
            'is_sold_out' => in_array('sold_out', $badges, true),
            'is_popular' => in_array('popular', $badges, true),
            'attributes' => $attributes ?: null,
            'position' => $item['position'], 'active' => 1,
        ]);
    }

    /**
     * kit weekly hours ({day: [[open, close], ...]}) -> branches.hours ({day: {open, close, closed}}).
     * A close of 00:00 means midnight: 23:59 (the structured model has no next-day close).
     *
     * @param  array<string, list<array{0: string, 1: string}>>  $weekly
     * @return array<string, array<string, mixed>>
     */
    private function branchHours(array $weekly): array
    {
        $out = [];
        foreach (self::DAYS as $day) {
            $ranges = $weekly[$day] ?? [];
            if ($ranges === []) {
                $out[$day] = ['closed' => true];

                continue;
            }
            $close = (string) end($ranges)[1];
            $out[$day] = ['open' => (string) $ranges[0][0], 'close' => $close === '00:00' ? '23:59' : $close, 'closed' => false];
        }

        return $out;
    }

    /** @param  array<string, list<array{0: string, 1: string}>>  $weekly */
    private function timingText(array $weekly): string
    {
        $hours = $this->branchHours($weekly);
        $first = $hours['sun']['open'] ?? null;
        $uniform = $first !== null && count(array_unique(array_map(fn ($h) => ($h['open'] ?? '').'-'.($h['close'] ?? ''), $hours))) === 1;

        return $uniform ? 'Daily, '.$hours['sun']['open'].' - '.$hours['sun']['close'] : 'See opening hours';
    }

    /**
     * A flat brand-colour tile (GD) used as logo, cover and item picture until the image step. Falls back to the
     * stock default picture when GD is missing.
     *
     * @return array{logo: string, cover: string, item: string}
     */
    private function placeholder(string $filename, string $hex, string $label): array
    {
        if (! function_exists('imagecreatetruecolor')) {
            return ['logo' => 'default.png', 'cover' => 'default.png', 'item' => 'default.png'];
        }

        $dirs = ['logo' => 'store/logo', 'cover' => 'store/cover', 'item' => 'menu'];
        foreach ($dirs as $dir) {
            $abs = public_path('storage/'.$dir);
            @mkdir($abs, 0755, true);
            if (is_file($abs.'/'.$filename)) {
                continue;
            }
            [$r, $g, $b] = sscanf($hex, '#%02x%02x%02x') ?: [60, 60, 60];
            $img = imagecreatetruecolor(640, 640);
            imagefill($img, 0, 0, imagecolorallocate($img, $r, $g, $b));
            $text = imagecolorallocate($img, 255, 255, 255);
            $label = strtoupper(preg_replace('/[^A-Za-z0-9 ]+/', '', $label) ?: 'DEMO');
            imagestring($img, 5, max(10, (int) ((640 - 9 * strlen($label)) / 2)), 310, $label, $text);
            imagepng($img, $abs.'/'.$filename);
            imagedestroy($img);
        }

        return ['logo' => $filename, 'cover' => $filename, 'item' => $filename];
    }
}
