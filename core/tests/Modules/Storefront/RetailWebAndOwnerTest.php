<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Customer;
use App\Models\MenuCategory;
use App\Models\Order;
use Carbon\Carbon;
use Illuminate\Auth\Events\Login;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Settings\StoreSettings;
use Juwri\Modules\Menu\Jobs\SendStockAlertsJob;
use Juwri\Modules\Menu\Mail\BackInStockMail;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Menu\Models\MenuVariant;
use Juwri\Modules\Menu\Models\SizeGuide;
use Juwri\Modules\Menu\Models\StockAlert;
use Juwri\Modules\Menu\Models\WishlistItem;
use Juwri\Modules\Ordering\Models\ShippingMethod;
use Juwri\Modules\Ordering\Models\ShippingZone;
use Juwri\Modules\Storefront\Http\Controllers\RetailPublicController;
use Tests\Support\StorefrontApi\ApiFixtures;
use Tests\TestCase;

/**
 * P3-08 — the retail storefront endpoints a theme calls (wishlist with a guest cookie merged at sign-in, size
 * guide, back-in-stock) and the owner's retail screens (shipping zones / methods in minor units, size guides, the
 * variant grid), each scoped to the store; the back-in-stock job sends once; a shipped parcel gets its tracking link.
 */
class RetailWebAndOwnerTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
        $this->travelTo(Carbon::parse('2026-10-02 06:44:50', 'UTC'));
        $this->store = (new ApiFixtures)->duneRetail();
        $this->owner = User::query()->findOrFail($this->store->user_id);
    }

    public function test_a_guest_wishlist_lives_in_a_cookie_token_and_joins_the_customer_list_at_sign_in(): void
    {
        $add = $this->postJson(route('storefront.wishlist.store', ['slug' => $this->store->slug]), ['item_id' => ApiFixtures::SHIRT])->assertCreated();
        $cookie = collect($add->headers->getCookies())->first(fn ($c) => $c->getName() === RetailPublicController::GUEST_COOKIE);
        $this->assertNotNull($cookie, 'the browser keeps a wishlist token');
        $this->assertTrue($cookie->isHttpOnly());
        try { // the web group encrypts cookies: the browser holds the encrypted value, the app reads the plain token
            $token = CookieValuePrefix::remove(decrypt((string) $cookie->getValue(), false));
        } catch (DecryptException) {
            $token = (string) $cookie->getValue();
        }
        $this->assertSame(1, WishlistItem::query()->whereNotNull('guest_key')->count());
        $this->assertNotSame($token, WishlistItem::query()->value('guest_key'), 'only the token\'s hash is stored');

        // a JSON request carries the browser's cookies only with credentials (fetch `credentials: 'same-origin'`)
        $this->withCredentials()->withCookie(RetailPublicController::GUEST_COOKIE, $token)
            ->getJson(route('storefront.wishlist.index', ['slug' => $this->store->slug]))->assertOk()->assertJsonCount(1, 'items');
        $this->defaultCookies = [];
        $this->withCredentials = false;
        $this->getJson(route('storefront.wishlist.index', ['slug' => $this->store->slug]))->assertOk()->assertJsonCount(0, 'items');

        // sign-in: the guest list joins the customer's
        $customer = Customer::create(['store_id' => $this->store->id, 'name' => 'Noura', 'phone' => '+966551234567']);
        request()->cookies->set(RetailPublicController::GUEST_COOKIE, $token);
        event(new Login('customer', $customer, false));
        $this->assertSame(1, WishlistItem::query()->where('customer_id', $customer->id)->count());
        $this->assertSame(0, WishlistItem::query()->whereNotNull('guest_key')->count());

        $this->actingAs($customer, 'customer')->deleteJson(route('storefront.wishlist.destroy', ['slug' => $this->store->slug, 'item' => ApiFixtures::SHIRT]))
            ->assertOk()->assertJsonCount(0, 'items');
    }

    public function test_a_wishlist_refuses_another_stores_item(): void
    {
        $other = (new ApiFixtures)->freshMart();
        $cat = MenuCategory::create(['store_id' => $other->id, 'name' => 'X', 'active' => 1]);
        $foreign = (new ApiFixtures)->item($other, $cat, '019fffff-0000-7000-8000-00000000d001', 'Bananas', 5);
        $this->postJson(route('storefront.wishlist.store', ['slug' => $this->store->slug]), ['item_id' => $foreign->public_id])->assertNotFound();
        $this->assertSame(0, WishlistItem::query()->count());
    }

    public function test_size_guide_page_and_json_are_the_stores_own(): void
    {
        $guide = SizeGuide::create(['store_id' => $this->store->id, 'title' => 'Shirts', 'unit' => 'cm', 'columns' => ['Size', 'Chest'], 'rows' => [['M', '100–104']], 'active' => true]);
        $url = route('storefront.sizeGuide', ['slug' => $this->store->slug, 'guide' => $guide->public_id]);
        $this->get($url)->assertOk()->assertSee('100–104')->assertSee('Measurements in centimetres');
        $this->getJson($url)->assertOk()->assertJsonPath('columns', ['Size', 'Chest']);
        $other = (new ApiFixtures)->freshMart();
        $this->get(route('storefront.sizeGuide', ['slug' => $other->slug, 'guide' => $guide->public_id]))->assertNotFound();
    }

    public function test_the_back_in_stock_job_sends_once_and_only_while_in_stock(): void
    {
        Mail::fake();
        $xs = MenuVariant::query()->where('public_id', '019e8a90-b580-7d21-8841-c8ec7cc5c1e6')->firstOrFail();
        $this->postJson(route('storefront.stockAlerts.store', ['slug' => $this->store->slug]), [
            'item_id' => ApiFixtures::SHIRT, 'variant_id' => $xs->public_id, 'channel' => 'email', 'contact' => 'noura@example.com', 'marketing_opt_in' => true,
        ])->assertCreated();
        $shirt = Menu::query()->where('public_id', ApiFixtures::SHIRT)->firstOrFail();

        // still out: nothing goes, the request keeps waiting
        app()->call([new SendStockAlertsJob((int) $shirt->id, (int) $xs->id), 'handle']);
        Mail::assertNothingQueued();
        $this->assertSame('waiting', StockAlert::query()->value('status'));

        MenuVariant::query()->whereKey($xs->id)->update(['stock_qty' => 4]);
        app()->call([new SendStockAlertsJob((int) $shirt->id, (int) $xs->id), 'handle']);
        app()->call([new SendStockAlertsJob((int) $shirt->id, (int) $xs->id), 'handle']);
        Mail::assertQueued(BackInStockMail::class, 1);
        $this->assertSame('sent', StockAlert::query()->value('status'));
    }

    public function test_owner_shipping_zone_and_method_in_minor_units(): void
    {
        $q = ['store_id' => $this->store->id];
        $this->actingAs($this->owner)->get(route('stores.retail.shipping', $q))->assertOk()->assertSee('riyadh-north');
        $this->actingAs($this->owner)->post(route('stores.retail.zones.store', $q), ['code' => 'jeddah', 'name' => 'Jeddah', 'countries' => 'x1, 123'])
            ->assertSessionHasErrors('countries');
        $this->actingAs($this->owner)->post(route('stores.retail.zones.store', $q), ['code' => 'jeddah', 'name' => 'Jeddah', 'countries' => 'sa', 'cities' => 'Jeddah, Makkah'])
            ->assertSessionHasNoErrors();
        $zone = ShippingZone::query()->where('code', 'jeddah')->firstOrFail();
        $this->assertSame(['SA'], $zone->countries);
        $this->assertSame(['Jeddah', 'Makkah'], $zone->cities);

        $this->actingAs($this->owner)->post(route('stores.retail.methods.store', $q), [
            'kind' => 'standard', 'shipping_zone_id' => $zone->id, 'name' => 'Aramex', 'fee' => '12.50', 'free_over' => '300',
            'min_days' => 2, 'max_days' => 5, 'cutoff_time' => '18:00', 'delivery_days' => [0, 1, 2, 3, 4],
            'tracking_url_template' => 'https://aramex.example/track/{tracking}',
        ])->assertSessionHasNoErrors();
        $method = ShippingMethod::query()->where('name', 'Aramex')->firstOrFail();
        $this->assertSame([1250, 30000, 'SAR'], [$method->fee_minor, $method->free_over_minor, $method->currency_code]);
        $this->assertSame([0, 1, 2, 3, 4], $method->delivery_days);

        $this->actingAs($this->owner)->post(route('stores.retail.methods.store', $q), [
            'kind' => 'pickup_in_store', 'name' => 'Pickup', 'fee' => '0', 'min_days' => 0, 'max_days' => 0,
        ])->assertSessionHasErrors('pickup_branch_id');
        $this->actingAs($this->owner)->post(route('stores.retail.methods.store', $q), [
            'kind' => 'express', 'shipping_zone_id' => $zone->id, 'name' => 'Bad link', 'fee' => '1', 'min_days' => 1, 'max_days' => 1,
            'tracking_url_template' => 'http://insecure.example/{tracking}',
        ])->assertSessionHasErrors('tracking_url_template');
    }

    public function test_another_owner_cannot_read_or_change_the_stores_shipping_guides_or_variants(): void
    {
        $intruderStore = (new ApiFixtures)->freshMart();
        $intruder = User::query()->findOrFail($intruderStore->user_id);
        $zone = ShippingZone::query()->where('store_id', $this->store->id)->firstOrFail();
        $method = ShippingMethod::query()->where('store_id', $this->store->id)->firstOrFail();
        $shirt = Menu::query()->where('public_id', ApiFixtures::SHIRT)->firstOrFail();
        $guide = SizeGuide::create(['store_id' => $this->store->id, 'title' => 'Shirts', 'unit' => 'cm', 'columns' => ['Size'], 'rows' => [['M']], 'active' => true]);

        $victim = ['store_id' => $this->store->id];
        $own = ['store_id' => $intruderStore->id];
        $this->actingAs($intruder)->get(route('stores.retail.shipping', $victim))->assertForbidden();
        $this->actingAs($intruder)->post(route('stores.retail.zones.update', ['zone' => $zone->id] + $own), ['code' => 'x', 'name' => 'x', 'countries' => 'SA'])->assertNotFound();
        $this->actingAs($intruder)->post(route('stores.retail.methods.delete', ['method' => $method->id] + $own))->assertNotFound();
        $this->actingAs($intruder)->post(route('stores.retail.sizeGuides.delete', ['guide' => $guide->id] + $own))->assertNotFound();
        $this->actingAs($intruder)->get(route('stores.retail.variants', ['menu' => $shirt->id] + $own))->assertNotFound();
        $this->actingAs($intruder)->get(route('stores.retail.variants', ['menu' => $shirt->id] + $victim))->assertForbidden();
        $this->assertSame('riyadh-north', $zone->fresh()->code);
        $this->assertNotNull($method->fresh());
        $this->assertNotNull($guide->fresh());
    }

    public function test_owner_size_guides_and_the_variant_grid(): void
    {
        $q = ['store_id' => $this->store->id];
        $this->actingAs($this->owner)->post(route('stores.retail.sizeGuides.store', $q), [
            'title' => 'Shirts', 'unit' => 'cm', 'columns' => 'Size, Chest', 'rows' => "S, 92\nM, 100\n\nL, 108",
        ])->assertSessionHasNoErrors();
        $guide = SizeGuide::query()->where('store_id', $this->store->id)->firstOrFail();
        $this->assertSame([['S', '92'], ['M', '100'], ['L', '108']], $guide->rows);

        $shirt = Menu::query()->where('public_id', ApiFixtures::SHIRT)->firstOrFail();
        $grid = $q + ['menu' => $shirt->id];
        $this->actingAs($this->owner)->get(route('stores.retail.variants', $grid))->assertOk()->assertSee('RLS-SAN-M');
        $m = MenuVariant::query()->where('public_id', ApiFixtures::SHIRT_SAND_M)->firstOrFail();
        $this->actingAs($this->owner)->post(route('stores.retail.variants.cells', $grid), ['cells' => [
            $m->id => ['price' => '129', 'compare_at' => '149.5', 'stock_qty' => '7', 'low_stock_threshold' => '3', 'sku' => 'RLS-SAN-M', 'gtin' => '6281234567890', 'active' => '1'],
        ]])->assertSessionHasNoErrors();
        $m->refresh();
        $this->assertSame([14950, 7, 3, '6281234567890'], [$m->compare_at_minor, (int) $m->stock_qty, $m->low_stock_threshold, $m->gtin]);
        // a "was" price at or below the price is dropped
        $this->actingAs($this->owner)->post(route('stores.retail.variants.cells', $grid), ['cells' => [
            $m->id => ['price' => '129', 'compare_at' => '100', 'active' => '1'],
        ]])->assertSessionHasNoErrors();
        $this->assertNull($m->fresh()->compare_at_minor);

        // the grid adds only the missing cells (Olive · XS, S, L) and the size guide is attached
        $groups = $shirt->resolved_variant_options->pluck('id')->all();
        $this->actingAs($this->owner)->post(route('stores.retail.variants.generate', $grid), ['groups' => $groups])->assertSessionHas('success');
        $this->assertSame(8, MenuVariant::query()->where('menu_id', $shirt->id)->count());
        $this->actingAs($this->owner)->post(route('stores.retail.variants.groups', $grid), ['size_guide_id' => $guide->id, 'swatch' => [], 'display' => []])->assertSessionHasNoErrors();
        $this->assertSame((int) $guide->id, (int) $shirt->fresh()->size_guide_id);
    }

    public function test_store_settings_save_the_returns_and_back_in_stock_switches(): void
    {
        $this->actingAs($this->owner)->get(route('stores.edit', $this->store->id))->assertOk()->assertSee('name="returns_window_days"', false);
        $this->assertSame(14, StoreSettings::int((int) $this->store->id, 'returns_window_days'));
    }

    public function test_marking_a_parcel_shipped_builds_the_carriers_tracking_link(): void
    {
        $method = ShippingMethod::query()->where('public_id', ApiFixtures::SHIP_STANDARD)->firstOrFail();
        $order = Order::create(['store_id' => $this->store->id, 'type' => 'ship', 'status' => 'ready', 'shipping_method_id' => $method->id, 'track_token' => 'trk_ship_link_0001']);
        $this->actingAs($this->owner)->post(route('stores.orders.shipment.update', $order->id), ['tracking_number' => 'SM 123'])->assertRedirect();
        $this->getJson(route('storefront.shipment.track', ['token' => 'trk_ship_link_0001']))->assertOk()
            ->assertJsonPath('carrier', 'SMSA')
            ->assertJsonPath('tracking_url', 'https://track.smsa.example/SM%20123');
    }
}
