<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Database\Seeders;

use App\Models\Customer;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyProgram;
use App\Models\MenuCategory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\StoreOption;
use App\Models\Story;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Support\ModuleSeeders;
use Juwri\Modules\Customers\Models\CustomerToken;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Menu\Models\MenuExtra;
use Juwri\Modules\Menu\Models\MenuVariantOption;

/**
 * Idempotent demo store that exercises every theme-pack-blade feature (F1-F7).
 *
 * Rerunnable: everything keys on stable natural keys (slug / name / phone /
 * track_token) via updateOrCreate, so `db:seed --class=ThemePackDemoSeeder`
 * can run any number of times without duplicating rows or erroring.
 *
 * Run:  php artisan db:seed --class=ThemePackDemoSeeder
 */
class ThemePackDemoSeeder extends Seeder
{
    private const SLUG = 'themepack-demo';

    private const OWNER_EMAIL = 'themepack-owner@demo.test';

    private const OWNER_PASS = 'password';

    private const CUSTOMER_PHONE = '966500000001';

    // Stable raw customer token (stored hashed) so it can be reported/reused.
    private const CUSTOMER_TOKEN = 'themepack-demo-customer-token-0001';

    private const ORDER_TRACK = 'themepack-demo-order-0001';

    public function run(): void
    {
        ThemeShowcaseSeeder::enablePlatformMarketingForDemo();
        // 0. Plan 2 must exist where plans exist (Billing, ee): see the Billing module's DemoProPlanSeeder.
        //    P1-13: Community has no plans (owners run on the edition plan), so nothing to seed there.
        if ($planSeeder = ModuleSeeders::find('DemoProPlanSeeder')) {
            $this->call($planSeeder, true);
        }

        // 1. Owner user (group_id 2 => Plan 2, status 1 => storefront resolves).
        $owner = User::updateOrCreate(
            ['email' => self::OWNER_EMAIL],
            [
                'username' => 'themepackdemo',
                'name' => 'Theme Pack Demo Owner',
                'password' => Hash::make(self::OWNER_PASS),
                'user_type' => 'user',
                'group_id' => 2,
                'status' => 1,
                'country_code' => 'SA',
                'country' => 'Saudi Arabia',
                'email_verified_at' => now(),
            ]
        );

        // 1b. Restaurant (Post). F2: is_verified => official ✓ badge.
        $store = Store::updateOrCreate(
            ['slug' => self::SLUG],
            [
                'user_id' => $owner->id,
                'title' => 'Theme Pack Demo Kitchen',
                'sub_title' => 'All 7 features in one store',
                'description' => 'Demo restaurant provisioned by ThemePackDemoSeeder.',
                'is_verified' => true,
                'color' => '#e0480f',
                'address' => 'King Fahd Rd, Riyadh',
                'phone' => '966500000000',
                'catalog_visible' => 1,
            ]
        );
        $rid = $store->id;

        // 2. Per-restaurant option flags.
        //    F: theme selection via store_template = crave
        //    F1: meal_plans_enabled  |  F7: require_phone_verification
        foreach ([
            'store_template' => 'crave',
            'meal_plans_enabled' => '1',
            'require_phone_verification' => '1',
            // Ordering must be ON or the storefront hides the menu + product CTA.
            'restaurant_on_table_order' => '1',
            'restaurant_takeaway_order' => '1',
            'restaurant_delivery_order' => '1',
            // F2: the demo kitchen's promo box only renders when the store opts
            //    into coupons (CommerceRuntime reads allow_coupons); the demo
            //    coupons DEMO10 / DEMO5OFF are seeded on the owner by
            //    DemoCatalogSeeder, so this store is where they are redeemed.
            'allow_coupons' => '1',
        ] as $name => $value) {
            StoreOption::updateStoreOption($rid, $name, $value);
        }

        // 4. Two active Riyadh branches a few km apart (F4 nearest-branch map).
        $branchCoords = [
            ['name' => 'Olaya Branch',    'lat' => 24.7136000, 'lng' => 46.6753000],
            ['name' => 'Al Malqa Branch', 'lat' => 24.7500000, 'lng' => 46.7000000],
        ];
        $branchIds = [];
        foreach ($branchCoords as $i => $b) {
            $branch = Branch::updateOrCreate(
                ['store_id' => $rid, 'name' => $b['name']],
                [
                    'address' => $b['name'].', Riyadh',
                    'phone' => '96650000010'.$i,
                    'latitude' => $b['lat'],
                    'longitude' => $b['lng'],
                    'timezone' => 'Asia/Riyadh',
                    'active' => true,
                    'accepting_orders' => true,
                    'position' => $i,
                ]
            );
            $branchIds[] = $branch->id;
        }

        // 5. Categories + menu items (dietary/allergy/calories/prep_time).
        $catStarters = MenuCategory::updateOrCreate(
            ['store_id' => $rid, 'name' => 'Starters'],
            ['position' => 1, 'active' => '1']
        );
        $catMains = MenuCategory::updateOrCreate(
            ['store_id' => $rid, 'name' => 'Mains'],
            ['position' => 2, 'active' => '1']
        );
        $catDrinks = MenuCategory::updateOrCreate(
            ['store_id' => $rid, 'name' => 'Drinks'],
            ['position' => 3, 'active' => '1']
        );

        $items = [
            ['cat' => $catStarters, 'name' => 'Hummus Bowl',       'price' => 18, 'is_vegan' => true,  'is_halal' => true, 'is_gluten_free' => true,  'calories' => 220, 'prep' => 8,  'allergies' => 'sesame',        'popular' => true],
            ['cat' => $catStarters, 'name' => 'Cheese Sambousek',  'price' => 22, 'is_vegan' => false, 'is_halal' => true, 'is_gluten_free' => false, 'calories' => 340, 'prep' => 10, 'allergies' => 'gluten, dairy'],
            ['cat' => $catStarters, 'name' => 'Fattoush Salad',    'price' => 20, 'is_vegan' => true,  'is_halal' => true, 'is_gluten_free' => false, 'calories' => 180, 'prep' => 7,  'allergies' => 'gluten'],
            ['cat' => $catMains,    'name' => 'Chicken Shawarma',  'price' => 30, 'is_vegan' => false, 'is_halal' => true, 'is_gluten_free' => false, 'calories' => 620, 'prep' => 15, 'allergies' => 'gluten, garlic', 'popular' => true, 'variant' => true, 'extras' => true],
            ['cat' => $catMains,    'name' => 'Beef Kabsa',        'price' => 45, 'is_vegan' => false, 'is_halal' => true, 'is_gluten_free' => true,  'calories' => 780, 'prep' => 25, 'allergies' => 'nuts',          'variant' => true],
            ['cat' => $catMains,    'name' => 'Falafel Wrap',      'price' => 24, 'is_vegan' => true,  'is_halal' => true, 'is_gluten_free' => false, 'calories' => 480, 'prep' => 12, 'allergies' => 'gluten, sesame', 'extras' => true],
            ['cat' => $catMains,    'name' => 'Grilled Salmon',    'price' => 60, 'is_vegan' => false, 'is_halal' => true, 'is_gluten_free' => true,  'calories' => 540, 'prep' => 20, 'allergies' => 'fish'],
            ['cat' => $catDrinks,   'name' => 'Fresh Mint Lemonade', 'price' => 14, 'is_vegan' => true,  'is_halal' => true, 'is_gluten_free' => true,  'calories' => 120, 'prep' => 4,  'allergies' => ''],
            ['cat' => $catDrinks,   'name' => 'Saudi Coffee',      'price' => 12, 'is_vegan' => true,  'is_halal' => true, 'is_gluten_free' => true,  'calories' => 20,  'prep' => 5,  'allergies' => 'cardamom'],
        ];

        $menuByName = [];
        foreach ($items as $pos => $it) {
            $menu = Menu::updateOrCreate(
                ['store_id' => $rid, 'name' => $it['name']],
                [
                    'category_id' => $it['cat']->id,
                    'description' => $it['name'].' — demo item.',
                    'price' => $it['price'],
                    'calories' => $it['calories'],
                    'prep_minutes' => $it['prep'],
                    'allergies' => $it['allergies'],
                    'is_vegan' => $it['is_vegan'],
                    'is_halal' => $it['is_halal'],
                    'is_gluten_free' => $it['is_gluten_free'],
                    'is_popular' => $it['popular'] ?? false,
                    'active' => '1',
                    'position' => $pos,
                ]
            );
            $menuByName[$it['name']] = $menu;

            // Variant option group (e.g. size) for flagged items.
            if (! empty($it['variant'])) {
                MenuVariantOption::updateOrCreate(
                    ['store_id' => $rid, 'menu_id' => $menu->id, 'title' => 'Size'],
                    [
                        'is_global' => false,
                        'options' => ['Small', 'Medium', 'Large'],
                        'option_prices' => [0, 5, 10],
                        'required' => true,
                        'min_select' => 1,
                        'max_select' => 1,
                        'active' => 1,
                        'position' => 1,
                    ]
                );
            }

            // Extras / modifiers for flagged items.
            if (! empty($it['extras'])) {
                foreach ([['Extra Garlic Sauce', 3], ['Extra Pickles', 2]] as $ei => $extra) {
                    MenuExtra::updateOrCreate(
                        ['store_id' => $rid, 'menu_id' => $menu->id, 'title' => $extra[0]],
                        ['price' => $extra[1], 'is_global' => false, 'active' => 1, 'position' => $ei]
                    );
                }
            }
        }

        // 6. Customer + token (F7 login / F6 verified purchase).
        $customer = Customer::updateOrCreate(
            ['store_id' => $rid, 'phone' => self::CUSTOMER_PHONE],
            [
                'name' => 'Demo Diner',
                'email' => 'demo-diner@demo.test',
                'password' => Hash::make(self::OWNER_PASS),
                'phone_verified_at' => now(),
                'source' => 'seeder',
            ]
        );

        CustomerToken::updateOrCreate(
            ['customer_id' => $customer->id, 'name' => 'themepack-demo'],
            [
                'token' => hash('sha256', self::CUSTOMER_TOKEN),
                'expires_at' => now()->addYears(5),
            ]
        );

        // 6b. Delivered order containing a specific item -> F6 verified purchase.
        $verifiedItem = $menuByName['Chicken Shawarma'];
        $order = Order::updateOrCreate(
            ['store_id' => $rid, 'track_token' => self::ORDER_TRACK],
            [
                'customer_id' => $customer->id,
                'customer_name' => 'Demo Diner',
                'phone_number' => self::CUSTOMER_PHONE,
                'type' => 'delivery',
                'status' => 'delivered',
                'delivery_status' => 'delivered',
                'is_paid' => 1,
                'branch_id' => $branchIds[0],
                'delivered_at' => now()->subDay(),
                'completed_at' => now()->subDay(),
                'source' => 'seeder',
            ]
        );

        OrderItem::updateOrCreate(
            ['order_id' => $order->id, 'item_id' => $verifiedItem->id],
            ['quantity' => 1, 'status' => 'served']
        );

        // 7. Active Story within its time window (F3).
        Story::updateOrCreate(
            ['store_id' => $rid, 'caption' => 'Try our new Chicken Shawarma!'],
            [
                'media_url' => 'default.png', // WIRING-RUN C6: demo-story.jpg was never shipped, so the demo story rendered a broken frame. default.png is the image the rest of the app already falls back to.
                'cta_label' => 'Order now',
                'cta_url' => '/'.self::SLUG,
                'active' => true,
                'sort' => 1,
                'starts_at' => now()->subDay(),
                'expires_at' => now()->addYear(),
            ]
        );

        // 8. Loyalty program enabled + account for the demo customer.
        $program = LoyaltyProgram::forRestaurant($rid);
        $program->update([
            'type' => 'points',
            'earn_rate' => 1,
            'reward_threshold' => 100,
            'reward_description' => 'Free dish',
            'active' => true,
        ]);
        LoyaltyAccount::forCustomer($customer->id, $rid);

        $this->command?->info('ThemePackDemoSeeder: store "'.self::SLUG.'" (post '.$rid.') ready.');
        $this->command?->info('  Owner: '.self::OWNER_EMAIL.' / '.self::OWNER_PASS);
        $this->command?->info('  Customer phone: '.self::CUSTOMER_PHONE.'  token: '.self::CUSTOMER_TOKEN);
        $this->command?->info('  Storefront: /'.self::SLUG);
    }
}
