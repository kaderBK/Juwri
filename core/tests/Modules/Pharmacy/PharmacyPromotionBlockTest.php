<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Pharmacy;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyProgram;
use App\Models\MenuCategory;
use App\Models\Order;
use App\Models\PriceSchedule;
use App\Models\StoreOption;
use App\Services\FeatureAccess;
use App\Services\FeatureRegistry;
use App\Services\Loyalty\LoyaltyAccrual;
use App\Services\OrderApiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Juwri\Core\Models\EntitlementOverride;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Services\EntitlementWriter;
use Juwri\Modules\Menu\Contracts\PromotionRules;
use Juwri\Modules\Menu\Models\Combo;
use Juwri\Modules\Menu\Models\ComboSlot;
use Juwri\Modules\Menu\Models\ComboSlotOption;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Menu\Services\Availability\MenuScheduleService;
use Juwri\Modules\Menu\Services\Combos\ComboService;
use Juwri\Modules\Ordering\Models\Cart;
use Juwri\Modules\Pharmacy\Models\PharmacyLicence;
use Juwri\Modules\Pharmacy\Services\Pharmacy\PharmacyMarkets;
use Juwri\Modules\Verticals\Services\Fulfillment\GoodsCartService;
use Juwri\Modules\Verticals\Services\Fulfillment\GoodsOrderService;
use Tests\Support\PharmacyMarket;
use Tests\Support\TestPlans;
use Tests\Support\UniquePhone;
use Tests\TestCase;

/**
 * P1-23 follow-up — the pharmacy promotion block (DECISIONS 2026-10-04; help: pharmacy checklist, Saudi note).
 *
 * A per-market flag (config pharmacy: on for SA by default, off elsewhere) that, for medicine lines
 * (items.is_medicine) of a pharmacy store, blocks every promotion the product has:
 *
 *   rule                         web (storefront / goods checkout)   API (OrderApiService)   POS                      cart / display
 *   coupons                      submitOrder → coupon_declined + note   coupon not applied      coupon endpoint 422      —
 *   scheduled / sale / happy hr  submitOrder + goods checkout lines      line not snapshotted    order + menu JSON        decorate() + goods cart
 *   combos holding a medicine    —                                    refused (422)           refused + not on tiles   not sellable
 *   loyalty earn                 submitOrder + goods checkout: other    earn seam               earn seam + preview flag —
 *                                lines only
 *   loyalty redeem               submitOrder → no points + note;        no points spent         (POS has no redeem)      —
 *                                goods checkout applyDiscounts
 *   free gifts                   the product has no free-gift feature (BOGO is a coupon type → coupons row)
 *
 * Non-medicine lines (owner marked "not a medicine") and other store types are unaffected.
 */
class PharmacyPromotionBlockTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        Mail::fake();
        Notification::fake();
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
        config([
            'pharmacy.enabled_countries' => [], 'pharmacy.markets' => [], 'pharmacy.quantity_cap' => 5,
            'pharmacy.block_medicine_promotions' => false,
            'settings.admin_allow_menu_schedules' => true, 'settings.admin_allow_combos' => true,
            'settings.storefront_order_pipeline' => 'legacy',
        ]);
        FeatureRegistry::clearCache();
        FeatureAccess::flush();
        MenuScheduleService::flush();
    }

    protected function tearDown(): void
    {
        MenuScheduleService::flush();
        parent::tearDown();
    }

    /* ───────────────────────────── fixtures ──────────────────────────── */

    private function owner(string $country = 'SA'): User
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 'T', 'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1,
            'settings' => ['restaurant_limit' => 5, 'menu_limit' => 999, 'category_limit' => 999, 'allow_ordering' => '1', 'pos_enabled' => '1'],
            'status' => 1,
        ]);
        $user = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now(), 'country_code' => $country]);
        EntitlementOverride::create(['user_id' => $user->id, 'feature_key' => 'multi_vertical', 'value' => '1', 'source_type' => 'admin']);
        config(['settings.admin_allow_loyalty' => true]);
        EntitlementWriter::grant($user, 'loyalty', 'admin', null, '1', null, 'test');
        FeatureRegistry::clearCache();
        FeatureAccess::flush();

        return $user;
    }

    /** A published store: a pharmacy opened in $country (licence approved), or another store type. */
    private function store(string $type = 'pharmacy', string $country = 'SA'): Store
    {
        $owner = $this->owner($country);
        $title = 'S'.Str::random(6);
        $store = Store::create([
            'user_id' => $owner->id, 'store_type' => $type, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)), 'color' => '#111',
            'title' => $title, 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'default.png', 'cover_image' => 'default.png',
            'address' => '1 St', 'phone' => '1234', 'created_at' => now(), 'updated_at' => now(),
        ]);
        StoreOption::updateStoreOption($store->id, 'restaurant_takeaway_order', 1);
        StoreOption::updateStoreOption($store->id, 'allow_order', 1);
        if ($type === 'pharmacy') {
            PharmacyMarket::open($store, $country);
        }
        MenuScheduleService::flush();

        return $store;
    }

    private function item(Store $store, string $name, float $price, bool $medicine = true): Menu
    {
        $cat = MenuCategory::firstOrCreate(['store_id' => $store->id, 'name' => 'Shelf']);

        return Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => $name, 'price' => $price, 'active' => '1', 'is_medicine' => $medicine]);
    }

    /** [store, medicine (5.00), cosmetic (10.00, not a medicine)] */
    private function pharmacy(string $country = 'SA'): array
    {
        $store = $this->store('pharmacy', $country);

        return [$store, $this->item($store, 'Paracetamol 500mg', 5), $this->item($store, 'Hand cream', 10, false)];
    }

    private function happyHour(Store $store, int $percent = 20): PriceSchedule
    {
        $s = PriceSchedule::create([
            'store_id' => $store->id, 'name' => 'Sale', 'label' => 'Sale', 'scope' => 'store',
            'adjustment_type' => 'percent', 'adjustment_value' => $percent, 'active' => true,
        ]);
        MenuScheduleService::flush();

        return $s;
    }

    private function combo(Store $store, array $menus): Combo
    {
        $combo = Combo::create(['store_id' => $store->id, 'name' => 'Bundle '.Str::random(3), 'description' => 'Two together', 'price' => 12.00, 'active' => true, 'position' => 0]);
        $slot = ComboSlot::create(['combo_id' => $combo->id, 'store_id' => $store->id, 'title' => 'Pick one', 'min_select' => 1, 'max_select' => 1, 'position' => 0]);
        foreach (array_values($menus) as $i => $m) {
            ComboSlotOption::create(['combo_slot_id' => $slot->id, 'combo_id' => $combo->id, 'store_id' => $store->id, 'menu_id' => $m->id, 'upcharge' => 0, 'active' => true, 'position' => $i]);
        }

        return $combo->fresh();
    }

    private function customer(Store $store): Customer
    {
        return Customer::create(['store_id' => $store->id, 'name' => 'Ada', 'phone' => UniquePhone::next('55-')]);
    }

    private function program(Store $store, string $type = 'points', int $earnRate = 100, float $reward = 5.0, int $threshold = 5): LoyaltyProgram
    {
        return LoyaltyProgram::create([
            'store_id' => $store->id, 'type' => $type, 'earn_rate' => $earnRate, 'reward_threshold' => $threshold,
            'reward_description' => 'Reward', 'reward_discount' => $reward, 'active' => true,
        ]);
    }

    private function coupon(Store $store): Coupon
    {
        return Coupon::create([
            'code' => 'SAVE-'.rand(1000, 9999), 'percentage' => 20, 'limit' => 99, 'used' => 0, 'user_id' => $store->user_id,
            'coupon_type' => 'percentage', 'issuer_scope' => 'owner', 'applies_to' => 'food_order', 'expiry_at' => now()->addMonth(),
        ]);
    }

    /** Storefront checkout (web channel), as a signed-in customer. */
    private function submitOrder(Store $store, Customer $customer, array $lines, array $extra = []): array
    {
        return $this->actingAs($customer, 'customer')->postJson(route('store.submitOrder', $store), array_merge([
            'items' => json_encode(array_map(fn ($l) => ['id' => $l[0]->id, 'quantity' => $l[1], 'variants' => 0, 'extras' => [], 'mods' => []], $lines)),
            'name' => 'Ada', 'phone-number' => $customer->phone, 'ordering-type' => 'takeaway', 'pay_via' => 'cash', 'total' => '0',
        ], $extra))->assertOk()->json();
    }

    private function lineFor(Order $order, Menu $menu)
    {
        return $order->fresh()->items()->where('item_id', $menu->id)->firstOrFail();
    }

    /* ─────────────────────────────── rule ────────────────────────────── */

    public function test_the_block_is_on_for_saudi_arabia_by_default_and_off_elsewhere(): void
    {
        $this->assertTrue(PharmacyMarkets::marketRules('SA')['block_medicine_promotions']);
        $this->assertFalse(PharmacyMarkets::marketRules('AE')['block_medicine_promotions']);
        $this->assertFalse(PharmacyMarkets::marketRules(null)['block_medicine_promotions']);

        // Operators can switch a market on, or Saudi Arabia off (on counsel's advice) — the older inverse key too.
        config(['pharmacy.markets' => ['AE' => ['block_medicine_promotions' => true], 'SA' => ['promotions' => true]]]);
        $this->assertTrue(PharmacyMarkets::marketRules('AE')['block_medicine_promotions']);
        $this->assertFalse(PharmacyMarkets::marketRules('SA')['block_medicine_promotions']);
        config(['pharmacy.markets' => [], 'pharmacy.block_medicine_promotions' => true]);
        $this->assertTrue(PharmacyMarkets::marketRules('EG')['block_medicine_promotions'], 'the default for markets not listed');

        // Per item: a medicine of an SA pharmacy is blocked; its non-medicine products and other markets are not.
        config(['pharmacy.block_medicine_promotions' => false]);
        [, $medicine, $cosmetic] = $this->pharmacy('SA');
        $rules = app(PromotionRules::class);
        $this->assertTrue($rules->blocksPromotions($medicine));
        $this->assertFalse($rules->blocksPromotions($cosmetic));

        [, $aeMedicine] = $this->pharmacy('AE');
        $this->assertFalse($rules->blocksPromotions($aeMedicine), 'off outside SA by default');

        $store = $this->store('restaurant');
        $this->assertFalse($rules->blocksPromotions($this->item($store, 'Burger', 9)), 'other store types are never restricted');
        $this->assertNotSame('', $rules->reason());
    }

    /* ──────────────────── scheduled / sale / happy-hour ──────────────────── */

    public function test_sale_and_happy_hour_prices_skip_medicines_on_display(): void
    {
        [$store, $medicine, $cosmetic] = $this->pharmacy();
        $this->happyHour($store);

        $menus = Menu::whereIn('id', [$medicine->id, $cosmetic->id])->get()->keyBy('id');
        MenuScheduleService::decorate($menus->values(), (int) $store->id);
        $this->assertEqualsWithDelta(5.0, (float) $menus[$medicine->id]->price, 0.001, 'medicine keeps its list price');
        $this->assertNull($menus[$medicine->id]->schedule_label, 'no sale badge on a medicine');
        $this->assertEqualsWithDelta(8.0, (float) $menus[$cosmetic->id]->price, 0.001, 'non-medicine still on sale');
        $this->assertSame('Sale', $menus[$cosmetic->id]->schedule_label);

        // Control: the same pharmacy setup in a market without the block discounts the medicine.
        [$ae, $aeMedicine] = $this->pharmacy('AE');
        $this->happyHour($ae);
        $this->assertEqualsWithDelta(4.0, MenuScheduleService::quote($aeMedicine->fresh())['price'], 0.001);
    }

    public function test_sale_and_happy_hour_prices_skip_medicines_on_api_and_pos_orders(): void
    {
        [$store, $medicine, $cosmetic] = $this->pharmacy();
        $this->happyHour($store);

        foreach (['customer_app', 'pos'] as $source) {
            $order = OrderApiService::create((int) $store->user_id, (int) $store->id, [
                'source' => $source, 'type' => 'takeaway',
                'items' => [['item_id' => $medicine->id, 'quantity' => 1], ['item_id' => $cosmetic->id, 'quantity' => 1]],
            ]);
            $this->assertNull($this->lineFor($order, $medicine)->unit_price, "$source: no sale price snapshotted on the medicine");
            $this->assertEqualsWithDelta(8.0, (float) $this->lineFor($order, $cosmetic)->unit_price, 0.001, "$source: the cosmetic keeps its sale price");
            $this->assertEqualsWithDelta(13.0, (float) $order->fresh()->total, 0.001, "$source: 5 + 8");
        }
    }

    public function test_sale_and_happy_hour_prices_skip_medicines_on_the_storefront_order_and_the_pos_menu(): void
    {
        [$store, $medicine, $cosmetic] = $this->pharmacy();
        $this->happyHour($store);
        $customer = $this->customer($store);

        $this->submitOrder($store, $customer, [[$medicine, 1], [$cosmetic, 1]]);
        $order = Order::where('store_id', $store->id)->latest('id')->firstOrFail();
        $this->assertNull($this->lineFor($order, $medicine)->unit_price);
        $this->assertEqualsWithDelta(8.0, (float) $this->lineFor($order, $cosmetic)->unit_price, 0.001);
        $this->assertEqualsWithDelta(13.0, (float) $order->fresh()->total, 0.001);

        // POS register menu: the medicine shows its list price, no sale chip, and is flagged out of the earn preview.
        $items = collect($this->actingAs(User::find($store->user_id), 'web')->getJson(route('pos.menu'))->assertOk()->json('categories'))
            ->flatMap(fn ($c) => $c['items'])->keyBy('id');
        $this->assertEqualsWithDelta(5.0, $items[$medicine->id]['price'], 0.001);
        $this->assertNull($items[$medicine->id]['schedule_label']);
        $this->assertTrue($items[$medicine->id]['no_promotions']);
        $this->assertEqualsWithDelta(8.0, $items[$cosmetic->id]['price'], 0.001);
        $this->assertFalse($items[$cosmetic->id]['no_promotions']);
    }

    public function test_the_goods_cart_and_checkout_keep_medicines_at_list_price_and_earn_on_the_other_lines(): void
    {
        [$store, $medicine, $cosmetic] = $this->pharmacy();
        $this->happyHour($store);
        $this->program($store, 'points', 100);
        $customer = $this->customer($store);

        foreach ([[$medicine, 1], [$cosmetic, 1]] as [$menu, $qty]) {
            $this->actingAs($customer, 'customer')->post(route('storefront.goods.cart.add', ['slug' => $store->slug, 'menu' => $menu->id]), ['quantity' => $qty]);
        }
        $token = (string) Cart::query()->where('store_id', $store->id)->value('guest_token');
        $lines = app(GoodsCartService::class)->lines($store, $token)->keyBy('menu_id');
        $this->assertEqualsWithDelta(5.0, (float) $lines[$medicine->id]['unit_price'], 0.001, 'cart: medicine at list price');
        $this->assertEqualsWithDelta(8.0, (float) $lines[$cosmetic->id]['unit_price'], 0.001, 'cart: cosmetic on sale');

        $this->actingAs($customer, 'customer')->postJson(route('storefront.checkout.place', ['slug' => $store->slug]), [
            'type' => 'ship', 'address' => '1 St', 'customer_name' => 'Ada', 'phone_number' => '5551234',
        ])->assertOk();

        $order = Order::where('store_id', $store->id)->latest('id')->firstOrFail();
        $this->assertNull($this->lineFor($order, $medicine)->unit_price);
        $this->assertEqualsWithDelta(8.0, (float) $this->lineFor($order, $cosmetic)->unit_price, 0.001);
        $this->assertEqualsWithDelta(13.0, (float) $order->fresh()->total, 0.001);
        $this->assertSame(8, (int) LoyaltyAccount::forCustomer($customer->id, $store->id)->fresh()->balance, 'points on the cosmetic only');
    }

    /* ─────────────────────────── combos / bundles ─────────────────────────── */

    public function test_combos_holding_a_medicine_are_not_sold_on_any_channel(): void
    {
        [$store, $medicine, $cosmetic] = $this->pharmacy();
        $soap = $this->item($store, 'Soap', 4, false);
        $withMedicine = $this->combo($store, [$medicine, $cosmetic]);
        $cosmeticsOnly = $this->combo($store, [$cosmetic, $soap]);

        // Display (storefront, POS tiles): only the bundle without a medicine.
        $this->assertSame([$cosmeticsOnly->id], ComboService::sellable((int) $store->id)->pluck('id')->all());
        $tiles = $this->actingAs(User::find($store->user_id), 'web')->getJson(route('pos.combos'))->assertOk()->json();
        $this->assertStringNotContainsString($withMedicine->name, json_encode($tiles));

        // Orders (API + POS): a combo holding a medicine is refused with the reason, even when a non-medicine is picked.
        $slot = $withMedicine->slots->first();
        $pickCosmetic = $slot->options->firstWhere('menu_id', $cosmetic->id)->id;
        foreach (['customer_app', 'pos'] as $source) {
            try {
                OrderApiService::create((int) $store->user_id, (int) $store->id, [
                    'source' => $source, 'type' => 'takeaway',
                    'combos' => [['combo_id' => $withMedicine->id, 'quantity' => 1, 'picks' => [['slot_id' => $slot->id, 'option_ids' => [$pickCosmetic]]]]],
                ]);
                $this->fail("$source: a combo holding a medicine must be refused");
            } catch (ValidationException $e) {
                $this->assertStringContainsString('do not apply to medicines', implode(' ', $e->validator->errors()->all()));
            }
        }

        // The bundle without a medicine still sells.
        $okSlot = $cosmeticsOnly->slots->first();
        $order = OrderApiService::create((int) $store->user_id, (int) $store->id, [
            'source' => 'customer_app', 'type' => 'takeaway',
            'combos' => [['combo_id' => $cosmeticsOnly->id, 'quantity' => 1, 'picks' => [['slot_id' => $okSlot->id, 'option_ids' => [$okSlot->options->first()->id]]]]],
        ]);
        $this->assertEqualsWithDelta(12.0, (float) $order->fresh()->total, 0.001);

        // Control: a market without the block sells the same bundle.
        [$ae, $aeMedicine, $aeCosmetic] = $this->pharmacy('AE');
        $aeCombo = $this->combo($ae, [$aeMedicine, $aeCosmetic]);
        $this->assertSame([$aeCombo->id], ComboService::sellable((int) $ae->id)->pluck('id')->all());
    }

    /* ─────────────────────────── loyalty earn ─────────────────────────── */

    public function test_no_loyalty_points_are_earned_on_medicine_lines(): void
    {
        [$store, $medicine, $cosmetic] = $this->pharmacy();
        $this->program($store, 'points', 100); // 1 point per 1.00 spent
        $customer = $this->customer($store);
        $balance = fn () => (int) LoyaltyAccount::forCustomer($customer->id, $store->id)->fresh()->balance;

        // Earn seam (POS, payment callbacks, marketplace): a mixed order earns on the cosmetic only (10 of 15).
        $mixed = OrderApiService::create((int) $store->user_id, (int) $store->id, [
            'source' => 'pos', 'type' => 'takeaway', 'customer_id' => $customer->id,
            'items' => [['item_id' => $medicine->id, 'quantity' => 1], ['item_id' => $cosmetic->id, 'quantity' => 1]],
        ]);
        LoyaltyAccrual::earnForOrder($mixed->fresh());
        $this->assertSame(10, $balance());

        // A medicine-only order earns nothing.
        $onlyMedicine = OrderApiService::create((int) $store->user_id, (int) $store->id, [
            'source' => 'customer_app', 'type' => 'takeaway', 'customer_id' => $customer->id,
            'items' => [['item_id' => $medicine->id, 'quantity' => 2]],
        ]);
        LoyaltyAccrual::earnForOrder($onlyMedicine->fresh());
        $this->assertSame(10, $balance());

        // Storefront checkout (web): earns through the same seam, on the cosmetic only.
        $this->submitOrder($store, $customer, [[$medicine, 1], [$cosmetic, 1]]);
        $this->assertSame(20, $balance());

        // Stamps: a stamp needs a non-medicine line.
        LoyaltyProgram::where('store_id', $store->id)->update(['type' => 'stamps']);
        LoyaltyAccrual::earnForOrder(OrderApiService::create((int) $store->user_id, (int) $store->id, [
            'source' => 'pos', 'type' => 'takeaway', 'customer_id' => $customer->id, 'items' => [['item_id' => $medicine->id, 'quantity' => 1]],
        ])->fresh());
        $this->assertSame(20, $balance());
        LoyaltyAccrual::earnForOrder(OrderApiService::create((int) $store->user_id, (int) $store->id, [
            'source' => 'pos', 'type' => 'takeaway', 'customer_id' => $customer->id, 'items' => [['item_id' => $cosmetic->id, 'quantity' => 1]],
        ])->fresh());
        $this->assertSame(21, $balance());
    }

    /* ─────────────────────────── loyalty redeem ─────────────────────────── */

    public function test_no_loyalty_points_are_redeemed_on_medicine_lines(): void
    {
        [$store, $medicine, $cosmetic] = $this->pharmacy();
        $this->program($store, 'points', 1, 5.0, 5);
        $customer = $this->customer($store);
        LoyaltyAccount::forCustomer($customer->id, $store->id)->earn(990001, 50, 'seed');
        $balance = fn () => (int) LoyaltyAccount::forCustomer($customer->id, $store->id)->fresh()->balance;

        // API: a medicine-only order spends no points and gets no reward.
        $order = OrderApiService::create((int) $store->user_id, (int) $store->id, [
            'source' => 'customer_app', 'type' => 'takeaway', 'customer_id' => $customer->id, 'loyalty_redeem' => true,
            'items' => [['item_id' => $medicine->id, 'quantity' => 2]],
        ]);
        $this->assertEqualsWithDelta(0.0, (float) $order->fresh()->discount_amount, 0.001);
        $this->assertSame(50, $balance());

        // API: with a non-medicine line worth at least the reward, the reward applies (non-medicine lines unaffected).
        $order = OrderApiService::create((int) $store->user_id, (int) $store->id, [
            'source' => 'customer_app', 'type' => 'takeaway', 'customer_id' => $customer->id, 'loyalty_redeem' => true,
            'items' => [['item_id' => $medicine->id, 'quantity' => 1], ['item_id' => $cosmetic->id, 'quantity' => 1]],
        ]);
        $this->assertEqualsWithDelta(5.0, (float) $order->fresh()->discount_amount, 0.001);
        $this->assertSame(45, $balance());

        // Web (storefront checkout): refused on a medicine-only order, and the customer is told why.
        $json = $this->submitOrder($store, $customer, [[$medicine, 1]], ['loyalty_redeem' => '1']);
        $this->assertTrue($json['promotions_restricted']);
        $this->assertStringContainsString('do not apply to medicines', (string) $json['promotions_message']);
        $this->assertSame(45, $balance());

        // Web (goods checkout): the shared step refuses it too.
        $goods = Order::create(['store_id' => $store->id, 'type' => 'takeaway', 'status' => 'pending', 'customer_id' => $customer->id]);
        $goods->items()->create(['item_id' => $medicine->id, 'quantity' => 1, 'variation' => 0]);
        app(GoodsOrderService::class)->applyDiscounts($goods, $store, ['loyalty_redeem' => '1'], 5.0);
        $this->assertEqualsWithDelta(0.0, (float) $goods->fresh()->discount_amount, 0.001);
        $this->assertSame(45, $balance());

        // Control: a restaurant redeems as before.
        $otherStore = $this->store('restaurant');
        $this->program($otherStore, 'points', 1, 5.0, 5);
        $diner = $this->customer($otherStore);
        LoyaltyAccount::forCustomer($diner->id, $otherStore->id)->earn(990002, 10, 'seed');
        $burger = $this->item($otherStore, 'Burger', 12);
        $order = OrderApiService::create((int) $otherStore->user_id, (int) $otherStore->id, [
            'source' => 'customer_app', 'type' => 'takeaway', 'customer_id' => $diner->id, 'loyalty_redeem' => true,
            'items' => [['item_id' => $burger->id, 'quantity' => 1]],
        ]);
        $this->assertEqualsWithDelta(5.0, (float) $order->fresh()->discount_amount, 0.001);
    }

    /* ─────────────────────────────── coupons ─────────────────────────────── */

    public function test_coupons_are_refused_on_medicine_orders_on_every_channel(): void
    {
        [$store, $medicine, $cosmetic] = $this->pharmacy();
        $customer = $this->customer($store);

        // API.
        $order = OrderApiService::create((int) $store->user_id, (int) $store->id, [
            'source' => 'customer_app', 'type' => 'takeaway', 'customer_id' => $customer->id, 'coupon_code' => $this->coupon($store)->code,
            'items' => [['item_id' => $medicine->id, 'quantity' => 1], ['item_id' => $cosmetic->id, 'quantity' => 1]],
        ]);
        $this->assertNull($order->fresh()->coupon_id);
        $this->assertEqualsWithDelta(15.0, (float) $order->fresh()->total, 0.001);

        // POS: the coupon endpoint answers with the reason.
        $pos = OrderApiService::create((int) $store->user_id, (int) $store->id, [
            'source' => 'pos', 'type' => 'takeaway', 'items' => [['item_id' => $medicine->id, 'quantity' => 1]],
        ]);
        $this->actingAs(User::find($store->user_id), 'web')->postJson(route('pos.orders.coupon', $pos->id), ['coupon_code' => $this->coupon($store)->code])
            ->assertStatus(422)->assertJsonFragment(['message' => 'Coupons cannot be used on medicines in this market.']);

        // Web: coupon declined, and the customer is told why.
        $json = $this->submitOrder($store, $customer, [[$medicine, 1]], ['coupon_code' => $this->coupon($store)->code]);
        $this->assertTrue($json['coupon_declined']);
        $this->assertTrue($json['promotions_restricted']);

        // Non-medicine-only orders and markets without the block take the coupon.
        $order = OrderApiService::create((int) $store->user_id, (int) $store->id, [
            'source' => 'customer_app', 'type' => 'takeaway', 'customer_id' => $customer->id, 'coupon_code' => $this->coupon($store)->code,
            'items' => [['item_id' => $cosmetic->id, 'quantity' => 1]],
        ]);
        $this->assertNotNull($order->fresh()->coupon_id);
        [$ae, $aeMedicine] = $this->pharmacy('AE');
        $order = OrderApiService::create((int) $ae->user_id, (int) $ae->id, [
            'source' => 'customer_app', 'type' => 'takeaway', 'customer_id' => $this->customer($ae)->id, 'coupon_code' => $this->coupon($ae)->code,
            'items' => [['item_id' => $aeMedicine->id, 'quantity' => 1]],
        ]);
        $this->assertNotNull($order->fresh()->coupon_id);
    }

    /* ─────────────────────────────── owner ─────────────────────────────── */

    public function test_the_owner_sees_the_rule_of_their_market(): void
    {
        [$store] = $this->pharmacy();
        $this->actingAs(User::find($store->user_id), 'web')->get(route('pharmacy.compliance.index', $store))->assertOk()
            ->assertSee('Promotions on medicines')->assertSee('Switched off in your market');

        // A new licence for another market moves the store's rule at once (the per-store memo is flushed).
        config(['pharmacy.enabled_countries' => ['SA', 'AE']]);
        PharmacyMarket::licence($store, PharmacyLicence::APPROVED, 'AE');
        $this->actingAs(User::find($store->user_id), 'web')->get(route('pharmacy.compliance.index', $store))->assertOk()
            ->assertSee('Allowed in your market.');
        $this->assertFalse(app(PromotionRules::class)->storeBlocksPromotions((int) $store->id));
    }
}
