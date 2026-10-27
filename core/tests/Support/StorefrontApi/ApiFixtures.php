<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Support\StorefrontApi;

use App\Models\Coupon;
use App\Models\MenuCategory;
use App\Models\Order;
use App\Models\StorefrontToken;
use App\Models\StoreOption;
use App\Services\OrderApiService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Settings\StoreSettings;
use Juwri\Modules\Bookings\Models\BookableService;
use Juwri\Modules\Bookings\Models\BookingHour;
use Juwri\Modules\Bookings\Models\BookingStaff;
use Juwri\Modules\Menu\Contracts\GroceryCatalog;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Menu\Models\MenuVariant;
use Juwri\Modules\Menu\Models\MenuVariantOption;
use Juwri\Modules\Menu\Models\Modifier;
use Juwri\Modules\Menu\Models\ModifierGroup;
use Juwri\Modules\Ordering\Models\DeliverySlotRule;
use Juwri\Modules\Ordering\Models\ShippingMethod;
use Juwri\Modules\Ordering\Models\ShippingZone;
use Tests\Support\TestPlans;

/**
 * P3-06b test fixtures: the demo stores of the kit API examples (tools/themes/kit/api/examples), built with the
 * examples' own public ids, prices and token strings so a recorded request replays against the app unchanged:
 *
 *  - Saffron House (restaurant, `table`): Mixed grill platter SAR 80 (For one · Garlic sauce · Hummus) and Chicken
 *    shish tawook SAR 42 (White rice), VAT 15% on top, delivery SAR 9, tips on, coupon WELCOME10 (10% off food):
 *    the food flow's design total SAR 142.10. Legacy items Hummus 18 / Moutabal 20 for the frozen legacy bodies.
 *  - Fresh Mart (grocery), Sidra Salon (services), Dune & Co. (retail): stores + tokens for the late-payment,
 *    OTP and return-url examples.
 *
 * Each store has its own owner on a plan with `storefront_api` (and `allow_ordering`).
 */
final class ApiFixtures
{
    public const GRILL = '019fd1cb-7600-7197-8e48-219b57ed96e0';

    public const TAWOOK = '019eff4b-8580-729b-bbcf-1d93b83ec998';

    public const OPT_FOR_ONE = '019fb26b-c852-7904-aca4-bd1a1d1fba06';

    public const OPT_GARLIC = '019f0fe5-9871-76bd-a3e6-b6b2f53cc65c';

    public const OPT_HUMMUS = '019f7aa6-e9e6-77d0-ba6e-792614d13c83';

    public const OPT_RICE = '019f55ef-91b2-7314-b6db-52d33df40582';

    /** @var array<string, int> legacy example integer id => our row id (filled by saffron()) */
    public array $legacyIds = [];

    /** @param  array<string, mixed>  $plan */
    public function owner(array $plan = []): User
    {
        $p = TestPlans::create([
            'name' => 'API '.Str::random(5), 'description' => 'T',
            'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1,
            'settings' => array_merge([
                'restaurant_limit' => 999, 'menu_limit' => 999, 'allow_ordering' => '1', 'storefront_api' => '1',
            ], $plan),
            'status' => 1,
        ]);

        return User::factory()->create(['group_id' => (string) $p->id, 'status' => 1, 'email_verified_at' => now()]);
    }

    /** @param  array<string, mixed>  $settings */
    public function store(User $owner, string $slug, string $title, string $type, array $settings = []): Store
    {
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => $slug, 'color' => '#111', 'title' => $title, 'sub_title' => 'Demo',
            'description' => 'Demo store', 'main_image' => 'default.png', 'cover_image' => 'default.png',
            'address' => 'King Fahd Road, Riyadh', 'phone' => '+966112345678', 'store_type' => $type,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        StoreSettings::setMany((int) $store->id, array_merge([
            'currency_code' => 'SAR', 'currency_sign' => 'SAR',
        ], $settings));

        return $store;
    }

