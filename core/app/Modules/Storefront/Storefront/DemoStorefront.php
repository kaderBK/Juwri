<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Storefront;

use App\Models\MenuBanner;
use App\Models\MenuCategory;
use App\Services\Verticals\VerticalRegistry;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Language;
use Juwri\Core\Models\Store;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Menu\Models\MenuExtra;
use Juwri\Modules\Menu\Models\MenuVariant;
use Juwri\Modules\Menu\Models\MenuVariantOption;

/**
 * P4-5: the seed dataset every theme preview renders against — buyers try a
 * theme, reviewers exercise it, and the quality gate audits it, all WITHOUT
 * touching live data. Everything is in-memory (unsaved models), deterministic,
 * and deliberately exercises the full Drop contract: categories, variants
 * (combination maps), additive groups, extras, banners, flags, RTL language.
 */
class DemoStorefront
{
    /**
     * Theme Studio B1c: the demo datasets. `restaurant` is the original food
     * dataset (vars() with no argument, unchanged); the others are vertical
     * stores built on the same scaffolding (options, languages, hours,
     * social, banner) with their own store_type, catalogue and features.
     */
    public const VERTICALS = ['restaurant', 'retail', 'pharmacy', 'laundry', 'hotel', 'carparts'];

    /**
     * @param  string  $vertical  one of self::VERTICALS; anything else is the restaurant dataset
     * @return array<string,mixed> render-site vars in the ThemeContext::build shape
     */
    public static function vars(string $vertical = 'restaurant'): array
    {
        return match ($vertical) {
            'retail' => self::retail(),
            'pharmacy' => self::pharmacy(),
            'laundry' => self::laundry(),
            'hotel' => self::hotel(),
            'carparts' => self::carparts(),
            default => self::resolveStore(),
        };
    }

