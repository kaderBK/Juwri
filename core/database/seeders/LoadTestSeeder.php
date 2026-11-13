<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Database\Seeders;

use App\Models\MenuCategory;
use App\Models\StorefrontToken;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Settings\StoreSettings;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Menu\Models\MenuVariantOption;

/**
 * P4-16 load-test data (optional seeder, never part of `migrate --seed`):
 *   php artisan db:seed --class=LoadTestSeeder --force
 * Creates one busy store (LOADTEST_BIG_ITEMS items, default 2,000, in 24 realistic categories) and LOADTEST_STORES normal
 * stores (default 200, 5 categories and ~40 items each), every store with its own owner and two storefront API tokens
 * (read, write). Writes the ids, tokens and owner logins the load scripts need to storage/app/loadtest.json. Seeders and
 * the models only: no factories (the production image has no Faker), no external data. The password is the same for all
 * owners (LOADTEST_PASSWORD, default `Load-Test-12345!`) and exists only on throw-away installs.
 */
class LoadTestSeeder extends Seeder
{
    private const CATEGORIES = [
        'Starters', 'Soups', 'Salads', 'Mezze', 'Grills', 'Kebabs', 'Chicken', 'Seafood', 'Pasta', 'Pizza', 'Burgers',
        'Sandwiches', 'Rice dishes', 'Vegetarian', 'Kids menu', 'Sides', 'Breakfast', 'Hot drinks', 'Cold drinks',
        'Juices', 'Desserts', 'Ice cream', 'Bakery', 'Combos',
    ];

    public function run(): void
    {
        DB::transaction(fn () => $this->seed());
    }

    private function seed(): void
    {
        $bigItems = (int) (getenv('LOADTEST_BIG_ITEMS') ?: 2000);
        $stores = (int) (getenv('LOADTEST_STORES') ?: 200);
        $password = bcrypt((string) (getenv('LOADTEST_PASSWORD') ?: 'Load-Test-12345!'));
        $out = ['password_note' => 'LOADTEST_PASSWORD', 'busy' => null, 'stores' => []];

        $out['busy'] = $this->store('busy', 'Busy Kitchen', $password, count(self::CATEGORIES), (int) ceil($bigItems / count(self::CATEGORIES)), true);
        for ($i = 1; $i <= $stores; $i++) {
            $out['stores'][] = $this->store(sprintf('shop-%03d', $i), 'Shop '.$i, $password, 5, 8, false);
        }

        File::ensureDirectoryExists(storage_path('app'));
        File::put(storage_path('app/loadtest.json'), json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $this->command?->info('load-test data written: busy store '.$bigItems.' items, '.$stores.' stores, storage/app/loadtest.json');
    }

    /** @return array<string, mixed> */
    private function store(string $slug, string $title, string $password, int $categories, int $perCategory, bool $busy): array
    {
        $owner = User::query()->create([
            'name' => $title, 'username' => $slug, 'email' => $slug.'@load.test', 'password' => $password, 'status' => 1,
            'email_verified_at' => now(), 'group_id' => 'free',
        ]);
        $store = Store::query()->create([
            'user_id' => $owner->id, 'slug' => $slug, 'color' => '#2c7656', 'title' => $title, 'sub_title' => 'Load test',
            'description' => 'Load-test store', 'main_image' => 'default.png', 'cover_image' => 'default.png',
            'address' => 'King Fahd Road, Riyadh', 'phone' => '+966112345678', 'store_type' => 'restaurant',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        StoreSettings::setMany((int) $store->id, [
            'store_template' => (string) (getenv('LOADTEST_THEME') ?: 'crave'), 'currency_code' => 'SAR', 'currency_sign' => 'SAR', 'restaurant_delivery_order' => true,
            'restaurant_takeaway_order' => true, 'restaurant_on_table_order' => true, 'restaurant_delivery_charge' => '9',
            'restaurant_taxx_charge' => '15', 'restaurant_tax_type' => 'percentage', 'restaurant_tax_label' => 'VAT',
        ]);

        $names = ['Classic', 'Special', 'House', 'Spicy', 'Garden', 'Royal', 'Smoky', 'Golden'];
        $sample = [];
        $rows = [];
        $n = 0;
        for ($c = 0; $c < $categories; $c++) {
            $category = MenuCategory::query()->create(['store_id' => $store->id, 'name' => self::CATEGORIES[$c % count(self::CATEGORIES)], 'active' => 1]);
            for ($i = 0; $i < $perCategory; $i++) {
                $n++;
                $item = Menu::query()->create([
                    'store_id' => $store->id, 'category_id' => $category->id, 'active' => 1,
                    'name' => $names[$i % count($names)].' '.self::CATEGORIES[$c % count(self::CATEGORIES)].' '.$n,
                    'description' => 'Fresh, made to order. Item '.$n.' of the '.$title.' menu.',
                    'price' => 8 + (($n * 7) % 90),
                ]);
                if ($n % 10 === 0) { // one item in ten has a required size and an optional extra
                    $group = MenuVariantOption::query()->create([
                        'store_id' => $store->id, 'title' => 'Size', 'is_global' => false, 'menu_id' => $item->id,
                        'options' => ['Regular', 'Large'], 'option_prices' => [0.0, 6.0], 'required' => true, 'min_select' => 1,
                        'max_select' => 1, 'position' => 0, 'active' => true,
                    ]);
                    $item->attachedVariantOptions()->syncWithoutDetaching([
                        $group->id => ['position' => 0, 'required' => true, 'min_select' => 1, 'max_select' => 1, 'active' => true],
                    ]);
                }
                if ($n % max(1, intdiv($categories * $perCategory, 12)) === 0) {
                    $sample[] = $item->id;
                }
            }
        }

        $read = 'sfx_load_'.$slug.'_read';
        $write = 'sfx_load_'.$slug.'_write';
        foreach ([[$read, ['menu:read']], [$write, ['order:write']]] as [$raw, $scopes]) {
            StorefrontToken::query()->create([
                'store_id' => $store->id, 'token' => hash('sha256', $raw), 'scopes' => $scopes, 'active' => true,
                'allowed_origins' => [], 'browser' => false,
            ]);
        }

        // plain items (no required choice) the scenarios can put in a cart without option ids
        $plain = Menu::query()->where('store_id', $store->id)->whereNotIn('id', DB::table('item_modifier_group')->select('menu_id'))->limit(12)->pluck('public_id', 'id')->all();

        return [
            'slug' => $slug, 'email' => $slug.'@load.test', 'store_id' => (int) $store->id, 'read' => $read, 'write' => $write,
            'items' => array_values($plain), 'item_pks' => array_map('intval', array_keys($plain)), // POS takes integer ids, the storefront public ids
            'categories' => MenuCategory::query()->where('store_id', $store->id)->pluck('id')->all(),
        ];
    }
}
