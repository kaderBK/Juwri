<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Pos;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\MenuCategory;
use App\Models\Order;
use App\Models\StoreOption;
use App\Support\KioskContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * GAPS-RUN-2 B9-P1: kiosk web mode.
 */
class KioskWebModeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        config(['settings.admin_allow_kiosk' => true]);
        // Session stays ON: the kiosk context is a server-side session marker.
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
    }

    private function storeFixture(bool $kiosk = true): array
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 'T', 'monthly_price' => 1, 'annual_price' => 1,
            'lifetime_price' => 1, 'status' => 1, 'settings' => json_encode(['restaurant_limit' => 999, 'menu_limit' => 999, 'allow_ordering' => '1']),
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $r = Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'd.png',
            'cover_image' => 'd.png', 'address' => '1', 'phone' => '1', 'created_at' => now(), 'updated_at' => now(),
        ]);
        StoreOption::updateStoreOption($r->id, 'store_submit_order_notification', 0);
        StoreOption::updateStoreOption($r->id, 'quickorder_enable', 0);
        if ($kiosk) {
            StoreOption::updateStoreOption($r->id, 'kiosk_enabled', '1');
            StoreOption::updateStoreOption($r->id, 'kiosk_exit_pin', '4321');
            StoreOption::updateStoreOption($r->id, 'kiosk_idle_seconds', '45');
        }
        $cat = MenuCategory::create(['store_id' => $r->id, 'name' => 'C', 'position' => 1]);
        $menu = Menu::create(['store_id' => $r->id, 'category_id' => $cat->id, 'name' => 'Item', 'price' => 10, 'active' => '1', 'position' => 1]);

        return [$r, $menu];
    }

    private function payload(Menu $m, array $extra = []): array
    {
        return array_merge([
            'items' => json_encode([['id' => $m->id, 'quantity' => 1, 'variants' => 0, 'extras' => [], 'mods' => []]]),
            'tip_amount' => '0', 'name' => 'Jane', 'ordering-type' => 'takeaway', 'phone-number' => '555', 'pay_via' => 'cod',
        ], $extra);
    }

    public function test_kiosk_page_renders_when_enabled(): void
    {
        [$r] = $this->storeFixture(true);
        $this->get('/'.$r->slug.'/kiosk')->assertOk()->assertSee(___('Tap to order'));
    }

    public function test_kiosk_page_404_when_disabled(): void
    {
        [$r] = $this->storeFixture(false);
        $this->get('/'.$r->slug.'/kiosk')->assertNotFound();
    }

    public function test_kiosk_page_404_when_killswitch_off(): void
    {
        [$r] = $this->storeFixture(true);
        config(['settings.admin_allow_kiosk' => false]);
        $this->get('/'.$r->slug.'/kiosk')->assertNotFound();
    }

    public function test_order_through_the_kiosk_flow_is_tagged_kiosk_from_server_context(): void
    {
        [$r, $m] = $this->storeFixture(true);
        // The tablet opens the kiosk route first (server marks the session) …
        $this->get('/'.$r->slug.'/kiosk')->assertOk();
        // … and the storefront order carries NO client kiosk field at all.
        $this->post(route('store.submitOrder', $r), $this->payload($m))
            ->assertOk()->assertJson(['success' => true]);

        $order = Order::where('store_id', $r->id)->latest('id')->first();
        $this->assertSame('kiosk', $order->source);
    }

    public function test_normal_storefront_order_is_not_tagged_kiosk(): void
    {
        [$r, $m] = $this->storeFixture(true);
        $this->post(route('store.submitOrder', $r), $this->payload($m))->assertOk();
        $order = Order::where('store_id', $r->id)->latest('id')->first();
        $this->assertNotSame('kiosk', $order->source);
    }

    public function test_forged_kiosk_mode_field_on_the_normal_storefront_does_not_tag(): void
    {
        [$r, $m] = $this->storeFixture(true);
        $this->post(route('store.submitOrder', $r), $this->payload($m, ['kiosk_mode' => '1', 'kiosk' => '1']))
            ->assertOk();
        $order = Order::where('store_id', $r->id)->latest('id')->first();
        $this->assertNotSame('kiosk', $order->source);
    }

    public function test_kiosk_session_of_one_store_does_not_tag_another_stores_order(): void
    {
        [$a] = $this->storeFixture(true);
        [$b, $mb] = $this->storeFixture(true);
        $this->get('/'.$a->slug.'/kiosk')->assertOk();
        // P3-06: a kiosk session of store A does not even reach store B's order endpoint (KioskLock); and it is no kiosk context for B
        $this->post(route('store.submitOrder', $b), $this->payload($mb))->assertForbidden();
        $this->assertFalse(KioskContext::active((int) $b->id));
        $this->assertSame(0, Order::where('store_id', $b->id)->count());
        // an ordinary browser still orders at B and is not tagged
        $this->flushSession();
        $this->post(route('store.submitOrder', $b), $this->payload($mb))->assertOk();
        $this->assertNotSame('kiosk', Order::where('store_id', $b->id)->latest('id')->first()->source);
    }

    public function test_staff_exit_pin_ends_the_kiosk_context(): void
    {
        [$r, $m] = $this->storeFixture(true);
        $this->get('/'.$r->slug.'/kiosk')->assertOk();
        $this->postJson('/'.$r->slug.'/kiosk/exit', ['pin' => '4321'])->assertJson(['ok' => true]);
        $this->post(route('store.submitOrder', $r), $this->payload($m))->assertOk();
        $this->assertNotSame('kiosk', Order::where('store_id', $r->id)->latest('id')->first()->source);
    }

    public function test_kiosk_context_does_not_tag_when_the_owner_has_disabled_kiosk_since(): void
    {
        [$r, $m] = $this->storeFixture(true);
        $this->get('/'.$r->slug.'/kiosk')->assertOk();
        StoreOption::updateStoreOption($r->id, 'kiosk_enabled', '0');
        $this->post(route('store.submitOrder', $r), $this->payload($m))->assertOk();
        $this->assertNotSame('kiosk', Order::where('store_id', $r->id)->latest('id')->first()->source);
    }

    public function test_exit_pin_verified_server_side(): void
    {
        [$r] = $this->storeFixture(true);
        $this->postJson('/'.$r->slug.'/kiosk/exit', ['pin' => '0000'])->assertOk()->assertJson(['ok' => false]);
        $this->postJson('/'.$r->slug.'/kiosk/exit', ['pin' => '4321'])->assertOk()->assertJson(['ok' => true]);
    }

    /** Coordinator review of lane A: after "Tap to order" the storefront keeps the idle return and the PIN-gated exit. */
    public function test_a_kiosk_session_gets_the_idle_return_and_the_staff_exit_on_the_storefront_and_the_track_page(): void
    {
        [$r, $m] = $this->storeFixture(true);
        $this->get('/'.$r->slug.'/kiosk')->assertOk();

        $store = $this->get('/'.$r->slug.'?kiosk=1')->assertOk();
        $store->assertSee('id="kioskExit"', false)->assertSee('IDLE_MS = 45 * 1000', false)->assertSee("post('reset')", false);

        $this->post(route('store.submitOrder', $r), $this->payload($m))->assertOk();
        $token = Order::where('store_id', $r->id)->latest('id')->value('track_token');
        $this->get(route('store.orderTrack', $token))->assertOk()->assertSee('id="kioskExit"', false);
    }

    public function test_an_ordinary_visitor_or_a_store_without_the_kiosk_gets_no_kiosk_shell(): void
    {
        [$r] = $this->storeFixture(true);
        $this->get('/'.$r->slug)->assertOk()->assertDontSee('kioskExit', false); // never opened the kiosk route

        [$off] = $this->storeFixture(false);
        $this->get('/'.$off->slug)->assertOk()->assertDontSee('kioskExit', false);

        // a kiosk session of store A does not put the shell on store B
        [$b] = $this->storeFixture(true);
        $this->get('/'.$r->slug.'/kiosk')->assertOk();
        $this->get('/'.$b->slug)->assertRedirect('/'.$r->slug.'/kiosk'); // P3-06: the kiosk session stays at its own store
        $this->flushSession();
        $this->get('/'.$b->slug)->assertOk()->assertDontSee('kioskExit', false);
    }

    public function test_the_idle_reset_keeps_kiosk_mode_but_drops_the_guest_cart_and_404s_without_the_kiosk(): void
    {
        [$r, $m] = $this->storeFixture(true);
        $this->get('/'.$r->slug.'/kiosk')->assertOk();
        $this->withSession(['mv_goods_cart_token' => 'abc'])->postJson('/'.$r->slug.'/kiosk/reset')->assertOk()->assertJson(['ok' => true]);
        $this->assertNull(session('mv_goods_cart_token'));
        $this->post(route('store.submitOrder', $r), $this->payload($m))->assertOk();
        $this->assertSame('kiosk', Order::where('store_id', $r->id)->latest('id')->first()->source);

        [$off] = $this->storeFixture(false);
        $this->postJson('/'.$off->slug.'/kiosk/reset')->assertForbidden(); // the kiosk session of $r cannot reach another store (KioskLock)
        $this->flushSession();
        $this->postJson('/'.$off->slug.'/kiosk/reset')->assertNotFound();
    }

    public function test_the_staff_exit_needs_the_kiosk_pin_and_leaving_removes_the_shell(): void
    {
        [$r] = $this->storeFixture(true);
        $this->get('/'.$r->slug.'/kiosk')->assertOk();
        $this->postJson('/'.$r->slug.'/kiosk/exit', ['pin' => '0000'])->assertOk()->assertJson(['ok' => false]);
        $this->get('/'.$r->slug)->assertSee('kioskExit', false);
        $this->postJson('/'.$r->slug.'/kiosk/exit', ['pin' => '4321'])->assertOk()->assertJson(['ok' => true]);
        $this->get('/'.$r->slug)->assertDontSee('kioskExit', false);
    }
}