    /** @return array<string,mixed> the original food dataset */
    protected static function resolveStore(): array
    {
        $store = new Store([
            'title' => 'Demo Bistro', 'slug' => 'demo-bistro',
            'sub_title' => 'Preview data — not a real restaurant',
            'description' => 'A seeded storefront for theme previews.',
            'address' => '1 Preview Lane', 'phone' => '+100000000', 'color' => '#3fd463',
        ]);
        $store->id = 0;
        $store->main_image = 'default.png';

        // Size dimension + two priced combinations (the real data shape).
        $size = new MenuVariantOption(['title' => 'Size', 'options' => ['Regular', 'Large']]);
        $size->id = 9001;
        $small = new MenuVariant(['price' => 9.5, 'options' => [9001 => 0]]);
        $small->id = 9101;
        $large = new MenuVariant(['price' => 13.5, 'options' => [9001 => 1]]);
        $large->id = 9102;

        $sauce = new MenuVariantOption(['title' => 'Sauce', 'options' => ['Garlic', 'Spicy'], 'option_prices' => [0, 0.75]]);
        $sauce->id = 9002;
        $sauce->required = false;
        $sauce->min_select = 0;
        $sauce->max_select = 2;

        $cheese = new MenuExtra(['title' => 'Extra cheese', 'price' => 1.25]);
        $cheese->id = 9201;

        $mains = new MenuCategory(['position' => 1]);
        $mains->id = 9301;
        $mains->name = 'Mains';
        $drinks = new MenuCategory(['position' => 2]);
        $drinks->id = 9302;
        $drinks->name = 'Drinks';

        $burger = new Menu(['price' => 9.5]);
        $burger->id = 9401;
        $burger->name = 'Signature Burger';
        $burger->description = 'Chargrilled, brioche bun, house pickles.';
        $burger->image = 'default.png';
        $burger->is_popular = true;
        $burger->setRelation('category', $mains);
        $burger->setRelation('variantOptions', collect([$size]));
        $burger->setRelation('variants', collect([$small, $large]));
        $burger->setRelation('extras', collect([$cheese]));
        $burger->setRelation('globalGroups', collect([$sauce]));

        $salad = new Menu(['price' => 7.0]);
        $salad->id = 9402;
        $salad->name = 'Garden Salad';
        $salad->description = 'Seasonal greens, lemon dressing.';
        $salad->image = 'default.png';
        $salad->is_vegan = true;
        $salad->setRelation('category', $mains);
        $salad->setRelation('variantOptions', collect());
        $salad->setRelation('variants', collect());
        $salad->setRelation('extras', collect());
        $salad->setRelation('globalGroups', collect());

        $tea = new Menu(['price' => 3.0]);
        $tea->id = 9403;
        $tea->name = 'Mint Tea';
        $tea->description = 'Fresh mint, lightly sweetened.';
        $tea->image = 'default.png';
        $tea->setRelation('category', $drinks);
        $tea->setRelation('variantOptions', collect());
        $tea->setRelation('variants', collect());
        $tea->setRelation('extras', collect());
        $tea->setRelation('globalGroups', collect());

        $banner = new MenuBanner(['title' => 'Happy Hour', 'subtitle' => 'Every day 4–6pm', 'image' => 'default.png', 'active' => true]);
        $banner->id = 9501;

        $en = new Language(['code' => 'en', 'name' => 'English', 'direction' => 'ltr']);
        $ar = new Language(['code' => 'ar', 'name' => 'العربية', 'direction' => 'rtl']);

        $branch = new Branch(['name' => 'Main Branch', 'active' => true, 'accepting_orders' => true]);
        $branch->id = 9601;

        return [
            'store' => $store,
            'postOptions' => (object) [
                'currency_sign' => '$', 'currency_pos' => '1',
                'restaurant_taxx_charge' => 10, 'restaurant_tax_label' => 'Tax',
                'restaurant_delivery_charge' => 2.5, 'min_order_value' => 0,
                'allow_call_waiter' => 1, 'allow_coupons' => 1, 'allow_tips' => 1,
            ],
            'total_menus' => [9401 => $burger, 9402 => $salad, 9403 => $tea],
            'allergies' => collect(),
            'menuBanners' => collect([$banner]),
            'branches' => collect([$branch]),
            'activeBranch' => null,
            'table' => null,
            'customer' => null,
            'menu_languages' => collect([$en, $ar]),
            'default_menu_language' => $en,
            'scans_today' => 128,
            'flags' => [
                'allow_order' => true, 'on_table' => true, 'takeaway' => true,
                'delivery' => true, 'payment' => false, 'scheduling' => false,
            ],
            // Theme Studio B0.6: preview values for the extras globals.
            'extras' => [
                'hours' => [
                    'is_open' => true, 'manual_closed' => false, 'state' => 'open',
                    'today' => '09:00–23:00', 'closes_at' => '23:00', 'opens_at' => null, 'has_hours' => true,
                    'week' => array_map(
                        fn ($d) => ['day' => $d, 'closed' => $d === 'mon', 'open' => $d === 'mon' ? null : '09:00', 'close' => $d === 'mon' ? null : '23:00'],
                        ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun']
                    ),
                ],
                'stories' => [],
                'social' => [
                    'instagram_url' => 'https://instagram.com/', 'facebook_url' => null,
                    'google_review_url' => null, 'whatsapp' => '100000000',
                    'whatsapp_url' => 'https://wa.me/100000000', 'phone' => '+100000000',
                    'address' => '1 Preview Lane', 'maps_url' => null,
                ],
                'announcement' => ['text' => 'Free delivery this weekend', 'key' => 'demo0001'],
                'features' => [
                    'reservations' => true, 'waitlist' => true, 'loyalty' => true, 'dietary_filters' => true,
                    'call_waiter' => true, 'coupons' => true, 'tips' => true, 'store_reviews' => true,
                ],
            ],
        ];
    }

    // ------------------------------------------------------------------
    // Theme Studio B1c: vertical datasets (in-memory, deterministic).
    // ------------------------------------------------------------------