    /** @param  list<string>  $scopes  @param  list<string>  $origins */
    public function token(Store $store, string $raw, array $scopes, array $origins = [], bool $browser = false): StorefrontToken
    {
        return StorefrontToken::create([
            'store_id' => $store->id, 'token' => hash('sha256', $raw), 'scopes' => $scopes, 'active' => true,
            'allowed_origins' => $origins, 'browser' => $browser,
        ]);
    }

    /** Saffron House with the food flow's catalogue, coupon and tokens. */
    public function saffron(?User $owner = null): Store
    {
        $owner ??= $this->owner();
        $store = $this->store($owner, 'saffron-house-demo', 'Saffron House', 'restaurant', [
            'restaurant_delivery_order' => true, 'restaurant_takeaway_order' => true, 'restaurant_on_table_order' => true,
            'restaurant_delivery_charge' => '9', 'restaurant_taxx_charge' => '15', 'restaurant_tax_type' => 'percentage',
            'restaurant_tax_label' => 'VAT', 'allow_tips' => true,
            'restaurant_online_payment' => true, 'restaurant_stripe_install' => '1',
        ]);
        config(['settings.admin_allow_online_payment' => '1']);

        $grills = MenuCategory::create(['store_id' => $store->id, 'name' => 'Grills', 'active' => 1]);
        $mezze = MenuCategory::create(['store_id' => $store->id, 'name' => 'Mezze', 'active' => 1]);
        $grill = $this->item($store, $grills, self::GRILL, 'Mixed grill platter', 80);
        $this->group($store, $grill, 'Size', [['For one', 0, self::OPT_FOR_ONE]], true);
        $this->group($store, $grill, 'Sauce', [['Garlic sauce', 0, self::OPT_GARLIC]], false);
        $this->group($store, $grill, 'Side', [['Hummus', 0, self::OPT_HUMMUS]], false);
        $tawook = $this->item($store, $grills, self::TAWOOK, 'Chicken shish tawook', 42);
        $this->group($store, $tawook, 'Rice', [['White rice', 0, self::OPT_RICE]], false);
        $this->legacyIds['101'] = (int) $this->item($store, $mezze, (string) Str::uuid7(), 'Hummus', 18)->id;
        $this->legacyIds['102'] = (int) $this->item($store, $mezze, (string) Str::uuid7(), 'Moutabal', 20)->id;

        Coupon::create([
            'user_id' => $owner->id, 'code' => 'WELCOME10', 'coupon_type' => 'percentage', 'percentage' => 10,
            'limit' => 0, 'used' => 0, 'applies_to' => 'food_order', 'status' => 1,
        ]);
        $this->token($store, 'sfx_demo_saffron_read_0001', ['menu:read']);
        $this->token($store, 'sfx_demo_saffron_write_0001', ['order:write'], ['https://saffron-house.example']);
        $this->token($store, 'sfx_demo_saffron_kiosk_0001', ['kiosk'], ['https://saffron-house.example']);

        return $store;
    }

    public function item(Store $store, MenuCategory $category, string $publicId, string $name, float $price): Menu
    {
        $menu = Menu::create(['store_id' => $store->id, 'category_id' => $category->id, 'name' => $name, 'price' => $price, 'active' => 1]);
        Menu::query()->whereKey($menu->id)->update(['public_id' => $publicId]);

        return $menu->fresh();
    }

