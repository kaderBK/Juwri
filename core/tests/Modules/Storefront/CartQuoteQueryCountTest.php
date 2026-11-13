<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront;

use App\Http\Middleware\EnsureStaffDashboardPermission;
use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Coupon;
use App\Models\MenuCategory;
use App\Models\StoreOption;
use App\Services\FeatureAccess;
use App\Services\FeatureRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Juwri\Core\Models\EntitlementOverride;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Settings\StoreSettings;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Menu\Models\MenuExtra;
use Juwri\Modules\Menu\Models\MenuVariant;
use Juwri\Modules\Menu\Models\MenuVariantOption;
use Juwri\Modules\Ordering\Services\Fulfillment\FulfillmentSettingsService;
use Tests\Support\PharmacyMarket;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * P4-16b: the storefront cart quotes ({store}/quote for the legacy food themes, {store}/cart-quote for the goods
 * cart) do not grow with the cart. Before: food 18 / 36 / 63 queries for 1 / 3 / 6 lines, goods 23 / 35 / 53;
 * after: 17 and 23 for every size (most of them the plan / entitlement / market checks of the request).
 */
class CartQuoteQueryCountTest extends TestCase
{
    use RefreshDatabase;

    private const FOOD_BUDGET = 17;

    private const GOODS_BUDGET = 23;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        config(['settings.admin_allow_combos' => true, 'settings.storefront_order_pipeline_stores' => '', 'pharmacy.block_medicine_promotions' => false]);
        FeatureRegistry::clearCache();
        FeatureAccess::flush();
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class, EnsureStaffDashboardPermission::class]);
    }

    /** @return array{0: int, 1: list<string>} */
    private function measure(callable $call): array
    {
        $call(); // warm the per-process caches
        DB::flushQueryLog();
        DB::enableQueryLog();
        $call();
        $log = array_map(fn ($q) => $q['query'], DB::getQueryLog());
        DB::disableQueryLog();

        return [count($log), $log];
    }

    /**
     * @param  array<int, int>  $counts
     * @param  array<int, list<string>>  $logs
     */
    private function assertFlat(array $counts, array $logs, int $budget): void
    {
        $why = json_encode($counts).' — 3 lines: '.json_encode(array_count_values($logs[3]));
        $this->assertLessThanOrEqual($budget, $counts[3], 'queries for a 3-line cart: '.$why);
        $this->assertSame([$counts[1]], array_values(array_unique($counts)), 'the query count grows with the lines: '.$why);
    }

    public function test_the_legacy_food_quote_is_flat_as_the_cart_grows(): void
    {
        $store = $this->store('restaurant', 'aurora');
        $this->coupon($store);
        $counts = $logs = [];
        foreach ([1, 3, 6] as $n) {
            $items = [];
            for ($i = 0; $i < $n; $i++) {
                [$item, $variant, $extra] = $this->goodsItem($store);
                $items[] = ['id' => $item->id, 'quantity' => 2, 'variants' => $variant->id, 'extras' => [['id' => $extra->id]]];
            }
            $body = ['items' => $items, 'ordering-type' => 'takeaway', 'coupon_code' => 'SAVE10'];
            [$counts[$n], $logs[$n]] = $this->measure(fn () => $this->postJson(route('store.quote', $store->slug), $body)->assertOk());
        }
        $this->assertFlat($counts, $logs, self::FOOD_BUDGET);
    }

    public function test_the_goods_cart_quote_is_flat_as_the_cart_grows(): void
    {
        $store = $this->store('pharmacy', 'carely');
        PharmacyMarket::open($store, 'KW');
        app(FulfillmentSettingsService::class)->update($store, ['ship']);
        $this->coupon($store);
        $counts = $logs = [];
        $lines = 0;
        foreach ([1, 3, 6] as $n) {
            for (; $lines < $n; $lines++) {
                [$item, $variant, $extra] = $this->goodsItem($store);
                $this->post(route('storefront.goods.cart.add', ['slug' => $store->slug, 'menu' => $item->id]), ['quantity' => 2, 'variation' => $variant->id, 'extras' => [$extra->id]])->assertSessionHasNoErrors();
            }
            [$counts[$n], $logs[$n]] = $this->measure(fn () => $this->postJson(route('store.cartQuote', $store->slug), ['type' => 'ship', 'coupon_code' => 'SAVE10', 'flow' => 'goods'])->assertOk());
        }
        $this->assertFlat($counts, $logs, self::GOODS_BUDGET);
    }

    private function store(string $type, string $theme): Store
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(4), 'description' => 'T', 'monthly_price' => 10, 'annual_price' => 100, 'lifetime_price' => 1000, 'status' => 1,
            'settings' => ['restaurant_limit' => 5, 'menu_limit' => 999, 'category_limit' => 999, 'allow_ordering' => '1'],
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now(), 'country_code' => 'KW']);
        EntitlementOverride::create(['user_id' => $owner->id, 'feature_key' => 'multi_vertical', 'value' => '1', 'source_type' => 'admin']);
        $title = 'S'.Str::random(6);
        $store = Store::create([
            'user_id' => $owner->id, 'store_type' => $type, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)), 'color' => '#111',
            'title' => $title, 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'default.png', 'cover_image' => 'default.png',
            'address' => '1 St', 'phone' => '1234', 'created_at' => now(), 'updated_at' => now(),
        ]);
        StoreOption::updateStoreOption($store->id, 'store_template', $theme);
        StoreOption::updateStoreOption($store->id, 'allow_order', 1);
        StoreSettings::setMany((int) $store->id, [
            'currency_code' => 'KWD', 'currency_sign' => 'KWD', 'restaurant_tax_type' => 'fixed', 'restaurant_taxx_charge' => '0.250',
        ]);
        FeatureRegistry::clearCache();
        FeatureAccess::flush();

        return $store;
    }

    /** @return array{0: Menu, 1: MenuVariant, 2: MenuExtra} */
    private function goodsItem(Store $store): array
    {
        $cat = MenuCategory::firstOrCreate(['store_id' => $store->id, 'name' => 'Cat']);
        $item = Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Cream'.Str::random(3), 'price' => 2.125, 'active' => 1]);
        $size = MenuVariantOption::create(['menu_id' => $item->id, 'title' => 'Size', 'options' => ['50 ml', '100 ml'], 'position' => 0, 'active' => 1]);
        $variant = MenuVariant::create(['menu_id' => $item->id, 'price' => 3.375, 'options' => [$size->id => 1], 'active' => 1, 'position' => 0]);
        $extra = MenuExtra::create(['menu_id' => $item->id, 'title' => 'Gift wrap', 'price' => 0.250, 'position' => 0, 'active' => 1]);

        return [$item, $variant, $extra];
    }

    private function coupon(Store $store): void
    {
        Coupon::create([
            'user_id' => $store->user_id, 'code' => 'SAVE10', 'coupon_type' => 'percentage', 'percentage' => 10,
            'limit' => 0, 'used' => 0, 'applies_to' => 'food_order', 'status' => 1,
        ]);
    }
}