    /**
     * Retail: goods with a size × colour variant matrix and tracked stock.
     *
     * @return array<string,mixed>
     */
    protected static function retail(): array
    {
        $apparel = self::category(9311, 'Apparel', 1);
        $home = self::category(9312, 'Home', 2);

        $size = self::dimension(9711, 'Size', ['S', 'M', 'L']);
        $colour = self::dimension(9712, 'Colour', ['Black', 'White']);
        $variants = [];
        $vid = 9811;
        foreach ([0, 1, 2] as $s) {
            foreach ([0, 1] as $c) {
                // M · White sold through; L · Black is running low.
                $stock = ($s === 1 && $c === 1) ? 0 : (($s === 2 && $c === 0) ? 2 : 8);
                $variants[] = self::variant($vid++, $s === 2 ? 21.0 : 19.0, [9711 => $s, 9712 => $c], $stock, 'TEE-'.$s.$c);
            }
        }
        $tee = self::item(9411, 'Classic Cotton Tee', 19.0, $apparel, 'Heavyweight organic cotton, relaxed fit.', [
            'track_inventory' => true, 'stock_qty' => 42, 'plu' => 'TEE-001', 'is_popular' => true, 'rating_avg' => 4.6, 'rating_count' => 38,
        ], [$size, $colour], $variants);

        $hoodie = self::item(9412, 'Everyday Hoodie', 45.0, $apparel, 'Brushed fleece lining, kangaroo pocket.', [
            'track_inventory' => true, 'stock_qty' => 3, 'plu' => 'HOOD-002', 'rating_avg' => 4.8, 'rating_count' => 12,
            'original_price' => 55.0,
        ]);

        $mug = self::item(9413, 'Stoneware Mug', 14.0, $home, 'Hand-glazed, 350 ml, dishwasher safe.', [
            'track_inventory' => true, 'stock_qty' => 0, 'plu' => 'MUG-003',
        ]);
        $candle = self::item(9414, 'Cedar Candle', 22.0, $home, 'Soy wax, 40-hour burn.', ['is_daily_special' => true]);

        return self::vertical('retail', 'Demo Market', 'Everyday goods, delivered', [$tee, $hoodie, $mug, $candle], [
            'allow_order' => true, 'on_table' => false, 'takeaway' => true, 'delivery' => true, 'payment' => false, 'scheduling' => false,
        ]);
    }

    /**
     * Pharmacy (OTC only): expiry dates, an age-restricted item, pack-size variants.
     *
     * @return array<string,mixed>
     */
    protected static function pharmacy(): array
    {
        $relief = self::category(9321, 'Pain relief', 1);
        $care = self::category(9322, 'Vitamins & care', 2);

        $pack = self::dimension(9721, 'Pack', ['30 tablets', '60 tablets']);
        $vitc = self::item(9422, 'Vitamin C 1000 mg', 8.5, $care, 'Effervescent tablets, orange flavour.', [
            'track_inventory' => true, 'stock_qty' => 60, 'plu' => 'VITC-1000', 'expiry_date' => '2027-06-30',
        ], [$pack], [
            self::variant(9821, 8.5, [9721 => 0], 40, 'VITC-30'),
            self::variant(9822, 14.0, [9721 => 1], 20, 'VITC-60'),
        ]);

        return self::vertical('pharmacy', 'Demo Pharmacy', 'Over-the-counter care', [
            self::item(9421, 'Paracetamol 500 mg', 4.25, $relief, 'Pack of 20 tablets. Read the label before use.', [
                'track_inventory' => true, 'stock_qty' => 120, 'plu' => 'PARA-500', 'expiry_date' => '2027-03-31', 'is_popular' => true,
            ]),
            $vitc,
            self::item(9423, 'Night-time Cough Syrup', 9.75, $relief, '150 ml. May cause drowsiness.', [
                'track_inventory' => true, 'stock_qty' => 4, 'plu' => 'COUGH-150', 'expiry_date' => '2026-12-31', 'age_restricted' => true,
            ]),
            self::item(9424, 'Mineral Sunscreen SPF 50', 16.0, $care, 'Fragrance-free, water resistant.', [
                'expiry_date' => '2027-09-30',
            ]),
        ], [
            'allow_order' => true, 'on_table' => false, 'takeaway' => true, 'delivery' => true, 'payment' => false, 'scheduling' => false,
        ]);
    }

