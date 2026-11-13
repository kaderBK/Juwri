<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Ordering;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Order;
use App\Models\StoreOption;
use App\Services\OrderApiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * MC-14 — the order currency stamp (MC-10) lands on EVERY creation path, because
 * the stamp is a model creating() hook that fires on any Eloquent create. Covers
 * the storefront checkout and the OrderApiService (POS/kiosk/agent/API) paths,
 * the null-store-currency fallback, and immunity to a later store currency
 * change. Marketplace / WhatsApp / aggregator all create through the same hook.
 */
class OrderCurrencySnapshotTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
    }

    private function store(?string $currency): array
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 'T', 'monthly_price' => 1, 'annual_price' => 1,
            'lifetime_price' => 1, 'status' => 1,
            'settings' => ['restaurant_limit' => 999, 'menu_limit' => 999, 'allow_ordering' => '1'],
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $title = 'R '.Str::random(5);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1 St', 'phone' => '1',
        ]);
        if ($currency !== null) {
            StoreOption::updateStoreOption($store->id, 'currency_code', $currency);
        }
        $menu = Menu::create(['store_id' => $store->id, 'name' => 'Dish', 'price' => 10, 'active' => 1]);

        return [$store, $menu];
    }

    private function latestOrder(Store $store): Order
    {
        return Order::where('store_id', $store->id)->orderByDesc('id')->firstOrFail();
    }

    public function test_storefront_checkout_stamps_currency(): void
    {
        [$store, $menu] = $this->store('OMR');

        $this->post(route('store.submitOrder', $store), [
            'items' => json_encode([['id' => $menu->id, 'quantity' => 1, 'variants' => 0, 'extras' => [], 'mods' => []]]),
            'name' => 'Ada', 'phone-number' => '55-'.rand(1000, 9999),
            'ordering-type' => 'takeaway', 'pay_via' => 'cash', 'total' => '0',
        ]);

        $this->assertSame('OMR', $this->latestOrder($store)->currency_code);
    }

    public function test_api_pos_path_stamps_currency(): void
    {
        [$store, $menu] = $this->store('KWD');

        $order = OrderApiService::create((int) $store->user_id, $store->id, [
            'type' => 'takeaway',
            'items' => [['item_id' => $menu->id, 'quantity' => 1]],
        ]);

        $this->assertSame('KWD', $order->fresh()->currency_code);
    }

    /**
     * P1-05 (changed): order money is stored in minor units of the stamped currency, so an order is never left
     * unstamped — a store without its own currency stamps the platform currency (what every reader already fell
     * back to for a NULL stamp).
     */
    public function test_null_store_currency_stamps_the_platform_currency(): void
    {
        [$store, $menu] = $this->store(null);

        $order = OrderApiService::create((int) $store->user_id, $store->id, [
            'type' => 'takeaway',
            'items' => [['item_id' => $menu->id, 'quantity' => 1]],
        ]);

        $this->assertSame(strtoupper((string) config('settings.currency_code', 'USD')), $order->fresh()->currency_code);
    }

    public function test_stamp_is_immune_to_a_later_store_currency_change(): void
    {
        [$store, $menu] = $this->store('OMR');

        $order = OrderApiService::create((int) $store->user_id, $store->id, [
            'type' => 'takeaway',
            'items' => [['item_id' => $menu->id, 'quantity' => 1]],
        ]);
        $this->assertSame('OMR', $order->fresh()->currencyCode());

        StoreOption::updateStoreOption($store->id, 'currency_code', 'USD');

        $this->assertSame('OMR', $order->fresh()->currencyCode(), 'stamped order keeps its currency');
    }
}