    /** @param  list<array{0: string, 1: float|int, 2: string}>  $options  [label, price, option public id] */
    public function group(Store $store, Menu $menu, string $title, array $options, bool $required, ?string $groupPublicId = null): MenuVariantOption
    {
        $group = MenuVariantOption::create([
            'store_id' => $store->id, 'title' => $title, 'is_global' => true, 'menu_id' => null,
            'options' => array_column($options, 0), 'option_prices' => array_map(fn ($o) => (float) $o[1], $options),
            'required' => $required, 'min_select' => $required ? 1 : 0, 'max_select' => 1, 'position' => 0, 'active' => true,
        ]);
        ModifierGroup::query()->whereKey($group->id)->update(['public_id' => $groupPublicId ?? (string) Str::uuid7()]);
        foreach (Modifier::query()->where('modifier_group_id', $group->id)->orderBy('position')->orderBy('id')->get() as $i => $modifier) {
            Modifier::query()->whereKey($modifier->id)->update(['public_id' => $options[$i][2]]);
        }
        $menu->attachedVariantOptions()->syncWithoutDetaching([
            $group->id => ['position' => 0, 'required' => $required, 'min_select' => $required ? 1 : 0, 'max_select' => 1, 'active' => true],
        ]);

        return $group;
    }

    /** Bloom Coffee (cafe) with the food-pickup flow's two items: design total SAR 39.10 (VAT 15% on top). */
    public function bloom(?User $owner = null): Store
    {
        $store = $this->store($owner ?? $this->owner(), 'bloom-coffee-demo', 'Bloom Coffee', 'cafe', [
            'restaurant_takeaway_order' => true, 'restaurant_on_table_order' => true,
            'restaurant_taxx_charge' => '15', 'restaurant_tax_type' => 'percentage', 'restaurant_tax_label' => 'VAT',
            'restaurant_online_payment' => true, 'restaurant_stripe_install' => '1',
        ]);
        config(['settings.admin_allow_online_payment' => '1']);
        $drinks = MenuCategory::create(['store_id' => $store->id, 'name' => 'Matcha', 'active' => 1]);
        $matcha = $this->item($store, $drinks, '019f4809-5900-7ffe-af8b-0c857a8d77d6', 'Iced matcha latte', 21);
        $this->group($store, $matcha, 'Size', [['Regular', 0, '019f08ad-e78b-715c-8459-590a2475c663']], true);
        $this->group($store, $matcha, 'Milk', [['Whole', 0, '019f8276-075b-7777-a704-8d732a99b184']], true);
        $this->group($store, $matcha, 'Ice', [['Light', 0, '019f922c-c3f6-7d80-9a10-77bed77fcfc3']], false);
        $this->group($store, $matcha, 'Extras', [['Extra matcha shot', 4, '019fd82a-f556-7f48-ab43-fe4b3f138eb2']], false);
        $bakery = MenuCategory::create(['store_id' => $store->id, 'name' => 'Bakery', 'active' => 1]);
        $croissant = $this->item($store, $bakery, '019ee02e-6f00-7a68-86ae-170d6823078c', 'Butter croissant', 9);
        $this->group($store, $croissant, 'Warm it', [['Warmed', 0, '019fe2de-bd56-791a-8f00-c8c894198a6e']], false);
        $this->token($store, 'sfx_demo_bloom_read_0001', ['menu:read']);
        $this->token($store, 'sfx_demo_bloom_write_0001', ['order:write'], ['https://bloom-coffee.example']);

        return $store;
    }