    /**
     * Laundry: per-kg and per-piece services with turnaround times.
     *
     * @return array<string,mixed>
     */
    protected static function laundry(): array
    {
        $wash = self::category(9331, 'Wash & fold', 1);
        $dry = self::category(9332, 'Dry cleaning', 2);

        $finish = self::dimension(9731, 'Finish', ['Folded', 'On hanger']);

        return self::vertical('laundry', 'Demo Laundry', 'Pickup and delivery laundry', [
            self::item(9431, 'Wash & fold', 3.5, $wash, 'Washed, dried and folded. Priced per kilogram.', [
                'pricing_unit' => 'kg', 'turnaround_hours' => 24, 'is_popular' => true,
            ]),
            self::item(9432, 'Bedding & duvets', 18.0, $wash, 'Duvets, blankets and pillows, deep cleaned.', [
                'turnaround_hours' => 72,
            ]),
            self::item(9433, 'Shirt', 3.0, $dry, 'Cleaned and pressed.', ['turnaround_hours' => 48], [$finish], [
                self::variant(9831, 3.0, [9731 => 0]),
                self::variant(9832, 3.5, [9731 => 1]),
            ]),
            self::item(9434, 'Suit (2 pieces)', 14.0, $dry, 'Jacket and trousers, steamed and pressed.', ['turnaround_hours' => 48]),
        ], [
            'allow_order' => true, 'on_table' => false, 'takeaway' => true, 'delivery' => true, 'payment' => false, 'scheduling' => true,
        ]);
    }

    /**
     * Hotel: rooms (amenities in the description) plus in-room dining.
     *
     * @return array<string,mixed>
     */
    protected static function hotel(): array
    {
        $rooms = self::category(9341, 'Rooms', 1);
        $dining = self::category(9342, 'In-room dining', 2);

        return self::vertical('hotel', 'Demo Hotel', 'Boutique stays in the old town', [
            self::item(9441, 'Standard Room', 129.0, $rooms, '1 King or 2 Twin beds · 2 guests · Free Wi-Fi · City view', ['rating_avg' => 4.5, 'rating_count' => 210]),
            self::item(9442, 'Deluxe Room', 189.0, $rooms, '1 King bed · 2 guests · Balcony · Rain shower · Breakfast included', ['is_popular' => true, 'rating_avg' => 4.8, 'rating_count' => 96]),
            self::item(9443, 'Family Suite', 249.0, $rooms, '2 Queen beds · 4 guests · Separate lounge · Kitchenette'),
            self::item(9444, 'Club Sandwich', 16.0, $dining, 'Served with fries, delivered to your room.'),
        ], [
            'allow_order' => true, 'on_table' => true, 'takeaway' => false, 'delivery' => false, 'room_service' => true, 'payment' => false, 'scheduling' => true,
        ]);
    }

    /**
     * Car parts: part numbers, stock and the fitment capability (fitment rows come from the fitment API).
     *
     * @return array<string,mixed>
     */
    protected static function carparts(): array
    {
        $brakes = self::category(9351, 'Brakes', 1);
        $filters = self::category(9352, 'Filters', 2);

        $axle = self::dimension(9751, 'Axle', ['Front', 'Rear']);

        return self::vertical('carparts', 'Demo Parts', 'OEM and aftermarket parts', [
            self::item(9451, 'Ceramic Brake Pads', 48.0, $brakes, 'Low dust, quiet braking.', [
                'track_inventory' => true, 'stock_qty' => 16, 'plu' => '04465-33471', 'is_popular' => true, 'rating_avg' => 4.7, 'rating_count' => 54,
            ], [$axle], [
                self::variant(9851, 48.0, [9751 => 0], 10, 'BP-F'),
                self::variant(9852, 42.0, [9751 => 1], 6, 'BP-R'),
            ]),
            self::item(9452, 'Brake Disc (vented)', 65.0, $brakes, 'Coated against corrosion.', [
                'track_inventory' => true, 'stock_qty' => 2, 'plu' => '43512-02230',
            ]),
            self::item(9453, 'Oil Filter', 9.5, $filters, 'Spin-on cartridge.', [
                'track_inventory' => true, 'stock_qty' => 80, 'plu' => '90915-YZZD4',
            ]),
            self::item(9454, 'Cabin Air Filter', 18.0, $filters, 'Activated carbon, blocks pollen and odours.', [
                'plu' => '87139-YZZ08',
            ]),
        ], [
            'allow_order' => true, 'on_table' => false, 'takeaway' => true, 'delivery' => true, 'payment' => false, 'scheduling' => false,
        ]);
    }

