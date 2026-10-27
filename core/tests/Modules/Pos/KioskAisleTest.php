<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Pos;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Order;
use App\Models\StoreOption;
use App\Support\KioskContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\AisleStore;
use Tests\Support\PinsMiddayClock;
use Tests\Support\StorefrontApi\ApiFixtures;
use Tests\TestCase;

/**
 * P3-08: the kiosk in the retail theme `aisle` (P3-06 pattern): its own start screen, the store page as the kiosk layout, a
 * kiosk order of goods needs no name or phone and is paid at the counter (the retail checkout's kiosk branch), the done screen.
 */
class KioskAisleTest extends TestCase
{
    use PinsMiddayClock;
    use RefreshDatabase;

    private AisleStore $shop;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        config(['settings.admin_allow_kiosk' => true]);
        $this->shareLayoutGlobals();
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
        $this->shop = AisleStore::make();
        $id = (int) $this->shop->store->id;
        foreach (['fulfillment_enabled_modes' => json_encode(['ship', 'in_store']), 'kiosk_enabled' => '1', 'kiosk_exit_pin' => '4321', 'kiosk_idle_seconds' => '45'] as $k => $v) {
            StoreOption::updateStoreOption($id, $k, $v);
        }
    }

    private const SLUG = 'dune-and-co-demo';

    public function test_the_start_screen_is_the_themes_own_and_marks_the_session(): void
    {
        $res = $this->get('/'.self::SLUG.'/kiosk')->assertOk();
        $res->assertSee('data-theme-slug="aisle"', false)->assertSee('data-kiosk-start', false)->assertSee('data-kiosk-go', false)
            ->assertSee('data-kiosk-exit', false)->assertSee('?kiosk=1', false);
        $this->assertTrue(KioskContext::active((int) $this->shop->store->id));
    }

    public function test_the_store_page_is_the_kiosk_layout_only_for_a_kiosk_session_and_has_no_account_or_wishlist(): void
    {
        $plain = $this->get('/'.self::SLUG.'?kiosk=1')->assertOk()->getContent();
        $this->assertStringNotContainsString('c-kiosk__panel', $plain, 'the URL alone never turns the kiosk on');

        $this->get('/'.self::SLUG.'/kiosk')->assertOk();
        $html = $this->get('/'.self::SLUG.'?kiosk=1')->assertOk()->getContent();
        foreach (['p-kiosk', 'c-kiosk__panel', 'data-kiosk-overlay="idle"', 'data-kiosk-overlay="pin"'] as $needle) {
            $this->assertStringContainsString($needle, $html, $needle);
        }
        foreach (['data-kit-wish="', 'c-cartbar', 'data-kit-notify-open', 'name="phone"', 'name="line1"'] as $gone) {
            $this->assertStringNotContainsString($gone, $html, $gone);
        }
    }

    public function test_a_kiosk_order_needs_no_phone_and_is_paid_at_the_counter_but_the_same_cart_elsewhere_still_needs_contact(): void
    {
        $cart = ['order_type' => 'pickup', 'lines' => [['item' => ApiFixtures::TROUSERS, 'qty' => 1, 'variant' => ApiFixtures::TROUSERS_NAVY_M]]];
        $place = fn (array $extra = []) => $this->postJson(route('storefront.retail.place', ['slug' => self::SLUG]), $cart + $extra, ['Idempotency-Key' => (string) Str::uuid7()]);

        $this->get('/'.self::SLUG.'/kiosk')->assertOk();
        $before = Order::where('store_id', $this->shop->store->id)->count();
        $res = $place(['pay_via' => 'online']);
        $this->assertContains($res->status(), [200, 201], $res->getContent());
        $res->assertJson(['success' => true]);
        $this->assertArrayNotHasKey('payment_url', $res->json(), 'a kiosk never goes to the hosted pay page');
        $order = Order::where('store_id', $this->shop->store->id)->latest('id')->firstOrFail();
        $this->assertSame($before + 1, Order::where('store_id', $this->shop->store->id)->count());
        $this->assertSame(___('Guest'), $order->customer_name);
        $this->assertEmpty($order->phone_number);

        $this->get($res->json('track_url'))->assertOk()->assertSee('data-kiosk-done', false);
    }
}