    /**
     * Lane C: Bloom Coffee takes pre-orders (the food-pickup examples 02–04): takeaway scheduling on, every 15 minutes,
     * 15 minutes lead, at most 8 orders a slot, store time Asia/Riyadh; on Fri 2 Oct 2026 the 10:00 slot is full (8
     * scheduled orders), 10:15 has 2 left (6), 10:30 has 6 left (2), 10:45 is empty (8).
     */
    public function bloomPreorders(Store $store): void
    {
        StoreSettings::setMany((int) $store->id, [
            'timezone' => 'Asia/Riyadh', 'allow_order_scheduling' => true, 'scheduling_types' => 'takeaway',
            'scheduling_slot_interval' => 15, 'scheduling_lead_minutes' => 15, 'scheduling_slot_capacity' => 8,
            'scheduling_max_days' => 7,
        ]);
        foreach (['10:00' => 8, '10:15' => 6, '10:30' => 2] as $time => $count) {
            for ($i = 0; $i < $count; $i++) {
                Order::query()->insert([
                    'store_id' => $store->id, 'account_id' => $store->account_id, 'status' => 'pending', 'is_paid' => 0,
                    'type' => 'takeaway', 'customer_name' => 'Guest', 'phone_number' => '1',
                    'scheduled_at' => '2026-10-02 '.$time.':00', 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
    }

    /** Lane C (API-13): the store's kiosk switched on and the platform's `kiosk` feature on (the web kiosk's own gates). */
    public function kioskOn(Store $store, bool $feature = true): void
    {
        config(['settings.admin_allow_kiosk' => $feature]);
        StoreSettings::setMany((int) $store->id, ['kiosk_enabled' => true, 'kiosk_idle_seconds' => 60, 'kiosk_auto_print' => true]);
    }

    public function freshMart(?User $owner = null): Store
    {
        $store = $this->store($owner ?? $this->owner(), 'fresh-mart-demo', 'Fresh Mart', 'grocery', ['restaurant_delivery_charge' => '0']);
        $this->token($store, 'sfx_demo_freshmart_read_0001', ['menu:read']);
        $this->token($store, 'sfx_demo_freshmart_write_0001', ['order:write'], ['https://fresh-mart.example']);

        return $store;
    }

    public const BANANAS = '019ee2f8-8b80-7c41-ace1-e47e281dc213';

    public const TOMATOES = '019ef1fd-c280-78f5-ac68-e35b62b4070a';

    public const TOMATO_WEIGHT_GROUP = '019f4455-1a00-7c3e-9b1d-5e2f7a8c0d11';

    /** grams => option public id */
    public const TOMATO_STEPS = [
        250 => '019f4456-6e13-7adc-806c-2d05ad4a7d2a', 500 => '019fed0d-59ba-713e-8d04-7eb7d692e48e',
        1000 => '019f5e83-6451-772f-9c65-872f9d042fe8', 2000 => '019fd8db-9ec6-75b9-9b41-497726fddf21',
    ];

    public const SUBSTITUTION_GROUP = '019f4455-1a00-7d42-8a6e-0c3b9f1e2d22';

    public const SUB_REPLACE = '019f4455-1a00-7e15-a4c7-6d8e2b0f3a31';

    public const SUB_CALL = '019f4455-1a00-7f28-b5d8-7e9f3c1a4b42';

    public const SUB_REMOVE = '019f4455-1a00-7039-86e9-8fa04d2b5c53';

    /**
     * Fresh Mart with the grocery flow's catalogue (P3-09, phase C): Bananas SAR 6.50 (1 kg pack), Tomatoes on the vine
     * SAR 11.80 / kg sold by weight (250 g, 500 g, 1 kg, 2 kg), Strawberries SAR 14.95 (250 g), Full-fat milk SAR 19 (2 L),
     * Sourdough loaf SAR 14 (800 g); delivery SAR 9, no tax (the examples show an inclusive VAT note core does not
     * have); hourly slots 11:00–17:00 today, 8 orders an hour with seats already taken (11:00 → 2, 12:00 → 4, 13:00 →
     * full, 16:00 → 6), express SAR 5 in 25–35 min; the "If it's out of stock" group (Replace with similar →
     * offer_replacement, Call me → call_customer / no answer remove_item, Remove it → remove_item); Stripe on (it can
     * hold a card amount). Store time Asia/Riyadh; the examples run at 10:45 on Fri 2 Oct 2026.
     */
    public function freshMartGrocery(?User $owner = null): Store
    {
        $store = $this->freshMart($owner);
        StoreSettings::setMany((int) $store->id, [
            'timezone' => 'Asia/Riyadh', 'restaurant_delivery_order' => true, 'restaurant_delivery_charge' => '9',
            'restaurant_online_payment' => true, 'restaurant_stripe_install' => '1',
        ]);
        config(['settings.admin_allow_online_payment' => '1']);
        $grocery = app(GroceryCatalog::class);

        $fresh = MenuCategory::create(['store_id' => $store->id, 'name' => 'Fruit & veg', 'active' => 1]);
        $daily = MenuCategory::create(['store_id' => $store->id, 'name' => 'Dairy & bakery', 'active' => 1]);
        foreach ([
            [self::BANANAS, $fresh, 'Bananas', 6.50, 1, 'kg'],
            ['019f3fab-0380-73c2-89a4-21d8a38a1c3f', $fresh, 'Strawberries', 14.95, 250, 'g'],
            ['019feb8b-4200-7e01-bb06-c6019e258487', $daily, 'Full-fat milk', 19.00, 2, 'l'],
            ['019e8646-1380-7aeb-91aa-3627eb8ec48e', $daily, 'Sourdough loaf', 14.00, 800, 'g'],
        ] as [$publicId, $category, $name, $price, $size, $unit]) {
            $item = $this->item($store, $category, $publicId, $name, $price);
            $item->forceFill(['attributes' => ['pack_size' => $size, 'pack_unit' => $unit]])->save();
        }
        $tomatoes = $this->item($store, $fresh, self::TOMATOES, 'Tomatoes on the vine', 11.80);
        $group = $grocery->syncWeightSteps($tomatoes, array_keys(self::TOMATO_STEPS));
        ModifierGroup::query()->whereKey($group->id)->update(['public_id' => self::TOMATO_WEIGHT_GROUP]);
        foreach (Modifier::query()->where('modifier_group_id', $group->id)->get() as $modifier) {
            Modifier::query()->whereKey($modifier->id)->update(['public_id' => self::TOMATO_STEPS[(int) $modifier->weight_g]]);
        }

        $substitution = $grocery->saveSubstitutionGroup($store, "If it's out of stock", [
            ['title' => 'Replace with similar', 'action' => 'offer_replacement'],
            ['title' => 'Call me', 'action' => 'call_customer', 'fallback' => 'remove_item'],
            ['title' => 'Remove it', 'action' => 'remove_item'],
        ]);
        ModifierGroup::query()->whereKey($substitution->id)->update(['public_id' => self::SUBSTITUTION_GROUP]);
        foreach ($substitution->modifiers->sortBy('position')->values() as $i => $modifier) {
            Modifier::query()->whereKey($modifier->id)->update(['public_id' => [self::SUB_REPLACE, self::SUB_CALL, self::SUB_REMOVE][$i]]);
        }

        DeliverySlotRule::create([
            'account_id' => $store->account_id, 'store_id' => $store->id, 'branch_id' => null, 'active' => true,
            'windows' => [['11:00', '17:00']], 'capacity_per_hour' => 8, 'lead_minutes' => 10, 'days_ahead' => 1,
            'express_fee_minor' => 500, 'express_eta_from' => 25, 'express_eta_to' => 35, 'currency_code' => 'SAR',
        ]);
        foreach (['08:00' => 2, '09:00' => 4, '10:00' => 8, '13:00' => 6] as $utc => $taken) {
            $this->takeSeats($store, '2026-10-02 '.$utc.':00', $taken);
        }

        return $store;
    }

    /** Seats already taken by other orders of a slot (UTC start; the order ids are stand-ins). */
    public function takeSeats(Store $store, string $slotAtUtc, int $count, int $from = 1): void
    {
        for ($n = $from; $n < $from + $count; $n++) {
            DB::table('slot_seats')->insert([
                'account_id' => (int) $store->account_id, 'store_id' => (int) $store->id, 'branch_key' => 0,
                'slot_at' => $slotAtUtc, 'seat_no' => $n, 'hold_id' => null, 'order_id' => 900000 + $n, 'expires_at' => null,
            ]);
        }
    }

    public function sidra(?User $owner = null): Store
    {
        $store = $this->store($owner ?? $this->owner(), 'sidra-salon-demo', 'Sidra Salon', 'services');
        $this->token($store, 'sfx_demo_sidra_read_0001', ['menu:read']);
        $this->token($store, 'sfx_demo_sidra_write_0001', ['order:write'], ['https://sidra-salon.example']);
        $this->token($store, 'sfx_demo_sidra_kiosk_0001', ['kiosk'], ['https://sidra-salon.example']);

        return $store;
    }

    public const SVC_HAIRCUT = '019ed72b-4e00-76d8-a0e0-611df411c074';

    public const STAFF_MAHA = '019ed498-2000-71f5-ae20-debce6e349f7';

    public const STAFF_LINA = '019ec449-5200-727a-a25a-1a736f3f33d9';

    public const STAFF_SARA = '019fae9a-ac00-74ce-9097-b86a1ad9bc1e';

    /**
     * Sidra Salon on the booking engine (P3-10) with the services examples' ids: "Haircut & blow-dry" SAR 180, 75 min,
     * deposit SAR 50 (the examples' Medium + Deep conditioning choice as one service: the engine has no service options
     * yet), done by Maha, Lina and Sara; hours 13:00–22:00 Sunday–Thursday, 16:30–22:00 Friday, closed Saturday (the
     * examples' day strip); Stripe switched on for the hosted deposit.
     *
     * @param  array<string, mixed>  $settings
     */
    public function sidraBookings(?User $owner = null, array $settings = []): Store
    {
        $store = $this->sidra($owner);
        StoreOption::updateStoreOption($store->id, 'timezone', 'Asia/Riyadh');
        StoreSettings::setMany((int) $store->id, $settings + [
            'restaurant_online_payment' => true, 'restaurant_stripe_install' => '1', 'restaurant_stripe_secret_key' => 'sk_test_x',
        ]);
        config(['settings.admin_allow_online_payment' => '1']);
        $service = BookableService::query()->create([
            'store_id' => (int) $store->id, 'name' => 'Haircut & blow-dry', 'duration_min' => 75, 'price_minor' => 18000,
            'currency' => 'SAR', 'deposit_type' => 'amount', 'deposit_value' => 5000, 'venue' => true, 'at_home' => false, 'active' => true,
        ]);
        BookableService::query()->whereKey($service->id)->update(['public_id' => self::SVC_HAIRCUT]);
        foreach ([[self::STAFF_MAHA, 'Maha', 'Senior stylist'], [self::STAFF_LINA, 'Lina', 'Stylist'], [self::STAFF_SARA, 'Sara', 'Colourist']] as $i => [$id, $name, $role]) {
            $staff = BookingStaff::query()->create(['store_id' => (int) $store->id, 'name' => $name, 'role' => $role, 'active' => true, 'position' => $i]);
            BookingStaff::query()->whereKey($staff->id)->update(['public_id' => $id]);
            $staff->services()->syncWithPivotValues([$service->id], ['store_id' => (int) $store->id, 'account_id' => (int) $store->account_id]);
        }
        foreach ([0, 1, 2, 3, 4, 5] as $day) {
            BookingHour::query()->create([
                'store_id' => (int) $store->id, 'staff_id' => null, 'weekday' => $day, 'kind' => 'open',
                'starts_local' => $day === 5 ? '16:30' : '13:00', 'ends_local' => '22:00',
            ]);
        }

        return $store->fresh() ?? $store;
    }

    public function dune(?User $owner = null): Store
    {
        $store = $this->store($owner ?? $this->owner(), 'dune-and-co-demo', 'Dune & Co.', 'retail');
        $this->token($store, 'sfx_demo_dune_read_0001', ['menu:read']);
        $this->token($store, 'sfx_demo_dune_write_0001', ['order:write'], ['https://dune-and-co.example']);

        return $store;
    }

    public const SHIRT = '019fb72f-f000-7353-8c3b-9f7d5e1620c6';

    public const TROUSERS = '019ed8ab-d380-7c35-b059-e61d2459d573';

    public const SHIRT_SAND_M = '019fa718-1080-763e-a69c-9f701d44ac3b';

    public const TROUSERS_NAVY_M = '019ea63e-e400-7902-af49-16aadc649da7';

    public const SHIP_STANDARD = '01a01863-f880-702d-b8e8-df65791001f2';

    public const SHIP_EXPRESS = '019fc334-1c00-716c-b0f2-579cde858c7a';

    public const SHIP_PICKUP = '019ffcb5-ca00-7dcb-9be9-4fafaa945299';

    public const OLAYA_BRANCH = '019f3b29-7300-7551-972b-24389fcc4b17';

    /**
     * Dune & Co. with the retail flow's catalogue (P3-08, phase B): Relaxed linen shirt SAR 139 (was 199) in
     * Colour (Sand, Olive) × Size (XS, S, M, L) — XS sold out, M 2 left (low), Olive · M untracked — and Wide-leg
     * trousers Navy · M SAR 229; shipping zone `riyadh-north` (SA, Riyadh) with Standard (SMSA, SAR 15, free from SAR
     * 200, 2–4 days), Express (SMSA, SAR 25, 1 day, cut-off 21:00) and pickup at Olaya Mall (ready next day).
     * Online payment on (the store's own gateway) for the hosted order. Store time Asia/Riyadh.
     */
    public function duneRetail(?User $owner = null): Store
    {
        $store = $this->dune($owner);
        StoreSettings::setMany((int) $store->id, [
            'timezone' => 'Asia/Riyadh',
            'restaurant_online_payment' => true, 'restaurant_stripe_install' => '1',
            'returns_window_days' => 14,
        ]);
        config(['settings.admin_allow_online_payment' => '1']);

        $category = MenuCategory::create(['store_id' => $store->id, 'name' => 'Clothing', 'active' => 1]);
        $shirt = $this->item($store, $category, self::SHIRT, 'Relaxed linen shirt', 139);
        $colour = $this->group($store, $shirt, 'Colour', [
            ['Sand', 0, '019f23cd-4a14-7b7b-a7c0-91d71e6a5348'], ['Olive', 0, '019f35a9-6ec7-7869-b7ed-711dfec8feda'],
        ], true, '019f23cc-0b00-7a1e-9c2d-3e4f5a6b7c61');
        $size = $this->group($store, $shirt, 'Size', [
            ['XS', 0, '019f8489-f7d7-7f7e-b550-2536c06e64ad'], ['S', 0, '019f69da-06e5-723e-97d2-94bc820f0725'],
            ['M', 0, '019f6628-fcc8-7266-b5e7-8ac988ca5a9d'], ['L', 0, '019f08c5-dabe-7e68-9fcb-a31ab7644014'],
        ], true, '019f8488-0b00-7b2f-8d3e-4f5a6b7c8d72');
        ModifierGroup::query()->whereKey($colour->id)->update(['display' => 'swatch']);
        ModifierGroup::query()->whereKey($size->id)->update(['display' => 'size']);
        foreach ([
            ['019e8a90-b580-7d21-8841-c8ec7cc5c1e6', 'RLS-SAN-XS', 0, 0, 0],
            ['019f41d0-5480-70e0-a226-a4286dfc5326', 'RLS-SAN-S', 0, 1, 20],
            [self::SHIRT_SAND_M, 'RLS-SAN-M', 0, 2, 2],
            ['019ef234-b100-7756-84f0-2bc45f0c4e7e', 'RLS-SAN-L', 0, 3, 30],
            ['019fe81c-5a00-771a-ae9b-4d2ee36f1b7b', 'RLS-OLI-M', 1, 2, null],
        ] as $i => [$publicId, $sku, $c, $z, $qty]) {
            $this->variant($shirt, $publicId, $sku, [(int) $colour->id => $c, (int) $size->id => $z], 139, 19900, $qty, $i);
        }

        $trousers = $this->item($store, $category, self::TROUSERS, 'Wide-leg trousers', 229);
        $tColour = $this->group($store, $trousers, 'Colour', [['Navy', 0, (string) Str::uuid7()]], true);
        $tSize = $this->group($store, $trousers, 'Size', [['M', 0, (string) Str::uuid7()]], true);
        $this->variant($trousers, self::TROUSERS_NAVY_M, 'WLT-NAV-M', [(int) $tColour->id => 0, (int) $tSize->id => 0], 229, null, 12, 0);

        $branch = Branch::create(['store_id' => $store->id, 'name' => 'Olaya Mall', 'active' => true]);
        Branch::query()->whereKey($branch->id)->update(['public_id' => self::OLAYA_BRANCH]);
        $zone = ShippingZone::create(['store_id' => $store->id, 'code' => 'riyadh-north', 'name' => 'Riyadh North',
            'countries' => ['SA'], 'cities' => ['Riyadh'], 'active' => true, 'position' => 0]);
        foreach ([
            [self::SHIP_STANDARD, 'standard', 'Standard', 1500, 20000, 2, 4, null, $zone->id, null],
            [self::SHIP_EXPRESS, 'express', 'Express', 2500, null, 1, 1, '21:00', $zone->id, null],
            [self::SHIP_PICKUP, 'pickup_in_store', 'Pick up in store', 0, null, 1, 1, null, null, $branch->id],
        ] as $i => [$publicId, $kind, $name, $fee, $freeOver, $min, $max, $cutoff, $zoneId, $branchId]) {
            $method = ShippingMethod::create([
                'store_id' => $store->id, 'shipping_zone_id' => $zoneId, 'kind' => $kind, 'name' => $name,
                'carrier' => $kind === 'pickup_in_store' ? null : 'SMSA', 'tracking_url_template' => $kind === 'pickup_in_store' ? null : 'https://track.smsa.example/{tracking}',
                'fee_minor' => $fee, 'free_over_minor' => $freeOver, 'currency_code' => 'SAR', 'min_days' => $min, 'max_days' => $max,
                'cutoff_time' => $cutoff, 'pickup_branch_id' => $branchId, 'active' => true, 'position' => $i,
            ]);
            ShippingMethod::query()->whereKey($method->id)->update(['public_id' => $publicId]);
        }

        return $store;
    }

    /** @param  array<int, int>  $cell  group id => option index */
    public function variant(Menu $item, string $publicId, string $sku, array $cell, float $price, ?int $compareAtMinor, ?int $stock, int $position): MenuVariant
    {
        ksort($cell);
        $variant = MenuVariant::create([
            'menu_id' => $item->id, 'store_id' => $item->store_id, 'price' => $price, 'options' => (object) $cell,
            'sku' => $sku, 'stock_qty' => $stock, 'compare_at_minor' => $compareAtMinor, 'active' => true, 'position' => $position,
        ]);
        MenuVariant::query()->whereKey($variant->id)->update(['public_id' => $publicId]);

        return $variant->fresh();
    }

    /**
     * A placed order of `$store` (one line of its first item), for status / late-payment states.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function order(Store $store, string $trackToken, array $attributes = []): Order
    {
        $menu = Menu::query()->where('store_id', $store->id)->first();
        if ($menu === null) {
            $category = MenuCategory::create(['store_id' => $store->id, 'name' => 'Basics', 'active' => 1]);
            $menu = $this->item($store, $category, (string) Str::uuid7(), 'Bananas', 76.44);
        }
        $order = OrderApiService::create((int) $store->user_id, (int) $store->id, [
            'type' => 'delivery', 'items' => [['item_id' => $menu->id, 'quantity' => 1]], 'source' => 'storefront_api',
            'delivery_by_partner' => true,
        ]);
        Order::query()->whereKey($order->id)->update(array_merge(['track_token' => $trackToken], $attributes));

        return $order->fresh();
    }
}