    /**
     * A vertical store on the restaurant scaffolding: same options, languages,
     * hours, social and branch; its own identity, catalogue, flags and the
     * `features` its vertical actually has (VerticalRegistry capabilities).
     *
     * @param  list<Menu>  $items
     * @param  array<string,bool>  $flags
     * @return array<string,mixed>
     */
    protected static function vertical(string $storeType, string $title, string $tagline, array $items, array $flags): array
    {
        $vars = self::resolveStore();

        $store = new Store([
            'title' => $title, 'slug' => 'demo-'.$storeType,
            'sub_title' => $tagline,
            'description' => 'A seeded storefront for theme previews.',
            'address' => '1 Preview Lane', 'phone' => '+100000000', 'color' => '#2563eb',
        ]);
        $store->id = 0;
        $store->main_image = 'default.png';
        $store->store_type = $storeType;
        $vars['store'] = $store;

        $menus = [];
        foreach ($items as $item) {
            $menus[(int) $item->id] = $item;
        }
        $vars['total_menus'] = $menus;
        $vars['flags'] = $flags;

        $registry = app(VerticalRegistry::class);
        $features = [
            'loyalty' => true, 'coupons' => true, 'store_reviews' => true,
            'scheduling' => (bool) ($flags['scheduling'] ?? false),
            'reservations' => $storeType === 'hotel',
        ];
        foreach (['variant_goods', 'expiry', 'age_gate', 'fitment', 'service'] as $capability) {
            $features[$capability] = $registry->has($storeType, $capability);
        }
        $vars['extras']['features'] = $features;
        $vars['extras']['announcement'] = null;

        return $vars;
    }

    protected static function category(int $id, string $name, int $position): MenuCategory
    {
        $category = new MenuCategory(['position' => $position]);
        $category->id = $id;
        $category->name = $name;

        return $category;
    }

    /** @param  list<string>  $choices */
    protected static function dimension(int $id, string $title, array $choices): MenuVariantOption
    {
        $dimension = new MenuVariantOption(['title' => $title, 'options' => $choices]);
        $dimension->id = $id;

        return $dimension;
    }

    /** @param  array<int,int>  $options  {dimensionId: choiceIdx} */
    protected static function variant(int $id, float $price, array $options, ?int $stock = null, ?string $sku = null): MenuVariant
    {
        $variant = new MenuVariant(['price' => $price, 'options' => $options, 'stock_qty' => $stock, 'sku' => $sku]);
        $variant->id = $id;

        return $variant;
    }

    /**
     * @param  array<string,mixed>  $attrs
     * @param  list<MenuVariantOption>  $dimensions
     * @param  list<MenuVariant>  $variants
     */
    protected static function item(int $id, string $name, float $price, MenuCategory $category, string $description, array $attrs = [], array $dimensions = [], array $variants = []): Menu
    {
        $item = new Menu(['price' => $price]);
        $item->id = $id;
        $item->name = $name;
        $item->description = $description;
        $item->image = 'default.png';
        foreach ($attrs as $key => $value) {
            $item->setAttribute($key, $value);
        }
        $item->setRelation('category', $category);
        $item->setRelation('variantOptions', collect($dimensions));
        $item->setRelation('variants', collect($variants));
        $item->setRelation('extras', collect());
        $item->setRelation('globalGroups', collect());

        return $item;
    }
}
